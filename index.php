<?php
include 'includes/db_connect.php';
include 'includes/header.php';

// ================= AUTH =================
if (!isset($_SESSION['user_id'])) {
    header("Location: /wms-geo/login.php");
    exit();
}
if (!in_array($_SESSION['role'], ['admin','staff','marketing','procurement', 'production'])) {
    header("Location: /wms-geo/login.php");
    exit();
}

// ================= FILTER WAKTU (START-END RANGE) =================
$start_get = $_GET['start_date'] ?? '';
$end_get = $_GET['end_date'] ?? '';

if (!empty($start_get) && !empty($end_get)) {
    // Custom Range from Form
    $start = date('Y-m-d 00:00:00', strtotime($start_get));
    $end   = date('Y-m-d 23:59:59', strtotime($end_get));
    
    // Perhitungan durasi hari untuk periode sebelumnya
    $diff = abs(strtotime($end) - strtotime($start));
    $days = floor($diff / (60 * 60 * 24));
    
    $prev_start = date('Y-m-d 00:00:00', strtotime("-".($days + 1)." days", strtotime($start)));
    $prev_end   = date('Y-m-d 23:59:59', strtotime("-1 days", strtotime($start)));
} else {
    // Standard Range (month, day, week, year)
    $range = $_GET['range'] ?? 'month';
    $date  = $_GET['date'] ?? date('Y-m-d');

    switch ($range) {
        case 'day':
            $start = date('Y-m-d 00:00:00', strtotime($date));
            $end   = date('Y-m-d 23:59:59', strtotime($date));
            $prev_start = date('Y-m-d 00:00:00', strtotime('-1 day', strtotime($date)));
            $prev_end   = date('Y-m-d 23:59:59', strtotime('-1 day', strtotime($date)));
            break;
        case 'week':
            $start = date('Y-m-d 00:00:00', strtotime('monday this week', strtotime($date)));
            $end   = date('Y-m-d 23:59:59', strtotime('sunday this week', strtotime($date)));
            $prev_start = date('Y-m-d 00:00:00', strtotime('-1 week', strtotime($start)));
            $prev_end   = date('Y-m-d 23:59:59', strtotime('-1 week', strtotime($end)));
            break;
        case 'year':
            $start = date('Y-01-01 00:00:00', strtotime($date));
            $end   = date('Y-12-31 23:59:59', strtotime($date));
            $prev_start = date('Y-01-01 00:00:00', strtotime('-1 year', strtotime($date)));
            $prev_end   = date('Y-12-31 23:59:59', strtotime('-1 year', strtotime($date)));
            break;
        default: // month
            $start = date('Y-m-01 00:00:00', strtotime($date));
            $end   = date('Y-m-t 23:59:59', strtotime($date));
            $prev_start = date('Y-m-01 00:00:00', strtotime('-1 month', strtotime($date)));
            $prev_end   = date('Y-m-t 23:59:59', strtotime('-1 month', strtotime($date)));
    }
}

// ================= STATISTIK UMUM =================
$warehouse_id = $_SESSION['warehouse_id'] ?? 0;

if ($warehouse_id <= 0) {
    echo "Sesi lokasi gudang tidak ditemukan. Silakan <a href='/wms-geo/login.php'>Login kembali</a> untuk memilih gudang.";
    include 'includes/footer.php';
    exit();
}

$total_products = $conn->query("SELECT COUNT(*) FROM products WHERE is_deleted = 0 AND created_at BETWEEN '$start' AND '$end'")->fetch_row()[0];
// Corrected: Stock check must be based on product_stocks for the specific warehouse
$low_stock_products = $conn->query("SELECT COUNT(p.id) FROM products p 
                                    JOIN warehouse_stocks ps ON p.id = ps.product_id 
                                    WHERE ps.stock <= p.minimum_stock_level 
                                    AND p.is_deleted = 0 
                                    AND ps.warehouse_id = $warehouse_id")->fetch_row()[0];
$total_users = $conn->query("SELECT COUNT(*) FROM users WHERE created_at BETWEEN '$start' AND '$end'")->fetch_row()[0];

// ================= TOTAL PERIODE SEKARANG =================
$total_inbound_items = $conn->query("
SELECT SUM(itd.quantity)
FROM inbound_transaction_details itd
JOIN inbound_transactions it ON it.id = itd.transaction_id
WHERE it.is_deleted = 0 AND it.warehouse_id = $warehouse_id
AND it.created_at BETWEEN '$start' AND '$end'
")->fetch_row()[0] ?? 0;

$total_outbound_items = $conn->query("
SELECT SUM(otd.quantity)
FROM outbound_transaction_details otd
JOIN outbound_transactions ot ON ot.id = otd.transaction_id
WHERE ot.is_deleted = 0 AND ot.warehouse_id = $warehouse_id
AND ot.created_at BETWEEN '$start' AND '$end'
")->fetch_row()[0] ?? 0;

$total_borrowed_items = $conn->query("
SELECT SUM(bi.quantity)
FROM borrowed_transactions_detail bi
JOIN borrowed_transactions bt ON bt.id = bi.transaction_id
WHERE bt.is_deleted = 0 AND bt.warehouse_id = $warehouse_id
AND bt.status != 'sudah kembali'
AND bt.created_at BETWEEN '$start' AND '$end'
")->fetch_row()[0] ?? 0;

// ================= TOTAL PERIODE SEBELUMNYA =================
$prev_inbound_items = $conn->query("
SELECT SUM(itd.quantity)
FROM inbound_transaction_details itd
JOIN inbound_transactions it ON it.id = itd.transaction_id
WHERE it.is_deleted = 0 AND it.warehouse_id = $warehouse_id
AND it.created_at BETWEEN '$prev_start' AND '$prev_end'
")->fetch_row()[0] ?? 0;

$prev_outbound_items = $conn->query("
SELECT SUM(otd.quantity)
FROM outbound_transaction_details otd
JOIN outbound_transactions ot ON ot.id = otd.transaction_id
WHERE ot.is_deleted = 0 AND ot.warehouse_id = $warehouse_id
AND ot.created_at BETWEEN '$prev_start' AND '$prev_end'
")->fetch_row()[0] ?? 0;

$prev_borrowed_items = $conn->query("
SELECT SUM(bi.quantity)
FROM borrowed_transactions_detail bi
JOIN borrowed_transactions bt ON bt.id = bi.transaction_id
WHERE bt.is_deleted = 0 AND bt.warehouse_id = $warehouse_id
AND bt.status != 'sudah kembali'
AND bt.created_at BETWEEN '$prev_start' AND '$prev_end'
")->fetch_row()[0] ?? 0;

// ================= PERSENTASE =================
$total_activity = $total_inbound_items + $total_outbound_items + $total_borrowed_items;

$percent_in  = $total_activity ? round($total_inbound_items / $total_activity * 100) : 0;
$percent_out = $total_activity ? round($total_outbound_items / $total_activity * 100) : 0;
$percent_bor = $total_activity ? round($total_borrowed_items / $total_activity * 100) : 0;

// ================= TREND =================
function trend($current, $previous) {
    if ($previous == 0) {
        return $current > 0 ? 100 : 0;
    }
    return round((($current - $previous) / $previous) * 100);
}

$trend_in  = trend($total_inbound_items,  $prev_inbound_items);
$trend_out = trend($total_outbound_items, $prev_outbound_items);
$trend_bor = trend($total_borrowed_items, $prev_borrowed_items);


// ================= STATS MODUL BARU =================
// 1. Transfer Gudang (In Transit)
$total_transfer_transit = $conn->query("
    SELECT COUNT(*) 
    FROM transfers 
    WHERE status = 'In Transit' 
    AND (source_warehouse_id = $warehouse_id OR destination_warehouse_id = $warehouse_id)
    AND created_at BETWEEN '$start' AND '$end'
")->fetch_row()[0] ?? 0;

// 2. Stock Request (Pending)
$total_sr_pending = $conn->query("
    SELECT COUNT(*) 
    FROM stock_requests 
    WHERE status = 'Pending'
    AND created_at BETWEEN '$start' AND '$end'
")->fetch_row()[0] ?? 0;

// ================= TOP OUTBOUND (Split: Garmin & Non-Cable) =================
// 1. Khusus Garmin
$top_garmin = $conn->query("
    SELECT p.product_name, SUM(otd.quantity) total_quantity_out, p.unit
    FROM outbound_transaction_details otd
    JOIN outbound_transactions ot ON ot.id = otd.transaction_id
    JOIN products p ON p.id = otd.product_id
    JOIN brands b ON p.brand_id = b.id
    WHERE ot.is_deleted = 0 AND ot.warehouse_id = $warehouse_id
    AND ot.created_at BETWEEN '$start' AND '$end'
    AND b.brand_name LIKE '%Garmin%'
    GROUP BY p.product_name, p.unit
    ORDER BY total_quantity_out DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// 2. Selain Kabel & Selain Garmin
$top_non_cable = $conn->query("
    SELECT p.product_name, SUM(otd.quantity) total_quantity_out, p.unit
    FROM outbound_transaction_details otd
    JOIN outbound_transactions ot ON ot.id = otd.transaction_id
    JOIN products p ON p.id = otd.product_id
    JOIN brands b ON p.brand_id = b.id
    WHERE ot.is_deleted = 0 AND ot.warehouse_id = $warehouse_id
    AND ot.created_at BETWEEN '$start' AND '$end'
    AND p.product_name NOT LIKE '%kabel%'
    AND p.product_name NOT LIKE '%cable%'
    AND b.brand_name NOT LIKE '%Garmin%'
    GROUP BY p.product_name, p.unit
    ORDER BY total_quantity_out DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ================= AKTIVITAS TERKINI (ALL TYPES) =================
$sql_activity = "
(SELECT 'IN' COLLATE utf8mb4_unicode_ci as type, it.created_at as date, p.product_name COLLATE utf8mb4_unicode_ci as product_name, itd.quantity, it.id as transaction_id, it.transaction_number COLLATE utf8mb4_unicode_ci as transaction_no, it.sender COLLATE utf8mb4_unicode_ci as from_wh, '' COLLATE utf8mb4_unicode_ci as to_wh, it.sender COLLATE utf8mb4_unicode_ci as destination
 FROM inbound_transaction_details itd
 JOIN inbound_transactions it ON it.id = itd.transaction_id
 JOIN products p ON p.id = itd.product_id
 WHERE it.is_deleted = 0 AND it.warehouse_id = $warehouse_id
 AND it.created_at BETWEEN '$start' AND '$end')
UNION ALL
(SELECT 'OUT' COLLATE utf8mb4_unicode_ci as type, ot.created_at as date, p.product_name COLLATE utf8mb4_unicode_ci as product_name, otd.quantity, ot.id as transaction_id, ot.transaction_number COLLATE utf8mb4_unicode_ci as transaction_no, '' COLLATE utf8mb4_unicode_ci as from_wh, ot.recipient COLLATE utf8mb4_unicode_ci as to_wh, ot.recipient COLLATE utf8mb4_unicode_ci as destination
 FROM outbound_transaction_details otd
 JOIN outbound_transactions ot ON ot.id = otd.transaction_id
 JOIN products p ON p.id = otd.product_id
 WHERE ot.is_deleted = 0 AND ot.warehouse_id = $warehouse_id
 AND ot.created_at BETWEEN '$start' AND '$end')
UNION ALL
(SELECT 'BORROW' COLLATE utf8mb4_unicode_ci as type, bt.created_at as date, p.product_name COLLATE utf8mb4_unicode_ci as product_name, bi.quantity, bt.id as transaction_id, bt.transaction_number COLLATE utf8mb4_unicode_ci as transaction_no, '' COLLATE utf8mb4_unicode_ci as from_wh, '' COLLATE utf8mb4_unicode_ci as to_wh, bt.borrower_name COLLATE utf8mb4_unicode_ci as destination
 FROM borrowed_transactions_detail bi
 JOIN borrowed_transactions bt ON bt.id = bi.transaction_id
 JOIN products p ON p.id = bi.product_id
 WHERE bt.is_deleted = 0 AND bt.warehouse_id = $warehouse_id
 AND bt.created_at BETWEEN '$start' AND '$end')
UNION ALL
(SELECT 'TRF' COLLATE utf8mb4_unicode_ci as type, t.created_at as date, p.product_name COLLATE utf8mb4_unicode_ci as product_name, ti.quantity, t.id as transaction_id, t.transfer_no COLLATE utf8mb4_unicode_ci as transaction_no, w1.name COLLATE utf8mb4_unicode_ci as from_wh, w2.name COLLATE utf8mb4_unicode_ci as to_wh, w2.name COLLATE utf8mb4_unicode_ci as destination
 FROM transfer_items ti
 JOIN transfers t ON t.id = ti.transfer_id
 JOIN products p ON p.id = ti.product_id
 JOIN warehouses w1 ON t.source_warehouse_id = w1.id
 JOIN warehouses w2 ON t.destination_warehouse_id = w2.id
 WHERE (t.source_warehouse_id = $warehouse_id OR t.destination_warehouse_id = $warehouse_id)
 AND t.created_at BETWEEN '$start' AND '$end')
ORDER BY date DESC
LIMIT 100
";

$all_activities_res = $conn->query($sql_activity);
$all_activities = [];
if ($all_activities_res) {
    while ($row = $all_activities_res->fetch_assoc()) {
        $row['time_ago'] = time_ago($row['date']);
        $row['unit'] = 'Unit'; // Default, idealnya join tabel unit
        $all_activities[] = $row;
    }
}

// Fungsi pembantu untuk menghitung "time ago"
function time_ago($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'tahun',
        'm' => 'bulan',
        'w' => 'minggu',
        'd' => 'hari',
        'h' => 'jam',
        'i' => 'menit',
        's' => 'detik',
    );
    
    foreach ($string as $k => &$v) {
        if (isset($diff->$k) && $diff->$k) {
            $v = $diff->$k . ' ' . $v;
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) {
        $string = array_slice($string, 0, 1);
    }
    
    return $string ? implode(', ', $string) . ' lalu' : 'baru saja';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
        }

        .card-dashboard {
            background: #ffffff;
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s ease;
        }
        .card-dashboard:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .bg-primary-soft { background-color: #e3f2fd; color: #1976d2; }
        .bg-warning-soft { background-color: #fff3e0; color: #f57c00; }
        .bg-success-soft { background-color: #e8f5e9; color: #2e7d32; }
        .bg-danger-soft { background-color: #ffebee; color: #d32f2f; }
        .bg-info-soft { background-color: #e0f7fa; color: #0097a7; }
        .bg-purple-soft { background-color: #f3e5f5; color: #7b1fa2; }
        
        .hover-primary:hover {
            color: #0d6efd !important;
            text-decoration: underline !important;
            cursor: pointer;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #444;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        .section-title i { margin-right: 10px; color: #3498DB; }
        
        .activity-item {
            border-left: 3px solid transparent;
            padding-left: 15px !important;
            transition: all 0.2s;
        }
        .activity-item:hover {
            background-color: #f8f9fa;
            border-left-color: #3498DB;
        }
        .card-header {
            background-color: transparent !important;
            border-bottom: 1px solid rgba(0,0,0,0.05) !important;
        }
        .filter-card {
            background: #f8f9fa;
            border: none;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 25px;
        }
        .welcome-alert {
            border: none;
            border-radius: 10px;
            background-color: #e8f5e9;
            color: #2e7d32;
            padding: 10px 20px;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 fw-bold text-dark">Ringkasan Gudang</h1>
            <p class="text-muted small mb-0">Monitoring stok dan mutasi barang secara real-time.</p>
        </div>
        <div class="welcome-alert d-flex align-items-center mb-0 shadow-sm border border-success-subtle px-3 py-2">
            <i class="bi bi-person-badge-fill me-2 text-success fs-5"></i>
            <?php 
            $role_names = [
                'admin' => 'Kepala Gudang',
                'staff' => 'Staff Gudang',
                'marketing' => 'Marketing',
                'procurement' => 'Procurement'
            ];
            $display_role = $role_names[$_SESSION['role'] ?? ''] ?? ucfirst($_SESSION['role'] ?? 'User');
            ?>
            <div class="small fw-semibold">
                Welcome, <span class="text-success"><?= htmlspecialchars($_SESSION['name'] ?? 'Guest') ?></span>! 👋 
                <span class="badge bg-success-subtle text-success border border-success ms-1" style="font-size: 0.65rem;"><?= $display_role ?></span>
            </div>
        </div>
    </div>

    <!-- FILTER TOOLBAR -->
    <div class="filter-card shadow-sm border mb-4 bg-white p-3">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-lg-3 col-md-4">
                <label class="form-label small fw-bold text-muted mb-1"><i class="bi bi-calendar-date me-1"></i>Mulai dari:</label>
                <input type="date" name="start_date" class="form-control form-control-sm border-0 bg-light" value="<?= date('Y-m-d', strtotime($start)) ?>">
            </div>
            <div class="col-lg-3 col-md-4">
                <label class="form-label small fw-bold text-muted mb-1"><i class="bi bi-calendar-check me-1"></i>Hingga:</label>
                <input type="date" name="end_date" class="form-control form-control-sm border-0 bg-light" value="<?= date('Y-m-d', strtotime($end)) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm"><i class="bi bi-funnel-fill me-1"></i> Terapkan</button>
            </div>
            <div class="col-auto">
                <a href="index.php" class="btn btn-light btn-sm px-4 border shadow-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
            </div>
            <div class="col text-end d-none d-xl-block">
                <div class="badge bg-primary-subtle text-primary border border-primary px-3 py-2">
                    <i class="bi bi-calendar-range me-2"></i> <?= date('d M Y', strtotime($start)) ?> — <?= date('d M Y', strtotime($end)) ?>
                </div>
            </div>
        </form>
    </div>

<?php
    // Mengambil tanggal mulai dan akhir dari permintaan GET
    $start = $_GET['start_date'] ?? null;
    $end = $_GET['end_date'] ?? null;

    if ($start && $end) {
        // Jika ada filter rentang tanggal
        $start = date('Y-m-d 00:00:00', strtotime($start));
        $end = date('Y-m-d 23:59:59', strtotime($end));
    } else {
        // Jika tidak ada filter, ambil total keseluruhan
        $start = '1970-01-01 00:00:00';
        $end = date('Y-m-d H:i:s'); // Menampilkan semua data hingga saat ini
    }

    // ================= STATS KESELURUHAN =================
    $total_inbound_items = $conn->query("
    SELECT SUM(itd.quantity)
    FROM inbound_transaction_details itd
    JOIN inbound_transactions it ON it.id = itd.transaction_id
    WHERE it.is_deleted = 0 AND it.warehouse_id = $warehouse_id
    AND it.created_at BETWEEN '$start' AND '$end'
    ")->fetch_row()[0] ?? 0;

    $total_outbound_items = $conn->query("
    SELECT SUM(otd.quantity)
    FROM outbound_transaction_details otd
    JOIN outbound_transactions ot ON ot.id = otd.transaction_id
    WHERE ot.is_deleted = 0 AND ot.warehouse_id = $warehouse_id
    AND ot.created_at BETWEEN '$start' AND '$end'
    ")->fetch_row()[0] ?? 0;

    $total_borrowed_items = $conn->query("
    SELECT SUM(bi.quantity)
    FROM borrowed_transactions_detail bi
    JOIN borrowed_transactions bt ON bt.id = bi.transaction_id
    WHERE bt.is_deleted = 0 AND bt.warehouse_id = $warehouse_id
    AND bt.status != 'sudah kembali'
    AND bt.created_at BETWEEN '$start' AND '$end'
    ")->fetch_row()[0] ?? 0;

    // ================= DATA UNTUK CHART =================
    $top_outbound_products = $conn->query("
    SELECT p.product_name, SUM(otd.quantity) total_quantity_out, p.unit
    FROM outbound_transaction_details otd
    JOIN outbound_transactions ot ON ot.id = otd.transaction_id
    JOIN products p ON p.id = otd.product_id
    WHERE ot.is_deleted = 0 AND ot.warehouse_id = $warehouse_id
    AND ot.created_at BETWEEN '$start' AND '$end'
    AND p.product_name NOT LIKE '%kabel%'
    AND p.product_name NOT LIKE '%cable%'
    GROUP BY p.product_name, p.unit
    ORDER BY total_quantity_out DESC
    LIMIT 10
    ")->fetch_all(MYSQLI_ASSOC);
?>
<!-- ROW 1: PRIMARY STATS (4 Cards) -->
<div class="row g-4 mb-4">
    <!-- Total Produk -->
    <div class="col-xl-3 col-md-6">
        <div class="card card-dashboard h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="stat-icon bg-primary-soft">
                        <i class="bi bi-boxes"></i>
                    </div>
                </div>
                <h6 class="text-muted small mb-1">Total SKU Produk</h6>
                <div class="d-flex align-items-baseline">
                    <h3 class="fw-bold mb-0 text-dark"><?= number_format($total_products) ?></h3>
                </div>
            </div>
        </div>
    </div>
    <!-- Stok Rendah -->
    <div class="col-xl-3 col-md-6">
        <div class="card card-dashboard h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="stat-icon bg-warning-soft">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <?php if($low_stock_products > 0): ?>
                        <span class="badge bg-danger text-white rounded-pill px-2 py-1" style="font-size: 0.6rem;">Urgent</span>
                    <?php endif; ?>
                </div>
                <h6 class="text-muted small mb-1">Stok di Bawah Limit</h6>
                <h3 class="fw-bold mb-0 text-dark"><?= $low_stock_products ?> <small class="text-muted fs-6 fw-normal">Item</small></h3>
            </div>
        </div>
    </div>
    <!-- Barang Masuk -->
    <div class="col-xl-3 col-md-6">
        <div class="card card-dashboard h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="stat-icon bg-success-soft">
                        <i class="bi bi-arrow-down-left"></i>
                    </div>
                </div>
                <h6 class="text-muted small mb-1">Total Unit Masuk</h6>
                <h3 class="fw-bold mb-0 text-dark"><?= number_format($total_inbound_items) ?> <small class="text-muted fs-6 fw-normal">Unit</small></h3>
            </div>
        </div>
    </div>
    <!-- Barang Keluar -->
    <div class="col-xl-3 col-md-6">
        <div class="card card-dashboard h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="stat-icon bg-danger-soft">
                        <i class="bi bi-arrow-up-right"></i>
                    </div>
                </div>
                <h6 class="text-muted small mb-1">Total Unit Keluar</h6>
                <h3 class="fw-bold mb-0 text-dark"><?= number_format($total_outbound_items) ?> <small class="text-muted fs-6 fw-normal">Unit</small></h3>
            </div>
        </div>
    </div>
</div>

<!-- ROW 2: MODULE METRICS (4 Cards Compact) -->
<div class="row g-4 mb-4">
    <!-- Transfer Transit -->
    <div class="col-lg-3 col-md-6">
        <div class="card card-dashboard border-0">
            <div class="card-body py-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info-soft me-3" style="width: 38px; height: 38px; font-size: 16px;">
                        <i class="bi bi-truck"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block" style="font-size: 0.75rem;">Transfer Transit</small>
                        <h5 class="fw-bold mb-0"><?= $total_transfer_transit ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Stock Request Pending -->
    <div class="col-lg-3 col-md-6">
        <div class="card card-dashboard border-0">
            <div class="card-body py-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger-soft me-3" style="width: 38px; height: 38px; font-size: 16px;">
                        <i class="bi bi-cart-plus"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block" style="font-size: 0.75rem;">SR Pending</small>
                        <h5 class="fw-bold mb-0"><?= $total_sr_pending ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Borrows -->
    <div class="col-lg-3 col-md-6">
        <div class="card card-dashboard border-0">
            <div class="card-body py-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-purple-soft me-3" style="width: 38px; height: 38px; font-size: 16px;">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block" style="font-size: 0.75rem;">Dipinjam (Unit)</small>
                        <h5 class="fw-bold mb-0"><?= $total_borrowed_items ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Users -->
    <div class="col-lg-3 col-md-6">
        <div class="card card-dashboard border-0">
            <div class="card-body py-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info-soft me-3" style="width: 38px; height: 38px; font-size: 16px;">
                        <i class="bi bi-people"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block" style="font-size: 0.75rem;">System Users</small>
                        <h5 class="fw-bold mb-0"><?= $total_users ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <!-- ROW 3: INSIGHTS & ACTIVITY -->
    <div class="row g-4 mb-4">
        <!-- Aktivitas Terkini (8/12) -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 d-flex flex-column h-100" style="min-height: 550px;">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark d-flex align-items-center">
                        <i class="bi bi-clock-history me-2 text-primary"></i> Aktivitas Terkini
                    </h5>
                </div>
                <div class="card-body p-0 flex-grow-1 overflow-auto">
                    <div class="list-group list-group-flush">
                    <?php if (empty($all_activities)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-4 text-muted d-block mb-2"></i>
                            <span class="text-muted">Tidak ada aktivitas pada periode ini.</span>
                        </div>
                    <?php else: ?>
                        <?php 
                        // Ambil 10 aktivitas terbaru untuk tampilan dashboard
                        $display_activities = array_slice($all_activities, 0, 10);
                        foreach ($display_activities as $activity): 
                            $badge_class = 'bg-secondary';
                            $label = $activity['type'];
                            if ($activity['type'] == 'IN') { $badge_class = 'bg-success-subtle text-success'; $label = 'MASUK'; }
                            if ($activity['type'] == 'OUT') { $badge_class = 'bg-danger-subtle text-danger'; $label = 'KELUAR'; }
                            if ($activity['type'] == 'BORROW') { $badge_class = 'bg-info-subtle text-info'; $label = 'PINJAM'; }
                            if ($activity['type'] == 'TRF') { $badge_class = 'bg-primary-subtle text-primary'; $label = 'TRANS'; }
                        ?>
                            <li class="list-group-item d-flex justify-content-between align-items-start activity-item border-0 py-3 border-bottom-subtle">
                                <div class="d-flex align-items-start w-100 overflow-hidden">
                                    <span class="badge <?= $badge_class ?> me-3 py-2 px-1 rounded-2 text-uppercase fw-bold text-center" style="width: 55px; font-size: 9px; flex-shrink: 0;">
                                        <?= $label ?>
                                    </span>
                                    <div class="w-100 text-truncate">
                                        <div class="small fw-bold text-dark mb-1 d-flex align-items-center">
                                            <?php 
                                            $link = "#";
                                            if ($activity['type'] == 'IN') $link = "views/inbound/detail.php?id=" . $activity['transaction_id'];
                                            if ($activity['type'] == 'OUT') $link = "views/outbound/detail.php?id=" . $activity['transaction_id'];
                                            if ($activity['type'] == 'BORROW') $link = "views/borrow/detail.php?id=" . $activity['transaction_id'];
                                            if ($activity['type'] == 'TRF') $link = "views/transfer/detail.php?id=" . $activity['transaction_id'];
                                            ?>
                                            <a href="<?= $link ?>" class="text-decoration-none text-muted me-2 font-monospace hover-primary" style="font-size: 10px; border-bottom: 1px dashed #ccc;"><?= $activity['transaction_no'] ?></a>
                                            <span class="activity-product-name text-truncate" style="max-width: 100%;"><?= htmlspecialchars($activity['product_name']) ?></span>
                                        </div>
                                        <div class="text-muted" style="font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <span class="fw-bold text-dark">(<?= number_format($activity['quantity']) ?> <?= htmlspecialchars($activity['unit']) ?>)</span>
                                            <?php if ($activity['type'] == 'TRF'): ?>
                                                transfer <span class="text-primary fw-bold" style="font-size: 10px;">(<?= htmlspecialchars($activity['from_wh']) ?> &raquo; <?= htmlspecialchars($activity['to_wh']) ?>)</span>
                                            <?php elseif ($activity['type'] == 'BORROW'): ?>
                                                peminjaman oleh <span class="text-info fw-bold" style="font-size: 10px;">"<?= htmlspecialchars($activity['destination']) ?>"</span>
                                            <?php else: ?>
                                                <?= $activity['type'] == 'IN' ? 'masuk dari' : 'keluar untuk' ?> 
                                                <span class="text-dark fw-bold" style="font-size: 10px;">"<?= htmlspecialchars($activity['destination']) ?>"</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <small class="text-muted opacity-75 ms-2 flex-shrink-0" style="font-size: 11px; white-space: nowrap;">
                                        <i class="bi bi-clock me-1"></i><?= $activity['time_ago'] ?>
                                    </small>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer bg-white text-center py-2 border-0">
                    <a href="all_activities.php" class="btn btn-link btn-sm text-decoration-none text-muted fw-bold">Lihat Semua Aktivitas <i class="bi bi-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>

        <!-- Top Outbound (4/12) -->
        <div class="col-lg-4 flex-row">
            <!-- Part 1: Garmin -->
            <div class="card shadow-sm border-0 rounded-4 mb-4" style="min-height: 280px;">
                <div class="card-header bg-white py-2 border-0">
                    <h6 class="mb-0 fw-bold text-dark d-flex align-items-center">
                        <i class="bi bi-star-fill me-2 text-warning"></i> Top Outbound: Garmin
                    </h6>
                </div>
                <div class="card-body p-2">
                    <div style="height: 200px;">
                        <?php if (empty($top_garmin)): ?>
                            <div class="text-center py-4 text-muted small">Data Garmin tidak ditemukan.</div>
                        <?php else: ?>
                            <canvas id="garminBarChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Part 2: Non-Cable General -->
            <div class="card shadow-sm border-0 rounded-4" style="min-height: 280px;">
                <div class="card-header bg-white py-2 border-0">
                    <h6 class="mb-0 fw-bold text-dark d-flex align-items-center">
                        <i class="bi bi-box-seam me-2 text-primary"></i> Top Outbound: Umum (Unit)
                    </h6>
                </div>
                <div class="card-body p-2">
                    <div style="height: 200px;">
                        <?php if (empty($top_non_cable)): ?>
                            <div class="text-center py-4 text-muted small">Data tidak ditemukan.</div>
                        <?php else: ?>
                            <canvas id="nonCableBarChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div> <!-- Close container-fluid -->

<?php include 'includes/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Inisialisasi tooltip Bootstrap 5
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
          return new bootstrap.Tooltip(tooltipTriggerEl)
        })
        // Helper function for building horizontal bar charts
        function createHorizontalBar(ctxId, data) {
            if (!data || data.length === 0) return;
            
            const labels = data.map(item => item.product_name);
            const values = data.map(item => item.total_quantity_out);
            const units  = data.map(item => item.unit || 'Unit');
            
            const ctx = document.getElementById(ctxId).getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: ctxId === 'garminBarChart' ? '#F1C40F' : '#3498DB',
                        borderRadius: 4,
                        barThickness: 15
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => ctx.parsed.x + ' ' + units[ctx.dataIndex]
                            }
                        }
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { display: false }, ticks: { font: { size: 9 } } },
                        y: { 
                            grid: { display: false }, 
                            ticks: { 
                                font: { size: 9 },
                                callback: function(v) {
                                    let l = this.getLabelForValue(v);
                                    return l.length > 15 ? l.substring(0, 15) + '...' : l;
                                }
                            } 
                        }
                    }
                }
            });
        }

        // Initialize both charts
        createHorizontalBar('garminBarChart', <?= json_encode($top_garmin) ?>);
        createHorizontalBar('nonCableBarChart', <?= json_encode($top_non_cable) ?>);
    });
</script>

</body>
</html>