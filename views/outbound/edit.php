<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Proteksi Halaman
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

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transaction_id == 0) {
    header("Location: index.php");
    exit();
}

/**
 * Fungsi menyimpan foto dari Base64
 */
function saveBase64Image($base64_string, $upload_dir, $prefix = 'img_') {
    if (preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $base64_string, $matches)) {
        $img_type = $matches[1];
        $base64_string = substr($base64_string, strpos($base64_string, ',') + 1);
        $data = base64_decode($base64_string);

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0775, true);
        }

        $filename = $prefix . uniqid() . '.' . $img_type;
        $filepath = $upload_dir . '/' . $filename;

        if (file_put_contents($filepath, $data)) {
            return $filename;
        }
    }
    return null;
}

// 1. Fetch Header Transaksi - filtered by warehouse
$transaction_query = "SELECT * FROM outbound_transactions WHERE id = ? AND is_deleted = 0";
$stmt = $conn->prepare($transaction_query);
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transaction) {
    header("Location: index.php");
    exit();
}

$warehouse_id = (int)$transaction['warehouse_id'];

// 2. Fetch data untuk kebutuhan Form (Dropdown & Stock)
// Calculate editable stock: current warehouse stock + quantity already in this transaction
$products_query = $conn->query("
    SELECT 
        p.id, p.product_code, p.product_name, p.has_serial, p.unit,
        (IFNULL(ws.stock, 0) + IFNULL((SELECT SUM(quantity) FROM outbound_transaction_details WHERE transaction_id = $transaction_id AND product_id = p.id), 0)) AS stock
    FROM products p 
    LEFT JOIN warehouse_stocks ws ON p.id = ws.product_id AND ws.warehouse_id = $warehouse_id
    WHERE p.is_deleted = 0 
    ORDER BY p.product_name
");
$products = $products_query->fetch_all(MYSQLI_ASSOC);

if (!$transaction) {
    header("Location: index.php");
    exit();
}

// 3. Fetch Detail Transaksi (Untuk dikirim ke JavaScript) - filtered by warehouse via JOIN
$details_query = "SELECT 
    td.product_id, 
    p.product_code, 
    p.product_name, 
    p.has_serial, 
    td.quantity, 
    td.serial_number 
FROM outbound_transaction_details td 
JOIN products p ON td.product_id = p.id 
JOIN outbound_transactions ot ON td.transaction_id = ot.id
WHERE td.transaction_id = ?";
$stmt_details = $conn->prepare($details_query);
$stmt_details->bind_param("i", $transaction_id);
$stmt_details->execute();
$current_details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();

// 4. Proses POST Update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $recipient = trim($_POST['recipient']);
    $subject = trim($_POST['subject']);
    $transaction_date = trim($_POST['transaction_date']);
    $notes = trim($_POST['notes']);
    $po_number = trim($_POST['po_number']);
    $reference = trim($_POST['reference']);
    $photo_data_url = $_POST['photo_url'] ?? NULL;
    $new_items = json_decode($_POST['items_json'], true);

    if (empty($recipient) || empty($transaction_date) || empty($new_items)) {
        $message = 'Mohon lengkapi semua data transaksi dan tambahkan minimal satu item.';
        $message_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            // A0. PRE-VALIDASI TOTAL STOK KESELURUHAN & HITUNG SELISIH (Net Change)
            $old_qty_map = [];
            $old_details_query = $conn->query("SELECT product_id, quantity FROM outbound_transaction_details WHERE transaction_id = $transaction_id");
            while ($old = $old_details_query->fetch_assoc()) {
                $pid = $old['product_id'];
                $old_qty_map[$pid] = ($old_qty_map[$pid] ?? 0) + $old['quantity'];
            }

            $new_qty_map = [];
            foreach ($new_items as $item) {
                $pid = $item['product_id'];
                $new_qty_map[$pid] = ($new_qty_map[$pid] ?? 0) + $item['quantity'];
            }

            foreach ($new_qty_map as $pid => $new_qty) {
                $old_qty = $old_qty_map[$pid] ?? 0;
                $net_added = $new_qty - $old_qty;

                if ($net_added > 0) {
                    $res_stk = $conn->query("SELECT stock FROM warehouse_stocks WHERE product_id = $pid AND warehouse_id = $warehouse_id")->fetch_assoc();
                    $current_stk = $res_stk ? $res_stk['stock'] : 0;
                    if ($current_stk < $net_added) {
                        $product_name_res = $conn->query("SELECT product_name FROM products WHERE id = $pid")->fetch_assoc();
                        $pname = $product_name_res ? $product_name_res['product_name'] : 'ID: '.$pid;
                        throw new Exception("Stok produk $pname tidak mencukupi untuk penambahan item (Butuh $net_added lagi, Sisa $current_stk).");
                    }
                }
            }

            // A. REVERT STOK & SERIAL LAMA
            // Ambil detail lama untuk revert stok
            $old_details_query = $conn->query("SELECT product_id, quantity, serial_number FROM outbound_transaction_details WHERE transaction_id = $transaction_id");
            while ($old_item = $old_details_query->fetch_assoc()) {
                // Revert Stok - Using warehouse_stocks table
                $conn->query("UPDATE warehouse_stocks SET stock = stock + {$old_item['quantity']} WHERE product_id = {$old_item['product_id']} AND warehouse_id = $warehouse_id");
                
                // Revert Serial Number status jika ada
                if (!empty($old_item['serial_number'])) {
                    $conn->query("UPDATE serial_numbers SET status = 'Tersedia', last_transaction_id = NULL, last_transaction_type = NULL, updated_at = NOW() WHERE serial_number = '{$old_item['serial_number']}' AND product_id = {$old_item['product_id']} AND warehouse_id = $warehouse_id");
                }
            }

            // B. HAPUS DETAIL LAMA
            $conn->query("DELETE FROM outbound_transaction_details WHERE transaction_id = $transaction_id");

            // C. PROSES ITEM BARU (Update Stok & Serial)
            foreach ($new_items as $item) {
                $product_id = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                $serial_number = (!empty($item['serial_number']) && $item['serial_number'] !== '-') ? $item['serial_number'] : NULL;

                // Potong Stok - Using warehouse_stocks
                $conn->query("UPDATE warehouse_stocks SET stock = stock - $quantity WHERE product_id = $product_id AND warehouse_id = $warehouse_id");

                // Update Serial Number status - strictly by warehouse
                if ($serial_number) {
                    $stmt_sn = $conn->prepare("UPDATE serial_numbers SET status = 'Keluar', last_transaction_id = ?, last_transaction_type = 'OUT', updated_at = NOW() WHERE serial_number = ? AND product_id = ? AND warehouse_id = ?");
                    $stmt_sn->bind_param("isii", $transaction_id, $serial_number, $product_id, $warehouse_id);
                    $stmt_sn->execute();
                    if ($stmt_sn->affected_rows === 0) {
                        throw new Exception("Serial Number $serial_number tidak valid, sudah digunakan, atau tidak punya akses.");
                    }
                    $stmt_sn->close();
                }

                // Simpan Detail Baru
                $stmt_ins = $conn->prepare("INSERT INTO outbound_transaction_details (transaction_id, product_id, serial_number, quantity) VALUES (?, ?, ?, ?)");
                $stmt_ins->bind_param("iisi", $transaction_id, $product_id, $serial_number, $quantity);
                $stmt_ins->execute();
                $stmt_ins->close();
            }

            // D. MANAJEMEN FOTO
            $transaction_photo_filename = $transaction['photo_url'];
            if ($photo_data_url === 'REMOVE') {
                if ($transaction_photo_filename && file_exists('../../uploads/outbound_photos/' . $transaction_photo_filename)) {
                    unlink('../../uploads/outbound_photos/' . $transaction_photo_filename);
                }
                $transaction_photo_filename = NULL;
            } elseif ($photo_data_url && $photo_data_url !== 'NO_CHANGE') {
                // Jika ada data baru (Base64), hapus foto lama dan simpan yang baru
                if ($transaction_photo_filename && file_exists('../../uploads/outbound_photos/' . $transaction_photo_filename)) {
                    unlink('../../uploads/outbound_photos/' . $transaction_photo_filename);
                }
                $transaction_photo_filename = saveBase64Image($photo_data_url, '../../uploads/outbound_photos', 'outbound_edit_');
            }

            // E. UPDATE HEADER - filtered by warehouse
            $update_header = "UPDATE outbound_transactions 
                              SET transaction_date = ?, recipient = ?, subject = ?, notes = ?, 
                                  po_number = ?, reference = ?, total_items = ?, photo_url = ?, updated_at = NOW() 
                              WHERE id = ? AND warehouse_id = ?";
            $total_items_count = count($new_items);
            $stmt_hdr = $conn->prepare($update_header);
            $stmt_hdr->bind_param("ssssssisii", $transaction_date, $recipient, $subject, $notes, $po_number, $reference, $total_items_count, $transaction_photo_filename, $transaction_id, $warehouse_id);
            $stmt_hdr->execute();
            $stmt_hdr->close();

            $conn->commit();
            header("Location: index.php?status=success_edit");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Gagal memperbarui: ' . $e->getMessage();
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
    <title>Edit Barang Keluar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        /* Gaya tambahan untuk hasil pencarian produk/serial */
        #product_search_results, #serial_search_results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 0.375rem 0.375rem;
            background-color: white;
            width: 100%;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 1000;
            position: absolute;
        }
        #product_search_results .list-group-item, #serial_search_results .list-group-item {
            cursor: pointer;
            padding: 8px 12px;
            font-size: 0.9rem;
        }
        .serial-status {
            font-size: 0.75em;
            padding: 0.2em 0.5em;
            border-radius: 0.25rem;
            color: white;
            margin-left: 10px;
        }
        .status-tersedia { background-color: #28a745; }
        .status-bekas { background-color: #ffc107; color: #000; }
        .position-relative { position: relative; }

        /* Custom Modal Styles */
        .custom-alert-modal .modal-content { border-radius: 1rem; }
        .custom-alert-modal .modal-title { font-weight: bold; display: flex; align-items: center; }
        
        #cameraModal .modal-body video, #cameraModal .modal-body img {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
        }
        
        /* Table Compact Style */
        .table-sm-custom th, .table-sm-custom td { font-size: 0.875rem; padding: 0.5rem; }
    </style>
</head>
<body>

<div class="container-fluid my-4">
    <div class="content-header mb-4">
        <h2 class="fw-bold">Edit Barang Keluar</h2>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2"></i>Formulir Edit Transaksi</h5>
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

            <form id="outbound-edit-form" action="edit.php?id=<?= $transaction_id ?>" method="POST">
                <input type="hidden" id="warehouse_id" name="warehouse_id" value="<?= $warehouse_id ?>">
                <div class="row g-3">
                    <div class="col-md-3 mb-2">
                        <label class="form-label small fw-bold">Tanggal Transaksi</label>
                        <input type="date" class="form-control form-control-sm" name="transaction_date" value="<?= date('Y-m-d', strtotime($transaction['transaction_date'])) ?>" required>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label small fw-bold">Nomor Transaksi</label>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?= htmlspecialchars($transaction['transaction_number']) ?>" readonly>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label small fw-bold">PO Number</label>
                        <input type="text" class="form-control form-control-sm" name="po_number" value="<?= htmlspecialchars($transaction['po_number']) ?>">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label small fw-bold">Reference</label>
                        <input type="text" class="form-control form-control-sm" name="reference" value="<?= htmlspecialchars($transaction['reference']) ?>">
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6 mb-2">
                        <label class="form-label small fw-bold">Nama Penerima</label>
                        <input type="text" class="form-control form-control-sm" name="recipient" value="<?= htmlspecialchars($transaction['recipient']) ?>" required>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label small fw-bold">Subjek</label>
                        <input type="text" class="form-control form-control-sm" name="subject" value="<?= htmlspecialchars($transaction['subject']) ?>" required>
                    </div>
                </div>

                <div class="mb-3 mt-2">
                    <label class="form-label small fw-bold">Catatan</label>
                    <textarea class="form-control form-control-sm" name="notes" rows="2"><?= htmlspecialchars($transaction['notes']) ?></textarea>
                </div>
                
                <hr>

                <h6 class="fw-bold mt-3 text-primary"><i class="bi bi-image me-2"></i>Foto Bukti Transaksi</h6>
                <div class="card p-3 mb-4 bg-light border-dashed">
                    <div class="row align-items-center">
                        <div class="col-md-4 text-center border-end" id="transaction-photo-preview-section" style="<?= !empty($transaction['photo_url']) ? 'display: block;' : 'display: none;' ?>">
                            <?php 
                                // Tentukan path foto. Jika di database tersimpan nama filenya saja:
                                $photoPath = !empty($transaction['photo_url']) ? '../../uploads/outbound_photos/' . $transaction['photo_url'] : '';
                            ?>
                            <img id="transaction-photo-preview" src="<?= htmlspecialchars($photoPath) ?>" alt="Pratinjau" class="img-thumbnail mb-2" style="max-height: 150px;">
                            <br>
                            <button class="btn btn-sm btn-outline-danger py-0" type="button" id="remove-transaction-photo-btn">Hapus</button>
                            <input type="hidden" id="transaction-photo-data" name="photo_url" value="NO_CHANGE">
                        </div>

                        <div class="col-md">
                            <p class="small text-muted mb-2">Ambil foto atau unggah bukti surat jalan/barang keluar.</p>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-primary" type="button" id="transaction-camera-btn">
                                    <i class="bi bi-camera me-1"></i>Kamera
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" type="button" id="openFileModalBtn">
                                    <i class="bi bi-upload me-1"></i>Pilih File
                                </button>
                                <input type="file" id="fileInput" accept="image/*" style="display: none;">
                            </div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold mt-4 text-primary"><i class="bi bi-box-seam me-2"></i>Input Produk</h6>
                <div class="card p-3 mb-3 border-primary border-opacity-25 bg-white shadow-sm">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5 position-relative">
                            <label class="form-label small fw-bold">Scan Barcode / Cari Nama</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="bi bi-upc-scan"></i></span>
                                <input type="text" class="form-control" id="product_input" 
                                    placeholder="Scan barcode atau ketik nama produk..." autocomplete="off">
                            </div>
                            <div id="product_search_results" class="list-group position-absolute w-100 shadow-lg" 
                                style="display: none; z-index: 1050; max-height: 300px; overflow-y: auto;">
                            </div>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Qty</label>
                            <input type="number" class="form-control form-control-sm" id="item_quantity" 
                                min="1" value="1" disabled placeholder="Jumlah">
                        </div>

                        <div class="col-md-3" id="serial-section" style="display: none;">
                            <label class="form-label small fw-bold">Serial Number</label>
                            <input type="text" class="form-control form-control-sm border-info" 
                                id="item_serial_number" placeholder="Scan SN di sini..." disabled>
                        </div>

                        <div class="col-md-2">
                            <button type="button" class="btn btn-info btn-sm w-100 fw-bold text-white" id="add-item-btn">
                                <i class="bi bi-plus-circle me-1"></i>Tambah
                            </button>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="items_json" id="items_json">
                
                <h6 class="fw-bold mt-4">Daftar Item Transaksi</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover table-sm-custom" id="items-table">
                        <thead class="table-light">
                            <tr>
                                <th>Kode Produk</th>
                                <th>Nama Produk</th>
                                <th class="text-center" style="width: 100px;">Qty</th>
                                <th>Serial Number</th>
                                <th class="text-center" style="width: 80px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="submit" class="btn btn-primary px-5 rounded-pill shadow">
                        <i class="bi bi-check2-circle me-2"></i>Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade custom-alert-modal" id="customAlertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <i class="bi bi-exclamation-triangle-fill text-danger fs-1 mb-3"></i>
                <h5 class="fw-bold">Peringatan</h5>
                <p id="customAlertMessage" class="text-muted small"></p>
                <button type="button" class="btn btn-danger btn-sm rounded-pill px-4" data-bs-dismiss="modal">Mengerti</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade custom-alert-modal" id="customConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <i class="bi bi-question-circle-fill text-warning fs-1 mb-3"></i>
                <h5 class="fw-bold">Hapus Item?</h5>
                <p id="customConfirmMessage" class="text-muted small"></p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-light btn-sm rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-danger btn-sm rounded-pill px-3" id="customConfirmBtn">Ya, Hapus</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cameraModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold">Ambil Foto Bukti</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div class="btn-group btn-group-sm mb-3 w-100">
                    <button type="button" class="btn btn-outline-primary" id="camera-front-btn">Kamera Depan</button>
                    <button type="button" class="btn btn-outline-primary" id="camera-back-btn">Kamera Belakang</button>
                </div>
                <video id="cameraFeed" class="w-100 rounded bg-dark" autoplay playsinline style="height: 300px; object-fit: cover;"></video>
                <canvas id="photoCanvas" class="d-none"></canvas>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-primary w-100" id="capture-btn">
                    <i class="bi bi-camera-fill me-2"></i>Tangkap Foto
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="qtyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content shadow-lg">
            <div class="modal-header bg-light">
                <h6 class="modal-title fw-bold">Input Jumlah</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p id="qtyModalProductName" class="mb-2 fw-bold text-primary"></p>
                <div class="input-group">
                    <span class="input-group-text">Jumlah</span>
                    <input type="number" id="manualQtyInput" class="form-control text-center" value="1" min="1">
                </div>
            </div>
            <div class="modal-footer p-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" id="confirmQtyBtn" class="btn btn-primary btn-sm px-4">Konfirmasi</button>
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


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" xintegrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- ELEMEN UI ---
    const productInput = document.getElementById('product_input');
    const searchResultsContainer = document.getElementById('product_search_results');
    const itemQuantity = document.getElementById('item_quantity');
    const itemSerialNumber = document.getElementById('item_serial_number');
    const serialSection = document.getElementById('serial-section');
    const addItemBtn = document.getElementById('add-item-btn');
    const itemsTableBody = document.querySelector('#items-table tbody');
    const itemsJsonInput = document.getElementById('items_json');

    // Modals & Alert
    const customAlertModal = new bootstrap.Modal(document.getElementById('customAlertModal'));
    const customAlertMessage = document.getElementById('customAlertMessage');
    const customConfirmModal = new bootstrap.Modal(document.getElementById('customConfirmModal'));
    const customConfirmBtn = document.getElementById('customConfirmBtn');
    const customConfirmMessage = document.getElementById('customConfirmMessage');

    // Photo
    const removePhotoBtn = document.getElementById('remove-transaction-photo-btn');
    const cameraModal = new bootstrap.Modal(document.getElementById('cameraModal'));
    const fileInput = document.getElementById('fileInput');
    
    // --- STATE DATA ---
    let transactionItems = [];
    let selectedProduct = null;
    let productsStock = {}; 
    let currentStream = null;

    // Barcode Scanner Buffer
    let barcodeBuffer = '';
    let lastKeyTime = Date.now();

    // --- INISIALISASI DATA AWAL ---
    const initialItems = <?= json_encode($current_details) ?>;
    if (initialItems) {
        transactionItems = initialItems.map(item => ({
            product_id: item.product_id,
            product_code: item.product_code,
            product_name: item.product_name,
            has_serial: parseInt(item.has_serial),
            quantity: parseInt(item.quantity),
            serial_number: item.serial_number || '-'
        }));
    }

    const product_list_data = <?= json_encode($products) ?>;
    product_list_data.forEach(p => { productsStock[p.id] = parseInt(p.stock); });

    // --- HELPER ---
    function showCustomAlert(msg) {
        customAlertMessage.textContent = msg;
        customAlertModal.show();
    }

    // ==========================================================
    // 1. SMART SCANNER LOGIC (INTEGRASI PENUH SERVER)
    // ==========================================================
    document.addEventListener('keydown', function(e) {
        const active = document.activeElement;
        if ([productInput, itemSerialNumber, itemQuantity].includes(active) || active.tagName === 'TEXTAREA') return;

        const now = Date.now();
        if (now - lastKeyTime > 60) barcodeBuffer = '';
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

    async function handleSmartScan(code) {
        try {
            const warehouseId = document.getElementById('warehouse_id').value;
            const response = await fetch(`check_serial_availability.php?code=${encodeURIComponent(code)}&warehouse_id=${warehouseId}`);
            const result = await response.json();

            if (result.success) {
                const itemData = result.data;
                
                // Definisikan objek produk
                const productObj = {
                    id: itemData.product_id,
                    product_code: itemData.product_code,
                    product_name: itemData.product_name,
                    has_serial: (itemData.type === 'serial' ? 1 : 0)
                };

                if (itemData.type === 'non-serial') {
                    // JALUR NON-SERIAL: Langsung tambah tanpa validasi SN
                    addItemToTableLogic(productObj, 1, '-'); 
                } else {
                    // JALUR SERIAL: Tambah dengan SN yang didapat dari server
                    addItemToTableLogic(productObj, 1, itemData.serial);
                }
            } else {
                // Jika gagal (bisa jadi karena itu input manual atau kode tidak dikenal)
                showCustomAlert(result.message);
                // Opsional: fokuskan ke input manual jika ingin user mencari
                productInput.value = code;
                searchProducts(code);
            }
        } catch (err) {
            console.error("Scan Error:", err);
            showCustomAlert("Gagal memvalidasi barcode.");
        }
    }

// ==========================================================
    // 2. SEARCH & SELECTION LOGIC (PENYESUAIAN MODAL)
    // ==========================================================
    let debounceTimer;
    // Definisikan qtyModal di luar agar bisa diakses fungsi
    const qtyModal = new bootstrap.Modal(document.getElementById('qtyModal'));
    let tempProductData = null; // State sementara untuk produk yang dipilih manual

    productInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const term = this.value.trim();
        if (term.length < 2) { searchResultsContainer.style.display = 'none'; return; }
        debounceTimer = setTimeout(() => searchProducts(term), 300);
    });

    function searchProducts(term) {
        const warehouseId = document.getElementById('warehouse_id').value;
        const urlParams = new URLSearchParams(window.location.search);
        const transactionId = urlParams.get('id');
        fetch(`search_products.php?q=${encodeURIComponent(term)}&warehouse_id=${warehouseId}&transaction_id=${transactionId}`)
            .then(res => res.json())
            .then(data => displaySearchResults(data.results || data))
            .catch(err => console.error(err));
    }

    function displaySearchResults(results) {
        searchResultsContainer.innerHTML = '';
        if (!results.length) { 
            searchResultsContainer.style.display = 'none'; 
            return; 
        }

        results.forEach(product => {
            const item = document.createElement('a');
            item.className = 'list-group-item list-group-item-action';
            
            const currentStock = product.stock !== undefined ? product.stock : (productsStock[product.id] || 0);
            const isSerial = parseInt(product.has_serial) === 1;
            
            // Kurangi dengan yang sudah ada di keranjang untuk tampilan visual
            const existingOpt = transactionItems.find(i => i.product_id === product.id && i.serial_number === '-');
            const qtyInCart = existingOpt ? existingOpt.quantity : 0;
            const visualStock = currentStock - qtyInCart;

            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${product.product_code}</strong><br>
                        <span class="small text-muted">${product.product_name}</span>
                    </div>
                    <div>
                        ${isSerial 
                            ? '<span class="badge bg-warning text-dark">Wajib SN</span>' 
                            : `<span class="badge bg-info">Tersedia: ${visualStock}</span>`}
                    </div>
                </div>`;
                
            item.onclick = e => { 
                e.preventDefault(); 
                handleManualSelection(product); 
            };
            searchResultsContainer.appendChild(item);
        });
        searchResultsContainer.style.display = 'block';
    }

    function handleManualSelection(product) {
        searchResultsContainer.style.display = 'none';
        productInput.value = ''; 

        const isSerial = parseInt(product.has_serial) === 1;
        const availableStock = product.stock !== undefined ? product.stock : (productsStock[product.id] || 0);

        if (isSerial) {
            // JALUR SERIAL: Tampilkan Modal Checkout Checkbox
            tempProductData = product;
            openSerialSelectionModal(product);
        } else {
            // JALUR NON-SERIAL: Buka Modal
            const existingOpt = transactionItems.find(i => i.product_id === product.id && i.serial_number === '-');
            const qtyInCart = existingOpt ? existingOpt.quantity : 0;
            const remainingStock = availableStock - qtyInCart;

            if (remainingStock <= 0) {
                showCustomAlert(`Maaf, sisa stok untuk ditambahkan habis.`);
                return;
            }

            // Set data ke modal
            tempProductData = product;
            document.getElementById('qtyModalProductName').textContent = product.product_name;
            const manualQtyInput = document.getElementById('manualQtyInput');
            manualQtyInput.value = 1;
            manualQtyInput.max = remainingStock;

            qtyModal.show();

            // Auto focus ke input quantity
            setTimeout(() => manualQtyInput.focus(), 500);
        }
    }

    // Handler khusus untuk tombol Konfirmasi di dalam Modal
    document.getElementById('confirmQtyBtn').addEventListener('click', function() {
        if (!tempProductData) return;

        const qtyInput = document.getElementById('manualQtyInput');
        const numQty = parseInt(qtyInput.value);
        const availableStock = tempProductData.stock !== undefined ? tempProductData.stock : (productsStock[tempProductData.id] || 0);

        if (isNaN(numQty) || numQty <= 0) {
            showCustomAlert("Masukkan jumlah yang valid.");
            return;
        }

        if (numQty > availableStock) {
            showCustomAlert(`Stok tidak mencukupi (Maks: ${availableStock})`);
            return;
        }

        // Kirim ke fungsi tabel yang sudah ada
        addItemToTableLogic(tempProductData, numQty, '-');
        
        qtyModal.hide();
        tempProductData = null; // Reset state
    });

    // Support tombol Enter di dalam modal
    document.getElementById('manualQtyInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('confirmQtyBtn').click();
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
            const insideCartSerials = transactionItems.map(item => item.serial_number);
            availableSerialsData = availableSerialsData.filter(sn => !insideCartSerials.includes(sn.serial_number));

            renderSerialCheckboxes(availableSerialsData);
        } catch (err) {
            serialContainer.innerHTML = '<div class="alert alert-danger m-3">Gagal memuat daftar serial.</div>';
        }
    }

    function renderSerialCheckboxes(serialsList) {
        serialContainer.innerHTML = '';
        
        if (serialsList.length === 0) {
            serialContainer.innerHTML = '<div class="text-center py-4 text-danger"><i class="bi bi-exclamation-triangle display-4 d-block mb-2"></i>Tidak ada Serial Number tersedia.</div>';
            return;
        }

        serialsList.forEach((sn) => {
            const label = document.createElement('label');
            label.className = 'list-group-item list-group-item-action d-flex align-items-center cursor-pointer';
            
            const checkbox = document.createElement('input');
            checkbox.className = 'form-check-input me-3 sn-checkbox';
            checkbox.type = 'checkbox';
            checkbox.value = sn.serial_number;
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
        
        document.querySelectorAll('.sn-checkbox').forEach(cb => {
            if (cb.checked) cb.closest('label').classList.add('list-group-item-primary');
            else cb.closest('label').classList.remove('list-group-item-primary');
        });
    }

    btnConfirmSerial.addEventListener('click', function() {
        const checkedBoxes = document.querySelectorAll('.sn-checkbox:checked');
        
        if (checkedBoxes.length > 0 && tempProductData) {
            checkedBoxes.forEach(checkbox => {
                const sn = checkbox.value;
                const exists = transactionItems.find(i => i.serial_number === sn);
                if (!exists) {
                    transactionItems.push({
                        product_id: tempProductData.id,
                        product_code: tempProductData.product_code,
                        product_name: tempProductData.product_name,
                        has_serial: 1,
                        quantity: 1,
                        serial_number: sn
                    });
                }
            });
            renderItemsTable();
            serialModal.hide();
            tempProductData = null;
        }
    });

    // ==========================================================
    // 3. TABLE & DATA MANAGEMENT
    // ==========================================================
    function addItemToTableLogic(product, qty, sn) {
        const isSerial = parseInt(product.has_serial) === 1;
        // Ambil sisa stok dari referensi productsStock
        const availableStock = productsStock[product.id] || 0;

        if (isSerial) {
            // ... (logika serial tetap sama)
            if (!sn || sn === '-') {
                showCustomAlert('Produk ini wajib memiliki Serial Number!');
                return;
            }
            const isDuplicate = transactionItems.some(item => item.serial_number === sn);
            if (isDuplicate) {
                showCustomAlert(`SN "${sn}" sudah ada di daftar.`);
                return;
            }

            transactionItems.push({
                product_id: product.id,
                product_code: product.product_code,
                product_name: product.product_name,
                has_serial: 1,
                quantity: 1,
                serial_number: sn
            });
        } else {
            // JALUR NON-SERIAL dengan Validasi Stok
            const existingIndex = transactionItems.findIndex(item => 
                item.product_id === product.id && item.serial_number === '-'
            );

            let currentQtyInTable = 0;
            if (existingIndex > -1) {
                currentQtyInTable = transactionItems[existingIndex].quantity;
            }

            const totalNewQty = currentQtyInTable + parseInt(qty);

            // Validasi Batas Maksimum Stok
            if (totalNewQty > availableStock) {
                showCustomAlert(`Gagal! Stok tersedia hanya ${availableStock}. (Sudah ada ${currentQtyInTable} di daftar)`);
                return;
            }

            if (existingIndex > -1) {
                transactionItems[existingIndex].quantity = totalNewQty;
            } else {
                transactionItems.push({
                    product_id: product.id,
                    product_code: product.product_code,
                    product_name: product.product_name,
                    has_serial: 0,
                    quantity: parseInt(qty),
                    serial_number: '-'
                });
            }
        }
        
        renderItemsTable();
    }

    function renderItemsTable() {
        itemsTableBody.innerHTML = '';
        transactionItems.forEach((item, index) => {
            const row = itemsTableBody.insertRow();
            const availableStock = productsStock[item.product_id] || 0;
            const qtyDisplay = item.has_serial === 0 
                ? `<input type="number" class="form-control form-control-sm text-center mx-auto" 
                        style="max-width:80px" 
                        value="${item.quantity}" 
                        max="${availableStock}" 
                        min="1" 
                        onchange="updateQty(${index}, this.value)">`
                    : `<span class="fw-bold">${item.quantity}</span>`;

            row.innerHTML = `
                <td class="text-center">${item.product_code}</td>
                <td>${item.product_name}</td>
                <td class="text-center">${qtyDisplay}</td>
                <td class="text-center"><span class="badge ${item.serial_number !== '-' ? 'bg-info' : 'bg-light text-muted'}">${item.serial_number}</span></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="handleDelete(${index})">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;
        });
        itemsJsonInput.value = JSON.stringify(transactionItems);
    }

    window.updateQty = function(index, val) {
        const item = transactionItems[index];
        const newQty = parseInt(val);
        const availableStock = productsStock[item.product_id] || 0;

        if (isNaN(newQty) || newQty <= 0) return handleDelete(index);

        // Validasi stok saat edit manual di tabel
        if (item.has_serial === 0 && newQty > availableStock) {
            showCustomAlert(`Jumlah melebihi stok! Maksimum stok adalah ${availableStock}`);
            renderItemsTable(); // Reset tampilan ke angka sebelumnya
            return;
        }

        transactionItems[index].quantity = newQty;
        itemsJsonInput.value = JSON.stringify(transactionItems);
    };

    window.handleDelete = function(index) {
        customConfirmMessage.textContent = 'Hapus item ini?';
        customConfirmBtn.onclick = () => {
            transactionItems.splice(index, 1);
            renderItemsTable();
            customConfirmModal.hide();
        };
        customConfirmModal.show();
    };

    addItemBtn.addEventListener('click', () => {
        if (!selectedProduct) return;
        const qty = parseInt(itemQuantity.value) || 0;
        const sn = itemSerialNumber.value.trim();
        addItemToTableLogic(selectedProduct, qty, sn);
        if (parseInt(selectedProduct.has_serial) === 0) {
            productInput.value = '';
            selectedProduct = null;
        }
    });

// ==========================================================
    // 4. CAMERA LOGIC (SINKRONISASI DATABASE, GANTI & HAPUS)
    // ==========================================================

    // 1. Inisialisasi: Tampilkan foto jika sudah ada di database
    // Mengambil nama file dari PHP variabel $transaction['photo_url']
    const existingPhoto = "<?= $transaction['photo_url'] ?? '' ?>";
    const photoPreview = document.getElementById('transaction-photo-preview');
    const photoDataInput = document.getElementById('transaction-photo-data');
    const previewSection = document.getElementById('transaction-photo-preview-section');

    if (existingPhoto && existingPhoto !== "") {
        // Path disesuaikan dengan folder uploads Anda
        photoPreview.src = "../../uploads/outbound_photos/" + existingPhoto;
        previewSection.style.display = 'block';
        // Set nilai default agar PHP tahu tidak ada perubahan jika tidak menyentuh foto
        photoDataInput.value = 'NO_CHANGE'; 
    }

    document.getElementById('transaction-camera-btn').addEventListener('click', () => {
        cameraModal.show();
        startCamera('environment');
    });

    async function startCamera(mode) {
        if (currentStream) currentStream.getTracks().forEach(t => t.stop());
        try {
            currentStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: mode } });
            document.getElementById('cameraFeed').srcObject = currentStream;
        } catch { showCustomAlert('Gagal akses kamera.'); }
    }

    // Ambil Foto Baru (Ganti Foto)
    document.getElementById('capture-btn').addEventListener('click', () => {
        const canvas = document.getElementById('photoCanvas');
        const video = document.getElementById('cameraFeed');
        canvas.width = video.videoWidth; canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        
        const data = canvas.toDataURL('image/jpeg');
        
        // Simpan data base64 ke hidden input untuk dikirim ke server
        photoDataInput.value = data; 
        photoPreview.src = data;
        previewSection.style.display = 'block';
        
        stopCamera();
        cameraModal.hide();
    });

    // 2. Fitur Pilih File (Upload dari Galeri)
    document.getElementById('openFileModalBtn').addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                photoPreview.src = e.target.result;
                photoDataInput.value = e.target.result; // Base64 dari file upload
                previewSection.style.display = 'block';
            };
            reader.readAsDataURL(this.files[0]);
        }
    });

    // 3. Logika Hapus Foto
    document.getElementById('remove-transaction-photo-btn').addEventListener('click', function() {
        customConfirmMessage.textContent = 'Hapus foto bukti transaksi ini?';
        customConfirmBtn.onclick = () => {
            // Berikan flag 'REMOVE' agar backend menghapus file fisik di server
            photoDataInput.value = 'REMOVE'; 
            photoPreview.src = '';
            previewSection.style.display = 'none';
            customConfirmModal.hide();
        };
        customConfirmModal.show();
    });

    function stopCamera() {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
    }

    // Pastikan stream kamera mati saat modal ditutup manual
    document.getElementById('cameraModal').addEventListener('hidden.bs.modal', stopCamera);

    renderItemsTable();
});
</script>
<?php include '../../includes/footer.php'; ?>