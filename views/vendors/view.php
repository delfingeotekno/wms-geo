<?php 
include '../../includes/db_connect.php';
include '../../includes/header.php'; 

// --- PROTEKSI HALAMAN ---
if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: /wms-geo/index.php");
    exit();
}

$id = $_GET['id'] ?? '';

if (empty($id)) {
    echo "<script>window.location='index.php';</script>";
    exit;
}

// Ambil data vendor berdasarkan ID
$sql = "SELECT * FROM vendors WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    echo "<div class='alert alert-danger m-4'>Data vendor tidak ditemukan.</div>";
    include '../../includes/footer.php';
    exit;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Detail Vendor</h1>
    <a href="index.php" class="btn btn-secondary rounded-pill">
        <i class="bi bi-arrow-left me-2"></i>Kembali
    </a>
</div>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card shadow-sm border-0 rounded-4 text-center p-4">
            <div class="card-body">
                <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                    <i class="bi bi-building text-primary" style="font-size: 2.5rem;"></i>
                </div>
                <h4 class="fw-bold mb-1"><?= $vendor['vendor_name'] ?></h4>
                <p class="text-muted small mb-3"><?= $vendor['website_link'] ?? '-' ?></p>
                <span class="badge <?= $vendor['tax_status'] == 'PPN' ? 'bg-success' : 'bg-secondary' ?> px-3 py-2">
                    Status Pajak: <?= $vendor['tax_status'] ?>
                </span>
                <hr>
                <div class="d-grid gap-2">
                    <a href="edit.php?id=<?= $vendor['id'] ?>" class="btn btn-outline-primary">
                        <i class="bi bi-pencil me-2"></i>Edit Data Vendor
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i>Informasi Lengkap</h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <th width="35%" class="text-muted fw-normal">Nama Sales / PIC</th>
                        <td class="fw-bold">: <?= $vendor['sales_name'] ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Nomor Telepon</th>
                        <td class="fw-bold">: <?= $vendor['phone'] ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Alamat Kantor</th>
                        <td class="fw-bold">: <?= nl2br($vendor['address']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Mata Uang Dasar</th>
                        <td class="fw-bold">: <span class="badge bg-light text-dark border"><?= $vendor['currency'] ?></span></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Term of Payment (TOP)</th>
                        <td class="fw-bold">: <?= $vendor['payment_term'] ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><i class="bi bi-cart-check me-2"></i>Transaksi Terakhir</h5>
                <small class="text-muted">5 PO Terbaru</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">No. PO</th>
                                <th>Tanggal</th>
                                <th class="text-end pe-4">Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Query mengambil po.id agar bisa dijadikan link detail
                            $sql_po = "SELECT po.id, po.po_number, po.po_date, po.grand_total, v.currency 
                                       FROM purchase_orders po 
                                       JOIN vendors v ON po.vendor_id = v.id 
                                       WHERE po.vendor_id = ?
                                       ORDER BY po.po_date DESC LIMIT 5";
                            $stmt_po = $conn->prepare($sql_po);
                            $stmt_po->bind_param("i", $id);
                            $stmt_po->execute();
                            $res_po = $stmt_po->get_result();
                            
                            if ($res_po->num_rows > 0):
                                while($po = $res_po->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4 fw-bold">
                                        <a href="../po/detail.php?id=<?= $po['id'] ?>" class="text-decoration-none" title="Klik untuk lihat detail">
                                            <i class="bi bi-file-earmark-text me-1"></i><?= $po['po_number'] ?>
                                        </a>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($po['po_date'])) ?></td>
                                    <td class="text-end pe-4 fw-bold text-dark">
                                        <?= $po['currency'] ?> <?= number_format($po['grand_total'], 0, ',', '.') ?>
                                    </td>
                                </tr>
                                <?php endwhile;
                            else: ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox me-2"></i>Belum ada riwayat transaksi dengan vendor ini.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white border-0 text-center py-3">
                <a href="../po/index.php" class="small text-decoration-none">Lihat Semua Purchase Order <i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>