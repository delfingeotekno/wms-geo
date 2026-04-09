<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$message = '';
$message_type = '';

function convertToRoman($num) {
    $romans = array(
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'
    );
    return $romans[$num];
}

function generateTransactionNumber($conn, $transaction_type, $warehouse_id) {
    // Ambil kode warehouse
    $wh_query = $conn->query("SELECT code FROM warehouses WHERE id = $warehouse_id");
    $wh_row = $wh_query->fetch_assoc();
    $location_code = $wh_row['code'] ?? 'WH';

    $roman_month = convertToRoman(date('n'));
    $year = date('Y');
    $prefix_type = ($transaction_type === 'RI') ? 'RI' : 'RET';
    // Format: No_Urut/Tipe/Lokasi/Bulan/Tahun
    $prefix = "%/{$prefix_type}/{$location_code}/{$roman_month}/{$year}";

    // Filter
    $sql = "SELECT transaction_number FROM inbound_transactions WHERE transaction_number LIKE ? ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $last_number = 0;

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $parts = explode('/', $row['transaction_number']);
        $last_number = (int)$parts[0];
    }
    
    $new_number = $last_number + 1;
    $padded_number = str_pad($new_number, 3, '0', STR_PAD_LEFT);
    return "{$padded_number}/{$prefix_type}/{$location_code}/{$roman_month}/{$year}";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sender = trim($_POST['sender']);
    $warehouse_id = (int)$_POST['warehouse_id']; // Added to override session default
    $transaction_date = trim($_POST['transaction_date']);
    $notes = trim($_POST['notes']);
    $raw_items = json_decode($_POST['items_json'], true) ?? [];
    $items = [];
    foreach ($raw_items as $itm) {
        $has_serial = isset($itm['has_serial']) && $itm['has_serial'];
        if ($has_serial) {
            $serial_val = trim($itm['serial_number'] ?? '');
            if ($serial_val !== '' && $serial_val !== '-') {
                $items[] = $itm;
            }
        } else {
            if (isset($itm['quantity']) && (int)$itm['quantity'] > 0) {
                $items[] = $itm;
            }
        }
    }
    $transaction_type = trim($_POST['transaction_type']);
    $proof_photo_data = $_POST['proof_photo_data'] ?? null;
    $po_number = trim($_POST['po_search']) ?? ''; 
    $dn_number = trim($_POST['dn_number']) ?? '';
    $reference = trim($_POST['reference']) ?? '';

    if (empty($sender) || empty($transaction_date) || empty($items) || empty($transaction_type) || empty($proof_photo_data)) {
        $message = 'Mohon lengkapi semua data transaksi, tambahkan setidaknya satu item, dan ambil foto bukti.';
        $message_type = 'warning';
    } else {
        if ($transaction_type == 'RI' && empty($po_number)) {
            $message = 'Nomor Purchase Order (PO) wajib diisi untuk transaksi RECEIVE ITEMS.';
            $message_type = 'warning';
        } else {
            $conn->begin_transaction();
            try {
                $transaction_number = ($transaction_type === 'RI') ? generateTransactionNumber($conn, 'RI', $warehouse_id) : generateTransactionNumber($conn, 'RET', $warehouse_id);

                $proof_photo_path = null;
                $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $proof_photo_data));
                $dir = '../../uploads/inbound_photos/';
                if (!is_dir($dir)) { mkdir($dir, 0777, true); }
                $file_name = 'inbound_' . str_replace('/', '_', $transaction_number) . '.jpeg';
                $proof_photo_path = $dir . $file_name;
                file_put_contents($proof_photo_path, $data);

                $created_by = $_SESSION['user_id'];
                $total_items = count($items);
                $document_number = ($transaction_type === 'RI') ? $po_number : $dn_number;

                $insert_transaction_sql = "INSERT INTO inbound_transactions (transaction_number, transaction_type, transaction_date, sender, notes, total_items, created_by, is_deleted, proof_photo_path, document_number, reference, warehouse_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, NOW(), NOW())";
                $stmt_transaction = $conn->prepare($insert_transaction_sql);
                $stmt_transaction->bind_param("sssssiisssi", $transaction_number, $transaction_type, $transaction_date, $sender, $notes, $total_items, $created_by, $proof_photo_path, $document_number, $reference, $warehouse_id);
                
                if (!$stmt_transaction->execute()) { throw new Exception("Gagal menyimpan header transaksi."); }
                $transaction_id = $conn->insert_id;

                foreach ($items as $item) {
                    $product_id = (int)$item['product_id'];
                    $quantity = (int)$item['quantity'];
                    $serial_number = $item['serial_number'] ?? NULL;

                    // Update Product Stocks (Warehouse Specific)
                    $stock_sql = "INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock = stock + ?";
                    $stock_stmt = $conn->prepare($stock_sql);
                    $stock_stmt->bind_param("iiii", $product_id, $warehouse_id, $quantity, $quantity);
                    $stock_stmt->execute();
                    $stock_stmt->close();

                    $stmt_detail = $conn->prepare("INSERT INTO inbound_transaction_details (transaction_id, product_id, serial_number, quantity, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt_detail->bind_param("iisi", $transaction_id, $product_id, $serial_number, $quantity);
                    $stmt_detail->execute();
                    
                    if (!empty($serial_number)) {
                        $stmt_serial = $conn->prepare("INSERT INTO serial_numbers (product_id, warehouse_id, serial_number, status, is_deleted, created_at, updated_at) VALUES (?, ?, ?, 'Tersedia', 0, NOW(), NOW())");
                        $stmt_serial->bind_param("iis", $product_id, $warehouse_id, $serial_number);
                        $stmt_serial->execute();
                    }

                    // Update PO Received Quantity
                    if ($transaction_type === 'RI' && !empty($po_number)) {
                        // Optimasi: bisa juga $po_id diambil di luar loop
                        $po_id_query = $conn->query("SELECT id FROM purchase_orders WHERE po_number = '$po_number'");
                        if ($po_id_query && $po_id_row = $po_id_query->fetch_assoc()) {
                            $po_id = $po_id_row['id'];
                            $update_po_item_sql = "UPDATE po_items SET received_quantity = received_quantity + ? WHERE po_id = ? AND product_id = ?";
                            $stmt_po_qty = $conn->prepare($update_po_item_sql);
                            $stmt_po_qty->bind_param("iii", $quantity, $po_id, $product_id);
                            $stmt_po_qty->execute();
                            $stmt_po_qty->close();
                        }
                    }
                }

                $conn->commit();
                echo "<script>window.location.href='index.php?status=success_inbound';</script>";
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="content-header mb-4">
    <h1 class="fw-bold">Barang Masuk</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0 fw-bold text-dark">Formulir Barang Masuk (RI berbasis PO)</h5>
        </div>
        <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-arrow-left me-2"></i>Kembali
        </a>
    </div>
    <div class="card-body">
        <form id="inbound-form" action="create.php" method="POST">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="warehouse_id" class="form-label">Gudang Tujuan <span class="text-danger">*</span></label>
                    <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                        <option value="">Pilih Gudang</option>
                        <?php
                        $wh_options = $conn->query("SELECT id, name AS warehouse_name FROM warehouses WHERE is_active = 1");
                        while($w = $wh_options->fetch_assoc()) {
                            echo "<option value='{$w['id']}'>{$w['warehouse_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="transaction_date" class="form-label">Tanggal Transaksi</label>
                    <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="transaction_type" class="form-label">Tipe Transaksi</label>
                    <select class="form-select" id="transaction_type" name="transaction_type" required>
                        <option value="">Pilih Tipe</option>
                        <option value="RI" selected>RECEIVE ITEMS (RI)</option>
                        <option value="RETURN">RETURN</option>
                    </select>
                </div>
            </div>

            <div id="po-selection-container" class="row border-start border-primary border-4 ms-1 mb-3 bg-light p-3" style="display:flex;">
                <div class="col-md-6 mb-3 position-relative">
                    <label class="fw-bold">Cari Nomor PO</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="po_search" name="po_search" class="form-control" placeholder="Ketik nomor PO..." autocomplete="off">
                    </div>
                    <div id="po_search_results" class="list-group position-absolute w-100 shadow" style="z-index:1000; display:none;"></div>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="fw-bold">Vendor / Pengirim</label>
                    <input type="text" class="form-control bg-white" id="sender" name="sender" readonly>
                </div>
                <div class="col-md-12">
                    <label for="reference" class="form-label">Referensi</label>
                    <input type="text" class="form-control" id="reference" name="reference">
                </div>
            </div>

            <div class="mb-3">
                <label for="notes" class="form-label">Catatan</label>
                <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
            </div>

            <div class="card p-3 mb-4 bg-light text-center">
                <div id="photo-preview-container" class="mb-3" style="display: none;">
                    <img id="photo-preview" class="img-fluid rounded border shadow-sm" style="max-width: 250px;">
                    <br>
                    <button type="button" class="btn btn-sm btn-danger mt-2" id="delete-photo-btn">Hapus Foto</button>
                </div>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-dark btn-sm rounded-pill" id="take-photo-btn"><i class="bi bi-camera"></i> Ambil Foto</button>
                    <label class="btn btn-primary btn-sm rounded-pill mb-0" for="upload-photo-input"><i class="bi bi-upload"></i> Unggah</label>
                    <input type="file" id="upload-photo-input" class="d-none" accept="image/*">
                </div>
                <input type="hidden" name="proof_photo_data" id="proof_photo_data">
            </div>

            <h5 class="fw-bold"><i class="bi bi-list-check me-2"></i>Item yang Diterima</h5>
            <div class="table-responsive">
                <table class="table table-bordered align-middle" id="items-table">
                    <thead class="table-secondary">
                        <tr>
                            <th>Kode Produk</th>
                            <th>Nama Produk</th>
                            <th style="width: 80px;">Qty</th>
                            <th>Serial Number</th>
                            <th style="width: 50px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Silakan pilih Nomor PO untuk memuat item.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <input type="hidden" name="items_json" id="items_json">
            <div class="mt-4">
                <button type="submit" class="btn btn-primary rounded-pill px-5">Simpan Transaksi</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Scan Barcode Serial</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 position-relative" style="min-height: 300px; background: #000;">
                <div id="barcode-video-feed" style="width: 100%;"></div>
                <div class="position-absolute top-50 start-50 translate-middle w-75 border border-primary" style="height: 150px; pointer-events: none; border-style: dashed !important; opacity: 0.5;"></div>
            </div>
            <div class="modal-footer justify-content-center">
                <small class="text-muted">Arahkan kamera tepat pada barcode produk</small>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cameraModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5>Ambil Foto Bukti</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center">
                <div class="mb-3">
                    <button id="frontCamBtn" class="btn btn-sm btn-outline-primary">Kamera Depan</button>
                    <button id="backCamBtn" class="btn btn-sm btn-outline-info">Kamera Belakang</button>
                </div>
                <video id="camera-video" class="img-fluid rounded border" style="width: 100%; display: none;" autoplay playsinline></video>
                <canvas id="camera-canvas" style="display: none;"></canvas>
                <div id="camera-message" class="py-5 text-muted">Pilih kamera untuk memulai...</div>
            </div>
            <div class="modal-footer">
                <button id="capture-photo-btn" class="btn btn-primary w-100" disabled>Ambil Foto</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/quagga/dist/quagga.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const transactionTypeSelect = document.getElementById('transaction_type');
    const displayTransNo = document.getElementById('display_trans_no');
    const poContainer = document.getElementById('po-selection-container');
    const poSearchInput = document.getElementById('po_search');
    const poResults = document.getElementById('po_search_results');
    const senderInput = document.getElementById('sender');
    const itemsTableBody = document.querySelector('#items-table tbody');
    const itemsJsonInput = document.getElementById('items_json');
    const takePhotoBtn = document.getElementById('take-photo-btn');
    const proofPhotoDataInput = document.getElementById('proof_photo_data');
    const photoPreview = document.getElementById('photo-preview');
    const photoPreviewContainer = document.getElementById('photo-preview-container');

    const riNumber = "Otomatis saat disimpan";
    const retNumber = "Otomatis saat disimpan";

    let transactionItems = [];
    let activeScannerIndex = null;

// --- LOGIKA SCANNER HARDWARE (GLOBAL LISTENER) ---
    let barcodeBuffer = "";
    let lastKeyTime = Date.now();

    document.addEventListener('keydown', function(e) {
        const activeElem = document.activeElement;
        
        // Abaikan jika user sedang mengetik manual di kolom input teks tertentu
        const isManualInput = activeElem.id === 'notes' || 
                             activeElem.id === 'po_search' || 
                             activeElem.id === 'reference' ||
                             activeElem.tagName === 'TEXTAREA';
        
        if (isManualInput) return;

        const currentTime = Date.now();
        
        // Deteksi kecepatan ketik (Scanner hardware biasanya mengirim karakter sangat cepat < 50ms)
        if (currentTime - lastKeyTime > 100) {
            barcodeBuffer = "";
        }
        lastKeyTime = currentTime;

        // Tangkap input 'Enter' sebagai penanda barcode selesai dibaca
        if (e.key === 'Enter') {
            if (barcodeBuffer.length > 2) {
                e.preventDefault(); // Mencegah form tersimpan tidak sengaja
                e.stopPropagation();
                processHardwareScan(barcodeBuffer.trim());
                barcodeBuffer = "";
            }
        } else if (e.key.length === 1) {
            barcodeBuffer += e.key;
        }
    });

    async function processHardwareScan(code) {
        // Cari baris pertama yang HARUS pakai serial (has_serial: true) tapi MASIH KOSONG
        const emptyIndex = transactionItems.findIndex(item => item.has_serial && (!item.serial_number || item.serial_number === ''));
        
        if (emptyIndex !== -1) {
            playBeep();
            
            // Masukkan kode ke dalam data array melalui fungsi updateSerial (agar validasi duplikat berjalan)
            await updateSerial(emptyIndex, code);
            
            // Render ulang tabel untuk memperbarui tampilan visual
            renderTable();
            
            // Beri feedback visual pada baris yang baru terisi
            setTimeout(() => {
                const rows = itemsTableBody.querySelectorAll('tr');
                if(rows[emptyIndex]) {
                    rows[emptyIndex].classList.add('table-success');
                    rows[emptyIndex].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => rows[emptyIndex].classList.remove('table-success'), 1000);
                }
            }, 50);
        } else {
            // Cek apakah barcode ini sudah ada di daftar (mencegah double scan)
            const isAlreadyScanned = transactionItems.some(item => item.serial_number === code);
            if (isAlreadyScanned) {
                Swal.fire('Info', 'Serial ' + code + ' sudah di-scan.', 'info');
            } else {
                Swal.fire('Penuh', 'Semua slot item serial dalam PO ini sudah terpenuhi.', 'warning');
            }
        }
    }

    function playBeep() {
        try {
            const context = new (window.AudioContext || window.webkitAudioContext)();
            const osc = context.createOscillator();
            osc.type = 'sine'; osc.frequency.setValueAtTime(800, context.currentTime);
            osc.connect(context.destination); osc.start(); osc.stop(context.currentTime + 0.1);
        } catch(e) {}
    }

    // SweetAlert handling for PHP messages
    <?php if ($message): ?>
    Swal.fire({
        icon: '<?= $message_type ?>',
        title: '<?= $message_type == "error" ? "Oops..." : "Perhatian" ?>',
        text: '<?= $message ?>'
    });
    <?php endif; ?>

    // Logika Tipe Transaksi
    transactionTypeSelect.addEventListener('change', function() {
        if (this.value === 'RETURN') {
            window.location.href = 'return_create.php';
        } else if (this.value === 'RI') {
            displayTransNo.textContent = riNumber;
            poContainer.style.display = 'flex';
        } else {
            poContainer.style.display = 'none';
        }
    });

    // Pencarian PO
    poSearchInput.addEventListener('input', function() {
        const warehouseSelect = document.getElementById('warehouse_id');
        const selectedWarehouseId = warehouseSelect.value;
        
        if (!selectedWarehouseId) {
            Swal.fire('Perhatian', 'Silakan pilih Gudang Tujuan terlebih dahulu sebelum mencari PO.', 'warning');
            this.value = '';
            return;
        }

        const term = this.value;
        if (term.length < 2) { poResults.style.display = 'none'; return; }
        fetch(`get_po_list.php?term=${term}&warehouse_id=${selectedWarehouseId}`)
            .then(res => res.json())
            .then(data => {
                poResults.innerHTML = '';
                data.forEach(po => {
                    const btn = document.createElement('a');
                    btn.className = 'list-group-item list-group-item-action';
                    btn.innerHTML = `<b>${po.po_number}</b> <br><small>Vendor: ${po.vendor_name}</small>`;
                    btn.onclick = () => {
                        poSearchInput.value = po.po_number;
                        senderInput.value = po.vendor_name;
                        poResults.style.display = 'none';
                        loadItemsFromPO(po.id);
                    };
                    poResults.appendChild(btn);
                });
                poResults.style.display = 'block';
            });
    });

    function loadItemsFromPO(poId) {
        fetch(`get_po_details.php?po_id=${poId}`)
            .then(res => res.json())
            .then(items => {
                transactionItems = [];
                items.forEach(item => {
                    const isSerial = parseInt(item.has_serial) === 1;
                    if (isSerial) {
                        for (let i = 0; i < item.qty; i++) {
                            transactionItems.push({
                                product_id: item.product_id,
                                product_code: item.product_code,
                                product_name: item.product_name,
                                quantity: 1,
                                serial_number: '',
                                has_serial: true
                            });
                        }
                    } else {
                        transactionItems.push({
                            product_id: item.product_id,
                            product_code: item.product_code,
                            product_name: item.product_name,
                            quantity: item.qty,
                            serial_number: null,
                            has_serial: false
                        });
                    }
                });
                renderTable();
            });
    }

    // Validasi Serial
    async function isSerialDuplicate(serial, productId) {
        if (!serial) return false;
        try {
            const response = await fetch(`check_serial_number.php?serial=${serial}&product_id=${productId}`);
            const data = await response.json();
            return data.exists;
        } catch (e) {
            return false;
        }
    }

    window.updateSerial = async (index, val) => {
        val = val.trim();
        if (val === '') {
            transactionItems[index].serial_number = '';
            itemsJsonInput.value = JSON.stringify(transactionItems);
            return false;
        }

        // Cek Duplikat Internal (di dalam tabel yang sedang diisi)
        const isInternalDuplicate = transactionItems.some((it, idx) => it.serial_number === val && idx !== index);
        if (isInternalDuplicate) {
            Swal.fire('Duplikat!', 'Serial Number ini sudah ada di dalam daftar.', 'warning');
            transactionItems[index].serial_number = '';
            renderTable();
            return false;
        }

        // Cek Duplikat Database (menggunakan API yang sudah Anda buat)
        const existsInDb = await isSerialDuplicate(val, transactionItems[index].product_id);
        if (existsInDb) {
            Swal.fire('Sudah Terdaftar!', `Serial [${val}] sudah ada di sistem.`, 'error');
            transactionItems[index].serial_number = '';
            renderTable();
            return false;
        }

        transactionItems[index].serial_number = val;
        itemsJsonInput.value = JSON.stringify(transactionItems);
        return true;
    };

    // Upload Foto
    document.getElementById('upload-photo-input').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                photoPreview.src = event.target.result;
                proofPhotoDataInput.value = event.target.result;
                photoPreviewContainer.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });

    function renderTable() {
        itemsTableBody.innerHTML = '';
        if(transactionItems.length === 0) {
            itemsTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Pilih PO untuk memuat item.</td></tr>';
            return;
        }
        transactionItems.forEach((item, index) => {
            const row = itemsTableBody.insertRow();
            let serialField = item.has_serial ? `
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" value="${item.serial_number || ''}" onchange="updateSerial(${index}, this.value)" placeholder="Scan Serial">
                    <button type="button" class="btn btn-outline-primary" onclick="openScanner(${index})"><i class="bi bi-upc-scan"></i></button>
                </div>` : '-';

            row.innerHTML = `
                <td>${item.product_code}</td>
                <td>${item.product_name}</td>
                <td>${item.quantity}</td>
                <td>${serialField}</td>
                <td class="text-center"><button type="button" class="btn btn-link text-danger p-0" onclick="removeItem(${index})"><i class="bi bi-trash"></i></button></td>
            `;
        });
        itemsJsonInput.value = JSON.stringify(transactionItems);
    }

    window.removeItem = (index) => {
        transactionItems.splice(index, 1);
        renderTable();
    };

    // Barcode Scanner
    const scannerModalElement = document.getElementById('barcodeScannerModal');
    const scannerModal = new bootstrap.Modal(scannerModalElement);

    window.openScanner = (index) => {
        activeScannerIndex = index;
        scannerModal.show();
    };

    scannerModalElement.addEventListener('shown.bs.modal', function() {
        Quagga.init({
            inputStream: {
                name: "LiveStream",
                type: "LiveStream",
                target: document.querySelector('#barcode-video-feed'),
                constraints: { facingMode: "environment" },
            },
            decoder: { readers: ["code_128_reader", "ean_reader", "code_39_reader"] },
            locate: true
        }, function(err) {
            if (err) { Swal.fire('Error', 'Gagal mengakses kamera: ' + err, 'error'); return; }
            Quagga.start();
        });
    });

    Quagga.onDetected((data) => {
        if (data.codeResult && data.codeResult.code) {
            const code = data.codeResult.code;
            if (activeScannerIndex !== null) {
                updateSerial(activeScannerIndex, code).then(() => {
                    renderTable();
                    scannerModal.hide();
                    Quagga.stop();
                });
            }
        }
    });

    scannerModalElement.addEventListener('hidden.bs.modal', () => { Quagga.stop(); activeScannerIndex = null; });

    // Kamera Bukti
    let cameraStream = null;
    const cameraModalElement = document.getElementById('cameraModal');
    const cameraModal = new bootstrap.Modal(cameraModalElement);
    const cameraVideo = document.getElementById('camera-video');
    const cameraCanvas = document.getElementById('camera-canvas');
    const capturePhotoBtn = document.getElementById('capture-photo-btn');
    const cameraMsg = document.getElementById('camera-message');

    async function startCamera(facingMode) {
        if (cameraStream) { cameraStream.getTracks().forEach(track => track.stop()); }
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facingMode } });
            cameraVideo.srcObject = cameraStream;
            cameraVideo.style.display = 'block';
            cameraMsg.style.display = 'none';
            capturePhotoBtn.disabled = false;
        } catch (err) { Swal.fire('Error', 'Izin kamera ditolak.', 'error'); }
    }

    document.getElementById('frontCamBtn').onclick = () => startCamera('user');
    document.getElementById('backCamBtn').onclick = () => startCamera('environment');
    takePhotoBtn.onclick = () => cameraModal.show();

    capturePhotoBtn.onclick = () => {
        const context = cameraCanvas.getContext('2d');
        cameraCanvas.width = cameraVideo.videoWidth;
        cameraCanvas.height = cameraVideo.videoHeight;
        context.drawImage(cameraVideo, 0, 0);
        const dataUrl = cameraCanvas.toDataURL('image/jpeg', 0.8);
        photoPreview.src = dataUrl;
        proofPhotoDataInput.value = dataUrl;
        photoPreviewContainer.style.display = 'block';
        cameraModal.hide();
    };

    document.getElementById('delete-photo-btn').onclick = () => {
        photoPreviewContainer.style.display = 'none';
        proofPhotoDataInput.value = '';
    };

    cameraModalElement.addEventListener('hidden.bs.modal', () => {
        if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
        cameraVideo.style.display = 'none';
        cameraMsg.style.display = 'block';
    });
});
</script>

<style>
    #barcode-video-feed video { width: 100%; height: auto; }
    #barcode-video-feed canvas { display: none; }
</style>

<?php include '../../includes/footer.php'; ?>