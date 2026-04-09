<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
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

/**
 * Generates a unique transaction number in the format "001/SJ/GTG-JKT/IX/2025".
 * The number increments sequentially for the current month and year.
 * @param mysqli $conn The database connection.
 * @return string The new transaction number.
 */
function generateTransactionNumber($conn, $warehouse_id) {
    // Ambil kode warehouse
    $wh_query = $conn->query("SELECT code FROM warehouses WHERE id = $warehouse_id");
    $wh_row = $wh_query->fetch_assoc();
    $location_code = $wh_row['code'] ?? 'WH';

    // New static prefix as requested
    $prefix_type = "SJ/{$location_code}";
    $roman_month = convertToRoman(date('n'));
    $year = date('Y');
    // Adjust the prefix for the LIKE query to find the last transaction number
    $prefix = "%/{$prefix_type}/{$roman_month}/{$year}";

    // Query to find the last transaction number with the new format
    $sql = "SELECT transaction_number FROM outbound_transactions WHERE transaction_number LIKE ? ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $last_number = 0;

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Extract the numerical part from the start of the transaction number
        $parts = explode('/', $row['transaction_number']);
        $last_number = (int)$parts[0];
    }
    $new_number = $last_number + 1;
    $padded_number = str_pad($new_number, 3, '0', STR_PAD_LEFT);

    // Construct the new transaction number string
    return "{$padded_number}/{$prefix_type}/{$roman_month}/{$year}";
}

/**
 * Generates a unique BAST number in the format "001/BAST/GTG-JKT/IX/2025".
 * The number increments sequentially for the current month and year.
 * @param mysqli $conn The database connection.
 * @return string The new BAST number.
 */
function generateBastNumber($conn, $warehouse_id) {
    // Ambil kode warehouse
    $wh_query = $conn->query("SELECT code FROM warehouses WHERE id = $warehouse_id");
    $wh_row = $wh_query->fetch_assoc();
    $location_code = $wh_row['code'] ?? 'WH';

    // New static prefix for BAST to differentiate from Transaction Number
    $prefix_type = "BAST/{$location_code}";
    $roman_month = convertToRoman(date('n'));
    $year = date('Y');
    // Adjust the prefix for the LIKE query to find the last BAST number
    $prefix = "%/{$prefix_type}/{$roman_month}/{$year}";

    // Query to find the last BAST number
    // Uses the bast_number column which must exist (see schema note above)
    $sql = "SELECT bast_number FROM outbound_transactions WHERE bast_number LIKE ? ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $last_number = 0;

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Extract the numerical part from the start of the BAST number
        $parts = explode('/', $row['bast_number']);
        $last_number = (int)$parts[0];
    }
    $new_number = $last_number + 1;
    $padded_number = str_pad($new_number, 3, '0', STR_PAD_LEFT);

    // Construct the new BAST number string
    return "{$padded_number}/{$prefix_type}/{$roman_month}/{$year}";
}

/**
 * Saves a base64 image string to a file.
 * @param string $base64_string The base64 image data (e.g., "data:image/jpeg;base64,...").
 * @param string $upload_dir The directory to save the file (relative to this script).
 * @param string $prefix A prefix for the filename (e.g., "outbound_").
 * @return string|null The filename if successful, null otherwise.
 */
function saveBase64Image($base64_string, $upload_dir, $prefix = 'img_') {
    if (preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $base64_string, $matches)) {
        $img_type = $matches[1];
        $base64_string = substr($base64_string, strpos($base64_string, ',') + 1);
        $data = base64_decode($base64_string);

        // Ensure the upload directory exists
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0775, true); // Create recursively with appropriate permissions
        }

        $filename = $prefix . uniqid() . '.' . $img_type;
        $filepath = $upload_dir . '/' . $filename;

        if (file_put_contents($filepath, $data)) {
            return $filename;
        } else {
            error_log("Failed to save image to: " . $filepath);
        }
    } else {
        error_log("Invalid base64 image string format.");
    }
    return null;
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $recipient = trim($_POST['recipient']);
    $warehouse_id = (int)$_POST['warehouse_id'];
    $po_number = trim($_POST['po_number']); // Get PO Number
    $reference = trim($_POST['reference']); // Get Reference
    $bast_number = trim($_POST['bast_number']); // Get BAST Number (now pre-filled)
    $subject = trim($_POST['subject']);
    $transaction_date = trim($_POST['transaction_date']);
    $notes = trim($_POST['notes']);
    $photo_data_url = $_POST['transaction_photo_data'] ?? NULL;
    $items = json_decode($_POST['items_json'], true);

    if (empty($recipient) || empty($transaction_date) || empty($items) || empty($photo_data_url) || empty($warehouse_id)) {
        $message = 'Mohon lengkapi semua data transaksi dan tambahkan setidaknya satu item. Foto transaksi juga wajib disertakan.';
        $message_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            $used_serials_in_this_transaction = [];

foreach ($items as $item) {
                $product_id = (int)$item['product_id'];
                // --- SINKRONISASI KEY JS 'qty' ---
                $quantity = (int)$item['qty']; 
                // --- SINKRONISASI KEY JS 'serial' ---
                $serial_number = ($item['serial'] !== '-') ? $item['serial'] : NULL;

                $product_stock_query = $conn->prepare("SELECT ps.stock, p.has_serial FROM warehouse_stocks ps JOIN products p ON ps.product_id = p.id WHERE ps.product_id = ? AND ps.warehouse_id = ?");
                $product_stock_query->bind_param("ii", $product_id, $warehouse_id);
                $product_stock_query->execute();
                $product_data = $product_stock_query->get_result()->fetch_assoc();
                $product_stock_query->close();

                if (!$product_data) {
                     throw new Exception("Stok produk tidak ditemukan di warehouse ini.");
                }

                $product_stock = $product_data['stock'];
                $product_has_serial = (int)$product_data['has_serial'];
                
                if ($product_stock < $quantity) {
                    throw new Exception("Stok tidak mencukupi untuk produk: " . $item['product_name']);
                }

                if ($product_has_serial === 1) {
                    if ($serial_number === NULL) {
                        throw new Exception("Produk '{$item['product_name']}' wajib memiliki Serial Number.");
                    }

                    if (in_array($serial_number, $used_serials_in_this_transaction)) {
                        throw new Exception("Serial number '{$serial_number}' duplikat dalam daftar scan.");
                    }
                    $used_serials_in_this_transaction[] = $serial_number;

                    $check_serial_sql = "SELECT status FROM serial_numbers WHERE serial_number = ? AND product_id = ? AND is_deleted = 0 AND warehouse_id = ?";
                    $stmt_check_serial = $conn->prepare($check_serial_sql);
                    $stmt_check_serial->bind_param("sii", $serial_number, $product_id, $warehouse_id);
                    $stmt_check_serial->execute();
                    $serial_data = $stmt_check_serial->get_result()->fetch_assoc();
                    $stmt_check_serial->close();

                    $is_available = ($serial_data && ($serial_data['status'] === 'Tersedia' || $serial_data['status'] === 'Tersedia (Bekas)'));
                    
                    if (!$is_available) {
                        throw new Exception("Serial '{$serial_number}' tidak tersedia di sistem.");
                    }
                }
            }

            // 2. SIMPAN HEADER TRANSAKSI
            $transaction_photo_filename = saveBase64Image($photo_data_url, __DIR__ . '/../../uploads/outbound_photos', 'out_');
            
            $insert_transaction_sql = "INSERT INTO outbound_transactions (transaction_number, transaction_date, recipient, po_number, reference, bast_number, subject, notes, total_items, created_by, is_deleted, photo_url, warehouse_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_transaction = $conn->prepare($insert_transaction_sql);
            
            $transaction_number = generateTransactionNumber($conn, $warehouse_id);
            if (empty($bast_number) || $bast_number == 'Otomatis' || $bast_number == 'Otomatis saat disimpan') {
                $bast_number = generateBastNumber($conn, $warehouse_id);
            }
            $created_by = $_SESSION['user_id'];
            $total_items = count($items);
            $is_deleted = 0;

            $stmt_transaction->bind_param("ssssssssiiisi", 
                $transaction_number, $transaction_date, $recipient, $po_number, 
                $reference, $bast_number, $subject, $notes, $total_items, 
                $created_by, $is_deleted, $transaction_photo_filename, $warehouse_id
            );
            
            if (!$stmt_transaction->execute()) throw new Exception("Gagal simpan header.");
            $transaction_id = $conn->insert_id;
            $stmt_transaction->close();

            // 3. SIMPAN DETAIL & UPDATE STOK/SN
            foreach ($items as $item) {
                $product_id = (int)$item['product_id'];
                $quantity = (int)$item['qty']; // Sesuai JS
                $serial_number = ($item['serial'] !== '-') ? $item['serial'] : NULL; // Sesuai JS

                // Update Stok
                // Update Stok (Warehouse Specific)
                $conn->query("UPDATE warehouse_stocks SET stock = stock - $quantity WHERE product_id = $product_id AND warehouse_id = $warehouse_id");

                // Simpan Detail
                $insert_detail_sql = "INSERT INTO outbound_transaction_details (transaction_id, product_id, serial_number, quantity) VALUES (?, ?, ?, ?)";
                $stmt_detail = $conn->prepare($insert_detail_sql);
                $stmt_detail->bind_param("iisi", $transaction_id, $product_id, $serial_number, $quantity);
                $stmt_detail->execute();
                $stmt_detail->close();

                // Update Status Serial Number
                if ($serial_number !== NULL) {
                    $update_sn = $conn->prepare("UPDATE serial_numbers SET status = 'Keluar', last_transaction_id = ?, last_transaction_type = 'OUT', updated_at = NOW() WHERE serial_number = ? AND product_id = ? AND warehouse_id = ?");
                    $update_sn->bind_param("isii", $transaction_id, $serial_number, $product_id, $warehouse_id);
                    $update_sn->execute();
                    $update_sn->close();
                }
            }

            $conn->commit();
            header("Location: index.php?status=success_outbound");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = $e->getMessage();
            $message_type = 'danger';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <style>
        /* Gaya tambahan untuk hasil pencarian serial number */
        #serial_search_results {
            max-height: 200px; /* Batasi tinggi dropdown */
            overflow-y: auto; /* Aktifkan scroll jika terlalu banyak item */
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 0.375rem 0.375rem;
            background-color: white; /* Beri latar belakang putih */
            width: 100%; /* Lebar penuh parent */
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); /* Tambahkan bayangan */
            z-index: 1000; /* Pastikan di atas elemen lain */
            position: absolute; /* Penting agar dropdown tidak mengganggu layout */
        }
        #serial_search_results .list-group-item {
            cursor: pointer;
            display: flex;
            justify-content: space-between; /* Untuk menempatkan status di kanan */
            align-items: center;
        }
        .serial-status {
            font-size: 0.8em;
            padding: 0.2em 0.5em;
            border-radius: 0.25rem;
            color: white;
            margin-left: 10px; /* Beri sedikit jarak */
        }
        .status-tersedia {
            background-color: #28a745; /* Green */
        }
        .status-keluar {
            background-color: #ffc107; /* Yellow */
            color: #343a40;
        }
        .status-lain { /* Untuk status lain jika ada */
             background-color: #6c757d; /* Grey */
        }
        /* Pastikan parent dari serial_search_results memiliki position-relative */
        .position-relative {
            position: relative;
        }

        /* Gaya untuk modal peringatan kustom */
        .custom-alert-modal .modal-content {
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .custom-alert-modal .modal-header {
            border-bottom: none;
            padding: 1.5rem 1.5rem 0.5rem 1.5rem;
        }
        .custom-alert-modal .modal-title {
            font-weight: bold;
            color: #dc3545; /* Warna merah untuk error */
            display: flex;
            align-items: center;
        }
        .custom-alert-modal .modal-title .bi {
            font-size: 1.5em;
            margin-right: 0.5rem;
        }
        .custom-alert-modal .modal-body {
            padding: 0.5rem 1.5rem 1.5rem 1.5rem;
            text-align: center;
        }
        .custom-alert-modal .modal-footer {
            border-top: none;
            padding: 0 1.5rem 1.5rem 1.5rem;
            justify-content: center;
        }
        .custom-alert-modal .btn-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }

        /* Styles for camera modal */
        #cameraModal .modal-body video, #cameraModal .modal-body img {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
        }
    </style>
</head>
<body>

<div class="container-fluid my-4">
    <div class="content-header mb-4">
        <h1 class="fw-bold">Barang Keluar</h1>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark">Formulir Barang Keluar</h5>
            <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-2"></i>Kembali
            </a>
        </div>
        <div class="card-body">
            <?php if (isset($message) && $message): ?>
                <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form id="outbound-form" action="create.php" method="POST">
                
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label for="warehouse_id" class="form-label">Gudang Asal <span class="text-danger">*</span></label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                            <option value="">Pilih Gudang Asal</option>
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
                    <div class="col-md-6 mb-3">
                        <label for="transaction_date" class="form-label">Tanggal Transaksi</label>
                        <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="transaction_number_display" class="form-label">Nomor Transaksi</label>
                        <input type="text" class="form-control" id="transaction_number_display" value="Otomatis saat disimpan" readonly>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="recipient" class="form-label">Nama Penerima</label>
                        <input type="text" class="form-control" id="recipient" name="recipient" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="subject" class="form-label">Subjek</label>
                        <input type="text" class="form-control" id="subject" name="subject" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="po_number" class="form-label">PO Number</label>
                        <input type="text" class="form-control" id="po_number" name="po_number" placeholder="Contoh: PO/2025/001">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="reference" class="form-label">Reference (Referensi)</label>
                        <input type="text" class="form-control" id="reference" name="reference" placeholder="">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="bast_number_display" class="form-label">Nomor BAST</label>
                        <input type="text" class="form-control" id="bast_number_display" name="bast_number" value="Otomatis saat disimpan" readonly style="background-color: #e9ecef;">
                        <small class="text-muted">Nomor BAST digenerate otomatis oleh sistem.</small>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="notes" class="form-label">Catatan</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                </div>

                <hr>

                <h5 class="fw-bold mt-4">Foto Bukti Transaksi</h5>
                <div class="card p-3 mb-3 bg-light text-center border-dashed">
                    <div class="position-relative mb-3" id="transaction-photo-preview-section" style="display: none;">
                        <img id="transaction-photo-preview" src="" alt="Foto Bukti" class="img-thumbnail me-3" style="max-width: 200px;">
                        <button class="btn btn-sm btn-outline-danger" type="button" id="remove-transaction-photo-btn">Hapus Foto</button>
                        <input type="hidden" id="transaction-photo-data" name="transaction_photo_data">
                    </div>
                    <div class="d-grid gap-2 d-md-flex justify-content-center">
                        <button class="btn btn-outline-primary" type="button" id="transaction-camera-btn"><i class="bi bi-camera me-2"></i>Ambil Foto</button>
                        <button class="btn btn-outline-secondary" type="button" id="openFileModalBtn"><i class="bi bi-upload me-2"></i>Pilih File</button>
                        <input type="file" id="fileInput" accept="image/*" style="display: none;">
                    </div>
                </div>

                <hr>

                <div class="card p-3 mb-4 bg-light border-primary shadow-sm">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <h6 class="fw-bold mb-1 text-primary"><i class="bi bi-upc-scan me-2"></i>Smart Barcode Scanner Hardware</h6>
                            <p class="small text-muted mb-0">Langsung scan Serial Number atau Kode Produk. Sistem akan mendeteksi otomatis.</p>
                        </div>
                        <div class="col-md-5 text-end">
                            <div id="scan-status-loading" class="badge bg-primary p-2" style="display:none;">
                                <span class="spinner-border spinner-border-sm me-1"></span> Mencari Data...
                            </div>
                            <div id="scan-status-idle" class="badge bg-outline-secondary p-2 text-dark border">
                                <i class="bi bi-hdd-network me-1"></i> Scanner Ready
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3 position-relative">
                        <label class="form-label">Pencarian Manual</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="product_input" placeholder="Ketik nama produk untuk input manual...">
                            <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#barcodeModal">
                                <i class="bi bi-camera-fill"></i> Scan Kamera
                            </button>
                        </div>
                        <div id="product_search_results" class="list-group position-absolute w-100 shadow-sm" style="z-index: 1000; display: none;"></div>
                    </div>
                </div>

                <input type="hidden" name="items_json" id="items_json">
                
                <h5 class="fw-bold mt-4">Daftar Item Transaksi</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="items-table">
                        <thead>
                            <tr class="table-secondary text-center">
                                <th>Kode Produk</th>
                                <th>Nama Produk</th>
                                <th width="150">Kuantitas</th>
                                <th>Serial Number</th>
                                <th width="100">Aksi</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <button type="submit" class="btn btn-primary rounded-pill px-5 mt-3 shadow-sm">
                    <i class="bi bi-box-arrow-up-right me-2"></i>Simpan Transaksi Keluar
                </button>
            </form>
        </div>
    </div>
</div>

<div class="modal fade custom-alert-modal" id="customAlertModal" tabindex="-1" aria-labelledby="customAlertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customAlertModalLabel"><i class="bi bi-exclamation-circle-fill"></i> Peringatan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="customAlertMessage" class="lead"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger rounded-pill px-4" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade custom-alert-modal" id="customConfirmModal" tabindex="-1" aria-labelledby="customConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customConfirmModalLabel"><i class="bi bi-question-circle-fill text-warning"></i> Konfirmasi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="customConfirmMessage" class="lead"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger rounded-pill px-4" id="customConfirmBtn">Ya, Hapus</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="cameraModal" tabindex="-1" aria-labelledby="cameraModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"> <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cameraModalLabel">Ambil Foto Bukti</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="btn-group mb-3 w-100" role="group">
                    <button type="button" class="btn btn-primary active" id="camera-front-btn">Kamera Depan</button>
                    <button type="button" class="btn btn-info" id="camera-back-btn">Kamera Belakang</button>
                </div>
                <video id="cameraFeed" class="w-100 rounded" autoplay playsinline></video>
                <img id="photo-preview-modal" class="w-100 rounded d-none" alt="Preview Foto">
                <div id="camera-error" class="alert alert-danger mt-3 d-none"></div>
                <canvas id="photoCanvas" class="d-none"></canvas>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-primary" id="capture-btn">
                    <i class="bi bi-camera me-2"></i>Ambil Foto
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="qtyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold">Input Kuantitas</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="qtyModalProductName" class="small mb-2 text-muted"></p>
                <input type="number" id="manualQtyInput" class="form-control form-control-lg text-center" min="1" value="1">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary w-100" id="confirmQtyBtn">Tambahkan</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL PILIH SERIAL NUMBER -->
<div class="modal fade" id="serialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-primary">Pilih Serial Number</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="serialModalProductName" class="fw-bold mb-3"></p>
                
                <!-- Search Box Mini -->
                <div class="input-group mb-3">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="serialSearchInput" class="form-control" placeholder="Cari 4-5 digit terakhir SN...">
                </div>

                <!-- Daftar Checkbox -->
                <div id="serialCheckboxesContainer" class="list-group" style="max-height: 300px; overflow-y: auto;">
                    <!-- Checkboxes akan di-render di sini oleh JavaScript -->
                    <div class="text-center py-4 text-muted" id="serialLoadingSpinner">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>Mencari Serial...
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light d-flex justify-content-between">
                <div>
                    <span class="badge bg-primary rounded-pill mb-1">Dipilih: <span id="selectedSerialCount">0</span></span>
                </div>
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-success fw-bold" id="confirmSerialBtn" disabled>
                        <i class="bi bi-check2-circle me-1"></i>Tambahkan
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- ELEMEN UTAMA ---
    const itemsTableBody = document.querySelector('#items-table tbody');
    const itemsJsonInput = document.getElementById('items_json');
    const customAlertModal = new bootstrap.Modal(document.getElementById('customAlertModal'));
    const customAlertMessage = document.getElementById('customAlertMessage');
    
    // --- DATA STATE ---
    let transactionItems = [];
    let barcodeBuffer = "";
    let lastKeyTime = Date.now();

    function showCustomAlert(message) {
        customAlertMessage.textContent = message;
        customAlertModal.show();
    }

    // =================================================================
    // 1. LOGIKA SCANNER HARDWARE (PENGGANTI CHECK_BARCODE)
    // =================================================================
    document.addEventListener('keydown', function(e) {
        const activeElem = document.activeElement;
        // Jangan jalankan scanner jika user sedang mengetik di input manual
        if (['recipient', 'subject', 'notes'].includes(activeElem.id)) return;

        const currentTime = Date.now();
        if (currentTime - lastKeyTime > 50) barcodeBuffer = "";
        lastKeyTime = currentTime;

        if (e.key === 'Enter') {
            if (barcodeBuffer.length > 2) {
                e.preventDefault();
                processSmartScan(barcodeBuffer); // Memanggil backend
                barcodeBuffer = "";
            }
        } else if (e.key.length === 1) {
            barcodeBuffer += e.key;
        }
    });

    async function processSmartScan(code) {
        const loadingStatus = document.getElementById('scan-status-loading');
        const idleStatus = document.getElementById('scan-status-idle');
        loadingStatus.style.display = 'inline-block';
        idleStatus.style.display = 'none';

        const warehouseId = document.getElementById('warehouse_id').value;
        if (!warehouseId) {
            showCustomAlert("Silakan pilih Gudang Asal terlebih dahulu.");
            loadingStatus.style.display = 'none';
            idleStatus.style.display = 'inline-block';
            return;
        }

        try {
            const response = await fetch(`check_serial_availability.php?code=${encodeURIComponent(code)}&warehouse_id=${warehouseId}`);
            
            // Cek jika file PHP ditemukan
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success) {
                addItemToTable(result.data);
                // Opsional: Bunyi bip jika scan sukses
                // new Audio('beep.mp3').play(); 
            } else {
                showCustomAlert(result.message);
            }
        } catch (err) {
            console.error("Detail Error:", err);
            showCustomAlert("Gagal terhubung ke server: " + err.message);
        } finally {
            loadingStatus.style.display = 'none';
            idleStatus.style.display = 'inline-block';
        }
    }

    function addItemToTable(data) {
        if (data.type === 'serial') {
            const duplicate = transactionItems.find(i => i.serial === data.serial);
            if (duplicate) {
                showCustomAlert("Serial Number ini sudah di-scan!");
                return;
            }
            transactionItems.push({ ...data, qty: 1 });
        } else {
            // Logika Auto-Increment untuk non-serial
            const existing = transactionItems.find(i => i.product_id === data.product_id && i.type === 'non-serial');
            if (existing) {
                existing.qty += 1;
            } else {
                transactionItems.push({ ...data, qty: 1 });
            }
        }
        renderTable();
    }

    function renderTable() {
        itemsTableBody.innerHTML = '';
        transactionItems.forEach((item, index) => {
            // Jika barang non-serial, biarkan qty bisa diedit
            // Jika serial, qty dikunci di angka 1
            const qtyHtml = item.type === 'non-serial' 
                ? `<input type="number" class="form-control form-control-sm text-center mx-auto" 
                    style="max-width: 80px;" 
                    value="${item.qty}" 
                    min="1" 
                    onchange="updateItemQty(${index}, this.value)">`
                : `<span class="fw-bold">${item.qty}</span>`;

            const row = `
                <tr>
                    <td class="text-center">${item.product_code}</td>
                    <td>${item.product_name}</td>
                    <td class="text-center">${qtyHtml}</td>
                    <td class="text-center">
                        <span class="badge ${item.serial !== '-' ? 'bg-primary' : 'bg-secondary'}">
                            ${item.serial}
                        </span>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(${index})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>`;
            itemsTableBody.insertAdjacentHTML('beforeend', row);
        });
        // Update hidden input untuk dikirim ke PHP
        itemsJsonInput.value = JSON.stringify(transactionItems);
    }

    // =================================================================
    // 2. LOGIKA KAMERA (FOTO BUKTI)
    // =================================================================
    const cameraModal = new bootstrap.Modal(document.getElementById('cameraModal'));
    const cameraFeed = document.getElementById('cameraFeed');
    const photoCanvas = document.getElementById('photoCanvas');
    const transactionPhotoData = document.getElementById('transaction-photo-data');
    const transactionPhotoPreview = document.getElementById('transaction-photo-preview');
    let currentStream = null;

    document.getElementById('transaction-camera-btn').addEventListener('click', async () => {
        cameraModal.show();
        try {
            currentStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
            cameraFeed.srcObject = currentStream;
        } catch (err) {
            showCustomAlert("Akses kamera ditolak.");
        }
    });

    // Tambahkan variabel modal di bagian atas state
    const qtyModal = new bootstrap.Modal(document.getElementById('qtyModal'));
    let tempProductData = null; // Untuk menyimpan data produk sementara saat input qty

    // =================================================================
    // A. FUNGSI HAPUS FOTO
    // =================================================================
    document.getElementById('remove-transaction-photo-btn').addEventListener('click', function() {
        const previewSection = document.getElementById('transaction-photo-preview-section');
        const photoData = document.getElementById('transaction-photo-data');
        const photoPreview = document.getElementById('transaction-photo-preview');
        const fileInput = document.getElementById('fileInput');

        // Reset data
        photoData.value = "";
        photoPreview.src = "";
        fileInput.value = ""; // Bersihkan input file jika ada
        previewSection.style.display = 'none';
    });

    document.getElementById('capture-btn').addEventListener('click', () => {
        photoCanvas.width = cameraFeed.videoWidth;
        photoCanvas.height = cameraFeed.videoHeight;
        photoCanvas.getContext('2d').drawImage(cameraFeed, 0, 0);
        const dataURL = photoCanvas.toDataURL('image/jpeg');
        
        transactionPhotoData.value = dataURL;
        transactionPhotoPreview.src = dataURL;
        document.getElementById('transaction-photo-preview-section').style.display = 'block';
        cameraModal.hide();
    });

    document.getElementById('cameraModal').addEventListener('hidden.bs.modal', () => {
        if (currentStream) currentStream.getTracks().forEach(t => t.stop());
    });

    // --- File Upload ---
    document.getElementById('openFileModalBtn').addEventListener('click', () => document.getElementById('fileInput').click());
    document.getElementById('fileInput').addEventListener('change', function(e) {
        const reader = new FileReader();
        reader.onload = (event) => {
            transactionPhotoPreview.src = event.target.result;
            transactionPhotoData.value = event.target.result;
            document.getElementById('transaction-photo-preview-section').style.display = 'block';
        };
        reader.readAsDataURL(e.target.files[0]);
    });

    // =================================================================
    // B. FUNGSI HAPUS BARIS TABEL (FUNGSI GLOBAL)
    // =================================================================
    window.removeRow = function(index) {
        // Gunakan konfirmasi agar tidak sengaja terhapus
        if (confirm("Hapus item ini dari daftar?")) {
            transactionItems.splice(index, 1);
            renderTable();
        }
    };

    // --- LOGIKA PENCARIAN MANUAL ---
    let debounceTimer;
    const productInput = document.getElementById('product_input');
    const searchResultsContainer = document.getElementById('product_search_results');

    productInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = this.value.trim();

        if (query.length < 2) {
            searchResultsContainer.style.display = 'none';
            return;
        }

        debounceTimer = setTimeout(async () => {
            const warehouseId = document.getElementById('warehouse_id').value;
            if (!warehouseId) {
                showCustomAlert("Silakan pilih Gudang Asal terlebih dahulu.");
                return;
            }

            try {
                const response = await fetch(`search_products.php?q=${encodeURIComponent(query)}&warehouse_id=${warehouseId}`);
                const products = await response.json();

                if (products.length > 0) {
                    renderSearchResults(products);
                } else {
                    searchResultsContainer.style.display = 'none';
                }
            } catch (err) {
                console.error("Gagal mencari produk manual");
            }
        }, 300); // Tunggu 300ms setelah user berhenti mengetik
    });

    function renderSearchResults(products) {
        searchResultsContainer.innerHTML = '';
        products.forEach(p => {
            const item = document.createElement('a');
            item.href = '#';
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            
            // Berikan label jika barang wajib SN
            const badge = p.has_serial == 1 
                ? '<span class="badge bg-warning text-dark">Wajib SN</span>' 
                : `<span class="badge bg-info">Stok: ${p.stock}</span>`;
            
            item.innerHTML = `
                <div>
                    <div class="fw-bold">${p.product_name}</div>
                    <small class="text-muted">${p.product_code}</small>
                </div>
                ${badge}
            `;

            item.addEventListener('click', (e) => {
                e.preventDefault();
                handleManualSelection(p);
            });
            searchResultsContainer.appendChild(item);
        });
        searchResultsContainer.style.display = 'block';
    }

    function handleManualSelection(product) {
        searchResultsContainer.style.display = 'none';
        productInput.value = '';

        if (product.has_serial == 1) {
            // PRODUK SERIAL: Tampilkan Pop-up Multi-Checkbox Serial
            tempProductData = product;
            openSerialSelectionModal(product);
        } else {
            // PRODUK NON-SERIAL: Tampilkan Pop-up Angka Kuatitas
            tempProductData = product;
            document.getElementById('qtyModalProductName').textContent = product.product_name;
            document.getElementById('manualQtyInput').value = 1;
            qtyModal.show();
            setTimeout(() => document.getElementById('manualQtyInput').focus(), 500);
        }
    }

    // Handler tombol konfirmasi di modal kuantitas
    document.getElementById('confirmQtyBtn').addEventListener('click', function() {
        const qtyInput = document.getElementById('manualQtyInput');
        const numQty = parseInt(qtyInput.value);

        if (isNaN(numQty) || numQty <= 0) {
            alert("Masukkan jumlah yang valid (minimal 1).");
            return;
        }

        if (tempProductData) {
            const dataForTable = {
                type: 'non-serial',
                product_id: tempProductData.id,
                product_code: tempProductData.product_code,
                product_name: tempProductData.product_name,
                serial: '-',
                qty: numQty
            };
            addItemToTable(dataForTable);
            qtyModal.hide();
            tempProductData = null; // Reset data sementara
        }
    });

    // =================================================================
    // LOGIKA MODAL PILIH SERIAL (MULTI-CHECKBOX)
    // =================================================================
    const serialModal = new bootstrap.Modal(document.getElementById('serialModal'));
    const serialContainer = document.getElementById('serialCheckboxesContainer');
    const serialSearchBox = document.getElementById('serialSearchInput');
    const serialCountBadge = document.getElementById('selectedSerialCount');
    const btnConfirmSerial = document.getElementById('confirmSerialBtn');
    let availableSerialsData = [];

    async function openSerialSelectionModal(product) {
        document.getElementById('serialModalProductName').textContent = product.product_name;
        serialSearchBox.value = '';
        serialCountBadge.textContent = '0';
        btnConfirmSerial.disabled = true;
        serialContainer.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Memuat Serial...</div>';
        serialModal.show();

        const warehouseId = document.getElementById('warehouse_id').value;

        try {
            const response = await fetch(`get_available_serials.php?product_id=${product.id}&warehouse_id=${warehouseId}`);
            availableSerialsData = await response.json();
            
            // Filter out serials that are already IN THE CART
            const insideCartSerials = transactionItems.map(item => item.serial);
            availableSerialsData = availableSerialsData.filter(sn => !insideCartSerials.includes(sn.serial_number));

            renderSerialCheckboxes(availableSerialsData);
        } catch (err) {
            serialContainer.innerHTML = '<div class="alert alert-danger m-3">Gagal memuat daftar serial.</div>';
        }
    }

    function renderSerialCheckboxes(serialsList) {
        serialContainer.innerHTML = '';
        
        if (serialsList.length === 0) {
            serialContainer.innerHTML = '<div class="text-center py-4 text-danger"><i class="bi bi-exclamation-triangle display-4 d-block mb-2"></i>Tidak ada Serial Number tersedia di Gudang ini.</div>';
            return;
        }

        serialsList.forEach((sn, index) => {
            const label = document.createElement('label');
            label.className = 'list-group-item list-group-item-action d-flex align-items-center cursor-pointer';
            
            const checkbox = document.createElement('input');
            checkbox.className = 'form-check-input me-3 sn-checkbox';
            checkbox.type = 'checkbox';
            checkbox.value = sn.serial_number;
            // Prevent toggling if max stock rule applies elsewhere, but here it's 1-to-1 so it's fine
            checkbox.addEventListener('change', updateSerialSelectionCount);

            const divContent = document.createElement('div');
            divContent.innerHTML = `<span class="fw-bold fs-6">${sn.serial_number}</span><br><small class="text-muted">Status: ${sn.status}</small>`;

            label.appendChild(checkbox);
            label.appendChild(divContent);
            serialContainer.appendChild(label);
        });
    }

    serialSearchBox.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const filtered = availableSerialsData.filter(sn => sn.serial_number.toLowerCase().includes(query));
        renderSerialCheckboxes(filtered);
    });

    function updateSerialSelectionCount() {
        const checkedBoxes = document.querySelectorAll('.sn-checkbox:checked');
        serialCountBadge.textContent = checkedBoxes.length;
        btnConfirmSerial.disabled = checkedBoxes.length === 0;
        
        // Aesthetic: Highlight chosen rows
        document.querySelectorAll('.sn-checkbox').forEach(cb => {
            if (cb.checked) {
                cb.closest('label').classList.add('list-group-item-primary');
            } else {
                cb.closest('label').classList.remove('list-group-item-primary');
            }
        });
    }

    btnConfirmSerial.addEventListener('click', function() {
        const checkedBoxes = document.querySelectorAll('.sn-checkbox:checked');
        
        if (checkedBoxes.length > 0 && tempProductData) {
            checkedBoxes.forEach(checkbox => {
                const dataForTable = {
                    type: 'serial',
                    product_id: tempProductData.id,
                    product_code: tempProductData.product_code,
                    product_name: tempProductData.product_name,
                    serial: checkbox.value,
                    qty: 1
                };
                // We use push directly since addItemToTable does complex things.
                const exists = transactionItems.find(i => i.serial === checkbox.value);
                if (!exists) {
                     transactionItems.push(dataForTable);
                }
            });
            renderTable();
            serialModal.hide();
            tempProductData = null;
        }
    });

    // Menutup hasil pencarian jika klik di luar
    document.addEventListener('click', function(e) {
        if (!productInput.contains(e.target) && !searchResultsContainer.contains(e.target)) {
            searchResultsContainer.style.display = 'none';
        }
    });
});
</script>
<?php include '../../includes/footer.php'; ?>