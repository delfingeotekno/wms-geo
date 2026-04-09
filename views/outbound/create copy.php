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
function generateTransactionNumber($conn) {
    // New static prefix as requested
    $prefix_type = 'SJ/GTG-JKT';
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
function generateBastNumber($conn) {
    // New static prefix for BAST to differentiate from Transaction Number
    $prefix_type = 'BAST/GTG-JKT';
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

// Generate the unique numbers before processing the form
$current_transaction_number_out = generateTransactionNumber($conn);
$current_bast_number = generateBastNumber($conn); // NEW: Generate BAST Number

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $recipient = trim($_POST['recipient']);
    $po_number = trim($_POST['po_number']); // Get PO Number
    $reference = trim($_POST['reference']); // Get Reference
    $bast_number = trim($_POST['bast_number']); // Get BAST Number (now pre-filled)
    $subject = trim($_POST['subject']);
    $transaction_date = trim($_POST['transaction_date']);
    $notes = trim($_POST['notes']);
    $photo_data_url = $_POST['transaction_photo_data'] ?? NULL;
    $items = json_decode($_POST['items_json'], true);

    if (empty($recipient) || empty($transaction_date) || empty($items) || empty($photo_data_url)) {
        $message = 'Mohon lengkapi semua data transaksi dan tambahkan setidaknya satu item. Foto transaksi juga wajib disertakan.';
        $message_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            $used_serials_in_this_transaction = [];

            foreach ($items as $item) {
                $product_id = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                $serial_number = $item['serial_number'] ?? NULL;

                $product_stock_query = $conn->prepare("SELECT stock, has_serial FROM products WHERE id = ?");
                $product_stock_query->bind_param("i", $product_id);
                $product_stock_query->execute();
                $product_data = $product_stock_query->get_result()->fetch_assoc();
                $product_stock_query->close();

                $product_stock = $product_data['stock'];
                $product_has_serial = (int)$product_data['has_serial'];
                
                if ($product_stock < $quantity) {
                    throw new Exception("Stok tidak mencukupi untuk produk ID " . $product_id . ".");
                }

                if ($product_has_serial === 1 && $serial_number !== NULL) {
                    if (in_array($serial_number, $used_serials_in_this_transaction)) {
                        throw new Exception("Serial number '{$serial_number}' duplikat dalam transaksi ini.");
                    }
                    $used_serials_in_this_transaction[] = $serial_number;

                    $check_serial_sql = "SELECT id, status FROM serial_numbers WHERE serial_number = ? AND product_id = ?";
                    $stmt_check_serial = $conn->prepare($check_serial_sql);
                    $stmt_check_serial->bind_param("si", $serial_number, $product_id);
                    $stmt_check_serial->execute();
                    $serial_data = $stmt_check_serial->get_result()->fetch_assoc();
                    $stmt_check_serial->close();

                    // 💥 PERBAIKAN KRITIS DI SINI (Logical OR vs Logical AND)
                    // Original: if (!$serial_data || $serial_data['status'] !== 'Tersedia' || $serial_data['status'] !== 'Tersedia (Bekas)') {
                    // Masalah: Kondisi ini selalu TRUE. SN tidak mungkin BUKAN 'Tersedia' DAN BUKAN 'Tersedia (Bekas)' secara bersamaan.
                    // Contoh: Jika statusnya 'Tersedia', maka $serial_data['status'] !== 'Tersedia' adalah FALSE, tetapi $serial_data['status'] !== 'Tersedia (Bekas)' adalah TRUE. Logika OR menyebabkan seluruh kondisi menjadi TRUE dan throw error.

                    $is_available_status = ($serial_data['status'] === 'Tersedia' || $serial_data['status'] === 'Tersedia (Bekas)');
                    
                    if (!$serial_data || !$is_available_status) {
                         throw new Exception("Serial number '{$serial_number}' untuk produk ID " . $product_id . " tidak tersedia atau tidak ada.");
                    }
                } elseif ($product_has_serial === 1 && $serial_number === NULL) {
                    throw new Exception("Produk ini membutuhkan serial number, tetapi tidak ada yang diberikan.");
                }
            }

            // --- LOGIKA PENYIMPANAN FOTO TRANSAKSI UTAMA ---
            $transaction_photo_filename = null;
            if ($photo_data_url) {
                $upload_dir = __DIR__ . '/../../uploads/outbound_photos';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $transaction_photo_filename = saveBase64Image($photo_data_url, $upload_dir, 'outbound_trans_');
                if (!$transaction_photo_filename) {
                    throw new Exception("Gagal menyimpan foto bukti transaksi.");
                }
            }
            // --- END LOGIKA PENYIMPANAN FOTO ---

            // Use the generated transaction number
            $transaction_number = $current_transaction_number_out;
            
            // Check if BAST number was overridden, if not, use the generated one
            // NOTE: Since the input is read-only now, $bast_number from POST should be the generated one.
            if (empty($bast_number)) {
                $bast_number = $current_bast_number;
            }
            
            $created_by = $_SESSION['user_id'];
            $total_items = count($items);
            $is_deleted = 0;

            // UPDATED: Added bast_number to the INSERT statement (6th column) - 12 placeholders
            $insert_transaction_sql = "INSERT INTO outbound_transactions (transaction_number, transaction_date, recipient, po_number, reference, bast_number, subject, notes, total_items, created_by, is_deleted, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_transaction = $conn->prepare($insert_transaction_sql);
            
            // UPDATED: Corrected type string to 'ssssssssiiis' (12 characters for 12 variables)
            $stmt_transaction->bind_param("ssssssssiiis", 
                $transaction_number, 
                $transaction_date, 
                $recipient, 
                $po_number, 
                $reference, 
                $bast_number, 
                $subject, 
                $notes, 
                $total_items, 
                $created_by, 
                $is_deleted, 
                $transaction_photo_filename
            ); // <-- Line 232 FIX APPLIED
            
            if (!$stmt_transaction->execute()) {
                throw new Exception("Gagal menyimpan header transaksi.");
            }
            $transaction_id = $conn->insert_id;
            $stmt_transaction->close();

            foreach ($items as $item) {
                $product_id = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                $serial_number = $item['serial_number'] ?? NULL;
                
                $update_product_sql = "UPDATE products SET stock = stock - ?, updated_at = NOW() WHERE id = ?";
                $stmt_product = $conn->prepare($update_product_sql);
                $stmt_product->bind_param("ii", $quantity, $product_id);
                if (!$stmt_product->execute()) {
                    throw new Exception("Gagal memperbarui stok produk.");
                }
                $stmt_product->close();

                $insert_detail_sql = "INSERT INTO outbound_transaction_details (transaction_id, product_id, serial_number, quantity) VALUES (?, ?, ?, ?)";
                $stmt_detail = $conn->prepare($insert_detail_sql);
                $stmt_detail->bind_param("iisi", $transaction_id, $product_id, $serial_number, $quantity);
                if (!$stmt_detail->execute()) {
                    throw new Exception("Gagal menyimpan detail transaksi.");
                }
                $stmt_detail->close();

                if ($serial_number !== NULL) {
                    $update_serial_status_sql = "UPDATE serial_numbers SET status = 'Keluar', updated_at = NOW() WHERE serial_number = ? AND product_id = ?";
                    $stmt_update_serial = $conn->prepare($update_serial_status_sql);
                    $stmt_update_serial->bind_param("si", $serial_number, $product_id);
                    if (!$stmt_update_serial->execute()) {
                        throw new Exception("Gagal memperbarui status serial number.");
                    }
                    $stmt_update_serial->close();
                }
            }

            $conn->commit();
            header("Location: index.php?status=success_outbound");
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
            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form id="outbound-form" action="create.php" method="POST">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="transaction_date" class="form-label">Tanggal Transaksi</label>
                        <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="transaction_number_display" class="form-label">Nomor Transaksi</label>
                        <input type="text" class="form-control" id="transaction_number_display" value="<?= $current_transaction_number_out ?>" readonly>
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
                        <input type="text" class="form-control" id="po_number" name="po_number">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="reference" class="form-label">Reference</label>
                        <input type="text" class="form-control" id="reference" name="reference">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="bast_number" class="form-label">Nomor BAST</label>
                        <input type="text" class="form-control" id="bast_number" name="bast_number" value="<?= $current_bast_number ?>" readonly>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="notes" class="form-label">Catatan</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                </div>
                
                <hr>

                <h5 class="fw-bold mt-4">Foto Bukti Transaksi</h5>
                <div class="card p-3 mb-3 bg-light text-center">
                    <div class="position-relative mb-3" id="transaction-photo-preview-section" style="display: none;">
                        <label class="form-label">Pratinjau Foto</label>
                        <div class="d-flex align-items-center">
                            <img id="transaction-photo-preview" src="" alt="Foto Bukti Transaksi" class="img-thumbnail me-3" style="max-width: 200px; max-height: 200px; display: none;">
                            <button class="btn btn-sm btn-outline-danger" type="button" id="remove-transaction-photo-btn">Hapus Foto</button>
                        </div>
                        <input type="hidden" id="transaction-photo-data" name="transaction_photo_data">
                    </div>
                    <div class="d-grid gap-2 d-md-flex justify-content-center">
                        <button class="btn btn-outline-primary" type="button" id="transaction-camera-btn">
                            <i class="bi bi-camera me-2"></i>Ambil Foto
                        </button>
                        <button class="btn btn-outline-secondary" type="button" id="openFileModalBtn">
                            <i class="bi bi-upload me-2"></i>Pilih Foto
                        </button>
                        <input type="file" id="fileInput" accept="image/*" style="display: none;">
                    </div>
                </div>
                <hr>
                <h5 class="fw-bold mt-4">Detail Produk</h5>
                <div class="card p-3 mb-4 bg-light">
                    <div class="mb-3 d-flex justify-content-center">
                        <button type="button" class="btn btn-primary me-2" id="btn-mode-serial">
                            <i class="bi bi-tag me-2"></i>Barang Serial
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="btn-mode-non-serial">
                            <i class="bi bi-boxes me-2"></i>Barang Non Serial
                        </button>
                    </div>
                    <input type="hidden" id="transaction_mode" value="serial">           
                    
                    <div class="row">
                        <div class="col-md-6 mb-3 position-relative">
                            <label for="product_input" class="form-label">Cari Produk</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="product_input" placeholder="Ketik kode atau nama produk / Scan barcode" autocomplete="off">
                                <button class="btn btn-outline-secondary" type="button" id="scanProductBarcodeBtn" data-bs-toggle="modal" data-bs-target="#barcodeModal" data-target-input="product_input">
                                    <i class="bi bi-upc-scan"></i> Scan
                                </button>
                            </div>
                            <input type="hidden" id="selected_product_id">
                            <div id="product_search_results" class="list-group position-absolute w-100 shadow-sm" style="z-index: 1000; display: none;"></div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="item_quantity" class="form-label">Kuantitas</label>
                            <input type="number" class="form-control" id="item_quantity" min="1" disabled>
                        </div>
                    </div>
                    
                    <div class="row" id="serial-section" style="display: block;">
                        <div class="col-md-12 mb-3 position-relative">
                            <label for="item_serial_number" class="form-label">Serial Number</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="item_serial_number" placeholder="Scan atau ketik serial number">
                                <button class="btn btn-outline-secondary" type="button" id="scanBarcodeBtn" data-bs-toggle="modal" data-bs-target="#barcodeModal" data-target-input="item_serial_number">
                                    <i class="bi bi-upc-scan"></i> Scan
                                </button>
                            </div>
                            <small class="text-muted" id="serial-info">Serial number harus diisi dan tersedia (Baru/Bekas).</small>
                            <div id="serial_search_results" class="list-group position-absolute w-100 shadow-sm" style="z-index: 1000; display: none;"></div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end mt-2">
                        <button type="button" class="btn btn-info rounded-pill px-4" id="add-item-btn" disabled>
                            <i class="bi bi-plus me-2"></i>Tambahkan Item
                        </button>
                    </div>
                </div>
                <input type="hidden" name="items_json" id="items_json">
                
                <h5 class="fw-bold mt-4">Daftar Item Transaksi</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="items-table">
                        <thead>
                            <tr class="table-secondary">
                                <th>Kode Produk</th>
                                <th>Nama Produk</th>
                                <th>Kuantitas</th>
                                <th>Serial Number</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary rounded-pill px-4 mt-3">
                    <i class="bi bi-box-arrow-up-right me-2"></i>Simpan Transaksi
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

<div class="modal fade" id="barcodeModal" tabindex="-1" aria-labelledby="barcodeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="barcodeModalLabel">Scan Barcode</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div id="barcode-scanner-container" style="width: 100%; height: 300px; background: #000; overflow: hidden; margin: 0 auto;">
                    <video id="barcodeFeed" style="width: 100%; height: 100%;"></video>
                </div>
                <p id="scanInfo" class="mt-2 text-primary">Arahkan kamera ke Barcode Produk atau Serial Number.</p>
                <div id="barcodeError" class="alert alert-danger mt-3 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
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

<script src="https://unpkg.com/quagga@0.12.1/dist/quagga.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- ELEMEN UTAMA FORM DAN PRODUK ---
    const productInput = document.getElementById('product_input');
    const searchResultsContainer = document.getElementById('product_search_results');
    const itemQuantity = document.getElementById('item_quantity');
    const itemSerialNumber = document.getElementById('item_serial_number');
    const serialSection = document.getElementById('serial-section');
    const serialInfo = document.getElementById('serial-info');
    const addItemBtn = document.getElementById('add-item-btn');
    const itemsTableBody = document.querySelector('#items-table tbody');
    const itemsJsonInput = document.getElementById('items_json');
    const selectedProductIdInput = document.getElementById('selected_product_id');

    // --- MODE ELEMENTS (TAMBAHAN PENTING) ---
    const btnModeSerial = document.getElementById('btn-mode-serial');
    const btnModeNonSerial = document.getElementById('btn-mode-non-serial');
    // Anda harus memiliki hidden input ini di HTML Anda: <input type="hidden" id="transaction_mode" value="serial">
    const transactionMode = document.getElementById('transaction_mode'); 

    const serialSearchResultsContainer = document.getElementById('serial_search_results');
    const customAlertModal = new bootstrap.Modal(document.getElementById('customAlertModal'));
    const customAlertMessage = document.getElementById('customAlertMessage');

    // --- VARIABEL DATA ---
    let transactionItems = [];
    let selectedProduct = null;
    let productsStock = {};
    let availableSerialsCache = {}; 
    
    // --- ELEMEN KAMERA UTAMA (FOTO BUKTI TRANSAKSI) ---
    const transactionCameraBtn = document.getElementById('transaction-camera-btn');
    const transactionPhotoPreviewSection = document.getElementById('transaction-photo-preview-section');
    const transactionPhotoPreview = document.getElementById('transaction-photo-preview');
    const transactionPhotoData = document.getElementById('transaction-photo-data'); 
    const removeTransactionPhotoBtn = document.getElementById('remove-transaction-photo-btn');

    const cameraModalElement = document.getElementById('cameraModal');
    const cameraModal = new bootstrap.Modal(cameraModalElement);
    const cameraFrontBtn = document.getElementById('camera-front-btn');
    const cameraBackBtn = document.getElementById('camera-back-btn');
    const cameraFeed = document.getElementById('cameraFeed'); // <video> element
    const captureBtn = document.getElementById('capture-btn');
    const photoPreviewModal = document.getElementById('photo-preview-modal'); // <img> element
    const photoCanvas = document.getElementById('photoCanvas'); // <canvas> element
    const cameraError = document.getElementById('camera-error');
    
    let currentStream = null; 
    let capturedPhotoDataURL = null; 
    
    // --- ELEMEN UPLOAD FILE ---
    const openFileModalBtn = document.getElementById('openFileModalBtn');
    const fileInput = document.getElementById('fileInput');
    
    // --- ELEMEN BARCODE SCANNING ---
    const barcodeScannerModalElement = document.getElementById('barcodeModal'); // Modal element
    const barcodeScannerModal = new bootstrap.Modal(barcodeScannerModalElement); // Modal instance
    const scanBarcodeBtn = document.getElementById('scanBarcodeBtn'); // SN Scan Button
    // Asumsi Anda memiliki tombol Scan Produk: <button id="scanProductBarcodeBtn" ...>
    const scanProductBarcodeBtn = document.getElementById('scanProductBarcodeBtn'); 
    const barcodeVideoFeed = document.getElementById('barcodeFeed'); // <video> element
    const barcodeResultDisplay = document.getElementById('scanInfo'); // Use scanInfo for messages
    const barcodeError = document.getElementById('barcodeError'); // Use existing error display

    let scannerStream = null; // Stream kamera untuk barcode
    let scanningInterval = null; // Interval untuk deteksi barcode
    let targetInputField = null; // Untuk melacak input mana yang akan diisi hasil scan


    function showCustomAlert(message) {
        customAlertMessage.textContent = message;
        customAlertModal.show();
    }

    // =================================================================
    // FUNGSI KAMERA (FOTO BUKTI TRANSAKSI)
    // =================================================================
    async function startCamera(facingMode = 'user') {
        try {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
            }
            
            const constraints = { 
                video: { 
                    facingMode: facingMode === 'environment' ? { exact: 'environment' } : 'user' 
                } 
            };
            const stream = await navigator.mediaDevices.getUserMedia(constraints);
            currentStream = stream;
            cameraFeed.srcObject = stream;
            
            cameraFeed.style.display = 'block';
            photoPreviewModal.style.display = 'none';
            cameraError.style.display = 'none';
            
            cameraFrontBtn.classList.toggle('active', facingMode === 'user');
            cameraBackBtn.classList.toggle('active', facingMode === 'environment');

        } catch (err) {
            console.error("Gagal mengakses kamera: ", err);
            cameraError.textContent = 'Gagal mengakses kamera. Pastikan izin kamera telah diberikan.';
            cameraError.style.display = 'block';
            cameraFeed.style.display = 'none';
            photoPreviewModal.style.display = 'none';
        }
    }

    function capturePhoto() {
        if (!currentStream) return;

        photoCanvas.width = cameraFeed.videoWidth;
        photoCanvas.height = cameraFeed.videoHeight;
        
        const context = photoCanvas.getContext('2d');
        context.drawImage(cameraFeed, 0, 0, photoCanvas.width, photoCanvas.height);
        
        capturedPhotoDataURL = photoCanvas.toDataURL('image/jpeg', 0.8); 
        
        photoPreviewModal.src = capturedPhotoDataURL;
        photoPreviewModal.style.display = 'block';
        cameraFeed.style.display = 'none';
    }
    
// =================================================================
// FUNGSI MODE TRANSAKSI (SERIAL vs NON-SERIAL)
// =================================================================
/**
 * Mengatur tampilan form berdasarkan mode transaksi (serial/non-serial).
 * @param {string} mode - 'serial' atau 'non-serial'
 */
function updateProductModeDisplay(mode) {
    // Simpan mode ke hidden input
    if (transactionMode) {
        transactionMode.value = mode;
    }
    
    // Reset input item
    resetItemInputFields(true); 
    
    // Mengatur tampilan tombol mode
    if (mode === 'serial') {
        btnModeSerial.classList.replace('btn-outline-primary', 'btn-primary');
        btnModeNonSerial.classList.replace('btn-primary', 'btn-outline-primary');
        
        // Mode Serial: Fokus pada SN. Pencarian Produk di-disable.
        productInput.placeholder = "Scan serial number untuk mengisi produk";
        productInput.disabled = true; 
        
        itemQuantity.value = 1;
        itemQuantity.readOnly = true;
        itemQuantity.disabled = true; 

        serialSection.style.display = 'block';
        itemSerialNumber.disabled = false;
        serialInfo.textContent = 'Scan/ketik Serial Number. Produk akan terisi otomatis.';
        
        itemSerialNumber.focus();
    } else { // mode === 'non-serial'
        btnModeNonSerial.classList.replace('btn-outline-primary', 'btn-primary');
        btnModeSerial.classList.replace('btn-primary', 'btn-outline-primary');

        // Mode Non-Serial: Fokus pada Produk.
        productInput.placeholder = "Ketik kode atau nama produk / Scan barcode";
        productInput.disabled = false;
        
        serialSection.style.display = 'none';
        itemSerialNumber.disabled = true;

        // >>> PERBAIKAN DI SINI: Mengaktifkan kuantitas untuk produk non-serial default <<<
        itemQuantity.disabled = false;
        itemQuantity.readOnly = false;
        itemQuantity.value = ''; // Pastikan kosong agar user bisa mengisi dari awal
        // >>> AKHIR PERBAIKAN <<<
        
        productInput.focus();
    }
    
    checkAddItemButtonStatus();
}

/**
 * Membersihkan input item tanpa mereset mode atau foto.
 * (Tidak perlu diubah)
 */
function resetItemInputFields(clearProductToo = true) {
    if (clearProductToo) {
        selectedProduct = null;
        selectedProductIdInput.value = '';
        productInput.value = '';
    }
    
    itemQuantity.value = '';
    itemQuantity.disabled = true; // Tetap disabled sebagai default reset
    itemSerialNumber.value = '';
    itemSerialNumber.disabled = true;
    serialSection.style.display = 'none';
    addItemBtn.disabled = true;

    serialSearchResultsContainer.style.display = 'none';
    searchResultsContainer.style.display = 'none';
    availableSerialsCache = {};

    // Pastikan status disabled productInput dikembalikan sesuai mode yang berlaku
    if (transactionMode && transactionMode.value === 'serial') {
        productInput.disabled = true;
        itemSerialNumber.focus();
    } else {
        productInput.disabled = false;
        productInput.focus();
    }
}


// =================================================================
// FUNGSI FORMULIR (PENCARIAN, VALIDASI, TAMBAH ITEM)
// =================================================================

/**
 * Mencari produk berdasarkan input
 * @param {boolean} isScan - True jika dipicu oleh scan barcode
 */
function searchProducts(isScan = false) { 
    const searchTerm = productInput.value.trim();
    const mode = transactionMode.value;

    // MODE SERIAL: Jika input bukan dari scan, abaikan. Jika scan, kirim ke SN.
    if (mode === 'serial') {
        if (isScan) {
            // Dipicu oleh scan produk di mode serial -> asumsikan SN
            itemSerialNumber.value = searchTerm;
            itemSerialNumber.focus();
            // Panggil event input SN untuk memicu pencarian serial
            // Kita tidak memanggil searchSerials() di sini, karena logika ADD OTOMATIS ada di scan listener
            itemSerialNumber.dispatchEvent(new Event('input')); 
        }
        searchResultsContainer.style.display = 'none';
        return;
    }

    // MODE NON-SERIAL: Lanjutkan pencarian produk
    if (searchTerm.length < 2 && !isScan) {
        searchResultsContainer.style.display = 'none';
        return;
    }
    
    // Pencegahan pencarian jika mode non-serial tetapi produk sudah dipilih
    if (selectedProduct && selectedProduct.id === selectedProductIdInput.value && !isScan) {
        searchResultsContainer.style.display = 'none';
        return;
    }

    fetch(`search_products.php?term=${searchTerm}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            // Langsung pilih jika hasil scan dan hanya ada 1 hasil
            if (isScan && data.results.length === 1) {
                selectedProduct = data.results[0];
                // Tambahkan stok ke cache
                productsStock[selectedProduct.id] = parseInt(selectedProduct.stock); 
                updateFormFields();
                // Jika produk non-serial dan di-scan, langsung fokus ke kuantitas
                if (parseInt(selectedProduct.has_serial) !== 1) {
                    itemQuantity.focus();
                } else {
                    itemSerialNumber.focus();
                }
            } else if (isScan && data.results.length === 0) {
                showCustomAlert('Barcode produk tidak ditemukan.');
                productInput.value = '';
            } else {
                displaySearchResults(data.results);
            }
        })
        .catch(error => {
            console.error('Error fetching products:', error);
            searchResultsContainer.innerHTML = `<div class="list-group-item text-danger">Gagal memuat pencarian produk.</div>`;
            searchResultsContainer.style.display = 'block';
        });
}

function displaySearchResults(results) {
    searchResultsContainer.innerHTML = '';
    if (results.length === 0) {
        searchResultsContainer.style.display = 'none';
        return;
    }
    
    results.forEach(product => {
        const resultItem = document.createElement('a');
        resultItem.href = '#';
        resultItem.classList.add('list-group-item', 'list-group-item-action');
        // Pastikan properti stock dan unit ada di objek produk Anda
        const serialBadge = parseInt(product.has_serial) === 1 ? '<span class="badge bg-danger ms-1">SERIAL</span>' : '';
        resultItem.innerHTML = `<strong>${product.product_code}</strong> - ${product.product_name} (Stok: ${product.stock} ${product.unit})${serialBadge}`;
        
        resultItem.addEventListener('click', function(e) {
            e.preventDefault();
            selectedProduct = product;
            productsStock[product.id] = parseInt(product.stock); 
            
            updateFormFields();
        });

        searchResultsContainer.appendChild(resultItem);
    });
    searchResultsContainer.style.display = 'block';
}

/**
 * Memperbarui field form setelah produk dipilih.
 */
function updateFormFields() {
    if (!selectedProduct) {
        resetItemInputFields(true);
        return;
    }
    
    const mode = transactionMode.value;
    const hasSerial = parseInt(selectedProduct.has_serial) === 1;

    selectedProductIdInput.value = selectedProduct.id;
    productInput.value = `${selectedProduct.product_code} - ${selectedProduct.product_name}`;
    
    // Mode Serial (SN yang mengisi ini)
    if (mode === 'serial') {
        if (!hasSerial) {
            showCustomAlert('Produk ini (Non Serial) tidak valid untuk Mode Barang Serial.');
            resetItemInputFields(true);
            updateProductModeDisplay('serial');
            return;
        }
        // Di mode serial, SN sudah diisi di searchSerials() sebelum ini dipanggil
        itemQuantity.value = 1;
        itemQuantity.disabled = true;
        itemSerialNumber.disabled = false;
        serialSection.style.display = 'block';
        serialInfo.textContent = `Serial Number untuk ${selectedProduct.product_code} (${selectedProduct.product_name}).`;
        // Jangan fokus ke SN di sini, karena ini mungkin dipanggil dari SN itu sendiri
    } 
    // Mode Non-Serial
    else {
        if (hasSerial) {
            // Produk Serial di Mode Non-Serial
            itemQuantity.value = 1;
            itemQuantity.disabled = true;
            itemSerialNumber.disabled = false;
            itemSerialNumber.value = ''; 
            serialSection.style.display = 'block';
            serialInfo.textContent = 'Serial number wajib diisi (Kuantitas otomatis 1).';
            itemSerialNumber.focus();
        } else {
            // Produk Non-Serial di Mode Non-Serial
            itemQuantity.value = '';
            itemQuantity.disabled = false;
            itemSerialNumber.disabled = true;
            itemSerialNumber.value = '';
            serialSection.style.display = 'none';
            serialInfo.textContent = '';
            itemQuantity.focus();
        }
    }
    
    checkAddItemButtonStatus();
    serialSearchResultsContainer.style.display = 'none';
    searchResultsContainer.style.display = 'none';
    availableSerialsCache = {};
}

function checkAddItemButtonStatus() {
    if (!selectedProduct) {
        addItemBtn.disabled = true;
        return;
    }
    const hasSerial = parseInt(selectedProduct.has_serial) === 1;
    if (hasSerial) {
        // Serial: SN harus terisi
        addItemBtn.disabled = itemSerialNumber.value.trim() === '';
    } else {
        // Non-Serial: Qty harus valid
        addItemBtn.disabled = isNaN(parseInt(itemQuantity.value)) || parseInt(itemQuantity.value) <= 0;
    }
}

let serialSearchTimeout;
/**
 * Mencari serial number dan, jika dalam mode serial dan dipicu oleh scan, 
 * secara otomatis mencoba menambahkan item.
 * @param {boolean} autoAdd - Jika true, akan memanggil addItemToTable setelah sukses.
 */
function searchSerials(autoAdd = false) { 
  // 1. MEMBATALKAN pencarian yang mungkin tertunda sebelumnya.
    // Jika user mengetik lagi, timer yang lama dibatalkan.
  clearTimeout(serialSearchTimeout);
  
    // 2. MENJADWALKAN pencarian untuk dieksekusi setelah 2000ms (2 detik).
  serialSearchTimeout = setTimeout(() => {
    const searchTerm = itemSerialNumber.value.trim();
    const productId = selectedProductIdInput.value;
    const mode = transactionMode.value;

    // ===============================================================
    // LOGIKA MODE SERIAL (Mencari Produk berdasarkan SN)
    // ===============================================================
    if (mode === 'serial') {
      if (searchTerm.length === 0) {
        selectedProduct = null;
        resetItemInputFields(false);
        updateProductModeDisplay(mode);
        return;
      }
            
            // JANGAN LAKUKAN FETCH jika input manual dan SN terlalu pendek (misalnya < 5)
            if (!autoAdd && searchTerm.length < 5) { 
                return;
            }
      
      // Panggil get_product_by_serial.php
      fetch(`get_product_by_serial.php?serial=${searchTerm}`)
        .then(response => {
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          const productData = data.data || data.product;

          if (data && data.success && productData) {
            
            const foundProduct = {
              id: productData.product_id, 
              product_code: productData.product_code,
              product_name: productData.product_name,
              unit: productData.unit,
              has_serial: 1,
              stock: productData.stock || 999999 
            };
            
            selectedProduct = foundProduct;
            productsStock[selectedProduct.id] = parseInt(selectedProduct.stock); 
            
            itemSerialNumber.value = searchTerm;
            
            updateFormFields();

            if (autoAdd && addItemBtn.disabled === false) {
              addItemToTable();
            }
            
          } else {
            showCustomAlert(data.message || 'Serial Number tidak ditemukan atau tidak tersedia.');
            
                        const currentSN = itemSerialNumber.value; 
            selectedProduct = null;
            resetItemInputFields(false); 
            updateProductModeDisplay(mode); 

                        itemSerialNumber.value = currentSN; 
                        itemSerialNumber.focus();
          }
        })
        .catch(error => {
          console.error('Error fetching product by serial:', error);
          showCustomAlert('Terjadi kesalahan saat mencari produk berdasarkan Serial Number.');
          
                    const currentSN = itemSerialNumber.value; 
          selectedProduct = null;
          resetItemInputFields(false);
          updateProductModeDisplay(mode); 

                    itemSerialNumber.value = currentSN; 
                    itemSerialNumber.focus();
        });
      return;
    }


    // ===============================================================
    // LOGIKA PENCARIAN SN UNTUK PRODUK YANG SUDAH TERPILIH (MODE NON-SERIAL)
    // ... (Logika ini tetap menggunakan 300ms, yang wajar untuk pencarian saran)
    // ===============================================================
    if (searchTerm.length < 1 || !productId || !selectedProduct || parseInt(selectedProduct.has_serial) !== 1) {
      serialSearchResultsContainer.style.display = 'none';
      return;
    }
    
    // Cek cache dan fetch
    if (availableSerialsCache[searchTerm] && availableSerialsCache[searchTerm].length > 0) {
      displaySerialSearchResults(availableSerialsCache[searchTerm]);
      return;
    }

    fetch(`search_serials.php?product_id=${productId}&term=${searchTerm}`)
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        if (data && data.serials) {
          availableSerialsCache[searchTerm] = data.serials;
          displaySerialSearchResults(data.serials);
        } else {
          displaySerialSearchResults([]);
        }
      })
      .catch(error => {
        console.error('Error fetching serials:', error);
        serialSearchResultsContainer.innerHTML = `<div class="list-group-item text-danger">Gagal memuat serial number.</div>`;
        serialSearchResultsContainer.style.display = 'block';
      });

  }, 2000); // INI SUDAH DIATUR KE 2000 MS (2 detik)
}

function displaySerialSearchResults(serials) {
    serialSearchResultsContainer.innerHTML = '';
    if (serials.length === 0) {
        serialSearchResultsContainer.style.display = 'none';
        return;
    }
    
    serials.forEach(serial => {
        const resultItem = document.createElement('a');
        resultItem.href = '#';
        resultItem.classList.add('list-group-item', 'list-group-item-action');
        
        const serialNumberText = document.createElement('span');
        serialNumberText.textContent = serial.serial_number;
        resultItem.appendChild(serialNumberText);

        const statusBadge = document.createElement('span');
        if (serial.status === 'Tersedia (Bekas)') {
            statusBadge.classList.add('serial-status', 'status-bekas', 'badge', 'bg-warning', 'ms-2');
            statusBadge.textContent = 'Bekas Tersedia';
        } else { // Asumsikan 'Tersedia'
            statusBadge.classList.add('serial-status', 'status-tersedia', 'badge', 'bg-success', 'ms-2');
            statusBadge.textContent = 'Baru Tersedia';
        }
        resultItem.appendChild(statusBadge);
        
        resultItem.addEventListener('click', function(e) {
            e.preventDefault();
            itemSerialNumber.value = serial.serial_number;
            serialSearchResultsContainer.style.display = 'none';
            checkAddItemButtonStatus();
        });

        serialSearchResultsContainer.appendChild(resultItem);
    });
    serialSearchResultsContainer.style.display = 'block';
}


async function addItemToTable() {
    if (!selectedProduct) {
        showCustomAlert('Mohon pilih produk dari daftar pencarian.');
        return;
    }
    
    const productId = selectedProduct.id;
    const hasSerial = parseInt(selectedProduct.has_serial) === 1; 
    const productName = selectedProduct.product_name;
    
    let quantity = parseInt(itemQuantity.value);
    let serialNumber = itemSerialNumber.value.trim();
    let serialStatus = null; 

    if (hasSerial) {
        quantity = 1;
        if (serialNumber === '') {
            showCustomAlert('Serial number wajib diisi.');
            return;
        }
        if (transactionItems.some(item => item.serial_number === serialNumber && item.has_serial === 1)) {
            showCustomAlert('Serial number ini sudah ditambahkan.');
            return;
        }

        try {
            // Memanggil check_serial_availability.php
            const checkSerialResponse = await fetch(`check_serial_availability.php?product_id=${productId}&serial_number=${serialNumber}`);
            if (!checkSerialResponse.ok) {
                throw new Error(`HTTP error! status: ${checkSerialResponse.status}`);
            }
            const availabilityData = await checkSerialResponse.json();

            if (!availabilityData.is_available) {
                showCustomAlert(`Serial number '${serialNumber}' untuk produk '${productName}' tidak tersedia atau sudah digunakan.`);
                return;
            }
            
            serialStatus = availabilityData.status || 'Tersedia'; 

        } catch (error) {
            console.error('Error checking serial availability:', error);
            showCustomAlert('Terjadi kesalahan saat memeriksa ketersediaan serial number. Silakan coba lagi.');
            return;
        }

    } else {
        if (isNaN(quantity) || quantity <= 0) {
            showCustomAlert('Kuantitas harus berupa angka lebih dari 0.');
            return;
        }
        serialNumber = null;
    }

    const currentTotal = transactionItems
        .filter(item => item.product_id == productId)
        .reduce((sum, item) => sum + item.quantity, 0);

    if (productsStock[productId] < (currentTotal + quantity)) {
        showCustomAlert(`Stok tidak mencukupi. Stok saat ini: ${productsStock[productId]}.`);
        return;
    }
    
    const item = {
        product_id: productId,
        product_code: selectedProduct.product_code,
        product_name: productName,
        has_serial: hasSerial ? 1 : 0,
        quantity: quantity,
        serial_number: serialNumber,
        status: serialStatus 
    };

    if (!hasSerial) {
        const existingIndex = transactionItems.findIndex(i => i.product_id === productId && !i.has_serial);
        if (existingIndex > -1) {
            transactionItems[existingIndex].quantity += quantity;
        } else {
            transactionItems.push(item);
        }
    } else {
        transactionItems.push(item);
    }

    renderItemsTable();
    
    // Reset hanya input item, kemudian kembalikan fokus sesuai mode
    resetItemInputFields(true);
    updateProductModeDisplay(transactionMode.value);
}

function renderItemsTable() {
    itemsTableBody.innerHTML = '';
    if (transactionItems.length === 0) {
        itemsTableBody.innerHTML = '<tr><td colspan="5" class="text-center">Belum ada item ditambahkan.</td></tr>'; 
    } else {
        transactionItems.forEach((item, index) => {
            const row = itemsTableBody.insertRow();
            
            let serialNumberContent = item.serial_number || '-';
            let statusBadge = '';
            
            if (item.serial_number && item.status) {
                let badgeClass = '';
                let badgeText = '';

                if (item.status === 'Tersedia (Bekas)') {
                    badgeClass = 'bg-warning text-dark';
                    badgeText = 'Bekas';
                } else if (item.status === 'Tersedia') {
                    badgeClass = 'bg-success';
                    badgeText = 'Baru';
                }
                
                statusBadge = `<span class="badge ${badgeClass} ms-2">${badgeText}</span>`;
                serialNumberContent = `${item.serial_number}${statusBadge}`;
            }

            row.innerHTML = `
                <td>${item.product_code}</td>
                <td>${item.product_name}</td>
                <td>${item.quantity}</td>
                <td>${serialNumberContent}</td> 
                <td>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeItem(${index})"><i class="bi bi-trash"></i></button>
                </td>
            `;
        });
    }
    itemsJsonInput.value = JSON.stringify(transactionItems);
}

window.removeItem = function(index) {
    const confirmModal = new bootstrap.Modal(document.getElementById('customConfirmModal'));
    const confirmMessage = document.getElementById('customConfirmMessage');
    const confirmButton = document.getElementById('customConfirmBtn');

    confirmMessage.textContent = 'Apakah Anda yakin ingin menghapus item ini?';
    confirmButton.onclick = function() {
        transactionItems.splice(index, 1);
        renderItemsTable();
        confirmModal.hide();
    };
    confirmModal.show();
};

function stopScanningAndCloseModal() {
    if (scanningInterval) {
        clearInterval(scanningInterval);
        scanningInterval = null;
    }
    if (scannerStream) {
        scannerStream.getTracks().forEach(track => track.stop());
        scannerStream = null;
        barcodeVideoFeed.srcObject = null;
        barcodeVideoFeed.style.transform = '';
        barcodeVideoFeed.style.webkitTransform = '';
    }
    barcodeScannerModal.hide();
}

function clearItemForm() {
    // Ini adalah fungsi reset total, termasuk mode awal
    transactionItems = [];
    resetItemInputFields(true);
    
    // Reset foto transaksi
    transactionPhotoPreview.src = '';
    transactionPhotoPreview.style.display = 'none';
    transactionPhotoData.value = '';
    capturedPhotoDataURL = null;
    transactionPhotoPreviewSection.style.display = 'none';

    // Terapkan mode awal
    updateProductModeDisplay('serial'); 
}

// =================================================================
// EVENT LISTENERS
// =================================================================

// --- MODE LISTENERS ---
btnModeSerial.addEventListener('click', () => updateProductModeDisplay('serial'));
btnModeNonSerial.addEventListener('click', () => updateProductModeDisplay('non-serial'));

// --- Form Product/Item Listeners ---
productInput.addEventListener('input', () => searchProducts(false));
itemQuantity.addEventListener('input', checkAddItemButtonStatus);

itemSerialNumber.addEventListener('input', () => {
    checkAddItemButtonStatus();
    searchSerials(false); // Tidak ada autoAdd untuk input manual/ketik
});

addItemBtn.addEventListener('click', addItemToTable);

itemSerialNumber.addEventListener('keydown', function(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        addItemToTable();
    }
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('#product_search_results') && e.target !== productInput) {
        searchResultsContainer.style.display = 'none';
    }
    if (!e.target.closest('#serial_search_results') && e.target !== itemSerialNumber) {
        serialSearchResultsContainer.style.display = 'none';
    }
});

// --- Barcode Scanner Listeners ---

// Listener tombol Scan Barcode Produk (Hanya untuk Mode Non-Serial)
scanProductBarcodeBtn.addEventListener('click', () => {
    if (transactionMode.value === 'serial') {
        showCustomAlert('Di Mode Barang Serial, gunakan Scan Serial Number.');
        return;
    }
    barcodeResultDisplay.textContent = 'Arahkan kamera ke Barcode Produk.';
    barcodeError.style.display = 'none';
    targetInputField = productInput; // Set target ke input produk
    barcodeScannerModal.show();
});

// Listener tombol Scan Serial Number (Mode Serial atau Produk Serial di Mode Non-Serial)
scanBarcodeBtn.addEventListener('click', () => {
    const mode = transactionMode.value;
    const isProductSerial = selectedProduct && parseInt(selectedProduct.has_serial) === 1;

    if (mode === 'non-serial' && !isProductSerial) {
        showCustomAlert('Fitur ini hanya untuk produk yang memiliki Serial Number.');
        return;
    }
    
    barcodeResultDisplay.textContent = 'Arahkan kamera ke Serial Number.';
    barcodeError.style.display = 'none';
    targetInputField = itemSerialNumber; // Set target ke input SN
    barcodeScannerModal.show();
});

// Event saat modal barcode ditampilkan
barcodeScannerModalElement.addEventListener('shown.bs.modal', async function () {
    if (scannerStream) {
        scannerStream.getTracks().forEach(track => track.stop());
        scannerStream = null;
    }
    if (scanningInterval) clearInterval(scanningInterval);
    
    barcodeVideoFeed.style.display = 'block';
    barcodeResultDisplay.style.display = 'block';
    barcodeResultDisplay.style.color = '';

    try {
        scannerStream = await navigator.mediaDevices.getUserMedia({ 
            video: { 
                facingMode: "environment",
                width: { ideal: 640 }, 
                height: { ideal: 480 } 
            } 
        });
        barcodeVideoFeed.srcObject = scannerStream;
        await barcodeVideoFeed.play();

        // Terapkan flip horizontal (biasanya diperlukan)
        barcodeVideoFeed.style.transform = 'scaleX(-1)';
        barcodeVideoFeed.style.webkitTransform = 'scaleX(-1)';

        if (typeof BarcodeDetector === 'undefined') {
            throw new Error("API BarcodeDetector tidak didukung oleh browser ini.");
        }

        const barcodeDetector = new BarcodeDetector({ 
            formats: ['code_128', 'ean_13', 'ean_8', 'code_39', 'upc_a', 'upc_e'] 
        });
        
        scanningInterval = setInterval(async () => {
            try {
                if (barcodeVideoFeed.readyState >= 2) { 
                    const barcodes = await barcodeDetector.detect(barcodeVideoFeed);
                    if (barcodes.length > 0) {
                        const code = barcodes[0].rawValue;
                        
                        // Hentikan setelah hasil ditemukan
                        clearInterval(scanningInterval);
                        scanningInterval = null;
                        
                        if (scannerStream) {
                            scannerStream.getTracks().forEach(track => track.stop());
                            scannerStream = null;
                            barcodeVideoFeed.srcObject = null;
                            barcodeVideoFeed.style.transform = '';
                            barcodeVideoFeed.style.webkitTransform = '';
                        }
                        
                        barcodeScannerModal.hide();

                        // LOGIKA HASIL SCAN
                        targetInputField.value = code;
                        
                        if (targetInputField === productInput) {
                            // Hasil Scan Produk di Mode Non-Serial
                            searchProducts(true); 
                        } else if (targetInputField === itemSerialNumber) {
                            // Hasil Scan SN
                            
                            if (transactionMode.value === 'serial') {
                                // Mode Serial: Panggil searchSerials dan minta untuk otomatis Add Item setelah produk ditemukan
                                searchSerials(true); 
                            } else {
                                // Mode Non-Serial (Produk Serial): Hanya panggil searchSerials (untuk mengisi field)
                                itemSerialNumber.dispatchEvent(new Event('input'));
                            }
                        }
                    }
                }
            } catch (err) {
                // Abaikan error deteksi
            }
        }, 500); 

    } catch (err) {
        console.error('Error accessing camera:', err);
        barcodeError.textContent = 'Tidak dapat mengakses kamera. Pastikan izin kamera diberikan. Detail Error: ' + err.message;
        barcodeError.style.display = 'block';
        barcodeResultDisplay.style.display = 'none';
    }
});
    // Event saat modal barcode ditutup
    barcodeScannerModalElement.addEventListener('hidden.bs.modal', function () {
        if (scanningInterval) {
             clearInterval(scanningInterval);
             scanningInterval = null;
        }

        if (scannerStream) {
            scannerStream.getTracks().forEach(track => track.stop());
            scannerStream = null;
            barcodeVideoFeed.srcObject = null;
            barcodeVideoFeed.style.transform = '';
            barcodeVideoFeed.style.webkitTransform = '';
        }
        
        barcodeResultDisplay.textContent = 'Arahkan kamera ke Serial Number (Barcode).';
        barcodeResultDisplay.style.color = '';
        
    });


    // --- Transaction Photo Camera Listeners ---
    transactionCameraBtn.addEventListener('click', () => {
        cameraModal.show();
        photoPreviewModal.style.display = 'none';
        cameraFeed.style.display = 'block';
        startCamera('user'); 
    });

    cameraFrontBtn.addEventListener('click', () => startCamera('user'));
    cameraBackBtn.addEventListener('click', () => startCamera('environment'));

    captureBtn.addEventListener('click', capturePhoto);

    // Stop camera stream saat modal ditutup
    cameraModalElement.addEventListener('hidden.bs.modal', () => {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
        if (capturedPhotoDataURL) {
            transactionPhotoData.value = capturedPhotoDataURL;
            transactionPhotoPreview.src = capturedPhotoDataURL;
            transactionPhotoPreview.style.display = 'block';
            transactionPhotoPreviewSection.style.display = 'block';
        }
    });

    removeTransactionPhotoBtn.addEventListener('click', () => {
        transactionPhotoPreview.src = '';
        transactionPhotoPreview.style.display = 'none';
        transactionPhotoData.value = '';
        capturedPhotoDataURL = null;
        transactionPhotoPreviewSection.style.display = 'none'; 
    });

    // --- File Upload Listeners ---
    openFileModalBtn.addEventListener('click', () => {
        fileInput.click(); 
    });

    fileInput.addEventListener('change', (event) => {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                capturedPhotoDataURL = e.target.result;
                transactionPhotoData.value = capturedPhotoDataURL;
                transactionPhotoPreview.src = capturedPhotoDataURL;
                transactionPhotoPreview.style.display = 'block';
                transactionPhotoPreviewSection.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });
    
    // INISIALISASI
    // Pastikan hidden input 'transaction_mode' ada di HTML Anda
    updateProductModeDisplay(transactionMode ? transactionMode.value : 'serial'); 
    renderItemsTable();
});
</script>
<?php include '../../includes/footer.php'; ?>