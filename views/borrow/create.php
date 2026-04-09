<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Validasi akses pengguna
if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    header("Location: ../../index.php");
    header("Location: ../../index.php");
    exit();
}

$message = '';
$message_type = '';

/**
 * Fungsi Konversi Romawi
 */
function integerToRoman($integer) {
    $roman_numerals = [
        'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400,
        'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40,
        'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1
    ];
    $result = '';
    foreach ($roman_numerals as $roman => $value) {
        $matches = intval($integer / $value);
        $result .= str_repeat($roman, $matches);
        $integer %= $value;
    }
    return $result;
}

/**
 * Simpan Gambar
 */
function saveBase64Image($base64_string, $upload_dir, $prefix = 'img_') {
    if (preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $base64_string, $matches)) {
        $img_type = $matches[1];
        $base64_string = substr($base64_string, strpos($base64_string, ',') + 1);
        $data = base64_decode($base64_string);
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);
        $filename = $prefix . uniqid() . '.' . $img_type;
        if (file_put_contents($upload_dir . '/' . $filename, $data)) return $filename;
    }
    return null;
}

// 1. Ambil Daftar Gudang Aktif using prepared statement
$wh_stmt = $conn->prepare("SELECT id, name AS warehouse_name, code AS warehouse_code FROM warehouses WHERE is_active = 1 ORDER BY name ASC");
$wh_stmt->execute();
$warehouses_query = $wh_stmt->get_result();

// 2. Variabel dasar untuk penomoran (digunakan oleh JS via PHP echo)
$current_month_roman = integerToRoman(date('n'));
$current_year = date('Y');

// 3. PROSES SIMPAN DATA
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $borrow_date    = $_POST['borrow_date'];
    $borrower_name  = trim($_POST['borrower_name']);
    $warehouse_id   = (int)$_POST['warehouse_id'];
    $subject        = trim($_POST['subject']);
    $notes          = trim($_POST['notes']);
    $items          = json_decode($_POST['items_json'], true);
    $transaction_number = $_POST['transaction_number']; 
    
    $camera_photo_data = $_POST['camera_photo_data'] ?? null;
    $photo_upload_exists = isset($_FILES['photo_upload']) && $_FILES['photo_upload']['error'] == 0;

    if (empty($borrower_name) || empty($warehouse_id) || empty($items) || (empty($camera_photo_data) && !$photo_upload_exists)) {
        $message = 'Mohon lengkapi data dan lampirkan foto.';
        $message_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            $photo_url_filename = null;
            $upload_dir = __DIR__ . '/../../uploads/borrowed_photos';
            if (!empty($camera_photo_data)) {
                $photo_url_filename = saveBase64Image($camera_photo_data, $upload_dir, 'borrowed_');
            } elseif ($photo_upload_exists) {
                $ext = pathinfo($_FILES['photo_upload']['name'], PATHINFO_EXTENSION);
                $photo_url_filename = uniqid('borrow_', true) . '.' . $ext;
                move_uploaded_file($_FILES['photo_upload']['tmp_name'], $upload_dir . '/' . $photo_url_filename);
            }

            $insert_sql = "INSERT INTO borrowed_transactions (transaction_number, borrow_date, borrower_name, warehouse_id, subject, notes, user_id, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_tx = $conn->prepare($insert_sql);
            $user_id = $_SESSION['user_id'];
            $stmt_tx->bind_param("sssissis", $transaction_number, $borrow_date, $borrower_name, $warehouse_id, $subject, $notes, $user_id, $photo_url_filename);
            $stmt_tx->execute();
            $transaction_id = $conn->insert_id;

            foreach ($items as $item) {
                $p_id = (int)$item['product_id'];
                $qty  = (int)$item['quantity'];
                $sn   = !empty($item['serial_number']) ? $item['serial_number'] : NULL;

                if ($sn) {
                    $upd_sn = $conn->prepare("UPDATE serial_numbers SET status = 'Dipinjam', last_transaction_id = ?, last_transaction_type = 'BORROW' WHERE product_id = ? AND serial_number = ? AND warehouse_id = ?");
                    $upd_sn->bind_param("iisi", $transaction_id, $p_id, $sn, $warehouse_id);
                    $upd_sn->execute();
                }

                // Update warehouse_stocks
                $conn->query("UPDATE warehouse_stocks SET stock = stock - $qty WHERE warehouse_id = $warehouse_id AND product_id = $p_id");

                $ins_det = $conn->prepare("INSERT INTO borrowed_transactions_detail (transaction_id, product_id, serial_number, quantity) VALUES (?, ?, ?, ?)");
                $ins_det->bind_param("iisi", $transaction_id, $p_id, $sn, $qty);
                $ins_det->execute();
            }

            $conn->commit();
            header("Location: index.php?status=success_borrow");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peminjaman Barang - Multi Warehouse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        #serial_search_results, #product_search_results {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0 0 0.5rem 0.5rem;
            background-color: white;
            width: 100%;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            z-index: 1060;
            position: absolute;
        }
        #serial_search_results .list-group-item, #product_search_results .list-group-item {
            cursor: pointer;
            transition: all 0.2s;
        }
        #serial_search_results .list-group-item:hover, #product_search_results .list-group-item:hover {
            background-color: #f0f7ff;
            color: #0d6efd;
        }
        .custom-alert-modal .modal-content { border-radius: 1rem; border: none; }
        .warehouse-banner { border-left: 5px solid #0d6efd; }
        #cameraModal .modal-body video, #cameraModal .modal-body img {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
        }
        .spinner-grow-sm { width: 0.8rem; height: 0.8rem; }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="container-fluid my-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="fw-bold h3 mb-1"><i class="bi bi-box-arrow-right me-2 text-primary"></i>Peminjaman Barang</h1>
                    <p class="text-muted small mb-0">Input data peminjaman barang dari gudang ke peminjam.</p>
                </div>
                <a href="/wms-geo/views/borrow/index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i> Kembali
                </a>
            </div>

            <form id="borrow-form" action="create.php" method="POST" enctype="multipart/form-data">
                
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body py-3 bg-light rounded shadow-sm">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="warehouse_id" class="form-label small fw-bold text-muted text-uppercase mb-1">Gudang Asal</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white border-primary text-primary"><i class="bi bi-geo-alt"></i></span>
                                    <select name="warehouse_id" id="warehouse_id" class="form-select" required onchange="generateTransactionNumber()">
                                        <option value="">-- Pilih Lokasi Gudang --</option>
                                        <?php 
                                        mysqli_data_seek($warehouses_query, 0); // Reset pointer
                                        while($wh = mysqli_fetch_assoc($warehouses_query)): ?>
                                            <option value="<?= $wh['id'] ?>" data-code="<?= $wh['warehouse_code'] ?>">
                                                <?= $wh['warehouse_name'] ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="borrow_date" class="form-label small fw-bold text-muted text-uppercase mb-1">Tanggal Transaksi</label>
                                <input type="date" class="form-control form-control-sm" id="borrow_date" name="borrow_date" value="<?= date('Y-m-d') ?>" required onchange="generateTransactionNumber()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-1">No. Transaksi</label>
                                <input type="text" class="form-control form-control-sm bg-white fw-bold" id="transaction_number_display" name="transaction_number" value="Pilih Gudang..." readonly>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-person me-2 text-primary"></i>Informasi Peminjam</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="borrower_name" class="form-label fw-semibold">Nama Peminjam</label>
                                <input type="text" class="form-control" id="borrower_name" name="borrower_name" placeholder="Siapa yang meminjam?" required>
                            </div>
                            <div class="col-md-6">
                                <label for="subject" class="form-label fw-semibold">Subjek / Keperluan</label>
                                <input type="text" class="form-control" id="subject" name="subject" placeholder="Tujuan peminjaman..." required>
                            </div>
                            <div class="col-md-12">
                                <label for="notes" class="form-label fw-semibold">Catatan</label>
                                <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Informasi tambahan jika ada..."></textarea>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">Foto Bukti / Dokumen</label>
                                <div class="d-flex align-items-center gap-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="openCameraModalBtn">
                                        <i class="bi bi-camera me-1"></i> Kamera
                                    </button>
                                    <input type="file" class="form-control form-control-sm w-auto" id="photoFileUpload" name="photo_upload" accept="image/*">
                                </div>
                                <div id="photoPreviewSection" class="mt-2" style="display: none;">
                                    <div class="d-inline-flex align-items-center p-2 border rounded">
                                        <img id="photoPreviewImg" src="" alt="Preview" class="rounded me-2" style="height: 50px; width: 50px; object-fit: cover;">
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0" id="removePhotoBtn">Hapus</button>
                                        <input type="hidden" id="cameraPhotoData" name="camera_photo_data">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2 text-primary"></i>Detail Barang</h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-primary py-2 border-0 mb-4 d-flex align-items-center" style="background-color: #eef6ff;">
                            <div class="spinner-grow spinner-grow-sm text-primary me-2" role="status"></div>
                            <small class="fw-bold">Scanner Ready:</small>
                            <small class="ms-1 text-muted">Arahkan kursor ke input produk untuk mulai scan.</small>
                        </div>

                        <div class="row g-2 mb-4 p-3 rounded border bg-light">
                            <div class="col-md-12 position-relative">
                                <label class="small fw-bold text-muted mb-1">Cari Produk / Manual Select</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control border-start-0" id="product_input" placeholder="Ketik nama atau kode produk..." autocomplete="off">
                                </div>
                                <div id="product_search_results" class="list-group" style="display: none; position: absolute; width: 100%; z-index: 1060;"></div>
                                <input type="hidden" id="selected_product_id">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle" id="items-table">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th class="ps-3 py-2">Produk</th>
                                        <th width="120" class="text-center">Qty</th>
                                        <th width="250">Serial Number</th>
                                        <th width="80" class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="border-top-0"></tbody>
                            </table>
                        </div>
                        
                        <input type="hidden" name="items_json" id="items_json">

                        <div class="border-top mt-4 pt-4 text-center">
                            <button type="submit" class="btn btn-success btn-lg rounded-pill px-5 shadow">
                                <i class="bi bi-cloud-check me-2"></i> Simpan Peminjaman
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <!-- MODAL QUANTITY (NON-SERIAL) -->
            <div class="modal fade" id="qtyModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-sm modal-dialog-centered">
                    <div class="modal-content border-0 shadow">
                        <div class="modal-header bg-light">
                            <h6 class="modal-title fw-bold">Input Kuantitas</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p id="qtyModalProductName" class="small mb-2 text-muted fw-semibold"></p>
                            <input type="number" id="manualQtyInput" class="form-control form-control-lg text-center" min="1" value="1">
                        </div>
                        <div class="modal-footer border-top-0">
                            <button type="button" class="btn btn-primary w-100 rounded-pill" id="confirmQtyBtn">Tambahkan</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODAL PILIH SERIAL NUMBER (CHECKBOX) -->
            <div class="modal fade" id="serialModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content border-0 shadow">
                        <div class="modal-header bg-light">
                            <h6 class="modal-title fw-bold text-primary">Pilih Serial Number</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p id="serialModalProductName" class="fw-bold mb-2"></p>
                            
                            <!-- Search Mini Box -->
                            <div class="input-group input-group-sm mb-3">
                                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                <input type="text" id="serialSearchInput" class="form-control" placeholder="Cari Serial Number...">
                            </div>

                            <!-- Container Checkbox -->
                            <div id="serialCheckboxesContainer" class="list-group list-group-flush" style="max-height: 250px; overflow-y: auto;">
                                <div class="text-center py-3 text-muted" id="serialLoadingSpinner">
                                    <div class="spinner-border spinner-border-sm me-1" role="status"></div> Memuat Serial...
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light d-flex justify-content-between py-2">
                            <span class="badge bg-primary rounded-pill">Dipilih: <span id="selectedSerialCount">0</span></span>
                            <div>
                                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                                <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-bold" id="confirmSerialBtn" disabled>Tambahkan</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODAL KAMERA -->
            <div class="modal fade" id="cameraModal" tabindex="-1" aria-labelledby="cameraModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered"> 
                    <div class="modal-content border-0 shadow">
                        <div class="modal-header bg-light">
                            <h6 class="modal-title fw-bold" id="cameraModalLabel">Ambil Foto Bukti</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-center">
                            <div class="btn-group mb-3 w-100" role="group">
                                <button type="button" class="btn btn-sm btn-primary active" id="camera-front-btn">Depan</button>
                                <button type="button" class="btn btn-sm btn-info text-white" id="camera-back-btn">Belakang</button>
                            </div>
                            <video id="cameraFeed" class="w-100 rounded" autoplay playsinline style="max-height: 250px; background: #000;"></video>
                            <canvas id="photoCanvas" class="d-none"></canvas>
                        </div>
                        <div class="modal-footer bg-light d-flex justify-content-between py-2">
                            <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Tutup</button>
                            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" id="capture-btn">
                                <i class="bi bi-camera me-1"></i>Foto
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function generateTransactionNumber() {
            const whSelect = document.getElementById('warehouse_id');
            const dateInput = document.getElementById('borrow_date').value;
            const displayInput = document.getElementById('transaction_number_display');
            
            if (!whSelect.value || !dateInput) {
                displayInput.value = "Pilih Gudang...";
                return;
            }

            const warehouseCode = whSelect.options[whSelect.selectedIndex].getAttribute('data-code');
            const romanMonth = "<?= $current_month_roman ?>";
            const year = "<?= $current_year ?>";

            // Memanggil file di folder yang sama
            fetch('get_next_number.php?warehouse_id=' + whSelect.value + '&date=' + dateInput)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    const nextId = data.next_id;
                    // Set ke input display
                    displayInput.value = nextId + '/SPB/' + warehouseCode + '/' + romanMonth + '/' + year;
                })
                .catch(err => {
                    console.error("Detail Error:", err);
                    displayInput.value = "Error generating number";
                });
        }
    </script>    

    <!-- BOOTSTRAP JS BUNDLE -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- ALERT FALLBACK ---
            function showCustomAlert(message) {
                const modalEl = document.getElementById('customAlertModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    document.getElementById('customAlertMessage').textContent = message;
                    modal.show();
                } else {
                    alert(message); // Standard alert fallback
                }
            }

            // --- ELEMEN UTAMA ---
            const warehouseSelect = document.getElementById('warehouse_id');
            const productInput = document.getElementById('product_input');
            const searchResultsContainer = document.getElementById('product_search_results');
            const itemsTableBody = document.querySelector('#items-table tbody');
            const itemsJsonInput = document.getElementById('items_json');
            
            // --- MODAL & TEMP STATE ---
            const qtyModal = new bootstrap.Modal(document.getElementById('qtyModal'));
            const serialModal = new bootstrap.Modal(document.getElementById('serialModal'));
            const serialCheckboxesContainer = document.getElementById('serialCheckboxesContainer');
            const serialSearchInput = document.getElementById('serialSearchInput');
            const serialCountBadge = document.getElementById('selectedSerialCount');
            const confirmSerialBtn = document.getElementById('confirmSerialBtn');

            let transactionItems = [];
            let tempProductData = null; 
            let availableSerialsData = [];

            // =================================================================
            // 1. SMART SCANNER HARDWARE
            // =================================================================
            let barcodeBuffer = '';
            let lastKeyTime = Date.now();

            document.addEventListener('keydown', function(e) {
                const active = document.activeElement;
                if (active.tagName === 'TEXTAREA' || active.tagName === 'INPUT') return;

                const now = Date.now();
                if (now - lastKeyTime > 50) barcodeBuffer = '';
                lastKeyTime = now;

                if (e.key === 'Enter') {
                    if (barcodeBuffer.length >= 3) {
                        e.preventDefault();
                        handleSmartScan(barcodeBuffer.trim());
                        barcodeBuffer = '';
                    }
                } else if (e.key.length === 1) {
                    barcodeBuffer += e.key;
                }
            });

            async function handleSmartScan(barcode) {
                const whId = warehouseSelect.value;
                if (!whId) { showCustomAlert('Silakan pilih Lokasi Gudang terlebih dahulu!'); return; }

                try {
                    const res = await fetch(`smart_scan_lookup.php?barcode=${encodeURIComponent(barcode)}&warehouse_id=${whId}`);
                    const data = await res.json();

                    if (!data.found) { showCustomAlert(data.error || 'Barcode tidak dikenali.'); return; }

                    const product = data.product;
                    
                    if (data.match_type === 'serial_number') {
                        const duplicate = transactionItems.find(i => i.serial_number === data.serial_number);
                        if (duplicate) { showCustomAlert('Serial ini sudah ada di daftar!'); return; }

                        transactionItems.push({
                            product_id: product.id,
                            product_code: product.product_code,
                            product_name: product.product_name,
                            has_serial: true,
                            quantity: 1,
                            serial_number: data.serial_number
                        });
                        renderItemsTable();
                        playBeep();
                    } else if (data.match_type === 'product_code') {
                        if (parseInt(product.has_serial) === 0) {
                            addItemToTable(product, 1);
                            playBeep();
                        } else {
                            tempProductData = product;
                            openSerialSelectionModal(product);
                        }
                    }
                } catch (e) { console.error(e); }
            }

            function playBeep() {
                try {
                    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    const oscillator = audioCtx.createOscillator();
                    oscillator.type = 'sine';
                    oscillator.frequency.setValueAtTime(1000, audioCtx.currentTime); 
                    oscillator.connect(audioCtx.destination);
                    oscillator.start(); oscillator.stop(audioCtx.currentTime + 0.1); 
                } catch (e) {}
            }

            // =================================================================
            // 2. SEARCH PRODUK MANUAL
            // =================================================================
            let searchTimeout = null;
            productInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const term = this.value.trim();
                if (term.length < 2) { searchResultsContainer.style.display = 'none'; return; }

                searchTimeout = setTimeout(() => {
                    const whId = warehouseSelect.value;
                    if (!whId) { showCustomAlert("Pilih gudang asal."); return; }
                    
                    fetch(`search_products.php?q=${encodeURIComponent(term)}&warehouse_id=${whId}`)
                        .then(res => res.json())
                        .then(data => {
                            console.log("Search products term:", term, "Data:", data);
                            searchResultsContainer.innerHTML = '';
                            if (data.results.length === 0) {
                                searchResultsContainer.innerHTML = '<div class="list-group-item text-muted">Barang tidak ada stock</div>';
                            } else {
                                data.results.forEach(p => {
                                    const a = document.createElement('a');
                                    a.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                                    a.innerHTML = `<div><strong>${p.product_code}</strong> - ${p.product_name}</div><span class="badge bg-primary rounded-pill">Stok: ${p.stock}</span>`;
                                    a.addEventListener('click', (e) => { e.preventDefault(); handleManualSelection(p); });
                                    searchResultsContainer.appendChild(a);
                                });
                            }
                            searchResultsContainer.style.display = 'block';
                        });
                }, 300);
            });

            document.addEventListener('click', (e) => {
                if (!searchResultsContainer.contains(e.target) && e.target !== productInput) {
                    searchResultsContainer.style.display = 'none';
                }
            });

            // =================================================================
            // 3. SELEKSI MANUAL & MODALS
            // =================================================================
            function handleManualSelection(product) {
                searchResultsContainer.style.display = 'none';
                productInput.value = '';
                tempProductData = product;

                if (parseInt(product.has_serial) === 1) {
                    openSerialSelectionModal(product);
                } else {
                    document.getElementById('qtyModalProductName').textContent = product.product_name;
                    document.getElementById('manualQtyInput').value = 1;
                    qtyModal.show();
                }
            }

            document.getElementById('confirmQtyBtn').addEventListener('click', function() {
                const qtyInput = document.getElementById('manualQtyInput');
                const qty = parseInt(qtyInput.value);

                if (qty <= 0 || isNaN(qty)) { alert('Qty tidak valid'); return; }

                if (tempProductData) {
                    const currentInTable = transactionItems
                        .filter(i => i.product_id === tempProductData.id)
                        .reduce((sum, i) => sum + i.quantity, 0);

                    if ((currentInTable + qty) > tempProductData.stock) {
                        alert(`Stok tidak mencukupi! Tersedia: ${tempProductData.stock}`);
                        return;
                    }

                    addItemToTable(tempProductData, qty);
                    qtyModal.hide();
                    tempProductData = null;
                }
            });

            async function openSerialSelectionModal(product) {
                document.getElementById('serialModalProductName').textContent = product.product_name;
                serialSearchInput.value = '';
                serialCountBadge.textContent = '0';
                confirmSerialBtn.disabled = true;
                serialCheckboxesContainer.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Memuat...</div>';
                serialModal.show();

                const whId = warehouseSelect.value;
                try {
                    const res = await fetch(`search_serial.php?product_id=${product.id}&warehouse_id=${whId}`);
                    const data = await res.json();
                    
                    if (data.results) {
                        const inCart = transactionItems.map(i => i.serial_number);
                        availableSerialsData = data.results.filter(sn => !inCart.includes(sn.serial_number));
                        renderSerialCheckboxes(availableSerialsData);
                    } else {
                        serialCheckboxesContainer.innerHTML = '<div class="text-danger p-2">Gagal memuat serial</div>';
                    }
                } catch (e) { console.error(e); }
            }

            function renderSerialCheckboxes(serials) {
                serialCheckboxesContainer.innerHTML = '';
                if (serials.length === 0) {
                    serialCheckboxesContainer.innerHTML = '<div class="text-muted p-2 text-center">Tidak ada SN tersedia</div>';
                    return;
                }

                serials.forEach((sn, idx) => {
                    const div = document.createElement('div');
                    div.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2';
                    div.innerHTML = `<input type="checkbox" class="form-check-input serial-checkbox" value="${sn.serial_number}" id="sn-${idx}"> <label class="form-check-label w-100" for="sn-${idx}">${sn.serial_number}</label>`;
                    serialCheckboxesContainer.appendChild(div);
                });

                bindSerialCheckboxes();
            }

            function bindSerialCheckboxes() {
                 const checkboxes = document.querySelectorAll('.serial-checkbox');
                 checkboxes.forEach(cb => {
                     cb.addEventListener('change', () => {
                         const checked = document.querySelectorAll('.serial-checkbox:checked');
                         serialCountBadge.textContent = checked.length;
                         confirmSerialBtn.disabled = checked.length === 0;
                     });
                 });
            }

            serialSearchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                const filtered = availableSerialsData.filter(sn => sn.serial_number.toLowerCase().includes(term));
                renderSerialCheckboxes(filtered);
            });

            confirmSerialBtn.addEventListener('click', function() {
                const checked = document.querySelectorAll('.serial-checkbox:checked');
                checked.forEach(cb => {
                    transactionItems.push({
                        product_id: tempProductData.id,
                        product_code: tempProductData.product_code,
                        product_name: tempProductData.product_name,
                        has_serial: true,
                        quantity: 1,
                        serial_number: cb.value
                    });
                });

                renderItemsTable();
                serialModal.hide();
                tempProductData = null;
            });

            // =================================================================
            // 4. ADD & RENDER TABLE
            // =================================================================
            function addItemToTable(product, qty) {
                const existing = transactionItems.find(i => i.product_id === product.id && !i.has_serial);
                if (existing) {
                    existing.quantity += qty;
                } else {
                    transactionItems.push({
                        product_id: product.id,
                        product_code: product.product_code,
                        product_name: product.product_name,
                        has_serial: parseInt(product.has_serial) === 1,
                        quantity: qty,
                        serial_number: null
                    });
                }
                renderItemsTable();
            }

            function renderItemsTable() {
                itemsTableBody.innerHTML = '';
                transactionItems.forEach((item, index) => {
                    const qtyHtml = !item.has_serial 
                        ? `<input type="number" class="form-control form-control-sm text-center mx-auto" style="max-width: 80px;" value="${item.quantity}" min="1" onchange="updateItemQty(${index}, this.value)">`
                        : `<span class="fw-bold">${item.quantity}</span>`;

                    const row = itemsTableBody.insertRow();
                    row.innerHTML = `
                        <td class="ps-3"><div class="fw-bold">${item.product_code}</div><small class="text-muted">${item.product_name}</small></td>
                        <td class="text-center">${qtyHtml}</td>
                        <td>${item.serial_number ? `<span class="badge bg-light text-dark border px-2"><i class="bi bi-upc me-1"></i>${item.serial_number}</span>` : '<span class="text-muted">-</span>'}</td>
                        <td class="text-center">
                            <button type="button" class="btn btn-outline-danger btn-sm rounded-circle" onclick="removeItem(${index})"><i class="bi bi-trash"></i></button>
                        </td>
                    `;
                });
                itemsJsonInput.value = JSON.stringify(transactionItems);
                if (transactionItems.length > 0) warehouseSelect.disabled = true;
                else warehouseSelect.disabled = false;
            }

            // =================================================================
            // 5. LOGIKA KAMERA (FOTO BUKTI)
            // =================================================================
            const openCameraModalBtn = document.getElementById('openCameraModalBtn');
            const cameraModalEl = document.getElementById('cameraModal');
            const cameraModal = new bootstrap.Modal(cameraModalEl);
            const cameraFeed = document.getElementById('cameraFeed');
            const photoCanvas = document.getElementById('photoCanvas');
            const captureBtn = document.getElementById('capture-btn');
            const photoPreviewImg = document.getElementById('photoPreviewImg');
            const photoPreviewSection = document.getElementById('photoPreviewSection');
            const cameraPhotoData = document.getElementById('cameraPhotoData');
            const removePhotoBtn = document.getElementById('removePhotoBtn');
            const photoFileUpload = document.getElementById('photoFileUpload');

            let currentStream = null;

            async function startCamera(facingMode) {
                if (currentStream) {
                    currentStream.getTracks().forEach(track => track.stop());
                }
                try {
                    currentStream = await navigator.mediaDevices.getUserMedia({
                        video: { facingMode: facingMode }
                    });
                    cameraFeed.srcObject = currentStream;
                } catch (err) {
                    showCustomAlert("Gagal mengakses kamera. Pastikan izin kamera diberikan.");
                }
            }

            openCameraModalBtn.addEventListener('click', () => {
                cameraModal.show();
                startCamera('user');
            });

            document.getElementById('camera-front-btn').addEventListener('click', () => startCamera('user'));
            document.getElementById('camera-back-btn').addEventListener('click', () => startCamera('environment'));

            cameraModalEl.addEventListener('hidden.bs.modal', () => {
                if (currentStream) {
                    currentStream.getTracks().forEach(track => track.stop());
                }
            });

            captureBtn.addEventListener('click', () => {
                photoCanvas.width = cameraFeed.videoWidth;
                photoCanvas.height = cameraFeed.videoHeight;
                if (photoCanvas.width && photoCanvas.height) {
                    const ctx = photoCanvas.getContext('2d');
                    ctx.drawImage(cameraFeed, 0, 0, photoCanvas.width, photoCanvas.height);
                    
                    const dataURL = photoCanvas.toDataURL('image/jpeg');
                    cameraPhotoData.value = dataURL;
                    photoPreviewImg.src = dataURL;
                    photoPreviewSection.style.display = 'block';
                    photoFileUpload.value = ''; // Reset file upload
                }
                cameraModal.hide();
            });

            removePhotoBtn.addEventListener('click', () => {
                cameraPhotoData.value = '';
                photoPreviewImg.src = '';
                photoPreviewSection.style.display = 'none';
            });

            photoFileUpload.addEventListener('change', function(e) {
                 if (e.target.files && e.target.files[0]) {
                     const reader = new FileReader();
                     reader.onload = function(event) {
                         photoPreviewImg.src = event.target.result;
                         photoPreviewSection.style.display = 'block';
                         cameraPhotoData.value = ''; 
                     };
                     reader.readAsDataURL(e.target.files[0]);
                 }
            });

            window.updateItemQty = function(index, newQty) {
                const qty = parseInt(newQty);
                if (qty > 0) {
                    transactionItems[index].quantity = qty;
                    // Dont rerender just sync
                    itemsJsonInput.value = JSON.stringify(transactionItems);
                }
            };

            window.removeItem = function(index) {
                if (confirm("Hapus item ini?")) {
                    transactionItems.splice(index, 1);
                    renderItemsTable();
                }
            };

            // Tambahan: Re-enable slect agar terposting via POST
            document.getElementById('borrow-form').addEventListener('submit', function() {
                warehouseSelect.disabled = false;
            });
        });
    </script>
</body>
</html>
