<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan hanya admin dan staff berwenang yang bisa mengakses
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager', 'procurement', 'staff'])) {
    // Memberikan akses ke staff jika memang diminta (bisa disesuaikan)
}

// Set rentang tanggal default (90 hari terakhir)
$end_date_default = date('Y-m-d');
$start_date_default = date('Y-m-d', strtotime('-90 days'));

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $start_date_default;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : $end_date_default;

// Konversi tanggal ke objek datetime untuk hitung jumlah hari (N)
$datetime1 = new DateTime($start_date);
$datetime2 = new DateTime($end_date);
$interval = $datetime1->diff($datetime2);
$n_days = $interval->days + 1; // Termasuk batas awal & akhir

// Jika jumlah hari < 2, kita tidak bisa secara matematis menghitung Sample Standard Deviation. Set minimum 2.
if ($n_days < 2) {
    $n_days = 2;
}

// Ambil daftar gudang
$warehouses_query = $conn->query("SELECT id, name FROM warehouses ORDER BY name");
$warehouses_list = $warehouses_query->fetch_all(MYSQLI_ASSOC);

// Warehouse Filter
$active_warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : $_SESSION['warehouse_id'];

// Filter barang aktif saja
$only_active = isset($_GET['only_active']) && $_GET['only_active'] == '1';

// Kueri Kompleks Pengambilan Aktivitas Barang Keluar Dikelompokkan per Produk
$sql = "
SELECT 
    p.id as product_id,
    p.product_code,
    p.product_name,
    t.type_name,
    p.lead_time_avg,
    p.lead_time_max,
    COALESCE(ws.stock, 0) as current_stock,
    p.minimum_stock_level,
    COALESCE(daily_out.total_qty, 0) as sum_qty,
    COALESCE(daily_out.total_sq_qty, 0) as sum_sq_qty
FROM products p
LEFT JOIN product_types t ON p.product_type_id = t.id
LEFT JOIN warehouse_stocks ws ON p.id = ws.product_id AND ws.warehouse_id = ?
LEFT JOIN (
    SELECT 
        product_id, 
        SUM(daily_total_qty) as total_qty, 
        SUM(daily_total_qty * daily_total_qty) as total_sq_qty
    FROM (
        SELECT product_id, date, SUM(qty) as daily_total_qty
        FROM (
            SELECT d.product_id, DATE(o.created_at) as date, SUM(d.quantity) as qty
            FROM outbound_transactions o
            JOIN outbound_transaction_details d ON o.id = d.transaction_id
            WHERE o.status = 'Completed' AND o.warehouse_id = ? AND DATE(o.created_at) BETWEEN ? AND ?
            GROUP BY d.product_id, DATE(o.created_at)
            
            UNION ALL
            SELECT d.product_id, DATE(b.created_at) as date, SUM(d.quantity) as qty
            FROM borrowed_transactions_detail d
            JOIN borrowed_transactions b ON d.transaction_id = b.id
            WHERE b.status IN ('Borrowed', 'Returned') AND b.warehouse_id = ? AND DATE(b.created_at) BETWEEN ? AND ?
            GROUP BY d.product_id, DATE(b.created_at)
            
            UNION ALL
            SELECT aoi.product_id, DATE(ao.created_at) as date, SUM(aoi.qty_out) as qty
            FROM assembly_outbound_items aoi
            JOIN assembly_outbound ao ON ao.id = aoi.outbound_id
            WHERE ao.status = 'Completed' AND ao.warehouse_id = ? AND DATE(ao.created_at) BETWEEN ? AND ?
            GROUP BY aoi.product_id, DATE(ao.created_at)
            
            UNION ALL
            SELECT ti.product_id, DATE(tf.created_at) as date, SUM(ti.quantity) as qty
            FROM transfers tf
            JOIN transfer_items ti ON tf.id = ti.transfer_id
            WHERE tf.status != 'Cancelled' AND tf.source_warehouse_id = ? AND DATE(tf.created_at) BETWEEN ? AND ?
            GROUP BY ti.product_id, DATE(tf.created_at)
        ) as daily_breakdown
        GROUP BY product_id, date
    ) as consolidated_daily
    GROUP BY product_id
) as daily_out ON p.id = daily_out.product_id
WHERE p.is_deleted = 0
GROUP BY p.id, p.product_code, p.product_name, t.type_name, p.lead_time_avg, p.lead_time_max, ws.stock, p.minimum_stock_level
" . ($only_active ? "HAVING sum_qty > 0" : "") . "
ORDER BY p.product_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iisssssssssss", $active_warehouse_id, $active_warehouse_id, $start_date, $end_date, $active_warehouse_id, $start_date, $end_date, $active_warehouse_id, $start_date, $end_date, $active_warehouse_id, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
$products = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>

<style>
    /* CSS Spesifik Halaman Analisis */
    .table-rop th {
        background-color: #f8f9fa !important;
        vertical-align: middle;
        text-align: center;
        font-size: 0.85rem;
        padding: 10px 5px;
        border-bottom: 2px solid #dee2e6;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
    }

    /* Memastikan container tidak memotong sticky header */
    .table-responsive-rop {
        max-height: 70vh;
        overflow-y: auto;
        border-bottom: 1px solid #dee2e6;
    }

    .table-rop td {
        vertical-align: middle;
        font-size: 0.90rem;
    }

    .red-zone {
        background-color: rgba(255, 99, 132, 0.15) !important;
        /* Latar merah pastel murni */
    }

    .red-zone td {
        background-color: transparent !important;
    }

    .status-indicator {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        margin-right: 5px;
    }
</style>

<div class="content-header mb-4">
    <div class="row align-items-center">
        <div class="col-md-6">
            <h1 class="fw-bold"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Analisis Stok & ROP</h1>
            <p class="text-muted mb-0">Prediksi kapan harus memesan barang (Reorder Point) berbasis data historis.</p>
        </div>
        <div class="col-md-6 d-flex justify-content-md-end mt-3 mt-md-0">
            <!-- Parameter Z-Score -->
            <div class="d-flex align-items-center bg-white p-3 rounded shadow-sm border border-primary">
                <i class="bi bi-shield-check fs-2 text-primary me-3"></i>
                <div>
                    <label for="service_level" class="form-label fw-bold mb-0" style="font-size: 0.85rem;">Target
                        Service Level</label>
                    <select class="form-select form-select-sm border-0 fw-bold fs-6 text-dark p-0 bg-transparent"
                        id="service_level" style="cursor: pointer;">
                        <option value="0.8416" selected>80% (Standar Industri)</option>
                        <option value="1.2816">90% (Pasokan Cepat)</option>
                        <option value="1.6449">95% (Barang Kritis)</option>
                        <option value="2.3263">99% (Medis / Sangat Kritis)</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4 border-0">
    <div class="card-body">
        <form action="" method="GET" class="row align-items-end g-3">
            <input type="hidden" name="warehouse_id" value="<?= $active_warehouse_id ?>">
            <div class="col-md-3">
                 <label class="form-label fw-semibold"><i class="bi bi-calendar3 me-1"></i>Tanggal Awal</label>
                 <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-md-3">
                 <label class="form-label fw-semibold"><i class="bi bi-calendar3-fill me-1"></i>Tanggal Akhir</label>
                 <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-md-3">
                 <button type="submit" class="btn btn-primary w-100 rounded-pill"><i class="bi bi-funnel me-1"></i>Kalkulasi (<?= $n_days ?> Hari)</button>
            </div>
            <div class="col-md-3">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="only_active" name="only_active" value="1" <?= $only_active ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="form-check-label fw-semibold" for="only_active">Hanya barang yang keluar</label>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Warehouse Tabs -->
<ul class="nav nav-tabs mb-4 px-3">
    <?php foreach ($warehouses_list as $wh): ?>
    <li class="nav-item">
        <a class="nav-link <?= ($active_warehouse_id == $wh['id']) ? 'active fw-bold' : '' ?>" 
           href="?warehouse_id=<?= $wh['id'] ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&only_active=<?= $only_active ? '1' : '0' ?>">
            🏢 <?= htmlspecialchars($wh['name']) ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive table-responsive-rop">
            <table class="table table-hover table-rop mb-0" id="ropTable">
                <thead>
                    <tr>
                        <th class="text-start ps-3">Nama Produk / Tipe</th>
                        <th>Lead Time<br><small class="text-muted">(Avg/Max)</small></th>
                        <th>vLead Time<br><small class="text-muted">√Avg</small></th>
                        <th>Total Keluar<br><small class="text-muted"><?= $n_days ?> Hr</small></th>
                        <th>Rata-rata<br><small class="text-muted">Per Hari</small></th>
                        <th>Standar Deviasi<br><small class="text-muted">σ</small></th>
                        <th class="bg-light">Safety Stock</th>
                        <th class="bg-primary bg-opacity-10 border-0 text-dark">ROP<br><small>(Titik Pesan)</small></th>
                        <th class="bg-secondary bg-opacity-10 border-0 text-dark">Stok Akhir</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $json_data = []; // Untuk ditarik ke Javascript
                    foreach ($products as $row):
                        // Perhitungan Matematika MURNI SQL Summation to StdDev Formula
                        $sum_n = (float) $row['sum_qty'];
                        $sum_sq = (float) $row['sum_sq_qty'];

                        $avg_daily = $sum_n / $n_days;

                        // Sample Variance Formula: (SumSq - ((Sum * Sum) / N)) / (N - 1)
                        $variance = ($sum_sq - (($sum_n * $sum_n) / $n_days)) / ($n_days - 1);
                        if ($variance < 0)
                            $variance = 0; // Menghindari akar negatif karena presisi float
                        $stddev = sqrt($variance);

                        $vlead_time = sqrt((float)$row['lead_time_avg']);

                        // Kita simpan ke array untuk Javascript mengolah Z-Score multiplier
                        $json_data[] = [
                            'id' => $row['product_id'],
                            'name' => $row['product_name'],
                            'type' => $row['type_name'] ?? '-',
                            'lt_avg' => $row['lead_time_avg'],
                            'lt_max' => $row['lead_time_max'],
                            'vlead' => $vlead_time,
                            'total_out' => $sum_n,
                            'avg_daily' => $avg_daily,
                            'stddev' => $stddev,
                            'stock' => $row['current_stock']
                        ];
                    endforeach;
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Container penyimpan data JSON untuk JS -->
<script type="application/json" id="ropData"><?= json_encode($json_data) ?></script>

<?php include '../../includes/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const rawData = document.getElementById('ropData').textContent;
        const products = JSON.parse(rawData);
        const tbody = document.querySelector('#ropTable tbody');
        const zScoreSelect = document.getElementById('service_level');

        function renderTable() {
            const zScore = parseFloat(zScoreSelect.value);
            tbody.innerHTML = '';

            products.forEach(p => {
                // KALKULASI BERDASARKAN RUMUS EXCEL USER:
                // 1. vLeadTime = SQRT(Lead Time Avg) -> dihitung di PHP
                // 2. Safety Stock = Z-Score * StdDev * vLeadTime
                const safetyStock = zScore * p.stddev * p.vlead;
                
                // 3. ROP = (Rata-rata Keluar * Lead Time Avg) + Safety Stock
                const rop = (p.avg_daily * p.lt_avg) + safetyStock;
                
                // Flag Red Zone jika Stock < ROP (atau sama dengan)
                const isCritical = p.stock <= Math.ceil(rop);
                const rowClass = isCritical ? 'red-zone fw-bold' : '';
                const statusHtml = isCritical
                    ? `<span class="badge bg-danger rounded-pill"><i class="bi bi-exclamation-triangle-fill me-1"></i>Reorder!</span>`
                    : `<span class="badge bg-success rounded-pill px-3">Aman</span>`;

                // Formatting Number
                const fmt = (num) => num.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const fmt0 = (num) => Math.ceil(num).toLocaleString('id-ID'); // Bulatkan ke atas untuk barang fisik

                const tr = document.createElement('tr');
                tr.className = rowClass;
                tr.innerHTML = `
                <td class="text-start ps-3">
                    <div class="fw-bold text-dark">${p.name}</div>
                    <div class="small text-muted">${p.type}</div>
                </td>
                <td class="text-center">
                    <span class="text-primary">${p.lt_avg}</span> / <span class="text-danger">${p.lt_max}</span>
                </td>
                <td class="text-center">${fmt(p.vlead)}</td>
                <td class="text-center fw-bold">${fmt0(p.total_out)}</td>
                <td class="text-center">${fmt(p.avg_daily)}</td>
                <td class="text-center">${fmt(p.stddev)}</td>
                <td class="text-center bg-light text-primary fw-bold" style="border-left:1px dashed #dee2e6;">${fmt(safetyStock)}</td>
                <td class="text-center bg-primary bg-opacity-10 text-primary border-0 fs-5">${fmt(rop)}</td>
                <td class="text-center bg-dark text-white border-0 fs-5 ${p.stock <= 0 ? 'text-danger' : ''}">${p.stock}</td>
                <td class="text-center">${statusHtml}</td>
            `;
                tbody.appendChild(tr);
            });

            // Terapkan DataTables jika ada library-nya
            if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#ropTable')) {
                $('#ropTable').DataTable({
                    "pageLength": 25,
                    "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                    "language": { "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json" },
                    "ordering": true,
                    "order": [[9, "asc"]] // Sort berdasar status (Reorder duluan karena merah biasanya sort value lebih kecil di DOM, tapi ini text base. Lebih baik sort by kolom status.)
                });
            }
        }

        // Trigger update bila z-score dirubah user
        zScoreSelect.addEventListener('change', renderTable);

        // Initial Render
        renderTable();
    });
</script>