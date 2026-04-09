<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($transaction_id == 0) {
    header("Location: index.php");
    exit();
}

$message = '';
$message_type = '';

// --- 1. Ambil data utama transaksi dan detailnya (TERMASUK proof_photo_path) ---
// Tambahkan filter tenant dan warehouse untuk keamanan
$transaction_query = "SELECT * FROM inbound_transactions WHERE id = ? AND is_deleted = 0";
$stmt = $conn->prepare($transaction_query);
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();
$stmt->close();

$warehouse_id = (int)$transaction['warehouse_id'];

if (!$transaction) {
    header("Location: index.php");
    exit();
}

// Kueri detail transaksi diperbarui untuk menyertakan has_serial - filter by tenant and warehouse via JOIN
$details_query = "SELECT td.id, td.product_id, td.quantity, td.serial_number, p.product_code, p.product_name, p.has_serial 
                  FROM inbound_transaction_details td 
                  JOIN products p ON td.product_id = p.id 
                  JOIN inbound_transactions t ON td.transaction_id = t.id
                  WHERE td.transaction_id = ?";
$stmt_details = $conn->prepare($details_query);
$stmt_details->bind_param("i", $transaction_id);
$stmt_details->execute();
$details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();

// --- 2. Proses formulir POST saat data disubmit ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sender = trim($_POST['sender']);
    $transaction_date = trim($_POST['transaction_date']);
    $transaction_type = trim($_POST['transaction_type']);
    $document_number = trim($_POST['document_number']);
    // --- AMBIL NILAI reference DARI FORM ---
    $reference = trim($_POST['reference']);
    $notes = trim($_POST['notes']);
    $raw_items = json_decode($_POST['items_json'], true) ?? [];
    $items_json = [];
    foreach ($raw_items as $itm) {
        $has_serial = isset($itm['has_serial']) && filter_var($itm['has_serial'], FILTER_VALIDATE_BOOLEAN);
        if ($has_serial) {
            $serial_val = trim($itm['serial_number'] ?? '');
            if ($serial_val !== '' && $serial_val !== '-') {
                $items_json[] = $itm;
            }
        } else {
            if (isset($itm['quantity']) && (int)$itm['quantity'] > 0) {
                $items_json[] = $itm;
            }
        }
    }
    if (empty($sender) || empty($transaction_date) || empty($transaction_type) || empty($items_json)) {
        $message = 'Mohon lengkapi semua data transaksi dan tambahkan setidaknya satu item.';
        $message_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            // A. Perbarui data header transaksi
            $total_items = count($items_json);
            // --- PERBARUI QUERY UNTUK MENYIMPAN document_number DAN reference ---
            $update_sql = "UPDATE inbound_transactions SET transaction_date = ?, sender = ?, transaction_type = ?, document_number = ?, reference = ?, notes = ?, total_items = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update = $conn->prepare($update_sql);
            $stmt_update->bind_param("ssssssii", $transaction_date, $sender, $transaction_type, $document_number, $reference, $notes, $total_items, $transaction_id);
            if (!$stmt_update->execute()) {
                throw new Exception("Gagal memperbarui header transaksi.");
            }
            $stmt_update->close();

            // B. Ambil kembali detail transaksi yang ada untuk perbandingan (termasuk has_serial)
            $existing_details_query = "SELECT td.id, td.product_id, td.quantity, td.serial_number, p.has_serial FROM inbound_transaction_details td JOIN products p ON td.product_id = p.id WHERE td.transaction_id = ?";
            $stmt_existing = $conn->prepare($existing_details_query);
            $stmt_existing->bind_param("i", $transaction_id);
            $stmt_existing->execute();
            $existing_details_result = $stmt_existing->get_result();
            $existing_details = $existing_details_result->fetch_all(MYSQLI_ASSOC);
            $stmt_existing->close();
            
            $existing_items_map = [];
            foreach ($existing_details as $item) {
                $existing_items_map[$item['id']] = $item;
            }

            $updated_items_ids = [];

            // Proses item yang ada dan item baru
            foreach ($items_json as $item) {
                $product_id = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                $serial_number = $item['serial_number'] ?? NULL;

                if (isset($item['id'])) { // Item yang sudah ada
                    $updated_items_ids[] = (int)$item['id'];
                    $old_item = $existing_items_map[$item['id']];
                    $old_quantity = (int)$old_item['quantity'];
                    
                    if ($old_item['product_id'] != $product_id) {
                        throw new Exception("Perubahan produk pada item yang sudah ada tidak diizinkan.");
                    }

                    // Perubahan Kuantitas atau Serial Number
                    if ($old_quantity != $quantity || ($old_item['serial_number'] != $serial_number)) {
                        $stock_diff = $quantity - $old_quantity;
                        $update_stock_sql = "UPDATE warehouse_stocks SET stock = stock + ? WHERE product_id = ? AND warehouse_id = ?";
                        $stmt_stock = $conn->prepare($update_stock_sql);
                        $stmt_stock->bind_param("iii", $stock_diff, $product_id, $warehouse_id);
                        if (!$stmt_stock->execute()) {
                            throw new Exception("Gagal menyesuaikan stok produk.");
                        }
                        $stmt_stock->close();
                        
                        // Update PO Received Quantity
                        if ($transaction['transaction_type'] === 'RI' && !empty($transaction['document_number'])) {
                            $po_id_query = $conn->query("SELECT id FROM purchase_orders WHERE po_number = '{$transaction['document_number']}'");
                            if ($po_id_query && $po_id_row = $po_id_query->fetch_assoc()) {
                                $po_id = $po_id_row['id'];
                                $update_po_item_sql = "UPDATE po_items SET received_quantity = received_quantity + ? WHERE po_id = ? AND product_id = ?";
                                $stmt_po_qty = $conn->prepare($update_po_item_sql);
                                $stmt_po_qty->bind_param("iii", $stock_diff, $po_id, $product_id);
                                $stmt_po_qty->execute();
                                $stmt_po_qty->close();
                            }
                        }

                        $update_detail_sql = "UPDATE inbound_transaction_details SET quantity = ?, serial_number = ? WHERE id = ?";
                        $stmt_detail = $conn->prepare($update_detail_sql);
                        $stmt_detail->bind_param("isi", $quantity, $serial_number, $item['id']);
                        if (!$stmt_detail->execute()) {
                            throw new Exception("Gagal memperbarui detail transaksi.");
                        }
                        $stmt_detail->close();

                        // Logika untuk tabel serial_numbers
                        if ((int)$old_item['has_serial'] === 1 && $old_item['serial_number'] !== $serial_number) {
                            // Cek apakah serial number baru sudah ada
                            if ($serial_number !== NULL) {
                                $check_serial_sql = "SELECT COUNT(*) FROM serial_numbers WHERE serial_number = ?";
                                $stmt_check = $conn->prepare($check_serial_sql);
                                $stmt_check->bind_param("s", $serial_number);
                                $stmt_check->execute();
                                $serial_exists = $stmt_check->get_result()->fetch_row()[0] > 0;
                                $stmt_check->close();
                                if ($serial_exists) {
                                    throw new Exception("Serial number '{$serial_number}' sudah ada di database.");
                                }
                            }
                            
                             // Hapus serial lama
                            $delete_serial_sql = "DELETE FROM serial_numbers WHERE serial_number = ? AND product_id = ?";
                            $stmt_del_serial = $conn->prepare($delete_serial_sql);
                            $stmt_del_serial->bind_param("si", $old_item['serial_number'], $product_id);
                            if (!$stmt_del_serial->execute()) {
                                throw new Exception("Gagal menghapus serial number lama.");
                            }
                            $stmt_del_serial->close();

                            // Tambah serial baru
                            $insert_serial_sql = "INSERT INTO serial_numbers (serial_number, product_id, warehouse_id, status) VALUES (?, ?, ?, 'Tersedia')";
                            $stmt_ins_serial = $conn->prepare($insert_serial_sql);
                            $stmt_ins_serial->bind_param("sii", $serial_number, $product_id, $warehouse_id);
                            if (!$stmt_ins_serial->execute()) {
                                throw new Exception("Gagal menambahkan serial number baru.");
                            }
                            $stmt_ins_serial->close();
                        }
                    }
                } else { // Item baru
                    $insert_detail_sql = "INSERT INTO inbound_transaction_details (transaction_id, product_id, quantity, serial_number, created_at) VALUES (?, ?, ?, ?, NOW())";
                    $stmt_new_detail = $conn->prepare($insert_detail_sql);
                    $stmt_new_detail->bind_param("iiis", $transaction_id, $product_id, $quantity, $serial_number);
                    if (!$stmt_new_detail->execute()) {
                        throw new Exception("Gagal menambahkan item baru.");
                    }
                    $stmt_new_detail->close();

                    $update_stock_sql = "INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock = stock + ?";
                    $stmt_stock = $conn->prepare($update_stock_sql);
                    $stmt_stock->bind_param("iiii", $product_id, $warehouse_id, $quantity, $quantity);
                    if (!$stmt_stock->execute()) {
                        throw new Exception("Gagal memperbarui stok untuk item baru.");
                    }
                    $stmt_stock->close();

                    // Update PO Received Quantity
                    if ($transaction['transaction_type'] === 'RI' && !empty($transaction['document_number'])) {
                        $po_id_query = $conn->query("SELECT id FROM purchase_orders WHERE po_number = '{$transaction['document_number']}'");
                        if ($po_id_query && $po_id_row = $po_id_query->fetch_assoc()) {
                            $po_id = $po_id_row['id'];
                            $update_po_item_sql = "UPDATE po_items SET received_quantity = received_quantity + ? WHERE po_id = ? AND product_id = ?";
                            $stmt_po_qty = $conn->prepare($update_po_item_sql);
                            $stmt_po_qty->bind_param("iii", $quantity, $po_id, $product_id);
                            $stmt_po_qty->execute();
                            $stmt_po_qty->close();
                        }
                    }

                    // Logika untuk tabel serial_numbers (item baru)
                    if ($serial_number !== NULL) {
                        // Cek apakah serial number sudah ada
                        $check_serial_sql = "SELECT COUNT(*) FROM serial_numbers WHERE serial_number = ?";
                        $stmt_check = $conn->prepare($check_serial_sql);
                        $stmt_check->bind_param("s", $serial_number);
                        $stmt_check->execute();
                        $serial_exists = $stmt_check->get_result()->fetch_row()[0] > 0;
                        $stmt_check->close();
                        if ($serial_exists) {
                            throw new Exception("Serial number '{$serial_number}' sudah ada di database.");
                        }

                        $insert_serial_sql = "INSERT INTO serial_numbers (serial_number, product_id, warehouse_id, status) VALUES (?, ?, ?, 'Tersedia')";
                        $stmt_ins_serial = $conn->prepare($insert_serial_sql);
                        $stmt_ins_serial->bind_param("sii", $serial_number, $product_id, $warehouse_id);
                        if (!$stmt_ins_serial->execute()) {
                            throw new Exception("Gagal menambahkan serial number baru ke tabel serial_numbers.");
                        }
                        $stmt_ins_serial->close();
                    }
                }
            }

            // C. Hapus item yang tidak ada lagi di formulir
            $ids_to_delete = array_diff(array_keys($existing_items_map), $updated_items_ids);
            if (!empty($ids_to_delete)) {
                $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
                
                // Ambil kuantitas, product_id, dan has_serial dari item yang akan dihapus
                $delete_details_sql = "SELECT td.product_id, td.quantity, td.serial_number, p.has_serial FROM inbound_transaction_details td JOIN products p ON td.product_id = p.id WHERE td.id IN ($placeholders)";
                $stmt_delete_details = $conn->prepare($delete_details_sql);
                $types = str_repeat('i', count($ids_to_delete));
                $stmt_delete_details->bind_param($types, ...$ids_to_delete);
                $stmt_delete_details->execute();
                $deleted_items = $stmt_delete_details->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_delete_details->close();
                
                // Kurangi stok dan hapus serial untuk item yang dihapus
                foreach ($deleted_items as $del_item) {
                    $update_stock_sql = "UPDATE warehouse_stocks SET stock = stock - ? WHERE product_id = ? AND warehouse_id = ?";
                    $stmt_stock_del = $conn->prepare($update_stock_sql);
                    $stmt_stock_del->bind_param("iii", $del_item['quantity'], $del_item['product_id'], $warehouse_id);
                    if (!$stmt_stock_del->execute()) {
                        throw new Exception("Gagal mengurangi stok produk untuk item yang dihapus.");
                    }
                    $stmt_stock_del->close();
                    
                    // Update PO Received Quantity
                    if ($transaction['transaction_type'] === 'RI' && !empty($transaction['document_number'])) {
                        $po_id_query = $conn->query("SELECT id FROM purchase_orders WHERE po_number = '{$transaction['document_number']}'");
                        if ($po_id_query && $po_id_row = $po_id_query->fetch_assoc()) {
                            $po_id = $po_id_row['id'];
                            $update_po_item_sql = "UPDATE po_items SET received_quantity = received_quantity - ? WHERE po_id = ? AND product_id = ?";
                            $stmt_po_qty = $conn->prepare($update_po_item_sql);
                            $stmt_po_qty->bind_param("iii", $del_item['quantity'], $po_id, $del_item['product_id']);
                            $stmt_po_qty->execute();
                            $stmt_po_qty->close();
                        }
                    }
                    
                    if ((int)$del_item['has_serial'] === 1) {
                        $delete_serial_sql = "DELETE FROM serial_numbers WHERE serial_number = ? AND product_id = ?";
                        $stmt_del_serial = $conn->prepare($delete_serial_sql);
                        $stmt_del_serial->bind_param("si", $del_item['serial_number'], $del_item['product_id']);
                        if (!$stmt_del_serial->execute()) {
                            throw new Exception("Gagal menghapus serial number dari tabel serial_numbers.");
                        }
                        $stmt_del_serial->close();
                    }
                }
                
                // Hapus item dari tabel detail
                $delete_sql = "DELETE FROM inbound_transaction_details WHERE id IN ($placeholders)";
                $stmt_delete = $conn->prepare($delete_sql);
                $stmt_delete->bind_param($types, ...$ids_to_delete);
                if (!$stmt_delete->execute()) {
                    throw new Exception("Gagal menghapus item dari detail transaksi.");
                }
                $stmt_delete->close();
            }

            // --- Bagian untuk memperbarui foto bukti ---
            if (isset($_POST['image_data']) && !empty($_POST['image_data'])) {
                $imageData = $_POST['image_data'];
                list($type, $imageData) = explode(';', $imageData);
                list(, $imageData) = explode(',', $imageData);
                $imageData = base64_decode($imageData);

                $photo_dir = '../../uploads/inbound_photos/';
                if (!is_dir($photo_dir)) {
                    mkdir($photo_dir, 0755, true);
                }

                $filename = 'inbound_' . $transaction_id . '_' . uniqid() . '.png';
                $filePath = $photo_dir . $filename;
                
                // Hapus foto lama jika ada
                if (!empty($transaction['proof_photo_path']) && file_exists($transaction['proof_photo_path'])) {
                    unlink($transaction['proof_photo_path']);
                }

                if (file_put_contents($filePath, $imageData)) {
                    $update_photo_sql = "UPDATE inbound_transactions SET proof_photo_path = ? WHERE id = ?";
                    $stmt_photo = $conn->prepare($update_photo_sql);
                    $stmt_photo->bind_param("si", $filePath, $transaction_id);
                    $stmt_photo->execute();
                    $stmt_photo->close();
                }
            }

            $conn->commit();
            header("Location: index.php?status=success_edit");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}
?>

<style>
    /* CSS untuk modal kamera */
    .camera-modal {
        display: none;
        position: fixed;
        z-index: 1050;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.8);
    }
    .modal-content-camera {
        position: relative;
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border-radius: 10px;
        width: 90%;
        max-width: 600px;
        text-align: center;
    }
    .camera-buttons {
        margin-top: 10px;
        display: flex;
        justify-content: center;
        gap: 10px;
    }
    #video-stream, #photo-preview {
        width: 100%;
        height: auto;
        border-radius: 8px;
        background: #000;
    }
</style>

<div class="content-header mb-4">
    <h1 class="fw-bold">Edit Transaksi Masuk</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Transaksi #<?= htmlspecialchars($transaction['transaction_number']) ?></h5>
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

        <form id="inbound-form" action="edit.php?id=<?= $transaction['id'] ?>" method="POST">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="transaction_date" class="form-label">Tanggal Transaksi</label>
                    <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?= htmlspecialchars($transaction['transaction_date']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="transaction_type" class="form-label">Tipe Transaksi</label>
                    <select class="form-select" id="transaction_type" name="transaction_type" required>
                        <option value="RI" <?= $transaction['transaction_type'] == 'RI' ? 'selected' : '' ?>>RECEIVE ITEMS</option>
                        <option value="RET" <?= $transaction['transaction_type'] == 'RET' ? 'selected' : '' ?>>RETURN</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="sender" class="form-label">Nama Vendor</label>
                    <input type="text" class="form-control" id="sender" name="sender" value="<?= htmlspecialchars($transaction['sender']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="transaction_number" class="form-label">Nomor Transaksi</label>
                    <input type="text" class="form-control" id="transaction_number" value="<?= htmlspecialchars($transaction['transaction_number']) ?>" readonly>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label id="document_label" for="document_number" class="form-label">Nomor Dokumen</label>
                    <input type="text" class="form-control" id="document_number" name="document_number" value="<?= htmlspecialchars($transaction['document_number']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="reference" class="form-label">Referensi</label>
                    <input type="text" class="form-control" id="reference" name="reference" value="<?= htmlspecialchars($transaction['reference']) ?>">
                </div>
            </div>
            <div class="mb-3">
                <label for="notes" class="form-label">Catatan</label>
                <textarea class="form-control" id="notes" name="notes" rows="3"><?= htmlspecialchars($transaction['notes']) ?></textarea>
            </div>
            
            <hr>
            
            <h5 class="fw-bold mt-4">Bukti Foto</h5>
            <div class="mb-3 text-center">
                <?php if (!empty($transaction['proof_photo_path'])): ?>
                    <p class="text-muted">Foto saat ini:</p>
                    <img id="current-photo" src="<?= htmlspecialchars($transaction['proof_photo_path']) ?>" alt="Bukti Transaksi" class="img-fluid rounded-lg shadow-sm" style="max-height: 300px;">
                <?php else: ?>
                    <p class="text-muted" id="current-photo-placeholder">Belum ada foto bukti.</p>
                    <img id="current-photo" src="" alt="Bukti Transaksi" class="img-fluid rounded-lg shadow-sm" style="max-height: 300px; display: none;">
                <?php endif; ?>
            </div>
            <div class="d-flex justify-content-center mb-4 gap-2">
                <button type="button" class="btn btn-primary rounded-pill px-4" id="openCameraModalBtn">
                    <i class="bi bi-camera me-2"></i>Ambil Foto
                </button>
                <button type="button" class="btn btn-info rounded-pill px-4 text-white" id="openFileModalBtn">
                    <i class="bi bi-upload me-2"></i>Pilih Foto
                </button>
                <input type="file" id="fileInput" accept="image/*" style="display: none;">
            </div>
            
            <hr>

            <h5 class="fw-bold mt-4">Detail Produk</h5>
            <div class="card p-3 mb-3 bg-light">
                <div class="row">
                    <div class="col-md-6 mb-3 position-relative">
                        <label for="product_input" class="form-label">Cari Produk</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="product_input" placeholder="Ketik kode atau nama produk" autocomplete="off">
                            <button type="button" class="btn btn-outline-primary" id="load-po-items-btn" style="display: <?= $transaction['transaction_type'] == 'RI' && !empty($transaction['document_number']) ? 'block' : 'none' ?>;">
                                <i class="bi bi-cloud-download me-2"></i>Muat Sisa PO
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
                <div class="row" id="serial-section" style="display: none;">
                    <div class="col-md-12 mb-3">
                        <label for="item_serial_number" class="form-label">Serial Number</label>
                        <div class="input-group">
                            <button type="button" class="btn btn-outline-secondary" id="scan-barcode-btn"><i class="bi bi-upc-scan"></i></button>
                            <input type="text" class="form-control" id="item_serial_number" placeholder="Scan atau ketik serial number">
                            
                        </div>
                        <small class="text-muted" id="serial-info"></small>
                    </div>
                </div>
                <button type="button" class="btn btn-info rounded-pill px-4 mt-2" id="add-item-btn" disabled>
                    <i class="bi bi-plus me-2"></i>Tambahkan Item
                </button>
            </div>

            <input type="hidden" name="items_json" id="items_json">
            <input type="hidden" name="image_data" id="imageDataInput">
            
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
                <i class="bi bi-floppy-fill me-2"></i>Simpan Perubahan
            </button>
        </form>
    </div>
</div>

<div id="cameraModal" class="camera-modal">
    <div class="modal-content-camera">
        <span class="btn-close-modal d-flex justify-content-end mb-2">
            <button type="button" class="btn-close" aria-label="Close"></button>
        </span>
        <div class="camera-display">
            <video id="video-stream" autoplay playsinline></video>
            <canvas id="photo-preview" style="display:none;"></canvas>
        </div>
        <div class="camera-buttons">
            <button id="switchCameraBtn" class="btn btn-secondary rounded-pill px-4">
                <i class="bi bi-arrow-repeat me-2"></i>Ganti Kamera
            </button>
            <button id="capturePhotoBtn" class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-camera me-2"></i>Ambil Foto
            </button>
            <button id="retakePhotoBtn" class="btn btn-warning rounded-pill px-4" style="display: none;">
                <i class="bi bi-arrow-counterclockwise me-2"></i>Ulangi
            </button>
            <button id="savePhotoBtn" class="btn btn-success rounded-pill px-4" style="display: none;">
                <i class="bi bi-download me-2"></i>Simpan
            </button>
        </div>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="barcodeScannerModalLabel">Pemindai Barcode</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <!-- PENTING: video feed harus di dalam modal-body -->
            <div class="modal-body" style="position: relative; min-height: 300px;">
                <div id="barcode-video-feed" style="width: 100%; height: 300px; background: #000;">
                    <!-- Di sinilah Quagga akan memasukkan elemen <video> -->
                </div>
                <p id="barcode-result-display" class="text-center mt-3 text-success">Scanner Tidak Aktif.</p>
                <!-- Overlay box akan ditambahkan oleh JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Pastikan QuaggaJS (Library Barcode Scanner) tersedia
    if (typeof Quagga === 'undefined') {
        console.error("QuaggaJS library is not loaded. Please include the script tag for Quagga.");
        return;
    }
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
    const scanBarcodeBtn = document.getElementById('scan-barcode-btn');
    
    // --- Barcode Scanner Elements ---
    const barcodeScannerModalElement = document.getElementById('barcodeScannerModal');
    const barcodeScannerModal = barcodeScannerModalElement ? new bootstrap.Modal(barcodeScannerModalElement) : null; 
    const barcodeVideoFeed = document.getElementById('barcode-video-feed');
    const barcodeResultDisplay = document.getElementById('barcode-result-display');
    const modalBody = barcodeScannerModalElement ? barcodeScannerModalElement.querySelector('.modal-body') : null;
    
    // Variabel Global Tambahan untuk Mengelola Mode Scan
    let scanTarget = null; // null = mode tambah (default); 'edit-serial-index-X' = mode edit
    let isEditing = false; // Flag untuk status apakah ada item yang sedang diedit di tabel
    
    // Overlay box untuk barcode (Dibiarkan sama)
    const overlayBox = document.createElement('div');
    overlayBox.id = 'barcode-overlay-box';
    overlayBox.style.position = 'absolute';
    overlayBox.style.border = '2px dashed red';
    overlayBox.style.width = '60%';
    overlayBox.style.height = '30%';
    overlayBox.style.top = '35%';
    overlayBox.style.left = '20%';
    overlayBox.style.pointerEvents = 'none';
    overlayBox.style.zIndex = '10';

    if (modalBody && !document.getElementById('barcode-overlay-box')) {
        modalBody.style.position = 'relative';
        modalBody.style.minHeight = '0'; 
        modalBody.appendChild(overlayBox);
    }
    
    // Data awal dari PHP (asumsi ada)
    const initialItems = <?= json_encode($details) ?>;
    let transactionItems = initialItems.map(item => ({
        id: item.id,
        product_id: parseInt(item.product_id),
        product_code: item.product_code,
        product_name: item.product_name,
        has_serial: parseInt(item.has_serial),
        quantity: parseInt(item.quantity),
        serial_number: item.serial_number || null,
    }));
    
    let selectedProduct = null;
    let productsStock = {}; 

    // --- LOGIKA SCANNER HARDWARE (GLOBAL LISTENER) ---
    let barcodeBuffer = "";
    let lastKeyTime = Date.now();

    document.addEventListener('keydown', function(e) {
        const activeElem = document.activeElement;
        
        // Abaikan jika user sedang mengetik manual di kolom input teks tertentu
        const isManualInput = activeElem.id === 'notes' || 
                             activeElem.id === 'product_input' ||
                             activeElem.id === 'item_serial_number' ||
                             activeElem.id === 'item_quantity' ||
                             activeElem.id === 'document_number' ||
                             activeElem.id === 'reference' ||
                             activeElem.id === 'sender' ||
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
        const emptyIndex = transactionItems.findIndex(item => item.has_serial && (!item.serial_number || item.serial_number === '' || item.serial_number === '-'));
        
        if (emptyIndex !== -1) {
            playBeep();
            
            // Verifikasi duplikat internal sebelum memasukkan
            const isInternalDuplicate = transactionItems.some((it, idx) => it.serial_number === code && idx !== emptyIndex);
            if (isInternalDuplicate) {
                alert('Tolak: Serial [' + code + '] sudah ada di dalam daftar transaksi ini.');
                return;
            }

            // Verifikasi DB (API check_serial_number.php) 
            try {
                const response = await fetch(`check_serial_number.php?serial=${encodeURIComponent(code)}&product_id=${transactionItems[emptyIndex].product_id}`);
                const data = await response.json();
                if (data.exists) {
                    alert(`Tolak: Serial [${code}] sudah terdaftar di sistem database gudang.`);
                    return;
                }
            } catch (e) {
                console.error("Gagal memvalidasi serial ke server", e);
            }

            // Jika lulus, simpan ke array
            transactionItems[emptyIndex].serial_number = code;
            
            // Render ulang tabel untuk memperbarui tampilan visual
            renderItemsTable();
            
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
                alert('Info: Serial ' + code + ' sudah berhasil di-scan sebelumnya.');
            } else {
                alert('Penuh: Tidak ada slot barang berserial yang kosong untuk diisi barcode ini.');
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

    // Fungsi utilitas untuk mendeteksi perangkat mobile (Dibiarkan sama)
    function isMobileDevice() {
        return window.innerWidth < 768; 
    }

    // ==========================================================
    // BAGIAN LOGIKA KAMERA (Dibiarkan sama)
    // ==========================================================
    const cameraModal = document.getElementById('cameraModal');
    const openCameraModalBtn = document.getElementById('openCameraModalBtn');
    const openFileModalBtn = document.getElementById('openFileModalBtn');
    const closeCameraBtn = cameraModal ? cameraModal.querySelector('.btn-close') : null;
    const videoStream = document.getElementById('video-stream');
    const photoPreview = document.getElementById('photo-preview');
    const switchCameraBtn = document.getElementById('switchCameraBtn');
    const capturePhotoBtn = document.getElementById('capturePhotoBtn');
    const retakePhotoBtn = document.getElementById('retakePhotoBtn');
    const savePhotoBtn = document.getElementById('savePhotoBtn');
    const imageDataInput = document.getElementById('imageDataInput');
    const currentPhoto = document.getElementById('current-photo');
    const currentPhotoPlaceholder = document.getElementById('current-photo-placeholder');
    const fileInput = document.getElementById('fileInput');

    let currentStream = null;
    let isFrontCamera = false; 

    async function startCamera(facingMode) {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
        }
        try {
            const constraints = { video: { facingMode: facingMode ? 'user' : 'environment' } };
            currentStream = await navigator.mediaDevices.getUserMedia(constraints);
            videoStream.srcObject = currentStream;
            videoStream.style.transform = facingMode ? 'scaleX(-1)' : 'scaleX(1)'; 
            videoStream.style.display = 'block';
            photoPreview.style.display = 'none';
            capturePhotoBtn.style.display = 'inline-block';
            retakePhotoBtn.style.display = 'none';
            savePhotoBtn.style.display = 'none';
        } catch (err) {
            console.error("Gagal mengakses kamera: ", err);
            const errorMessage = "Tidak dapat mengakses kamera. Pastikan Anda telah memberikan izin dan koneksi aman (HTTPS/localhost).";
            alert(errorMessage); 
        }
    }

    if (openCameraModalBtn) {
        openCameraModalBtn.addEventListener('click', () => {
            if (cameraModal) {
                cameraModal.style.display = 'block';
                startCamera(isFrontCamera);
            }
        });
    }

    if (openFileModalBtn) {
        openFileModalBtn.addEventListener('click', () => {
            if (fileInput) {
                fileInput.click();
            }
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const imageDataUrl = e.target.result;
                    if (imageDataInput) imageDataInput.value = imageDataUrl;
                    if (currentPhoto) currentPhoto.src = imageDataUrl;
                    if (currentPhoto) currentPhoto.style.display = 'block';
                    if (currentPhotoPlaceholder) {
                        currentPhotoPlaceholder.style.display = 'none';
                    }
                    alert('Foto berhasil disimpan dari file dan akan diunggah saat Anda menekan tombol "Simpan Perubahan".');
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (closeCameraBtn) {
        closeCameraBtn.addEventListener('click', () => {
            if (cameraModal) cameraModal.style.display = 'none';
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
            }
        });
    }

    if (switchCameraBtn) {
        switchCameraBtn.addEventListener('click', () => {
            isFrontCamera = !isFrontCamera;
            startCamera(isFrontCamera);
        });
    }

    if (capturePhotoBtn) {
        capturePhotoBtn.addEventListener('click', () => {
            if (!videoStream || !photoPreview) return;
            const context = photoPreview.getContext('2d');
            photoPreview.width = videoStream.videoWidth;
            photoPreview.height = videoStream.videoHeight;
            context.save();
            if (isFrontCamera) {
                context.scale(-1, 1); 
                context.drawImage(videoStream, -photoPreview.width, 0, photoPreview.width, photoPreview.height);
            } else {
                context.drawImage(videoStream, 0, 0, photoPreview.width, photoPreview.height);
            }
            context.restore();

            videoStream.style.display = 'none';
            photoPreview.style.display = 'block';
            capturePhotoBtn.style.display = 'none';
            retakePhotoBtn.style.display = 'inline-block';
            savePhotoBtn.style.display = 'inline-block';
        });
    }
    
    if (retakePhotoBtn) {
        retakePhotoBtn.addEventListener('click', () => {
            videoStream.style.display = 'block';
            photoPreview.style.display = 'none';
            capturePhotoBtn.style.display = 'inline-block';
            retakePhotoBtn.style.display = 'none';
            savePhotoBtn.style.display = 'none';
            if (imageDataInput) imageDataInput.value = ''; 
        });
    }

    if (savePhotoBtn) {
        savePhotoBtn.addEventListener('click', () => {
            if (!photoPreview) return;
            const imageDataUrl = photoPreview.toDataURL('image/png');
            if (imageDataInput) imageDataInput.value = imageDataUrl;
            
            if (currentPhoto) {
                currentPhoto.src = imageDataUrl;
                currentPhoto.style.display = 'block';
            }
            if (currentPhotoPlaceholder) {
                currentPhotoPlaceholder.style.display = 'none';
            }

            if (cameraModal) cameraModal.style.display = 'none';
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
            }
            alert('Foto berhasil disimpan dan akan diunggah saat Anda menekan tombol "Simpan Perubahan".');
        });
    }

    // ==========================================================
    // BAGIAN LOGIKA BARCODE SCANNER BARU
    // ==========================================================

    function startBarcodeScanner() {
        if (!barcodeVideoFeed) {
            console.error("Elemen barcode-video-feed tidak ditemukan.");
            if (barcodeResultDisplay) barcodeResultDisplay.textContent = "Error: Elemen video feed tidak ditemukan.";
            return;
        }

        barcodeVideoFeed.innerHTML = ''; // Kosongkan
        barcodeVideoFeed.style.width = '100%';
        barcodeVideoFeed.style.height = 'auto'; 
        barcodeVideoFeed.style.overflow = 'hidden';

        const preferredFacingMode = isMobileDevice() ? "environment" : "environment";

        Quagga.init({
            inputStream: {
                name: "Live",
                type: "LiveStream",
                target: barcodeVideoFeed, 
                constraints: {
                    facingMode: preferredFacingMode 
                }
            },
            decoder: {
                readers: ["code_128_reader", "ean_reader", "ean_8_reader", "code_39_reader", "code_39_vin_reader", "codabar_reader", "upc_reader", "upc_e_reader", "i2of5_reader"]
            },
            locate: true, 
            locator: {
                patchSize: "medium",
                halfSample: true
            }
        }, function(err) {
            if (err) {
                console.error("Gagal inisiasi Quagga:", err);
                if (barcodeResultDisplay) barcodeResultDisplay.textContent = "Error: Gagal mengakses kamera. Cek izin dan koneksi aman (HTTPS).";
                return;
            }
            Quagga.start();
            if (barcodeResultDisplay) {
                barcodeResultDisplay.textContent = (scanTarget && scanTarget.startsWith('edit-')) ? 
                    "Scanner Aktif (Mode Edit). Arahkan kamera ke Serial Number..." : 
                    "Scanner Aktif (Mode Tambah). Arahkan kamera ke Barcode Produk...";
            }

            const videoElement = barcodeVideoFeed.querySelector('video');
            const canvasElement = barcodeVideoFeed.querySelector('canvas');
            
            if (canvasElement) {
                canvasElement.style.width = '100%';
                canvasElement.style.height = '100%';
                canvasElement.style.position = 'absolute';
                canvasElement.style.top = '0';
                canvasElement.style.left = '0';
            }
            
            // Atur ulang posisi overlay box 
            if (modalBody && barcodeVideoFeed.offsetHeight > 0) {
                 const videoHeight = barcodeVideoFeed.offsetHeight;
                 const videoWidth = barcodeVideoFeed.offsetWidth;
                 
                 overlayBox.style.top = `${videoHeight * 0.35}px`;
                 overlayBox.style.left = `${videoWidth * 0.20}px`;
            }
        });

        // =========================================================================
        // LOGIKA UTAMA ON DETECTED (MODIFIKASI)
        // =========================================================================
        Quagga.onDetected(function(result) {
            if (result && result.codeResult && result.codeResult.code) {
                const code = result.codeResult.code;
                
                // Hentikan scanner segera setelah terdeteksi
                stopBarcodeScanner();
                
                // --- KASUS 1: MODE EDIT SERIAL NUMBER ---
                if (scanTarget && scanTarget.startsWith('edit-')) {
                    const index = scanTarget.split('-')[1];
                    const row = itemsTableBody.querySelector(`tr[data-index="${index}"]`);
                    
                    if (row) {
                        const serialInput = row.querySelector('.item-serial input');
                        if (serialInput) {
                            // Scanner sudah dihentikan di atas
                            serialInput.value = code; // Isi input Serial Number yang sedang diedit
                            if (barcodeResultDisplay) barcodeResultDisplay.textContent = `Serial ${code} dimasukkan ke item #${index}.`;
                            if (barcodeScannerModal) barcodeScannerModal.hide(); 
                            scanTarget = null; // Reset
                            return;
                        }
                    }
                }
                
                // --- KASUS 2: MODE TAMBAH ITEM BARU (Barcode Produk/Serial) ---
                
                // Cek apakah ada produk yang sudah dipilih di form utama
                if (!selectedProduct) {
                    if (barcodeResultDisplay) barcodeResultDisplay.textContent = `Barcode ${code} terdeteksi, tetapi produk belum dipilih di formulir.`;
                    // Lanjutkan scanning setelah jeda singkat
                    setTimeout(() => { Quagga.start(); }, 1500); 
                    return;
                }
                
                const hasSerial = parseInt(selectedProduct.has_serial) === 1;

                if (hasSerial) {
                    // Produk butuh Serial Number: Barcode yang discan adalah Serial Number
                    
                    // Cek duplikasi Serial Number sebelum penambahan (Opsional, tapi disarankan)
                    const isDuplicateSerial = transactionItems.some(item => 
                        item.product_id === parseInt(selectedProduct.id) && 
                        item.serial_number === code
                    );

                    if (isDuplicateSerial) {
                        if (barcodeResultDisplay) barcodeResultDisplay.textContent = `Serial ${code} sudah ada di daftar item!`;
                        setTimeout(() => { Quagga.start(); }, 1500);
                        return;
                    }
                    
                    // Lanjutkan penambahan
                    itemSerialNumber.value = code;
                    itemQuantity.value = 1;
                    addItemToTable(); // Tambahkan item
                    
                    if (barcodeResultDisplay) barcodeResultDisplay.textContent = `Serial ${code} berhasil ditambahkan!`;
                    clearItemForm();
                    if (barcodeScannerModal) barcodeScannerModal.hide(); 
                    
                } else {
                    // Produk non-Serial: Tambah 1 ke kuantitas
                    const existingItemIndex = transactionItems.findIndex(item => 
                        item.product_id === parseInt(selectedProduct.id) && 
                        item.has_serial === 0
                    );
                    
                    if (existingItemIndex > -1) {
                        // Item sudah ada, tambahkan kuantitas
                        transactionItems[existingItemIndex].quantity += 1;
                        renderItemsTable();
                        if (barcodeResultDisplay) barcodeResultDisplay.textContent = `Kuantitas produk ${selectedProduct.product_code} ditambah 1.`;
                    } else {
                        // Item belum ada, tambahkan baris baru
                        itemQuantity.value = 1; 
                        itemSerialNumber.value = '';
                        addItemToTable(); 
                        if (barcodeResultDisplay) barcodeResultDisplay.textContent = `Produk ${selectedProduct.product_code} berhasil ditambahkan!`;
                    }
                    
                    clearItemForm(); 
                    if (barcodeScannerModal) barcodeScannerModal.hide(); 
                }

            } else {
                // Barcode tidak terdeteksi (QuaggaJS failed to decode)
                // Biarkan Quagga terus berjalan atau berikan feedback
                if (barcodeResultDisplay) barcodeResultDisplay.textContent = "Gagal membaca barcode. Coba lagi.";
                // Karena stopBarcodeScanner() sudah dipanggil di awal, jika deteksi gagal, kita start lagi.
                setTimeout(() => { Quagga.start(); }, 500);
            }
        });
        // =========================================================================
        
        Quagga.onProcessed(function(result) {
            const drawingCtx = Quagga.canvas.ctx.overlay;
            const drawingCanvas = Quagga.canvas.dom.overlay;

            if (result) {
                // ... (Logika drawing QuaggaJS)
            }
        });
    }

    function stopBarcodeScanner() {
        if (typeof Quagga !== 'undefined') {
            Quagga.stop();
        }
    }
    
    // ==========================================================
    // EVENT LISTENER BARCODE SCANNER
    // ==========================================================
    if (scanBarcodeBtn) {
        scanBarcodeBtn.addEventListener('click', () => {
            if (barcodeScannerModal) {
                // Jika sedang mengedit item, tombol scan utama di-disable sementara
                if (isEditing) {
                    alert('Silakan simpan atau batalkan item yang sedang diedit di tabel sebelum menambahkan item baru.');
                    return;
                }
                scanTarget = null; // Pastikan mode kembali ke 'add' saat tombol utama diklik
                barcodeScannerModal.show();
            }
        });
    }
    
    if (barcodeScannerModalElement) {
        barcodeScannerModalElement.addEventListener('shown.bs.modal', function () {
            // Beri jeda agar modal benar-benar tampil sebelum inisialisasi kamera
            setTimeout(startBarcodeScanner, 500); 
        });
        
        barcodeScannerModalElement.addEventListener('hidden.bs.modal', function () {
            stopBarcodeScanner();
            if (barcodeResultDisplay) barcodeResultDisplay.textContent = "Scanner Tidak Aktif.";
            // Reset scanTarget saat modal ditutup (kecuali jika ditutup oleh hasil scan sukses)
            if (!scanTarget || scanTarget.startsWith('edit-')) { 
                 scanTarget = null;
            }
        });
    }

    // ==========================================================
    // BAGIAN LOGIKA BARANG (Dibiarkan sama)
    // ==========================================================
    
    function searchProducts(searchTermOverride = null) {
        let searchTerm;
        if (typeof searchTermOverride === 'string') {
             searchTerm = searchTermOverride;
        } else {
             searchTerm = productInput.value.trim();
        }
        
        if (searchTerm.length < 2) {
            searchResultsContainer.style.display = 'none';
            return;
        }

        fetch(`search_products.php?term=${searchTerm}`)
            .then(response => response.json())
            .then(data => {
                const scannedCodeForAlert = (typeof searchTermOverride === 'string') ? searchTermOverride : null;
                displaySearchResults(data.results, scannedCodeForAlert); 
            });
    }

    function displaySearchResults(results, scannedCode = null) {
        searchResultsContainer.innerHTML = '';
        
        if (results.length === 0) {
            searchResultsContainer.style.display = 'none';
            if (scannedCode) {
                 alert(`Barcode/Code ${scannedCode} tidak ditemukan di database produk.`);
            }
            return;
        }
        
        // Logika ini dilewati jika dipanggil oleh Barcode Scanner untuk otomatisasi
        if (scannedCode && results.length === 1) {
            const product = results[0];
            selectedProduct = product;
            productsStock[product.id] = parseInt(product.stock);
            
            productInput.value = `${product.product_code} - ${product.product_name}`;
            selectedProductIdInput.value = product.id;
            searchResultsContainer.style.display = 'none';
            updateFormFields();

            if (parseInt(product.has_serial) === 1) {
                itemSerialNumber.focus();
            } else {
                itemQuantity.focus();
            }
            return;
        }
        
        results.forEach(product => {
            const resultItem = document.createElement('a');
            resultItem.href = '#';
            resultItem.classList.add('list-group-item', 'list-group-item-action');
            resultItem.textContent = `${product.product_code} - ${product.product_name}`;
            
            resultItem.addEventListener('click', function(e) {
                e.preventDefault();
                selectedProduct = product;
                productsStock[product.id] = parseInt(product.stock);
                
                productInput.value = `${product.product_code} - ${product.product_name}`;
                selectedProductIdInput.value = product.id;
                searchResultsContainer.style.display = 'none';
                updateFormFields();
            });

            searchResultsContainer.appendChild(resultItem);
        });
        searchResultsContainer.style.display = 'block';
    }

    function updateFormFields() {
        if (!selectedProduct) {
            itemQuantity.disabled = true;
            itemSerialNumber.disabled = true;
            serialSection.style.display = 'none';
            addItemBtn.disabled = true;
            return;
        }
        
        const hasSerial = parseInt(selectedProduct.has_serial) === 1;
        itemQuantity.disabled = hasSerial;
        itemQuantity.value = hasSerial ? 1 : 1;
        itemSerialNumber.value = '';
        
        serialSection.style.display = hasSerial ? 'block' : 'none';
        itemSerialNumber.disabled = !hasSerial;
        addItemBtn.disabled = false;

        if (hasSerial) {
            itemSerialNumber.focus();
            serialInfo.textContent = 'Serial number wajib diisi.';
        } else {
            serialInfo.textContent = '';
            itemQuantity.focus();
        }
    }

    function addItemToTable() {
        if (!selectedProduct) {
            alert('Mohon pilih produk dari daftar pencarian.');
            return;
        }
        
        const productId = selectedProduct.id;
        const hasSerial = parseInt(selectedProduct.has_serial) === 1;
        const productName = selectedProduct.product_name;
        const productCode = selectedProduct.product_code;
        const quantity = parseInt(itemQuantity.value);
        const serialNumber = itemSerialNumber.value.trim();

        if (!productId || (hasSerial && !serialNumber) || (!hasSerial && (isNaN(quantity) || quantity <= 0))) {
            console.error('Gagal menambahkan item: Data tidak lengkap atau kuantitas tidak valid.');
            return;
        }
        
        if (!hasSerial) {
            const existingItemIndex = transactionItems.findIndex(item => item.product_id === productId && item.has_serial === 0);
            if (existingItemIndex > -1) {
                transactionItems[existingItemIndex].quantity += quantity;
            } else {
                transactionItems.push({
                    product_id: productId,
                    product_code: productCode,
                    product_name: productName,
                    has_serial: 0,
                    quantity: quantity,
                    serial_number: null
                });
            }
        } else {
            const isSerialExists = transactionItems.some(i => i.serial_number === serialNumber);
            if (isSerialExists) {
                alert('Serial number ini sudah ditambahkan.');
                return;
            }
            transactionItems.push({
                product_id: productId,
                product_code: productCode,
                product_name: productName,
                has_serial: 1,
                quantity: 1,
                serial_number: serialNumber
            });
        }
        
        renderItemsTable();
        clearItemForm();
    }

    function renderItemsTable() {
        itemsTableBody.innerHTML = '';
        isEditing = false; // Reset status editing
        
        if (transactionItems.length === 0) {
            itemsTableBody.innerHTML = '<tr><td colspan="5" class="text-center">Belum ada item ditambahkan.</td></tr>';
        } else {
            transactionItems.forEach((item, index) => {
                const row = itemsTableBody.insertRow();
                row.dataset.index = index;
                row.innerHTML = `
                    <td>${item.product_code}</td>
                    <td>${item.product_name}</td>
                    <td class="item-quantity">${item.quantity}</td>
                    <td class="item-serial">${item.serial_number || '-'}</td>
                    <td class="item-actions">
                        <button type="button" class="btn btn-warning btn-sm edit-btn me-2" data-index="${index}"><i class="bi bi-pencil-square"></i></button>
                        <button type="button" class="btn btn-danger btn-sm delete-btn" data-index="${index}"><i class="bi bi-trash"></i></button>
                    </td>
                `;
            });
        }
        itemsJsonInput.value = JSON.stringify(transactionItems);
        attachEventListeners();
    }

    function attachEventListeners() {
        document.querySelectorAll('.edit-btn').forEach(button => {
            button.removeEventListener('click', handleEditClick);
            button.addEventListener('click', handleEditClick);
        });
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.removeEventListener('click', handleDeleteClick);
            button.addEventListener('click', handleDeleteClick);
        });
    }

    function handleEditClick(event) {
        const index = event.currentTarget.dataset.index;
        const item = transactionItems[index];
        const row = event.currentTarget.closest('tr');
        
        if (isEditing) {
            alert('Silakan simpan atau batalkan perubahan pada item lain terlebih dahulu.');
            return;
        }

        isEditing = true; // Set status editing
        row.classList.add('table-warning');
        
        if (item.has_serial) {
            const serialCell = row.querySelector('.item-serial');
            serialCell.innerHTML = `
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control form-control-sm" value="${item.serial_number}" autofocus>
                    <button type="button" class="btn btn-outline-secondary scan-edit-btn" data-index="${index}"><i class="bi bi-barcode-scanned"></i></button>
                </div>`;
            const quantityCell = row.querySelector('.item-quantity');
            quantityCell.innerHTML = `1`;
            
            // Tambahkan event listener untuk tombol scan di mode edit
            const scanEditBtn = row.querySelector('.scan-edit-btn');
            scanEditBtn.addEventListener('click', function() {
                scanTarget = `edit-${index}`; // Set target scan ke index item yang diedit
                if (barcodeScannerModal) barcodeScannerModal.show();
            });

        } else {
            const quantityCell = row.querySelector('.item-quantity');
            quantityCell.innerHTML = `<input type="number" class="form-control form-control-sm" value="${item.quantity}" min="1" autofocus>`;
            const serialCell = row.querySelector('.item-serial');
            serialCell.innerHTML = '-';
        }

        const actionsCell = row.querySelector('.item-actions');
        actionsCell.innerHTML = `
            <button type="button" class="btn btn-success btn-sm save-btn me-2" data-index="${index}"><i class="bi bi-check-lg"></i> Simpan</button>
            <button type="button" class="btn btn-secondary btn-sm cancel-btn" data-index="${index}"><i class="bi bi-x-lg"></i> Batal</button>
        `;

        row.querySelector('.save-btn').addEventListener('click', handleSaveClick);
        row.querySelector('.cancel-btn').addEventListener('click', handleCancelClick);
    }
    
    function handleSaveClick(event) {
        const index = event.currentTarget.dataset.index;
        const item = transactionItems[index];
        const row = event.currentTarget.closest('tr');

        const isSerialItem = item.has_serial;
        let newValue;

        if (isSerialItem) {
            const serialInput = row.querySelector('.item-serial input');
            newValue = serialInput.value.trim();

            if (!newValue) {
                alert('Serial Number tidak boleh kosong.');
                return;
            }

            const isDuplicate = transactionItems.some((i, idx) => {
                return parseInt(idx) !== parseInt(index) && i.serial_number === newValue;
            });
            if (isDuplicate) {
                alert('Serial number ini sudah ditambahkan pada item lain.');
                return;
            }

            item.serial_number = newValue;
        } else {
            const quantityInput = row.querySelector('.item-quantity input');
            newValue = parseInt(quantityInput.value);
            
            if (isNaN(newValue) || newValue <= 0) {
                alert('Kuantitas harus angka yang valid dan lebih dari 0.');
                return;
            }
            item.quantity = newValue;
        }

        isEditing = false; // Reset status editing setelah simpan
        renderItemsTable();
    }
    
    function handleCancelClick(event) {
        isEditing = false; // Reset status editing saat batal
        renderItemsTable();
    }
    
    function handleDeleteClick(event) {
        const index = event.currentTarget.dataset.index;
        if (isEditing) {
            alert('Silakan simpan atau batalkan item yang sedang diedit sebelum menghapus.');
            return;
        }
        if (confirm('Apakah Anda yakin ingin menghapus item ini?')) {
            transactionItems.splice(index, 1);
            renderItemsTable();
        }
    }

    function clearItemForm() {
        productInput.value = '';
        selectedProductIdInput.value = '';
        selectedProduct = null;
        itemQuantity.value = 1;
        itemQuantity.disabled = true;
        itemSerialNumber.value = '';
        itemSerialNumber.disabled = true;
        serialSection.style.display = 'none';
        addItemBtn.disabled = true;
        productInput.focus();
    }
    
    productInput.addEventListener('input', function() {
        searchProducts();
    });

    addItemBtn.addEventListener('click', addItemToTable); 
    
    itemSerialNumber.addEventListener('keydown', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            addItemToTable();
        }
    });

    const loadPOItemsBtn = document.getElementById('load-po-items-btn');
    if (loadPOItemsBtn) {
        loadPOItemsBtn.addEventListener('click', function() {
            const documentNumber = document.getElementById('document_number').value;
            if (!documentNumber) {
                alert('Nomor Dokumen/PO kosong.');
                return;
            }
            
            // Because our API expects the internal DB ID, we need an extra search or we must pass the string to a new wrapper. 
            // Wait, get_po_details.php expects po_id. We need to create a helper or modify the API call.
            // Let's create an inline fetch to get the ID by po_number first.
            fetch(`get_po_list.php?term=${encodeURIComponent(documentNumber)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.length > 0) {
                        const poInfo = data.find(p => p.po_number === documentNumber);
                        if(poInfo) {
                            fetch(`get_po_details.php?po_id=${poInfo.id}`)
                                .then(res2 => res2.json())
                                .then(items => {
                                    if(items.length === 0) {
                                        alert('Semua item PO ini sudah diterima atau tidak ada sisa.');
                                        return;
                                    }
                                    let addedCount = 0;
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
                                                    has_serial: 1
                                                });
                                                addedCount++;
                                            }
                                        } else {
                                            // Append only if this non-serial product doesn't exist yet, or just push it.
                                            // Better to just push and let user consolidate or handle it.
                                            transactionItems.push({
                                                product_id: item.product_id,
                                                product_code: item.product_code,
                                                product_name: item.product_name,
                                                quantity: item.qty,
                                                serial_number: null,
                                                has_serial: 0
                                            });
                                            addedCount++;
                                        }
                                    });
                                    alert(`Berhasil menambahkan ${addedCount} baris sisa item PO.`);
                                    renderItemsTable();
                                });
                        } else {
                            alert('Data PO tidak ditemukan di keranjang sisa.');
                        }
                    } else {
                        alert('Nomor PO tidak memiliki sisa item untuk dimuat.');
                    }
                });
        });
    }

    renderItemsTable();
});
</script>

<?php include '../../includes/footer.php'; ?>