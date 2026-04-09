<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    exit();
}

$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$message_type = '';

if ($transaction_id <= 0) {
    header("Location: ../../index.php");
    exit();
}

$sql_header = "SELECT * FROM borrowed_transactions WHERE id = ? AND is_deleted = 0";
$stmt_header = $conn->prepare($sql_header);
$stmt_header->bind_param("i", $transaction_id);
$stmt_header->execute();
$transaction = $stmt_header->get_result()->fetch_assoc();
$stmt_header->close();

if (!$transaction) {
    echo "Transaksi tidak ditemukan.";
    exit();
}

$warehouse_id = (int)$transaction['warehouse_id'];
// ---- LOGIKA UNTUK MENANGANI FORM SUBMISSION (UPDATE) ----
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $transaction_id = intval($_POST['transaction_id']);
    $borrow_date = trim($_POST['borrow_date']);
    $borrower_name = trim($_POST['borrower_name']);
    $subject = trim($_POST['subject']);
    $notes = trim($_POST['notes']);
    $transaction_photo_data = $_POST['transaction_photo_data'] ?? null;
    $items_json = $_POST['items_json'] ?? '[]';
    $items_new = json_decode($items_json, true);

    if (empty($borrower_name) || empty($borrow_date)) {
        $message = 'Mohon lengkapi data peminjaman.';
        $message_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            // 1. Ambil data detail item LAMA dari database - strictly by tenant/warehouse via JOIN
            $sql_old_details = "SELECT btd.id, btd.product_id, btd.quantity, btd.serial_number 
                                FROM borrowed_transactions_detail btd
                                JOIN borrowed_transactions bt ON btd.transaction_id = bt.id
                                WHERE btd.transaction_id = ? AND bt.warehouse_id = ?";
            $stmt_old = $conn->prepare($sql_old_details);
            $stmt_old->bind_param("ii", $transaction_id, $warehouse_id);
            $stmt_old->execute();
            $result_old = $stmt_old->get_result();
            $old_items_db = $result_old->fetch_all(MYSQLI_ASSOC);
            $stmt_old->close();

            // Ubah array lama menjadi map untuk pencarian cepat
            $old_items_map = [];
            foreach ($old_items_db as $item) {
                $key = $item['product_id'] . '-' . ($item['serial_number'] ?? '');
                $old_items_map[$key] = $item;
            }

            // 2. Loop melalui item BARU untuk memproses UPDATE dan INSERT
            foreach ($items_new as $item) {
                $product_id = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                $serial_number = $item['serial_number'] ?? NULL;
                $key = $product_id . '-' . ($serial_number ?? '');

                if (isset($old_items_map[$key])) {
                    // Item sudah ada, cek jika kuantitas berubah (hanya berlaku untuk non-serial)
                    $old_quantity = (int)$old_items_map[$key]['quantity'];
                    if ($quantity != $old_quantity) {
                        $diff_quantity = $quantity - $old_quantity;
                        
                        // Validasi stok - using warehouse_stocks
                        $product_info_query = $conn->prepare("SELECT stock as stock FROM warehouse_stocks WHERE product_id = ? AND warehouse_id = ?");
                        $product_info_query->bind_param("ii", $product_id, $warehouse_id);
                        $product_info_query->execute();
                        $product_info = $product_info_query->get_result()->fetch_assoc();
                        $product_info_query->close();
                        
                        if ($diff_quantity > $product_info['stock']) {
                            throw new Exception("Stok tidak mencukupi untuk memperbarui item.");
                        }

                        $sql_update_detail = "UPDATE borrowed_transactions_detail SET quantity = ? WHERE id = ?";
                        $stmt_update_detail = $conn->prepare($sql_update_detail);
                        $stmt_update_detail->bind_param("ii", $quantity, $old_items_map[$key]['id']);
                        $stmt_update_detail->execute();
                        $stmt_update_detail->close();
                        
                        $sql_update_stock = "UPDATE warehouse_stocks SET stock = stock - ? WHERE product_id = ? AND warehouse_id = ?";
                        $stmt_update_stock = $conn->prepare($sql_update_stock);
                        $stmt_update_stock->bind_param("iii", $diff_quantity, $product_id, $warehouse_id);
                        $stmt_update_stock->execute();
                        $stmt_update_stock->close();
                    }
                    unset($old_items_map[$key]); // Hapus dari map agar tidak terhapus di akhir
                } else {
                    // ITEM BARU ditambahkan ke transaksi
                    $product_info_query = $conn->prepare("SELECT stock FROM products WHERE id = ?");
                    $product_info_query->bind_param("i", $product_id);
                    $product_info_query->execute();
                    $product_info = $product_info_query->get_result()->fetch_assoc();
                    $product_info_query->close();

                    if (!$product_info || $product_info['stock'] < $quantity) {
                        throw new Exception("Stok tidak mencukupi untuk item baru.");
                    }

                    // Insert Detail Baru
                    $insert_detail_sql = "INSERT INTO borrowed_transactions_detail (transaction_id, product_id, serial_number, quantity) VALUES (?, ?, ?, ?)";
                    $stmt_detail = $conn->prepare($insert_detail_sql);
                    $stmt_detail->bind_param("iisi", $transaction_id, $product_id, $serial_number, $quantity);
                    $stmt_detail->execute();
                    $stmt_detail->close();
                    
                    // Update Stok Produk - using warehouse_stocks
                    $update_stock_sql = "UPDATE warehouse_stocks SET stock = stock - ? WHERE product_id = ? AND warehouse_id = ?";
                    $stmt_stock = $conn->prepare($update_stock_sql);
                    $stmt_stock->bind_param("iii", $quantity, $product_id, $warehouse_id);
                    $stmt_stock->execute();
                    $stmt_stock->close();

                    // --- TAMBAHAN: Update Status SN Baru --- strictly by warehouse
                    if (!empty($serial_number)) {
                        $sql_update_sn = "UPDATE serial_numbers SET status = 'Dipinjam', last_transaction_id = ?, last_transaction_type = 'BORROW', updated_at = NOW() WHERE product_id = ? AND serial_number = ? AND warehouse_id = ?";
                        $stmt_sn = $conn->prepare($sql_update_sn);
                        $stmt_sn->bind_param("iisi", $transaction_id, $product_id, $serial_number, $warehouse_id);
                        $stmt_sn->execute();
                        $stmt_sn->close();
                    }
                }
            }

            // 3. Loop Item yang DIHAPUS dari list (ada di old_map tapi tidak ada di new_items)
            foreach ($old_items_map as $old_item) {
                // Kembalikan stok - using warehouse_stocks
                $sql_return_stock = "UPDATE warehouse_stocks SET stock = stock + ? WHERE product_id = ? AND warehouse_id = ?";
                $stmt_return_stock = $conn->prepare($sql_return_stock);
                $stmt_return_stock->bind_param("iii", $old_item['quantity'], $old_item['product_id'], $warehouse_id);
                $stmt_return_stock->execute();
                $stmt_return_stock->close();

                // --- TAMBAHAN: Reset Status SN yang dihapus --- strictly by warehouse
                if (!empty($old_item['serial_number'])) {
                    $sql_reset_sn = "UPDATE serial_numbers SET status = 'Tersedia', last_transaction_id = NULL, last_transaction_type = NULL, updated_at = NOW() WHERE product_id = ? AND serial_number = ? AND warehouse_id = ?";
                    $stmt_reset_sn = $conn->prepare($sql_reset_sn);
                    $stmt_reset_sn->bind_param("isi", $old_item['product_id'], $old_item['serial_number'], $warehouse_id);
                    $stmt_reset_sn->execute();
                    $stmt_reset_sn->close();
                }

                // Hapus Detail
                $sql_delete_item = "DELETE FROM borrowed_transactions_detail WHERE id = ?";
                $stmt_delete_item = $conn->prepare($sql_delete_item);
                $stmt_delete_item->bind_param("i", $old_item['id']);
                $stmt_delete_item->execute();
                $stmt_delete_item->close();
            }

            // 4. Penanganan Foto Transaksi
            $photo_path = null;
            if (!empty($transaction_photo_data)) {
                $upload_dir = '../../uploads/borrowed_photos/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $transaction_photo_data));
                $file_name = uniqid('trans_photo_') . '.png';
                $photo_path = $upload_dir . $file_name;
                
                if (!file_put_contents($photo_path, $data)) {
                    throw new Exception("Gagal menyimpan file foto.");
                }
                $photo_path = '../../uploads/borrowed_photos/' . $file_name;
            }

            // 5. Update Header Transaksi - strictly by warehouse
            $updated_by = $_SESSION['user_id'];
            $sql_update_header = "UPDATE borrowed_transactions SET borrow_date = ?, borrower_name = ?, subject = ?, notes = ?, photo_url = COALESCE(?, photo_url), updated_by = ?, updated_at = NOW() WHERE id = ? AND warehouse_id = ?";
            $stmt_header = $conn->prepare($sql_update_header);
            $stmt_header->bind_param("sssssiii", $borrow_date, $borrower_name, $subject, $notes, $photo_path, $updated_by, $transaction_id, $warehouse_id);
            $stmt_header->execute();
            $stmt_header->close();

            $conn->commit();
            header("Location: /wms-geo/views/borrow/detail.php?id=" . $transaction_id . "&status=success_edit");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// ---- LOGIKA GET REQUEST (MENAMPILKAN DATA) ----
// (Header Transaksi sudah diambil di atas)

// 2. Ambil data Detail Item Transaksi - strictly by tenant/warehouse via JOIN
$sql_details = "SELECT btd.*, p.product_code, p.product_name, p.has_serial, ps.stock as stock 
                FROM borrowed_transactions_detail btd
                JOIN products p ON btd.product_id = p.id
                JOIN borrowed_transactions bt ON btd.transaction_id = bt.id
                LEFT JOIN warehouse_stocks ps ON p.id = ps.product_id AND ps.warehouse_id = bt.warehouse_id
                WHERE btd.transaction_id = ? AND bt.warehouse_id = ?";
$stmt_details = $conn->prepare($sql_details);
$stmt_details->bind_param("ii", $transaction_id, $warehouse_id);
$stmt_details->execute();
$details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();

// Siapkan data untuk JavaScript
$transaction_items_json = json_encode(array_map(function($item) {
    return [
        'product_id' => $item['product_id'],
        'product_code' => $item['product_code'],
        'product_name' => $item['product_name'],
        'has_serial' => (int)$item['has_serial'],
        'quantity' => $item['quantity'],
        'serial_number' => $item['serial_number']
    ];
}, $details));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/wms-geo//assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>

        .video-container {
            position: relative;
            width: 100%;
            padding-top: 75%; /* 4:3 Aspect Ratio */
            overflow: hidden;
            border-radius: 0.5rem;
            margin-top: 1rem;
            border: 1px solid #e2e8f0;
        }
        .video-container video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 0.5rem;
        }

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
    </style>
</head>
<body>
<div class="main-content">
    <div class="content-header mb-4">
        <h1 class="fw-bold">Edit Peminjaman Barang</h1>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark">Formulir Edit Transaksi #<?= htmlspecialchars($transaction['transaction_number']) ?></h5>
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

            <form id="borrow-edit-form" action="edit.php?id=<?= $transaction_id ?>" method="POST">
                <input type="hidden" name="transaction_id" value="<?= $transaction_id ?>">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="borrow_date" class="form-label">Tanggal Peminjaman</label>
                        <input type="date" class="form-control" id="borrow_date" name="borrow_date" value="<?= htmlspecialchars($transaction['borrow_date']) ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="transaction_number" class="form-label">Nomor Transaksi</label>
                        <input type="text" class="form-control" id="transaction_number" value="<?= htmlspecialchars($transaction['transaction_number']) ?>" readonly>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="borrower_name" class="form-label">Nama Peminjam</label>
                        <input type="text" class="form-control" id="borrower_name" name="borrower_name" value="<?= htmlspecialchars($transaction['borrower_name']) ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="subject" class="form-label">Subjek</label>
                        <input type="text" class="form-control" id="subject" name="subject" value="<?= htmlspecialchars($transaction['subject']) ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="notes" class="form-label">Catatan</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?= htmlspecialchars($transaction['notes']) ?></textarea>
                </div>
                
                <div class="mb-4">
                    <label class="form-label fw-semibold">Foto Bukti / Dokumen</label>
                    <div class="d-flex align-items-center gap-3">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="openCameraModalBtn">
                            <i class="bi bi-camera me-1"></i> Kamera
                        </button>
                        <input type="file" class="form-control form-control-sm w-auto" id="photoFileUpload" name="photo_upload" accept="image/*">
                    </div>
                    
                    <div id="photoPreviewSection" class="mt-2" style="display: <?= !empty($transaction['photo_url']) ? 'block' : 'none' ?>;">
                        <div class="d-inline-flex align-items-center p-2 border rounded">
                            <img id="photoPreviewImg" src="<?= !empty($transaction['photo_url']) ? (strpos($transaction['photo_url'], 'http') === 0 || strpos($transaction['photo_url'], '/') === 0 ? htmlspecialchars($transaction['photo_url']) : '/wms-geo/uploads/borrowed_photos/' . htmlspecialchars($transaction['photo_url'])) : '' ?>" alt="Preview" class="rounded me-2" style="height: 50px; width: 50px; object-fit: cover;">
                            <button type="button" class="btn btn-sm btn-link text-danger p-0" id="removePhotoBtn">Hapus</button>
                            <!-- Simpan base64 foto baru jika diambil dari kamera -->
                            <input type="hidden" id="cameraPhotoData" name="transaction_photo_data" value="">
                        </div>
                    </div>
                </div>
                <hr>
                <hr>
                <div class="alert alert-primary py-2 border-0 mb-4 d-flex align-items-center" style="background-color: #eef6ff;">
                    <div class="spinner-grow spinner-grow-sm text-primary me-2" role="status"></div>
                    <small class="fw-bold">Smart Scanner & Manual Search:</small>
                    <small class="ms-1 text-muted">Arahkan kursor ke input untuk scan atau ketik manual.</small>
                </div>

                <div class="row g-2 mb-4 p-3 rounded border bg-light">
                     <!-- Smart Scan Input -->
                     <div class="col-md-5">
                         <label class="small fw-bold text-muted mb-1">Scan Barcode / Serial Number</label>
                         <div class="input-group shadow-sm">
                             <span class="input-group-text bg-white"><i class="bi bi-upc-scan"></i></span>
                             <input type="text" id="smart_scan_input" class="form-control" placeholder="Scan Barcode (SN atau Produk Code)..." autocomplete="off">
                         </div>
                     </div>
                     <div class="col-md-7 position-relative">
                         <label class="small fw-bold text-muted mb-1">Cari Produk / Manual Select</label>
                         <div class="input-group shadow-sm">
                             <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                             <input type="text" class="form-control border-start-0" id="product_input" placeholder="Atau Cari Produk Manual (Ketik Kode/Nama)..." autocomplete="off">
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
                        <i class="bi bi-cloud-check me-2"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
                <div class="input-group input-group-sm mb-3">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" id="serialSearchInput" class="form-control" placeholder="Cari Serial Number...">
                </div>
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
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
                    alert(message); 
                }
            }

            // --- ELEMEN UTAMA ---
            const whId = "<?= $warehouse_id ?>"; // Di-lock dari backend PHP
            const smartScanInput = document.getElementById('smart_scan_input');
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

            // LOAD EXISTING ITEMS FROM DATABASE PHP INJECTION
            let transactionItems = <?= $transaction_items_json ?>;
            let tempProductData = null; 
            let availableSerialsData = [];

            // =================================================================
            // 1. SMART SCANNER HARDWARE
            // =================================================================
            let barcodeBuffer = '';
            let lastKeyTime = Date.now();

            document.addEventListener('keydown', function(e) {
                const active = document.activeElement;
                if (active.tagName === 'TEXTAREA' || (active.tagName === 'INPUT' && active !== smartScanInput)) return;

                const now = Date.now();
                if (now - lastKeyTime > 50) barcodeBuffer = '';
                lastKeyTime = now;

                if (e.key === 'Enter') {
                    if (barcodeBuffer.length >= 3) {
                        e.preventDefault();
                        handleSmartScan(barcodeBuffer.trim());
                        barcodeBuffer = '';
                    } else if (active === smartScanInput && smartScanInput.value.length >= 3) {
                        e.preventDefault();
                        handleSmartScan(smartScanInput.value.trim());
                        smartScanInput.value = '';
                    }
                } else if (e.key.length === 1) {
                    barcodeBuffer += e.key;
                }
            });

            if (smartScanInput) {
                smartScanInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const barcode = this.value.trim();
                        if (barcode.length >= 3) {
                            handleSmartScan(barcode);
                            this.value = '';
                        }
                    }
                });
            }

            async function handleSmartScan(barcode) {
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
                    fetch(`search_products.php?q=${encodeURIComponent(term)}&warehouse_id=${whId}`)
                        .then(res => res.json())
                        .then(data => {
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

                try {
                    const res = await fetch(`search_serial.php?product_id=${product.id}&warehouse_id=${whId}`);
                    const data = await res.json();
                    
                    if (data.results) {
                        const inCart = transactionItems.filter(i => i.product_id === product.id).map(i => i.serial_number);
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

            if (openCameraModalBtn) {
                openCameraModalBtn.addEventListener('click', () => {
                    cameraModal.show();
                    startCamera('user');
                });
            }

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
                    photoFileUpload.value = ''; 
                }
                cameraModal.hide();
            });

            if (removePhotoBtn) {
                removePhotoBtn.addEventListener('click', () => {
                    cameraPhotoData.value = '';
                    photoPreviewImg.src = '';
                    photoPreviewSection.style.display = 'none';
                });
            }

            if (photoFileUpload) {
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
            }

            window.updateItemQty = function(index, newQty) {
                const qty = parseInt(newQty);
                if (qty > 0) {
                    transactionItems[index].quantity = qty;
                    itemsJsonInput.value = JSON.stringify(transactionItems);
                }
            };

            window.removeItem = function(index) {
                if (confirm("Hapus item ini?")) {
                    transactionItems.splice(index, 1);
                    renderItemsTable();
                }
            };

            renderItemsTable();
        });
</script>

</body>
</html>