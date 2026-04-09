<?php 
include '../../includes/header.php'; 

$id = $_GET['id'] ?? 0;

// 1. Ambil data Header Transaksi
$sql_header = "SELECT ao.*, a.assembly_name, u.name as admin_name, w.name as warehouse_name 
               FROM assembly_outbound ao 
               LEFT JOIN assemblies a ON ao.assembly_id = a.id 
               JOIN users u ON ao.user_id = u.id 
               LEFT JOIN warehouses w ON ao.warehouse_id = w.id
               WHERE ao.id = ?";
$stmt = $conn->prepare($sql_header);
$stmt->bind_param("i", $id);
$stmt->execute();
$header = $stmt->get_result()->fetch_assoc();

if (!$header) {
    echo "<script>alert('Data tidak ditemukan!'); window.location='index.php';</script>";
    exit;
}

// 2. Ambil Daftar Hasil Rakitan (Multi)
$sql_results = "SELECT aor.*, a.assembly_name 
                FROM assembly_outbound_results aor
                JOIN assemblies a ON aor.assembly_id = a.id
                WHERE aor.outbound_id = ?";
$stmt_res = $conn->prepare($sql_results);
$stmt_res->bind_param("i", $id);
$stmt_res->execute();
$results = $stmt_res->get_result();
$has_results = $results->num_rows > 0;

// 3. Ambil data Detail Item yang keluar (Group by product to show serials together)
$sql_items = "SELECT aoi.product_id, p.product_name, p.product_code, SUM(aoi.qty_out) as qty_out, 
                     GROUP_CONCAT(aoi.serial_number SEPARATOR ', ') as serials, 
                     aoi.is_returnable
              FROM assembly_outbound_items aoi
              JOIN products p ON aoi.product_id = p.id 
              WHERE aoi.outbound_id = ?
              GROUP BY aoi.product_id, aoi.is_returnable";
$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $id);
$stmt_items->execute();
$items = $stmt_items->get_result();
?>

<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Detail Keluar Rakitan</h1>
        <div>
            <a href="generate_bast.php?id=<?= $id ?>" target="_blank" class="btn btn-dark btn-sm">
                <i class="bi bi-file-earmark-pdf me-2"></i> Export ke PDF
            </a>
            <a href="index.php" class="btn btn-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow mb-4">
                <div class="card-header bg-primary text-white">
                    <h6 class="m-0 font-weight-bold">Informasi Dokumen</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="text-muted" width="120">No. BAST</td>
                            <td class="fw-bold text-primary">: <?= $header['transaction_no'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tanggal</td>
                            <td>: <?= date('d F Y H:i', strtotime($header['created_at'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Gudang Asal</td>
                            <td>: <strong><?= $header['warehouse_name'] ?></strong></td>
                        </tr>
                        <?php if ($has_results): ?>
                            <tr>
                                <td class="text-muted" colspan="2">Jenis Rakitan:</td>
                            </tr>
                            <?php while($res = $results->fetch_assoc()): ?>
                            <tr>
                                <td colspan="2">
                                    <div class="ms-3 d-flex justify-content-between border-start ps-2">
                                        <span class="small text-dark fw-bold"><?= $res['assembly_name'] ?></span>
                                        <span class="badge bg-info text-dark"><?= $res['qty'] ?> Unit</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td class="text-muted">Jenis Rakitan</td>
                                <td>: <span class="badge bg-secondary">Manual (Tanpa Template)</span></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="text-muted">Project</td>
                            <td>: <strong><?= $header['project_name'] ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Admin Logistik</td>
                            <td>: <?= $header['admin_name'] ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow mb-4">
                <div class="card-header bg-light">
                    <h6 class="m-0 font-weight-bold text-dark">Daftar Komponen yang Dikeluarkan</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th width="50">No</th>
                                    <th>Kode Produk</th>
                                    <th>Nama Komponen</th>
                                    <th class="text-center" width="120">Keluar</th>
                                    <th class="text-center" width="150">Status Return</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = 1;
                                while($row = $items->fetch_assoc()): 
                                ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><code class="fw-bold"><?= $row['product_code'] ?></code></td>
                                    <td>
                                        <?= htmlspecialchars($row['product_name']) ?>
                                        <?php if (!empty($row['serials'])): ?>
                                            <div class="mt-1">
                                                <?php foreach(explode(', ', $row['serials']) as $sn): ?>
                                                    <span class="badge bg-light text-dark border small me-1"><?= htmlspecialchars($sn) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-bold text-danger"><?= $row['qty_out'] ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $is_ret = $row['is_returnable'] ?? 0;
                                        $qty_ret = $row['qty_returned'] ?? 0;
                                        $qty_out = $row['qty_out'];
                                        
                                        if ($is_ret == 1): 
                                            if ($qty_ret >= $qty_out): ?>
                                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> Selesai Kembali: <?= $qty_ret ?> / <?= $qty_out ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><i class="bi bi-arrow-return-left"></i> Kembali: <?= $qty_ret ?> / <?= $qty_out ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Habis Pakai</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info small mt-3">
                        <i class="bi bi-info-circle me-2"></i>
                        Barang-barang di atas telah dipotong otomatis dari stok utama saat transaksi diproses.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, .sidebar-wrapper, .navbar, .alert { display: none !important; }
    #page-content-wrapper { padding: 0 !important; width: 100% !important; }
    .card { border: none !important; shadow: none !important; }
    .card-header { background: transparent !important; color: black !important; border-bottom: 2px solid #000 !important; }
}
</style>