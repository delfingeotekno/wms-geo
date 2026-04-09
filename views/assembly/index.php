<?php include '../../includes/header.php'; ?>

<?php 
// 1. Ambil daftar gudang aktif untuk tab filter
$warehouses_query = $conn->query("SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name ASC");
$warehouses_list = $warehouses_query->fetch_all(MYSQLI_ASSOC);

// 2. Tentukan tab aktif
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';

$warehouse_filter_sql = "";
$stock_warehouse_filter = "";
$filter_warehouse_id = 0;

if ($active_tab !== 'all') {
    $filter_warehouse_id = (int)$active_tab;
    $warehouse_filter_sql = "AND warehouse_id = $filter_warehouse_id";
    $stock_warehouse_filter = "AND ps.warehouse_id = $filter_warehouse_id";
}

// 3. (Query card dipindah ke HTML bawah agar dinamis)
?>

<div class="container-fluid py-4">
    <div class="d-md-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800 fw-bold">Manajemen Perakitan</h1>
            <p class="text-muted small mb-0">Monitor status produksi dari komponen hingga barang jadi.</p>
        </div>
        <div class="mt-3 mt-md-0">
            <?php if ($role != 'production'): ?>
            <a href="master_index.php" class="btn btn-white border shadow-sm btn-sm me-2">
                <i class="bi bi-gear-wide-connected me-1"></i> Master Data
            </a>
            <a href="create.php" class="btn btn-danger shadow-sm btn-sm me-2">
                <i class="bi bi-box-arrow-up me-1"></i> Barang Keluar
            </a>
            <a href="inbound_create.php" class="btn btn-success shadow-sm btn-sm">
                <i class="bi bi-box-arrow-in-down me-1"></i> Penerimaan Hasil
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- TABS WAREHOUSE -->
    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-body p-0">
            <ul class="nav nav-tabs custom-tabs" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= ($active_tab == 'all') ? 'active' : '' ?>" href="?tab=all">
                        <i class="bi bi-buildings me-2"></i>Semua Gudang
                    </a>
                </li>
                <?php foreach ($warehouses_list as $wh): ?>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= ($active_tab == (string)$wh['id']) ? 'active' : '' ?>" href="?tab=<?= $wh['id'] ?>">
                            <i class="bi bi-geo-alt me-2"></i><?= htmlspecialchars($wh['name']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- DYNAMIC ASSEMBLY CARDS -->
    <div class="row mb-4">
        <?php
        $wh_condition = ($active_tab !== 'all') ? " AND warehouse_id = $filter_warehouse_id" : "";
        
        $sql_cards = "
            SELECT 
                a.id, 
                a.assembly_name,
                (
                    SELECT IFNULL(MIN(
                        FLOOR(
                            (SELECT IFNULL(SUM(stock), 0) FROM warehouse_stocks WHERE product_id = ad.product_id $wh_condition) 
                            / NULLIF(ad.default_quantity, 0)
                        )
                    ), 0)
                    FROM assembly_details ad
                    WHERE ad.assembly_id = a.id
                ) as potential_build,
                (
                    SELECT IFNULL(SUM(aor.qty), 0)
                    FROM assembly_outbound_results aor
                    JOIN assembly_outbound ao ON aor.outbound_id = ao.id
                    WHERE aor.assembly_id = a.id AND ao.status = 'Pending'
                    $warehouse_filter_sql
                ) as wip_qty,
                (
                    SELECT IFNULL(SUM(stock), 0) 
                    FROM warehouse_stocks ws
                    WHERE ws.product_id = a.finished_product_id $wh_condition
                ) as finished_stock
            FROM assemblies a
            ORDER BY a.assembly_name ASC
        ";
        $res_cards = $conn->query($sql_cards);
        
        if ($res_cards && $res_cards->num_rows > 0):
            while($card = $res_cards->fetch_assoc()):
                // Warna card berdasarkan potensi rakitan
                $card_color = ($card['potential_build'] > 0) ? 'info' : 'secondary';
        ?>
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm border-left-<?= $card_color ?> h-100">
                <div class="card-body py-3">
                    <div class="text-dark fw-bold text-uppercase text-truncate mb-2" title="<?= htmlspecialchars($card['assembly_name']) ?>" style="font-size: 0.8rem;">
                        <i class="bi bi-box-seam text-<?= $card_color ?> me-1"></i> <?= htmlspecialchars($card['assembly_name']) ?>
                    </div>
                    <div class="row g-0 align-items-center mt-2">
                        <div class="col-4">
                            <div class="text-muted" style="font-size: 0.65rem; text-transform: uppercase;">Stok Jadi</div>
                            <div class="h5 fw-bold text-success mb-0"><?= number_format($card['finished_stock']) ?></div>
                        </div>
                        <div class="col-4 border-start ps-2">
                            <div class="text-muted" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px;">Stock Rakit</div>
                            <div class="h5 fw-bold text-<?= $card_color ?> mb-0"><?= number_format($card['potential_build']) ?></div>
                        </div>
                        <div class="col-4 border-start ps-2">
                            <div class="text-muted" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px;">Sedang Dirakit</div>
                            <div class="h5 fw-bold text-warning mb-0"><?= number_format($card['wip_qty']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php 
            endwhile;
        else:
        ?>
        <div class="col-12">
            <div class="alert alert-light border small text-muted"><i class="bi bi-info-circle me-1"></i> Belum ada Master Data Rakitan. Silakan ke halaman Master Data.</div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card border-0 shadow-sm bg-light-info mb-4">
        <div class="card-body py-3 d-flex align-items-center">
            <div class="icon-circle bg-info text-white me-3 shadow-sm">
                <i class="bi bi-info-circle"></i>
            </div>
            <div>
                <span class="small"><strong>Info:</strong> Kartu di atas menunjukkan berapa banyak unit yang <strong>Saat ini Bisa Dirakit</strong> (berdasarkan ketersediaan bahan terkecil di gudang), dan berapa unit yang sedang diproses rakit untuk setiap jenis rakitan.</span>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 overflow-hidden">
        <div class="card-header bg-white pt-3 border-0">
            <ul class="nav nav-pills mb-0" id="assemblyTab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active fw-bold small" id="history-tab" data-bs-toggle="tab">
                        <i class="bi bi-clock-history me-2"></i>Riwayat Transaksi
                    </button>
                </li>
            </ul>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 custom-table">
                    <thead class="bg-light">
                        <tr class="text-secondary small text-uppercase fw-bold">
                            <th class="ps-4 py-3">No. Transaksi</th>
                            <th>Tanggal</th>
                            <th>Status</th>
                            <th>Nama Rakitan</th>
                            <th class="text-center">Total</th>
                            <th class="text-end pe-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql = "SELECT ao.*, 
                                       GROUP_CONCAT(CONCAT(a.assembly_name, ' (', aor.qty, ')') SEPARATOR ', ') as multi_assemblies,
                                       SUM(aor.qty) as total_units_sum
                                FROM assembly_outbound ao 
                                LEFT JOIN assembly_outbound_results aor ON ao.id = aor.outbound_id
                                LEFT JOIN assemblies a ON aor.assembly_id = a.id 
                                WHERE 1=1";
                        if ($active_tab !== 'all') {
                            $sql .= " AND ao.warehouse_id = $filter_warehouse_id";
                        }
                        $sql .= " GROUP BY ao.id ORDER BY ao.created_at DESC";
                        $res = $conn->query($sql);
                        
                        if ($res && $res->num_rows > 0):
                            while($row = $res->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="text-primary fw-bold small"><?= $row['transaction_no'] ?></span>
                                    <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($row['project_name']) ?></div>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= date('d/m/Y', strtotime($row['created_at'])) ?></div>
                                    <div class="text-muted" style="font-size: 0.7rem;"><?= date('H:i', strtotime($row['created_at'])) ?> WIB</div>
                                </td>
                                <td>
                                    <?php if($row['status'] == 'Pending'): ?>
                                        <?php if(isset($row['total_received']) && $row['total_received'] > 0): ?>
                                            <span class="badge bg-info-subtle text-info border border-info px-2 fw-normal">
                                                <i class="bi bi-gear-fill spin-slow me-1"></i> Parsial
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning px-2 fw-normal">
                                                <i class="bi bi-gear-fill spin-slow me-1"></i> Dirakit
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success border border-success px-2 fw-normal">
                                            <i class="bi bi-check-circle-fill me-1"></i> Selesai
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-semibold text-dark small"><?= $row['multi_assemblies'] ?: 'Custom Order' ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="fw-bold"><?= $row['total_units_sum'] ?: 0 ?> <span class="text-muted fw-normal" style="font-size: 0.70rem;">Unit</span></div>
                                    <?php if(isset($row['total_received']) && $row['total_received'] > 0 && $row['status'] == 'Pending'): ?>
                                    <div class="text-success mt-1" style="font-size: 0.70rem;"><i class="bi bi-check2-all"></i> Diterima: <?= $row['total_received'] ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group shadow-sm border rounded bg-white">
                                        <a href="detail.php?id=<?= $row['id'] ?>" class="btn btn-sm px-2 text-info border-0" title="Detail"><i class="bi bi-eye"></i></a>
                                        <?php if($role != 'production'): ?>
                                        <?php if($row['status'] == 'Pending'): ?>
                                            <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm px-2 text-warning border-0 border-start" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <?php endif; ?>
                                        <a href="delete_process.php?id=<?= $row['id'] ?>" class="btn btn-sm px-2 text-danger border-0 border-start" onclick="return confirm('Hapus data?')" title="Hapus"><i class="bi bi-trash"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; 
                        else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <p class="text-muted small">Belum ada riwayat transaksi perakitan.</p>
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
    .border-left-info { border-left: 4px solid #36b9cc !important; }
    .border-left-warning { border-left: 4px solid #f6c23e !important; }
    .border-left-success { border-left: 4px solid #1cc88a !important; }
    .bg-light-info { background-color: #f0f9ff; border: 1px solid #e0f2fe; }
    .icon-circle { width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .custom-table thead th { border-bottom: 1px solid #f1f1f1; background-color: #fafafa; }
    .custom-table tbody td { border-bottom: 1px solid #f8f8f8; }
    .nav-pills .nav-link.active { background-color: transparent; color: #4e73df; border-bottom: 2px solid #4e73df; border-radius: 0; }
    .btn-white { background-color: #fff; color: #333; }
    .spin-slow { animation: spin 3s linear infinite; display: inline-block; }
    @keyframes spin { 100% { transform: rotate(360deg); } }
    .bg-warning-subtle { background-color: #fff9db !important; }
    .bg-success-subtle { background-color: #ebfbee !important; }
    .bg-info-subtle { background-color: #e7f5ff !important; }
</style>

<?php include '../../includes/footer.php'; ?>