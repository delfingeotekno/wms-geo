<?php
$page_title = "Transfer Gudang Antar Gudang";
include '../../includes/header.php';

// Cek hak akses
if (!in_array($_SESSION['role'], ['admin', 'staff'])) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Anda tidak memiliki akses ke halaman ini.</div></div>";
    include '../../includes/footer.php';
    exit;
}

// Logika Multi-Gudang (Tabbed view untuk Global User)
$filter_warehouse_id = $warehouse_id;
$active_tab = 'all';

if ($warehouse_id == 0) {
    if (isset($_GET['tab'])) {
        if ($_GET['tab'] == 'jkt') {
            $filter_warehouse_id = 1;
            $active_tab = 'jkt';
        } elseif ($_GET['tab'] == 'btm') {
            $filter_warehouse_id = 2;
            $active_tab = 'btm';
        }
    }
}

$warehouse_filter_sql = "";
if ($active_tab !== 'all') {
    $warehouse_filter_sql = " AND (t.source_warehouse_id = $filter_warehouse_id OR t.destination_warehouse_id = $filter_warehouse_id)";
}

// Menghitung statistik kartu
$sql_stats = "
    SELECT 
        SUM(CASE WHEN status = 'In Transit' THEN 1 ELSE 0 END) as total_in_transit,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as total_completed
    FROM transfers t WHERE 1=1 $warehouse_filter_sql";
$stats_res = $conn->query($sql_stats);
$stats = $stats_res->fetch_assoc();
?>

<div class="container-fluid py-4">
    <!-- Header dan Tombol Tambah -->
    <div class="d-flex justify-content-between align-items-end mb-4 flex-wrap gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb breadcrumb-chevron mb-2">
                    <li class="breadcrumb-item"><a href="/wms-geo/index.php" class="text-decoration-none text-muted">Dashboard</a></li>
                    <li class="breadcrumb-item text-dark fw-semibold" aria-current="page">Transfer Gudang</li>
                </ol>
            </nav>
            <h2 class="h3 fw-bold mb-0 flex-grow-1"><i class="bi bi-arrow-left-right text-primary me-2"></i> Transfer Gudang (Transfer)</h2>
        </div>
        <div>
            <a href="create.php" class="btn btn-primary shadow-sm rounded-pill fw-medium px-4 py-2">
                <i class="bi bi-truck me-2"></i> Buat Surat Jalan Transfer
            </a>
        </div>
    </div>

    <?php if (isset($_GET['status'])): ?>
        <?php if ($_GET['status'] == 'success'): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> Transaksi Transfer Gudang berhasil disimpan!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($_GET['status'] == 'updated'): ?>
            <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i> Perubahan Transfer Gudang berhasil diperbarui!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($_GET['status'] == 'deleted'): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-trash-fill me-2"></i> Transaksi berhasil dibatalkan dan stok telah dikembalikan ke gudang asal.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Kartu Statistik -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm bg-warning-subtle text-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1 fw-bold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.8rem;">Sedang di Perjalanan (In-Transit)</h6>
                            <h2 class="mb-0 fw-bold"><?= number_format($stats['total_in_transit'] ?? 0) ?></h2>
                        </div>
                        <div class="bg-warning text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px;">
                            <i class="bi bi-truck fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm bg-success-subtle text-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1 fw-bold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.8rem;">Transfer Selesai</h6>
                            <h2 class="mb-0 fw-bold"><?= number_format($stats['total_completed'] ?? 0) ?></h2>
                        </div>
                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px;">
                            <i class="bi bi-check2-circle fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Filter Gudang untuk Admin Global -->
    <?php if ($warehouse_id == 0): ?>
    <ul class="nav nav-tabs border-bottom-0 mb-4 gap-2">
        <li class="nav-item">
            <a class="nav-link rounded-top-3 <?= $active_tab == 'all' ? 'active shadow-sm fw-bold border-bottom-0 bg-white text-primary' : 'bg-light text-secondary border' ?>" href="?tab=all">
                <i class="bi bi-globe me-1"></i> Semua Ekspedisi
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-top-3 <?= $active_tab == 'jkt' ? 'active shadow-sm fw-bold border-bottom-0 bg-white text-primary' : 'bg-light text-secondary border' ?>" href="?tab=jkt">
                <i class="bi bi-building me-1"></i> Melibatkan Jakarta
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-top-3 <?= $active_tab == 'btm' ? 'active shadow-sm fw-bold border-bottom-0 bg-white text-primary' : 'bg-light text-secondary border' ?>" href="?tab=btm">
                <i class="bi bi-building me-1"></i> Melibatkan Batam
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <!-- Tabel Transfer Gudang -->
    <div class="card shadow-sm border-0 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 custom-table">
                    <thead class="bg-light">
                        <tr class="text-secondary small text-uppercase fw-bold">
                            <th class="ps-4 py-3">Surat Jalan</th>
                            <th>Tanggal Kirim</th>
                            <th>Gudang Asal <i class="bi bi-arrow-right mx-1"></i> Tujuan</th>
                            <th>Status Transfer</th>
                            <th>Kurir/Resi</th>
                            <th class="text-end pe-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql = "SELECT t.*, 
                                w1.name as source_name, 
                                w2.name as dest_name
                                FROM transfers t 
                                JOIN warehouses w1 ON t.source_warehouse_id = w1.id
                                JOIN warehouses w2 ON t.destination_warehouse_id = w2.id
                                WHERE 1=1 $warehouse_filter_sql
                                ORDER BY t.created_at DESC";
                        
                        $res = $conn->query($sql);
                        
                        if ($res && $res->num_rows > 0):
                            while($row = $res->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="text-primary fw-bold small"><?= $row['transfer_no'] ?></span>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= date('d/m/Y', strtotime($row['created_at'])) ?></div>
                                    <div class="text-muted" style="font-size: 0.7rem;"><i class="bi bi-clock"></i> <?= date('H:i', strtotime($row['created_at'])) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border px-2"><?= htmlspecialchars($row['source_name']) ?></span>
                                    <i class="bi bi-arrow-right-short text-muted"></i>
                                    <span class="badge bg-light text-dark border px-2 fw-bold"><?= htmlspecialchars($row['dest_name']) ?></span>
                                </td>
                                <td>
                                    <?php if($row['status'] == 'In Transit'): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning px-2 fw-normal">
                                            <i class="bi bi-truck me-1"></i> Di Perjalanan
                                        </span>
                                    <?php elseif($row['status'] == 'Completed'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success px-2 fw-normal">
                                            <i class="bi bi-check-circle-fill me-1"></i> Selesai
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger px-2 fw-normal">
                                            <i class="bi bi-x-circle-fill me-1"></i> Batal
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="small fw-medium text-dark"><?= htmlspecialchars($row['courier_info'] ?: '-') ?></span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group shadow-sm border rounded bg-white">
                                        <a href="detail.php?id=<?= $row['id'] ?>" class="btn btn-sm px-3 text-primary border-0 fw-bold" title="Detail">Detail <i class="bi bi-arrow-right-short"></i></a>
                                        <?php if($row['status'] == 'In Transit' && ($_SESSION['role'] == 'admin' || $row['created_by'] == $_SESSION['user_id'])): ?>
                                            <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm px-2 text-warning border-start border-0" title="Edit"><i class="bi bi-pencil-square"></i></a>
                                            <button type="button" class="btn btn-sm px-2 text-danger border-start border-0 btn-delete" data-id="<?= $row['id'] ?>" data-no="<?= $row['transfer_no'] ?>" title="Hapus"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; 
                        else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <img src="/wms-geo/assets/img/empty-box.svg" alt="Empty" style="height: 120px; opacity: 0.4;" class="mb-3 d-none d-md-block mx-auto">
                                    <h5 class="fw-bold text-muted mb-1">Belum Ada Transaksi Mutasi</h5>
                                    <p class="text-muted mb-0 small">Surat jalan transfer antar gudang akan muncul di sini.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .breadcrumb-item + .breadcrumb-item::before { content: "›"; font-size: 1.2rem; line-height: 1; vertical-align: middle; }
    .custom-table th { font-size: 0.75rem; letter-spacing: 0.5px; border-bottom: 2px solid #e3e6f0; }
    .custom-table td { vertical-align: middle; padding-top: 1rem; padding-bottom: 1rem; }
    .custom-table tbody tr { transition: all 0.2s ease; }
    .custom-table tbody tr:hover { background-color: #f8f9fc; transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .btn-group .btn { transition: all 0.2s; }
    .btn-group .btn:hover { background-color: #f8f9fa; }
</style>

<script>
$(document).ready(function() {
    $('.btn-delete').click(function() {
        let id = $(this).data('id');
        let no = $(this).data('no');
        if (confirm('Apakah Anda yakin ingin menghapus transfer ' + no + '? Stok yang sudah dipotong dari gudang asal akan dikembalikan.')) {
            window.location.href = 'delete.php?id=' + id;
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
