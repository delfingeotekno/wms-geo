<?php 
include '../../includes/header.php'; 

// --- PROTEKSI HALAMAN ---
if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: /wms-geo/index.php");
    exit();
}

// 1. Ambil ID dari URL
$id = $_GET['id'] ?? '';

if (empty($id)) {
    header("Location: index.php");
    exit;
}

// 2. Query Data Header PO & Join dengan Vendor
$sql_po = "SELECT po.*, v.vendor_name, v.address, v.phone, v.payment_term, v.currency
           FROM purchase_orders po 
           JOIN vendors v ON po.vendor_id = v.id 
           WHERE po.id = ?";
$stmt = $conn->prepare($sql_po);
$stmt->bind_param("i", $id);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();

if (!$po) {
    echo "<div class='alert alert-danger m-3'>Data PO tidak ditemukan.</div>";
    exit;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h3 mb-0 text-gray-800 fw-bold">Detail Purchase Order</h2>
            <p class="text-muted mb-0">No: <span class="text-dark fw-bold"><?= $po['po_number'] ?></span></p>
        </div>
        <div class="btn-group">
            <a href="index.php" class="btn btn-outline-secondary px-4 rounded-pill me-2">
                <i class="bi bi-arrow-left me-2"></i>Kembali
            </a>
            <a href="print_pdf.php?id=<?= $po['id'] ?>" target="_blank" class="btn btn-primary px-4 rounded-pill">
                <i class="bi bi-printer me-2"></i>Cetak PDF
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-body">
                    <h6 class="fw-bold text-uppercase text-muted small mb-3">Informasi Vendor</h6>
                    <h5 class="fw-bold text-dark mb-1"><?= $po['vendor_name'] ?></h5>
                    <p class="text-muted small mb-3">
                        <i class="bi bi-geo-alt me-1"></i> <?= $po['address'] ?><br>
                        <i class="bi bi-telephone me-1"></i> <?= $po['phone'] ?><br>
                        <i class="bi bi-credit-card me-1"></i> TOP: <span class="fw-bold text-dark"><?= $po['payment_term'] ?: '-' ?></span>
                    </p>
                    <hr>
                    <h6 class="fw-bold text-uppercase text-muted small mb-3">Rincian PO</h6>
                    <table class="table table-borderless table-sm mb-0 small">
                        <tr>
                            <td class="text-muted" style="width: 40%;">Tanggal PO</td>
                            <td class="fw-bold">: <?= date('d M Y', strtotime($po['po_date'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">PIC Marketing</td>
                            <td class="fw-bold">: <?= $po['pic_name'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">No. Referensi</td>
                            <td class="fw-bold">: <?= $po['reference'] ?: '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Status</td>
                            <td>: 
                                <?php 
                                    $badge = 'bg-secondary';
                                    if($po['status'] == 'Pending') $badge = 'bg-warning text-dark';
                                    if($po['status'] == 'Completed') $badge = 'bg-success';
                                    if($po['status'] == 'Cancelled') $badge = 'bg-danger';
                                ?>
                                <span class="badge rounded-pill <?= $badge ?>"><?= $po['status'] ?></span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body">
                    <h6 class="fw-bold text-uppercase text-muted small mb-2">Catatan / Internal Notes</h6>
                    <p class="mb-0 small text-dark italic"><?= nl2br($po['notes']) ?: 'Tidak ada catatan.' ?></p>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2 text-primary"></i>Item Barang</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Produk</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Harga Satuan</th>
                                    <th class="text-end">Potongan</th>
                                    <th class="text-end pe-4">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sql_items = "SELECT pi.*, p.product_name, p.product_code 
                                              FROM po_items pi 
                                              JOIN products p ON pi.product_id = p.id 
                                              WHERE pi.po_id = ?";
                                $stmt_i = $conn->prepare($sql_items);
                                $stmt_i->bind_param("i", $id);
                                $stmt_i->execute();
                                $res_items = $stmt_i->get_result();

                                while($item = $res_items->fetch_assoc()):
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?= $item['product_name'] ?></div>
                                        <small class="text-muted"><?= $item['product_code'] ?></small>
                                    </td>
                                    <td class="text-center"><?= number_format($item['qty'], 0) ?></td>
                                    <td class="text-end"><?= number_format($item['price'], 2, ',', '.') ?></td>
                                    <td class="text-end text-danger"><?= number_format($item['discount'], 2, ',', '.') ?></td>
                                    <td class="text-end fw-bold pe-4">
                                        <?= number_format($item['subtotal'], 2, ',', '.') ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="4" class="text-end fw-bold">Subtotal Sebelum Pajak</td>
                                    <td class="text-end fw-bold pe-4"><?= number_format($po['total_before_tax'], 2, ',', '.') ?></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end fw-bold">PPN (11%)</td>
                                    <td class="text-end fw-bold pe-4"><?= number_format($po['ppn_amount'], 2, ',', '.') ?></td>
                                </tr>
                                <tr class="table-primary text-primary">
                                    <td colspan="4" class="text-end fw-bold fs-5">GRAND TOTAL (<?= $po['currency'] ?>)</td>
                                    <td class="text-end fw-bold fs-5 pe-4"><?= number_format($po['grand_total'], 2, ',', '.') ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>