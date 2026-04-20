<?php
include '../../includes/db_connect.php';

// Pastikan hanya pengelola yang bisa mengakses
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager', 'procurement', 'staff'])) {
    die("Unauthorized access");
}

// Set rentang tanggal default
$end_date_default = date('Y-m-d');
$start_date_default = date('Y-m-d', strtotime('-90 days'));

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $start_date_default;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : $end_date_default;
$active_warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$only_active = isset($_GET['only_active']) && $_GET['only_active'] == '1';
$z_score = isset($_GET['z_score']) ? (float)$_GET['z_score'] : 0.8416; // Default to 80%

// New Filters: Brand & Product Type
$brand_filter = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;
$type_filter = isset($_GET['product_type_id']) ? (int)$_GET['product_type_id'] : 0;

// Get Brand and Type Names for Header
$brand_name_display = "Semua Merek";
if ($brand_filter > 0) {
    if ($b_r = $conn->query("SELECT brand_name FROM brands WHERE id = $brand_filter")->fetch_assoc()) {
        $brand_name_display = $b_r['brand_name'];
    }
}
$type_name_display = "Semua Tipe";
if ($type_filter > 0) {
    if ($t_r = $conn->query("SELECT type_name FROM product_types WHERE id = $type_filter")->fetch_assoc()) {
        $type_name_display = $t_r['type_name'];
    }
}

// Hitung N Hari
$datetime1 = new DateTime($start_date);
$datetime2 = new DateTime($end_date);
$interval = $datetime1->diff($datetime2);
$n_days = $interval->days + 1;
if ($n_days < 2) $n_days = 2;

// Get Warehouse Name
$wh_name = "Semua Gudang";
if ($active_warehouse_id > 0) {
    $wh_q = $conn->query("SELECT name FROM warehouses WHERE id = $active_warehouse_id");
    if ($wh_r = $wh_q->fetch_assoc()) {
        $wh_name = $wh_r['name'];
    }
}

// SQL Query (Sama seperti rop.php)
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
            WHERE o.status != 'Cancelled' AND o.warehouse_id = ? AND DATE(o.created_at) BETWEEN ? AND ?
            GROUP BY d.product_id, DATE(o.created_at)
            
            UNION ALL
            SELECT d.product_id, DATE(b.created_at) as date, SUM(d.quantity) as qty
            FROM borrowed_transactions_detail d
            JOIN borrowed_transactions b ON d.transaction_id = b.id
            WHERE b.status != 'Cancelled' AND b.warehouse_id = ? AND DATE(b.created_at) BETWEEN ? AND ?
            GROUP BY d.product_id, DATE(b.created_at)
            
            UNION ALL
            SELECT aoi.product_id, DATE(ao.created_at) as date, SUM(aoi.qty_out) as qty
            FROM assembly_outbound_items aoi
            JOIN assembly_outbound ao ON ao.id = aoi.outbound_id
            WHERE ao.status != 'Cancelled' AND ao.warehouse_id = ? AND DATE(ao.created_at) BETWEEN ? AND ?
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
" . ($brand_filter > 0 ? " AND p.brand_id = $brand_filter" : "") . "
" . ($type_filter > 0 ? " AND p.product_type_id = $type_filter" : "") . "
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

// Set Excel Headers
$filename = "Laporan_ROP_" . str_replace(" ", "_", $wh_name) . "_" . date('Ymd_His') . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=$filename");
header("Pragma: no-cache");
header("Expires: 0");

?>
<h3>LAPORAN ANALISIS STOK & REORDER POINT (ROP)</h3>
<table border="1">
    <tr>
        <td colspan="2">Gudang:</td>
        <td colspan="8"><?= htmlspecialchars($wh_name) ?></td>
    </tr>
    <tr>
        <td colspan="2">Periode:</td>
        <td colspan="8"><?= $start_date ?> s/d <?= $end_date ?> (<?= $n_days ?> Hari)</td>
    </tr>
    <tr>
        <td colspan="2">Z-Score:</td>
        <td colspan="8"><?= $z_score ?></td>
    </tr>
    <tr>
        <td colspan="2">Merek:</td>
        <td colspan="8"><?= htmlspecialchars($brand_name_display) ?></td>
    </tr>
    <tr>
        <td colspan="2">Tipe Produk:</td>
        <td colspan="8"><?= htmlspecialchars($type_name_display) ?></td>
    </tr>
</table>
<br>
<table border="1">
    <thead>
        <tr style="background-color: #f2f2f2; font-weight: bold;">
            <th>Unit No</th>
            <th>Nama Produk / Tipe</th>
            <th>Lead Time Avg</th>
            <th>Lead Time Max</th>
            <th>vLead Time (SQRT)</th>
            <th>Total Keluar (<?= $n_days ?> Hr)</th>
            <th>Rata-rata Per Hari</th>
            <th>Std Deviasi (sigma)</th>
            <th>Safety Stock</th>
            <th>ROP (Titik Pesan)</th>
            <th>Stok Akhir</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $i = 1;
        foreach ($products as $row):
            $sum_n = (float) $row['sum_qty'];
            $sum_sq = (float) $row['sum_sq_qty'];
            $avg_daily = $sum_n / $n_days;
            
            $variance = ($sum_sq - (($sum_n * $sum_n) / $n_days)) / ($n_days - 1);
            if ($variance < 0) $variance = 0;
            $stddev = sqrt($variance);

            $vlead_time = sqrt((float)$row['lead_time_avg']);
            
            // Math
            $safetyStock = $z_score * $stddev * $vlead_time;
            $rop = ($avg_daily * (float)$row['lead_time_avg']) + $safetyStock;
            
            $isCritical = (float)$row['current_stock'] <= ceil($rop);
            $status = $isCritical ? "REORDER!" : "AMAN";
            $bg_color = $isCritical ? 'style="background-color: #ffcccc;"' : '';
        ?>
        <tr <?= $bg_color ?>>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($row['product_name']) ?> (<?= htmlspecialchars($row['type_name'] ?? '-') ?>)</td>
            <td><?= $row['lead_time_avg'] ?></td>
            <td><?= $row['lead_time_max'] ?></td>
            <td><?= number_format($vlead_time, 2, ',', '.') ?></td>
            <td><?= number_format($sum_n, 0, ',', '.') ?></td>
            <td><?= number_format($avg_daily, 2, ',', '.') ?></td>
            <td><?= number_format($stddev, 2, ',', '.') ?></td>
            <td><?= number_format($safetyStock, 2, ',', '.') ?></td>
            <td><?= number_format($rop, 2, ',', '.') ?></td>
            <td><?= $row['current_stock'] ?></td>
            <td><?= $status ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
