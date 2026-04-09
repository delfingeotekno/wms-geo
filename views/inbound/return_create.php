<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan hanya user yang berhak yang bisa mengakses
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

// --- Fungsi Helper (Ambil dari create.php) ---
function convertToRoman($num) {
    $romans = array(
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'
    );
    return $romans[$num] ?? ''; // Added null coalesce for safety
}

function generateTransactionNumber($conn, $transaction_type, $warehouse_id) {
    // Ambil kode warehouse
    $wh_query = $conn->query("SELECT code FROM warehouses WHERE id = $warehouse_id");
    $wh_row = $wh_query->fetch_assoc();
    $location_code = $wh_row['code'] ?? 'WH';

    $roman_month = convertToRoman(date('n'));
    $year = date('Y');
    
    $prefix_type = ($transaction_type === 'RI') ? 'RI' : 'RET';
    // Periksa tabel inbound_transactions untuk nomor terakhir
    $sql = "SELECT transaction_number FROM inbound_transactions WHERE transaction_number LIKE ? ORDER BY id DESC LIMIT 1";
    // Cari yang berawalan angka (001, 002, dst) dan diikuti dengan prefix/lokasi/bulan/tahun
    $search_prefix = "%/" . $prefix_type . "/{$location_code}/{$roman_month}/{$year}"; 
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $search_prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $last_number = 0;

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Ambil bagian nomor urut (contoh: 001)
        $parts = explode('/', $row['transaction_number']);
        $last_number = (int)$parts[0];
    }
    
    $new_number = $last_number + 1;
    $padded_number = str_pad($new_number, 3, '0', STR_PAD_LEFT);

    return "{$padded_number}/{$prefix_type}/{$location_code}/{$roman_month}/{$year}";
}
// ---------------------------------------------


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $outbound_number = trim($_POST['outbound_number']);
    $sender = trim($_POST['sender']); 
    $warehouse_id = (int)$_POST['warehouse_id'];
    $transaction_date = trim($_POST['transaction_date']);
    $notes = trim($_POST['notes']);
    $items = json_decode($_POST['items_json'], true);
    
    // PAKSA transaction_type menjadi 'RET' agar sesuai permintaan Anda
    $transaction_type = 'RET'; 
    
    $proof_photo_data = $_POST['proof_photo_data'] ?? null;
    $reference = trim($_POST['reference']) ?? '';

    $document_number = $outbound_number; 

    if (empty($sender) || empty($transaction_date) || empty($items) || empty($proof_photo_data) || empty($outbound_number) || empty($warehouse_id)) {
        $message = 'Mohon lengkapi semua data dan ambil foto bukti.';
        $message_type = 'danger';
    } else {
        $conn->begin_transaction();

        try {
            // Generate nomor transaksi dengan prefix RET
            $transaction_number = generateTransactionNumber($conn, 'RET', $warehouse_id); 
            $total_items = count($items);
            $created_by = $_SESSION['user_id'];
            $is_deleted = 0;

            // --- 1. Proses Foto (Tetap sama) ---
            $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $proof_photo_data)); 
            $dir = '../../uploads/inbound_photos/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $file_name = 'return_' . str_replace('/', '_', $transaction_number) . '.jpeg';
            $full_path = $dir . $file_name;
            file_put_contents($full_path, $data);

            // --- 2. Simpan Header Transaksi ---
            // Pastikan kolom transaction_type menerima variabel $transaction_type yang bernilai 'RET'
            $insert_transaction_sql = "INSERT INTO inbound_transactions 
                (transaction_number, transaction_type, transaction_date, sender, notes, total_items, created_by, is_deleted, proof_photo_path, document_number, reference, created_at, updated_at, warehouse_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)";
            
            $stmt_transaction = $conn->prepare($insert_transaction_sql);
            
            // "sssssiiisssi" -> sesuaikan urutan dengan variabel
            $stmt_transaction->bind_param("sssssiiisssi", 
                $transaction_number, // RET/GTG-JKT/...
                $transaction_type,   // Isinya 'RET'
                $transaction_date, 
                $sender, 
                $notes, 
                $total_items, 
                $created_by, 
                $is_deleted, 
                $file_name, 
                $document_number, 
                $reference,
                $warehouse_id
            );
            
            if (!$stmt_transaction->execute()) {
                throw new Exception("Gagal simpan header: " . $stmt_transaction->error);
            }
            $transaction_id = $conn->insert_id;

            // --- 3. Proses Detail Item & Update Serial ---
foreach ($items as $item) {
                $product_id = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                $serial_number = $item['serial_number'] ?? NULL; 

                if ($quantity <= 0) continue;

                // 3a. Update Stok Produk
                $stmt_stock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmt_stock->bind_param("ii", $quantity, $product_id);
                $stmt_stock->execute();

                // 3b. Simpan Detail Transaksi Inbound
                $stmt_detail = $conn->prepare("INSERT INTO inbound_transaction_details (transaction_id, product_id, serial_number, quantity, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt_detail->bind_param("iisi", $transaction_id, $product_id, $serial_number, $quantity);
                $stmt_detail->execute();
                
                // 3c. Update Status Serial Number + Last Transaction Info
                if (!empty($serial_number)) {
                    // Update status menjadi Tersedia dan catat referensi transaksi terakhirnya
                    $update_serial_sql = "UPDATE serial_numbers 
                                        SET status = 'Tersedia', 
                                            last_transaction_id = ?, 
                                            last_transaction_type = 'RET', 
                                            warehouse_id = ?,
                                            updated_at = NOW() 
                                        WHERE serial_number = ? AND product_id = ? AND is_deleted = 0";
                    
                    $stmt_ser = $conn->prepare($update_serial_sql);
                    
                    // Bind parameter: transaction_id (int), warehouse_id (int), serial_number (string), product_id (int)
                    $stmt_ser->bind_param("iisi", $transaction_id, $warehouse_id, $serial_number, $product_id);
                    
                    if (!$stmt_ser->execute()) {
                        throw new Exception("Gagal memperbarui data serial number: " . $serial_number);
                    }
                    $stmt_ser->close();
                }
            }

            $conn->commit();
            header("Location: index.php?status=success_return");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            if (isset($full_path) && file_exists($full_path)) unlink($full_path);
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}


// Query untuk mengambil daftar Nomor Transaksi Outbound sekarang dilakukan via AJAX API `get_outbound_list.php`

?>
<div class="content-header mb-4">
    <h1 class="fw-bold">Formulir Barang Masuk (Return)</h1>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
    <?= $message ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Data Transaksi Return</h5>
        <a href="create.php" class="btn btn-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-arrow-left me-2"></i>Kembali ke Form Inbound
        </a>
    </div>
    <div class="card-body">
        <form id="return-form" action="return_create.php" method="POST">
            <input type="hidden" id="transaction_type" name="transaction_type" value="RETURN">
            <input type="hidden" name="items_json" id="items_json">

            <div class="row">
                <div class="col-md-12 mb-3">
                    <label for="warehouse_id" class="form-label">Gudang Tujuan Return <span class="text-danger">*</span></label>
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
            </div>

            <div class="row">
                <div class="col-md-6 mb-3 position-relative">
                    <label for="outbound_search" class="form-label">Nomor Transaksi Barang Keluar (DN/DO)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="outbound_search" class="form-control" placeholder="Ketik nomor DN/DO atau nama penerima..." autocomplete="off" required>
                    </div>
                    <input type="hidden" id="outbound_number" name="outbound_number" required>
                    <div id="outbound_search_results" class="list-group position-absolute w-100 shadow" style="z-index:1000; display:none;"></div>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="transaction_date" class="form-label">Tanggal Transaksi Return</label>
                    <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="sender" class="form-label">Nama Pengirim (Receiver Outbound)</label>
                    <input type="text" class="form-control" id="sender" name="sender" readonly required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="transaction_number_display" class="form-label">Nomor Transaksi Return (RET)</label>
                    <input type="text" class="form-control" id="transaction_number_display" value="Otomatis saat disimpan" readonly>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="reference" class="form-label">Referensi</label>
                <input type="text" class="form-control" id="reference" name="reference" placeholder="Referensi (dari transaksi keluar)" readonly>
            </div>
            
            <hr>
            
            <h5 class="fw-bold mt-4">Foto Bukti Transaksi <span class="text-danger">*</span></h5>
            <div class="card p-3 mb-3 bg-light text-center">
                <div id="photo-preview-container" class="position-relative mb-3" style="display: none; max-width: 400px; margin: auto;">
                    <img id="photo-preview" class="img-fluid rounded border" alt="Pratinjau Foto">
                    <button type="button" class="btn btn-sm btn-danger rounded-circle position-absolute top-0 end-0 m-2" id="delete-photo-btn" style="width: 30px; height: 30px; font-size: 1rem; padding: 0;">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-center">
                    <button type="button" class="btn btn-dark rounded-pill px-4" id="take-photo-btn">
                        <i class="bi bi-camera-fill me-2"></i>Ambil Foto
                    </button>
                    <label class="btn btn-primary rounded-pill px-4" for="upload-photo-input">
                        <i class="bi bi-upload me-2"></i>Unggah Gambar
                    </i-upload>
                    </label>
                    <input type="file" id="upload-photo-input" name="upload-photo" accept="image/*" class="d-none">
                </div>
                <input type="hidden" name="proof_photo_data" id="proof_photo_data" required>
            </div>
            <h5 class="fw-bold mt-4">Detail Item yang Dikembalikan</h5>
            <p class="text-muted" id="item-info-message">Pilih Nomor Transaksi Keluar di atas untuk memuat daftar item.</p>

            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="items-table">
                    <thead>
                        <tr class="table-secondary">
                            <th>Kode Produk</th>
                            <th>Nama Produk</th>
                            <th>SN/Detail</th>
                            <th>Qty Keluar</th>
                            <th>Qty Return</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        </tbody>
                </table>
            </div>

            <div class="mt-4">
                <label for="notes" class="form-label">Catatan Return</label>
                <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
            </div>
            
            <button type="submit" class="btn btn-success rounded-pill px-4 mt-3" id="submit-return-btn" disabled>
                <i class="bi bi-arrow-return-left me-2"></i>Proses Return
            </button>
        </form>
    </div>
</div>

<div class="modal fade" id="cameraModal" tabindex="-1" aria-labelledby="cameraModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cameraModalLabel">Ambil Foto Bukti</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div id="camera-select-buttons" class="mb-3 d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-sm btn-primary" id="frontCamBtn"><i class="bi bi-person-circle"></i> Kamera Depan</button>
                    <button type="button" class="btn btn-sm btn-info" id="backCamBtn"><i class="bi bi-camera-fill"></i> Kamera Belakang</button>
                </div>
                
                <video id="camera-video" class="img-fluid rounded border" style="width: 100%; max-width: 400px; display: none;"></video>
                <div id="camera-message" class="text-muted p-3">Pilih kamera untuk memulai.</div>
                <canvas id="camera-canvas" style="display: none;"></canvas>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="capture-photo-btn" disabled>
                    <i class="bi bi-camera me-2"></i>Ambil Foto
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>



<!-- Bootstrap JS (WAJIB sebelum script kamu) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>
    // --- Variabel Global untuk Foto/Kamera (Sama) ---
    let currentStream = null;
    let preferredFacingMode = 'environment';
    const cameraVideo = document.getElementById('camera-video');
    const cameraCanvas = document.getElementById('camera-canvas');
    const capturePhotoBtn = document.getElementById('capture-photo-btn');
    const takePhotoBtn = document.getElementById('take-photo-btn');
    const uploadPhotoInput = document.getElementById('upload-photo-input');
    const photoPreview = document.getElementById('photo-preview');
    const photoPreviewContainer = document.getElementById('photo-preview-container');
    const deletePhotoBtn = document.getElementById('delete-photo-btn');
    const proofPhotoDataInput = document.getElementById('proof_photo_data');
    const cameraModal = new bootstrap.Modal(document.getElementById('cameraModal'));

    // --- Variabel Global Form Return ---
    const outboundSearchInput = document.getElementById('outbound_search');
    const outboundResults = document.getElementById('outbound_search_results');
    const outboundHiddenInput = document.getElementById('outbound_number');
    const senderInput = document.getElementById('sender');
    const referenceInput = document.getElementById('reference');
    const itemsTableBody = document.querySelector('#items-table tbody');
    const itemsJsonInput = document.getElementById('items_json');
    const submitBtn = document.getElementById('submit-return-btn');
    const itemInfoMessage = document.getElementById('item-info-message');

    // Array global untuk menyimpan state item yang akan di-return
    const ITEMS_RETURN = []; 
    
    // Fungsi untuk menghentikan aliran kamera (Sama)
    function stopCamera() {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
        }
        cameraVideo.style.display = 'none';
        cameraVideo.srcObject = null;
        capturePhotoBtn.disabled = true;
        document.getElementById('camera-message').style.display = 'block';
        document.getElementById('camera-message').textContent = 'Pilih kamera untuk memulai.';
    }

    // Fungsi untuk memulai kamera (Sama)
    function startCamera(facingMode) {
        stopCamera();
        // ... (Logika startCamera tetap sama)
        const constraints = {
            video: {
                facingMode: facingMode
            }
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(stream => {
                currentStream = stream;
                cameraVideo.srcObject = stream;
                cameraVideo.style.display = 'block';
                document.getElementById('camera-message').style.display = 'none';
                
                cameraVideo.onloadedmetadata = () => {
                    cameraVideo.play();
                    capturePhotoBtn.disabled = false;
                };
            })
            .catch(err => {
                console.error("Error mengakses kamera:", err);
                document.getElementById('camera-message').textContent = 'Akses kamera ditolak atau tidak tersedia.';
            });
    }

    // Event Listener untuk tombol Ambil Foto (Sama)
    takePhotoBtn.addEventListener('click', function() {
        startCamera(preferredFacingMode); 
        cameraModal.show();
    });

    // Event Listener untuk tombol Kamera Depan (Sama)
    document.getElementById('frontCamBtn').addEventListener('click', () => {
        preferredFacingMode = 'user';
        startCamera('user');
    });

    // Event Listener untuk tombol Kamera Belakang (Sama)
    document.getElementById('backCamBtn').addEventListener('click', () => {
        preferredFacingMode = 'environment';
        startCamera('environment');
    });

    // Event saat modal kamera ditutup (Sama)
    document.getElementById('cameraModal').addEventListener('hidden.bs.modal', stopCamera);


    // Event Listener untuk Capture Photo (Sama)
    capturePhotoBtn.addEventListener('click', function() {
        cameraCanvas.width = cameraVideo.videoWidth;
        cameraCanvas.height = cameraVideo.videoHeight;
        const context = cameraCanvas.getContext('2d');
        
        context.drawImage(cameraVideo, 0, 0, cameraCanvas.width, cameraCanvas.height);

        const imageDataURL = cameraCanvas.toDataURL('image/jpeg', 0.8);

        photoPreview.src = imageDataURL;
        proofPhotoDataInput.value = imageDataURL;
        photoPreviewContainer.style.display = 'block';
        
        stopCamera();
        cameraModal.hide();
    });

    // Event Listener untuk Upload Foto (Sama)
    uploadPhotoInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                photoPreview.src = e.target.result;
                proofPhotoDataInput.value = e.target.result;
                photoPreviewContainer.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });

    // Event Listener untuk Hapus Foto (Sama)
    deletePhotoBtn.addEventListener('click', function() {
        photoPreview.src = '';
        proofPhotoDataInput.value = '';
        photoPreviewContainer.style.display = 'none';
        uploadPhotoInput.value = ''; // Reset input file
    });

    // --- LOGIKA FORM RETURN (Pengolahan Item) ---
    
    /**
     * Mengosongkan data form yang berkaitan dengan transaksi yang dicari.
     */
    function resetForm() {
        senderInput.value = '';
        referenceInput.value = '';
        itemsTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Belum ada data item yang dimuat.</td></tr>';
        itemInfoMessage.textContent = 'Pilih Nomor Transaksi Keluar di atas untuk memuat daftar item.';
        ITEMS_RETURN.length = 0;
        updateItemsJson();
        submitBtn.disabled = true;
    }

    /**
     * Mengambil data transaksi keluar menggunakan AJAX/Fetch API.
     */
    function fetchTransactionData(outboundNumber) {
    if (!outboundNumber) {
        resetForm();
        return;
    }

    itemInfoMessage.innerHTML = '<span class="text-info"><i class="bi bi-hourglass-split"></i> Memuat item dari transaksi ' + outboundNumber + '...</span>';
    
    fetch('get_outbound_data.php?txn_number=' + encodeURIComponent(outboundNumber)) 
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        if (data.status === 'success') {
            senderInput.value = data.header.recipient; // Penerima Outbound jadi Pengirim Return
            referenceInput.value = data.header.reference || '';
            itemInfoMessage.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Transaksi ditemukan! Daftar item siap untuk dikembalikan.</span>';
            renderItemsTable(data.details);
            submitBtn.disabled = data.details.length === 0;

        } else {
            alert('Gagal memuat data transaksi keluar: ' + data.message);
            itemInfoMessage.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + data.message + '</span>';
            resetForm();
        }
    })
    .catch(error => {
        console.error('Fetch atau Parsing Error:', error);
        alert('Terjadi kesalahan fatal saat memuat data: ' + error.message);
        itemInfoMessage.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Kesalahan Sistem.</span>';
        resetForm();
    });
}


    /**
     * Merender data item dari API ke dalam tabel dan mengisi array ITEMS_RETURN.
     */
    function renderItemsTable(details) {
    itemsTableBody.innerHTML = ''; 
    ITEMS_RETURN.length = 0; // Reset array global

    details.forEach((item, index) => {
        const isSerial = item.has_serial == 1;
        const row = itemsTableBody.insertRow();

        // Kolom 1: Kode Produk
        row.insertCell().innerHTML = `<span class="fw-bold">${item.product_code}</span>`;

        // Kolom 2: Nama Produk
        row.insertCell().textContent = item.product_name || '-';

        // Kolom 3: SN/Detail
        const snCell = row.insertCell();
        const serialValue = isSerial ? (item.serial_number || '-') : '-';
        snCell.textContent = serialValue;

        // Kolom 4: Qty Keluar
        row.insertCell().textContent = item.quantity;

        // Kolom 5: Qty Return
        const returnQtyCell = row.insertCell();
        let returnQtyInputHTML = '';

        // Inisialisasi item di array ITEMS_RETURN
        const defaultReturnQty = isSerial ? 1 : parseInt(item.quantity);

        ITEMS_RETURN.push({
            product_id: item.product_id,
            quantity: defaultReturnQty, // Default semua item di-return
            serial_number: isSerial ? item.serial_number : null,
            is_return: true
        });

        if (isSerial) {
            // Jika memiliki SN, gunakan switch
            returnQtyInputHTML = `
                <div class="form-check form-switch d-flex justify-content-center">
                    <input class="form-check-input item-switch" type="checkbox" id="return-switch-${index}" data-index="${index}" checked>
                    <label class="form-check-label ms-2" for="return-switch-${index}">Return</label>
                </div>
            `;
        } else {
            // Jika tidak memiliki SN, gunakan input untuk Qty Return
            returnQtyInputHTML = `
                <input type="number" class="form-control form-control-sm return-qty-input" 
                       data-index="${index}" value="${item.quantity}" min="0" max="${item.quantity}" 
                       required style="width: 80px; text-align: center;">
            `;
        }
        returnQtyCell.innerHTML = returnQtyInputHTML;

        // Kolom 6: Aksi (kosong dulu)
        row.insertCell().textContent = '';
    });

    setupItemListeners();
    updateItemsJson();
}
    /**
     * Menyiapkan listener untuk input/switch Qty Return.
     */
    function setupItemListeners() {
        // Listener untuk switch Serial Number
        document.querySelectorAll('.item-switch').forEach(switchEl => {
            switchEl.addEventListener('change', function() {
                const index = parseInt(this.dataset.index);
                const isChecked = this.checked;
                
                if (index < ITEMS_RETURN.length) {
                    ITEMS_RETURN[index].is_return = isChecked;
                    // Jika tidak di-return, set Qty ke 0, jika di-return, set Qty ke 1 (untuk SN)
                    ITEMS_RETURN[index].quantity = isChecked ? 1 : 0; 
                }
                updateItemsJson();
            });
        });

        // Listener untuk input Quantity (Non-Serial)
        document.querySelectorAll('.return-qty-input').forEach(inputEl => {
            inputEl.addEventListener('input', function() {
                const index = parseInt(this.dataset.index);
                let qty = parseInt(this.value);
                const maxQty = parseInt(this.getAttribute('max'));

                // Sanitasi input
                if (isNaN(qty) || qty < 0) {
                    qty = 0;
                } else if (qty > maxQty) {
                    qty = maxQty;
                    this.value = maxQty; 
                } else if (this.value === "") { // Agar tidak langsung menjadi 0 saat dikosongkan
                    return; 
                }
                
                if (index < ITEMS_RETURN.length) {
                    ITEMS_RETURN[index].quantity = qty;
                    ITEMS_RETURN[index].is_return = qty > 0; 
                }
                updateItemsJson();
            });
        });
    }

    /**
     * Mengupdate hidden field JSON sebelum submit.
     */
    function updateItemsJson() {
        // Filter hanya item yang ditandai untuk return (Qty > 0)
        const itemsToSubmit = ITEMS_RETURN.filter(item => item.quantity > 0);
        itemsJsonInput.value = JSON.stringify(itemsToSubmit);
        
        // Non-aktifkan tombol submit jika tidak ada item yang di-return
        submitBtn.disabled = itemsToSubmit.length === 0 || !proofPhotoDataInput.value || !outboundHiddenInput.value;
    }

    // --- Inisialisasi & Event Utama ---
    document.addEventListener('DOMContentLoaded', function() {
        // Inisialisasi awal (reset form saat load)
        resetForm();

        // Pencarian Transaksi Outbound (AJAX)
        outboundSearchInput.addEventListener('input', function() {
            const warehouseSelect = document.getElementById('warehouse_id');
            const selectedWarehouseId = warehouseSelect.value;
            
            if (!selectedWarehouseId) {
                Swal.fire('Perhatian', 'Silakan pilih Gudang Tujuan Return terlebih dahulu.', 'warning');
                this.value = '';
                return;
            }

            const term = this.value.trim();
            if (term.length < 2) { 
                outboundResults.style.display = 'none'; 
                if(term.length === 0) {
                    outboundHiddenInput.value = '';
                    resetForm();
                }
                return; 
            }
            
            fetch(`get_outbound_list.php?term=${encodeURIComponent(term)}&warehouse_id=${selectedWarehouseId}`)
                .then(res => res.json())
                .then(data => {
                    outboundResults.innerHTML = '';
                    if (data.length === 0) {
                        outboundResults.innerHTML = '<div class="list-group-item text-muted">Transaksi tidak ditemukan</div>';
                    } else {
                        data.forEach(txn => {
                            const btn = document.createElement('a');
                            btn.className = 'list-group-item list-group-item-action text-break';
                            btn.style.cursor = 'pointer';
                            btn.innerHTML = `<strong>${txn.transaction_number}</strong><br><small class="text-muted">Penerima: ${txn.recipient}</small>`;
                            btn.onclick = () => {
                                outboundSearchInput.value = txn.transaction_number;
                                outboundHiddenInput.value = txn.transaction_number;
                                outboundResults.style.display = 'none';
                                fetchTransactionData(txn.transaction_number);
                                updateItemsJson();
                            };
                            outboundResults.appendChild(btn);
                        });
                    }
                    outboundResults.style.display = 'block';
                }).catch(err => console.error(err));
        });

        // Sembunyikan hasil pencarian jika klik di luar
        document.addEventListener('click', (e) => {
            if (!outboundResults.contains(e.target) && e.target !== outboundSearchInput) {
                outboundResults.style.display = 'none';
            }
        });

        // Listener untuk reset form saat textbox dikosongkan secara manual
        outboundSearchInput.addEventListener('change', function() {
             if (this.value === '') {
                 outboundHiddenInput.value = '';
                 resetForm();
             }
        });
        
        // Listener untuk mengaktifkan/menonaktifkan tombol submit
        proofPhotoDataInput.addEventListener('change', updateItemsJson);

        // --- Validasi Final saat form disubmit ---
        document.getElementById('return-form').addEventListener('submit', function(e) {
            updateItemsJson(); // Pastikan JSON terakhir terupdate

            document.getElementById('transaction_type').value = 'RETURN';
            
            // Validasi 1: Foto Bukti
            if (!proofPhotoDataInput.value) {
                e.preventDefault();
                alert('Mohon ambil atau unggah foto bukti transaksi.');
                return false;
            }

            // Validasi 2: Minimal 1 item di-return
            const items = JSON.parse(itemsJsonInput.value || '[]');
            if (items.length === 0) {
                e.preventDefault();
                alert('Pilih setidaknya satu item untuk di-return dengan kuantitas lebih dari nol.');
                return false;
            }
            
            return true;
        });
    });
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Cek parameter URL
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');

    if (status === 'success_return') {
        Swal.fire({
            title: 'Transaksi Berhasil!',
            text: 'Barang return berhasil diproses dan disimpan ke sistem.',
            icon: 'success',
            confirmButtonText: 'OK',
            confirmButtonColor: '#198754',
            timer: 3000,
            timerProgressBar: true
        }).then(() => {
            // Setelah popup ditutup, arahkan ke halaman index daftar inbound
            window.location.href = 'index.php';
        });
    }
});
</script>


<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


<?php include '../../includes/footer.php'; ?>