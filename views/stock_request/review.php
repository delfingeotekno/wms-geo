<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if ($_SESSION['role'] !== 'procurement' && $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: index.php");
    exit();
}

// Fetch Request Header
$sql_req = "SELECT sr.*, u.name AS requester_name, w.name AS warehouse_name FROM stock_requests sr JOIN users u ON sr.user_id = u.id LEFT JOIN warehouses w ON sr.warehouse_id = w.id WHERE sr.id = ?";
$stmt = $conn->prepare($sql_req);
$stmt->bind_param("i", $id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    echo "<div class='alert alert-danger'>Data tidak ditemukan.</div>";
    include '../../includes/footer.php';
    exit();
}

if ($request['status'] !== 'Pending') {
    echo "<div class='alert alert-warning'>Hanya request dengan status 'Pending' yang dapat ditinjau.</div>";
    include '../../includes/footer.php';
    exit();
}

// Fetch Details
$sql_det = "SELECT srd.*, p.product_name AS catalog_name, p.product_code FROM stock_request_details srd LEFT JOIN products p ON srd.product_id = p.id WHERE srd.stock_request_id = ?";
$stmt_det = $conn->prepare($sql_det);
$stmt_det->bind_param("i", $id);
$stmt_det->execute();
$details = $stmt_det->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_det->close();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action_status = $_POST['status_action']; // 'Approved' or 'Rejected'
    $review_notes = trim($_POST['review_notes']);

    if (!in_array($action_status, ['Approved', 'Rejected'])) {
        $message = 'Aksi tidak valid.';
        $message_type = 'danger';
    } else {
        $update_sql = "UPDATE stock_requests SET status = ?, general_notes = CONCAT(IFNULL(general_notes,''), '\n\nReview Notes: ', ?) WHERE id = ?";
        $stmt_upd = $conn->prepare($update_sql);
        $stmt_upd->bind_param("ssi", $action_status, $review_notes, $id);
        
        if ($stmt_upd->execute()) {
            header("Location: index.php?status=success_review");
            exit();
        } else {
            $message = 'Gagal menyimpan review.';
            $message_type = 'danger';
        }
        $stmt_upd->close();
    }
}
?>

<div class="content-header mb-4 d-flex justify-content-between align-items-center">
    <h1 class="fw-bold">Review Stock Request</h1>
    <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
        <i class="bi bi-arrow-left me-1"></i> Kembali
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- Panel Kiri: Informasi -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 rounded-3 mb-3">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text me-2"></i>Informasi Request</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" width="120">No. Request</td><td class="fw-bold">: <?= htmlspecialchars($request['request_number']) ?></td></tr>
                    <tr><td class="text-muted">Subjek</td><td class="fw-bold text-primary">: <?= htmlspecialchars($request['subject'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted">Gudang</td><td>: <?= htmlspecialchars($request['warehouse_name'] ?? '-') ?></td></tr>
                    <tr><td class="text-muted">Pemohon</td><td>: <?= htmlspecialchars($request['requester_name']) ?></td></tr>
                    <tr><td class="text-muted">Tgl Pengajuan</td><td>: <?= date('d-m-Y', strtotime($request['created_at'])) ?></td></tr>
                    <tr><td class="text-muted">ETA Supply</td><td class="text-danger fw-bold">: <?= date('d-m-Y', strtotime($request['eta_supply'])) ?></td></tr>
                </table>
                <hr>
                <label class="form-label small fw-bold text-muted">Catatan Gudang:</label>
                <div class="p-2 bg-light rounded border mb-3">
                    <?= !empty($request['general_notes']) ? nl2br(htmlspecialchars($request['general_notes'])) : '<em>Tidak ada catatan.</em>' ?>
                </div>
            </div>
        </div>

        <!-- Form Review -->
        <div class="card shadow-sm border-0 rounded-3 border-top border-primary border-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-check-circle me-2"></i>Tindakan Procurement</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Keputusan <span class="text-danger">*</span></label>
                        <select name="status_action" class="form-select form-select-sm" required>
                            <option value="">-- Pilih --</option>
                            <option value="Approved" class="text-success fw-bold">Approve (Setujui)</option>
                            <option value="Rejected" class="text-danger fw-bold">Reject (Tolak)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Review Notes / Alasan</label>
                        <textarea name="review_notes" class="form-control form-control-sm" rows="3" placeholder="Tambahkan catatan untuk Gudang..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">Simpan Keputusan</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Panel Kanan: Tabel Item -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 rounded-3 h-100">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2"></i>Daftar Item Permintaan</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light text-muted small text-uppercase">
                            <tr>
                                <th>Nama Item</th>
                                <th>Spec</th>
                                <th class="text-center">Stock</th>
                                <th class="text-center">Req Qty</th>
                                <th>Unit</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($details as $item): ?>
                                <tr>
                                    <td>
                                        <?php if ($item['product_id']): ?>
                                            <div class="fw-bold"><?= htmlspecialchars($item['catalog_name']) ?></div>
                                            <small class="text-muted">Kode: <?= htmlspecialchars($item['product_code']) ?></small>
                                        <?php else: ?>
                                            <div class="fw-bold"><?= htmlspecialchars($item['item_name_manual']) ?></div>
                                            <span class="badge bg-light text-dark border"><small>Manual</small></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($item['specification'] ?? '-') ?></td>
                                    <td class="text-center"><?= htmlspecialchars($item['current_stock']) ?></td>
                                    <td class="text-center fw-bold"><?= htmlspecialchars($item['requested_qty']) ?></td>
                                    <td><?= htmlspecialchars($item['unit'] ?? '-') ?></td>
                                    <td><small><?= htmlspecialchars($item['item_notes'] ?? '-') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
