<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header("Location: index.php");
    exit();
}

// Fetch Request Header
$sql_req = "SELECT 
                sr.*,
                u.name AS requester_name,
                w.name AS warehouse_name,
                CASE
                    WHEN sr.status = 'Draft' THEN 'bg-secondary'
                    WHEN sr.status = 'Pending' THEN 'bg-warning text-dark'
                    WHEN sr.status = 'Approved' THEN 'bg-success'
                    WHEN sr.status = 'Rejected' THEN 'bg-danger'
                    ELSE 'bg-info text-dark'
                END AS badge_class
            FROM stock_requests sr
            JOIN users u ON sr.user_id = u.id
            LEFT JOIN warehouses w ON sr.warehouse_id = w.id
            WHERE sr.id = ?";
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

// Fetch Request Details
$sql_det = "SELECT 
                srd.*,
                p.product_name AS catalog_name,
                p.product_code
            FROM stock_request_details srd
            LEFT JOIN products p ON srd.product_id = p.id
            WHERE srd.stock_request_id = ?";
$stmt_det = $conn->prepare($sql_det);
$stmt_det->bind_param("i", $id);
$stmt_det->execute();
$details = $stmt_det->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_det->close();
?>

<div class="content-header mb-4 d-flex justify-content-between align-items-center">
    <h1 class="fw-bold">Detail Stock Request</h1>
    <div>
        <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
        
        <?php if ($request['status'] == 'Pending' && ($_SESSION['role'] == 'staff' || $_SESSION['role'] == 'admin')): ?>
            <a href="edit.php?id=<?= $request['id'] ?>" class="btn btn-warning btn-sm rounded-pill px-3">
                <i class="bi bi-pencil me-1"></i> Edit
            </a>
        <?php endif; ?>

        <a href="download_pdf.php?id=<?= $request['id'] ?>" class="btn btn-danger btn-sm rounded-pill px-3" target="_blank">
            <i class="bi bi-file-earmark-pdf me-1"></i> Unduh PDF
        </a>
    </div>
</div>

<div class="row g-3">
    <!-- Panel Kiri: Informasi Request -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 rounded-3 h-100">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text me-2"></i>Informasi Request</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" width="120">No. Request</td>
                        <td class="fw-bold">: <?= htmlspecialchars($request['request_number']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Subjek</td>
                        <td class="fw-bold text-primary">: <?= htmlspecialchars($request['subject'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Gudang</td>
                        <td>: <?= htmlspecialchars($request['warehouse_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Pemohon</td>
                        <td>: <?= htmlspecialchars($request['requester_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Tgl Pengajuan</td>
                        <td>: <?= date('d-m-Y', strtotime($request['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">ETA Supply</td>
                        <td class="text-danger fw-bold">: <?= date('d-m-Y', strtotime($request['eta_supply'])) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Status</td>
                        <td>: <span class="badge <?= htmlspecialchars($request['badge_class']) ?>"><?= htmlspecialchars($request['status']) ?></span></td>
                    </tr>
                </table>
                <hr>
                <label class="form-label small fw-bold text-muted">Catatan Umum:</label>
                <div class="p-2 bg-light rounded border">
                    <?= !empty($request['general_notes']) ? nl2br(htmlspecialchars($request['general_notes'])) : '<em class="text-muted">Tidak ada catatan.</em>' ?>
                </div>
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
