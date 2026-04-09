<?php
include 'includes/db_connect.php';
require('includes/fpdf/fpdf.php');

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    exit('Unauthorized');
}

// Ambil parameter dari URL
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date   = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

if ($product_id <= 0) exit('Invalid Product ID');

// Info Produk
$sql_product = "SELECT product_name, product_code, unit FROM products WHERE id = ?";
$stmt = $conn->prepare($sql_product);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$product_name = $product['product_name'] ?? 'N/A';
$product_code = $product['product_code'] ?? 'N/A';
$unit = $product['unit'] ?? 'unit';
$stmt->close();

// --- LOGIKA KALKULASI (Sama dengan product_history.php) ---
$total_in = 0; $total_out = 0; $initial_balance = 0;

// 1. Discrepancy (Total Pergerakan vs Stok Riil)
$sql_disc = [
    "SELECT SUM(itd.quantity) as q_in, 0 as q_out FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id WHERE itd.product_id = ? AND it.is_deleted = 0",
    "SELECT 0 as q_in, SUM(otd.quantity) as q_out FROM outbound_transaction_details otd JOIN outbound_transactions ot ON ot.id = otd.transaction_id WHERE otd.product_id = ? AND ot.is_deleted = 0",
    "SELECT 0 as q_in, SUM(btd.quantity) as q_out FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = ? AND bt.is_deleted = 0",
    "SELECT SUM(btd.quantity) as q_in, 0 as q_out FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = ? AND btd.return_date IS NOT NULL AND bt.is_deleted = 0",
    "SELECT 0 as q_in, SUM(aoi.qty_out) as q_out FROM assembly_outbound_items aoi JOIN assembly_outbound ao ON ao.id = aoi.outbound_id WHERE aoi.product_id = ?",
    "SELECT SUM(ao.total_units) as q_in, 0 as q_out FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id WHERE a.finished_product_id = ? AND ao.status = 'Completed'",
    "SELECT SUM(IF(type='Masuk', quantity, 0)) as q_in, SUM(IF(type='Keluar', quantity, 0)) as q_out FROM stock_adjustments WHERE product_id = ?"
];
$total_movements = 0;
foreach ($sql_disc as $q) {
    $st = $conn->prepare($q);
    $st->bind_param("i", $product_id);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $total_movements += ($res['q_in'] - $res['q_out']);
    $st->close();
}

$res_stock = $conn->query("SELECT stock FROM products WHERE id = $product_id");
$current_actual_stock = (int)($res_stock->fetch_assoc()['stock'] ?? 0);
$system_start_balance = $current_actual_stock - $total_movements;
$initial_balance = $system_start_balance;

// 2. Saldo Sebelum Filter Tanggal
if (!empty($start_date)) {
    $bal_sql = [
        "SELECT SUM(itd.quantity) as q_in, 0 as q_out FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id WHERE itd.product_id = ? AND it.is_deleted = 0 AND DATE(it.created_at) < '$start_date'",
        "SELECT 0 as q_in, SUM(otd.quantity) as q_out FROM outbound_transaction_details otd JOIN outbound_transactions ot ON ot.id = otd.transaction_id WHERE otd.product_id = ? AND ot.is_deleted = 0 AND DATE(ot.created_at) < '$start_date'",
        "SELECT 0 as q_in, SUM(btd.quantity) as q_out FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = ? AND bt.is_deleted = 0 AND DATE(bt.created_at) < '$start_date'",
        "SELECT SUM(btd.quantity) as q_in, 0 as q_out FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = ? AND btd.return_date IS NOT NULL AND bt.is_deleted = 0 AND DATE(btd.return_date) < '$start_date'",
        "SELECT 0 as q_in, SUM(aoi.qty_out) as q_out FROM assembly_outbound_items aoi JOIN assembly_outbound ao ON ao.id = aoi.outbound_id WHERE aoi.product_id = ? AND DATE(ao.created_at) < '$start_date'",
        "SELECT SUM(ao.total_units) as q_in, 0 as q_out FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id WHERE a.finished_product_id = ? AND ao.status = 'Completed' AND DATE(ao.created_at) < '$start_date'",
        "SELECT SUM(IF(type='Masuk', quantity, 0)) as q_in, SUM(IF(type='Keluar', quantity, 0)) as q_out FROM stock_adjustments WHERE product_id = ? AND DATE(created_at) < '$start_date'"
    ];
    foreach ($bal_sql as $q) {
        $st = $conn->prepare($q);
        $st->bind_param("i", $product_id);
        $st->execute();
        $res = $st->get_result()->fetch_assoc();
        $initial_balance += ($res['q_in'] - $res['q_out']);
        $st->close();
    }
}

// 3. Main Query Histori (Filtered)
$sql_parts = [];
$params = []; $types = "";

// Inbound
if (empty($type_filter) || $type_filter == 'Masuk') {
    $w = "WHERE itd.product_id = ? AND it.is_deleted = 0";
    $p = [$product_id]; $t = "i";
    if (!empty($start_date)) { $w .= " AND DATE(it.created_at) >= ?"; $p[] = $start_date; $t .= "s"; }
    if (!empty($end_date))   { $w .= " AND DATE(it.created_at) <= ?"; $p[] = $end_date; $t .= "s"; }
    $sql_parts[] = "(SELECT it.created_at AS date, 'Masuk' AS type, it.transaction_number, it.id AS transaction_id, SUM(itd.quantity) as quantity, it.sender AS counterparty, SUM(itd.quantity) AS quantity_in, 0 AS quantity_out 
                    FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id $w GROUP BY it.id)";
    $params = array_merge($params, $p); $types .= $t;
}
// Outbound
if (empty($type_filter) || $type_filter == 'Keluar') {
    $w = "WHERE otd.product_id = ? AND ot.is_deleted = 0";
    $p = [$product_id]; $t = "i";
    if (!empty($start_date)) { $w .= " AND DATE(ot.created_at) >= ?"; $p[] = $start_date; $t .= "s"; }
    if (!empty($end_date))   { $w .= " AND DATE(ot.created_at) <= ?"; $p[] = $end_date; $t .= "s"; }
    $sql_parts[] = "(SELECT ot.created_at AS date, 'Keluar' AS type, ot.transaction_number, ot.id AS transaction_id, SUM(otd.quantity) as quantity, ot.recipient AS counterparty, 0 AS quantity_in, SUM(otd.quantity) AS quantity_out 
                    FROM outbound_transaction_details otd JOIN outbound_transactions ot ON ot.id = otd.transaction_id $w GROUP BY ot.id)";
    $params = array_merge($params, $p); $types .= $t;
}
// Borrow/Return
if (empty($type_filter) || $type_filter == 'Peminjaman') {
    // Keluar (Borrow)
    $w_b = "WHERE btd.product_id = ? AND bt.is_deleted = 0";
    $p_b = [$product_id]; $t_b = "i";
    if (!empty($start_date)) { $w_b .= " AND DATE(bt.created_at) >= ?"; $p_b[] = $start_date; $t_b .= "s"; }
    if (!empty($end_date))   { $w_b .= " AND DATE(bt.created_at) <= ?"; $p_b[] = $end_date; $t_b .= "s"; }
    $sql_parts[] = "(SELECT bt.created_at AS date, 'Peminjaman' AS type, bt.transaction_number, bt.id AS transaction_id, SUM(btd.quantity) as quantity, bt.borrower_name AS counterparty, 0 AS quantity_in, SUM(btd.quantity) AS quantity_out 
                    FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id $w_b GROUP BY bt.id)";
    $params = array_merge($params, $p_b); $types .= $t_b;
    // Masuk (Return)
    $w_r = "WHERE btd.product_id = ? AND bt.is_deleted = 0 AND btd.return_date IS NOT NULL";
    $p_r = [$product_id]; $t_r = "i";
    if (!empty($start_date)) { $w_r .= " AND DATE(btd.return_date) >= ?"; $p_r[] = $start_date; $t_r .= "s"; }
    if (!empty($end_date))   { $w_r .= " AND DATE(btd.return_date) <= ?"; $p_r[] = $end_date; $t_r .= "s"; }
    $sql_parts[] = "(SELECT btd.return_date AS date, 'Masuk (Pinjaman Kembali)' AS type, bt.transaction_number, bt.id AS transaction_id, SUM(btd.quantity) as quantity, bt.borrower_name AS counterparty, SUM(btd.quantity) AS quantity_in, 0 AS quantity_out 
                    FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id $w_r GROUP BY bt.id)";
    $params = array_merge($params, $p_r); $types .= $t_r;
}
// Assembly
if (empty($type_filter) || $type_filter == 'Perakitan') {
    // Keluar
    $w_ao = "WHERE aoi.product_id = ?";
    $p_ao = [$product_id]; $t_ao = "i";
    if (!empty($start_date)) { $w_ao .= " AND DATE(ao.created_at) >= ?"; $p_ao[] = $start_date; $t_ao .= "s"; }
    if (!empty($end_date))   { $w_ao .= " AND DATE(ao.created_at) <= ?"; $p_ao[] = $end_date; $t_ao .= "s"; }
    $sql_parts[] = "(SELECT ao.created_at AS date, 'Keluar (Perakitan)' AS type, ao.transaction_no AS transaction_number, ao.id AS transaction_id, SUM(aoi.qty_out) AS quantity, ao.project_name AS counterparty, 0 AS quantity_in, SUM(aoi.qty_out) AS quantity_out 
                    FROM assembly_outbound_items aoi JOIN assembly_outbound ao ON ao.id = aoi.outbound_id $w_ao GROUP BY ao.id)";
    $params = array_merge($params, $p_ao); $types .= $t_ao;
    // Masuk
    $w_ai = "WHERE a.finished_product_id = ? AND ao.status = 'Completed'";
    $p_ai = [$product_id]; $t_ai = "i";
    if (!empty($start_date)) { $w_ai .= " AND DATE(ao.created_at) >= ?"; $p_ai[] = $start_date; $t_ai .= "s"; }
    if (!empty($end_date))   { $w_ai .= " AND DATE(ao.created_at) <= ?"; $p_ai[] = $end_date; $t_ai .= "s"; }
    $sql_parts[] = "(SELECT ao.created_at AS date, 'Masuk (Perakitan)' AS type, ao.transaction_no AS transaction_number, ao.id AS transaction_id, SUM(ao.total_units) AS quantity, ao.project_name AS counterparty, SUM(ao.total_units) AS quantity_in, 0 AS quantity_out 
                    FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id $w_ai GROUP BY ao.id)";
    $params = array_merge($params, $p_ai); $types .= $t_ai;
}
// Adjustments
if (empty($type_filter) || $type_filter == 'Masuk' || $type_filter == 'Keluar') {
    $w_adj = "WHERE product_id = ?";
    $p_adj = [$product_id]; $t_adj = "i";
    if (!empty($start_date)) { $w_adj .= " AND DATE(created_at) >= ?"; $p_adj[] = $start_date; $t_adj .= "s"; }
    if (!empty($end_date))   { $w_adj .= " AND DATE(created_at) <= ?"; $p_adj[] = $end_date; $t_adj .= "s"; }
    $sql_parts[] = "(SELECT created_at AS date, CONCAT(type, ' (Penyesuaian)') AS type, '-' AS transaction_number, id AS transaction_id, quantity, reason AS counterparty, (IF(type='Masuk', quantity, 0)) AS quantity_in, (IF(type='Keluar', quantity, 0)) AS quantity_out FROM stock_adjustments $w_adj)";
    $params = array_merge($params, $p_adj); $types .= $t_adj;
}

$history_records = [];
if (!empty($sql_parts)) {
    $final_sql = implode(" UNION ALL ", $sql_parts) . " ORDER BY date ASC, transaction_id ASC";
    $stmt = $conn->prepare($final_sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $history_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$current_bal = $initial_balance;
foreach ($history_records as &$record) {
    $total_in += $record['quantity_in'];
    $total_out += $record['quantity_out'];
    $current_bal += ($record['quantity_in'] - $record['quantity_out']);
    $record['running_balance'] = $current_bal;
}
unset($record);

// Stok Awal Absolut
if ($system_start_balance > 0) {
    $first_stock_val = $system_start_balance;
} else {
    $sql_f = "SELECT q FROM (
        SELECT itd.quantity as q, it.created_at as d FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id WHERE itd.product_id = $product_id AND it.is_deleted = 0
        UNION ALL SELECT quantity as q, created_at as d FROM stock_adjustments WHERE product_id = $product_id AND type = 'Masuk'
        UNION ALL SELECT ao.total_units as q, ao.created_at as d FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id WHERE a.finished_product_id = $product_id AND ao.status = 'Completed'
    ) as t ORDER BY d ASC LIMIT 1";
    $first_stock_val = (int)($conn->query($sql_f)->fetch_assoc()['q'] ?? 0);
}

// --- GENERATE PDF ---
// --- GENERATE PDF ---
class PDF extends FPDF {
    function Header() {
        // Logo
        if (file_exists('assets/img/logogeo.png')) {
            $this->Image('assets/img/logogeo.png', 10, 10, 40);
        }
        
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(33, 37, 41);
        $this->Cell(0, 10, 'LAPORAN HISTORI TRANSAKSI STOK', 0, 1, 'R');
        
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(108, 117, 125);
        $this->Cell(0, 5, 'Dihasilkan pada: ' . date('d/m/Y H:i'), 0, 1, 'R');
        $this->Ln(10);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, 'Halaman ' . $this->PageNo() . ' dari {nb}', 0, 0, 'C');
        $this->Cell(0, 10, 'WMS-Geo System', 0, 0, 'R');
    }
}

$pdf = new PDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();

// Card Info Produk
$pdf->SetFillColor(248, 249, 250);
$pdf->Rect(10, $pdf->GetY(), 277, 25, 'F');
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(33, 37, 41);
$pdf->SetXY(15, $pdf->GetY() + 4);
$pdf->Cell(30, 6, 'Nama Produk', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(100, 6, ': ' . $product_name, 0, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 6, 'Periode', 0, 0); $pdf->SetFont('Arial', '', 10); 
$periode_text = ($start_date ?: 'Awal') . ' s/d ' . ($end_date ?: 'Sekarang');
$pdf->Cell(0, 6, ': ' . $periode_text, 0, 1);

$pdf->SetX(15);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 6, 'Kode Produk', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(100, 6, ': ' . $product_code, 0, 0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 6, 'Satuan', 0, 0); $pdf->SetFont('Arial', '', 10); $pdf->Cell(0, 6, ': ' . $unit, 0, 1);
$pdf->Ln(8);

// Summary Grid (Fancy Boxes)
$startX = 10;
$boxY = $pdf->GetY();
$boxWidth = 69;
$boxHeight = 20;

// 1. Stok Awal (Blue)
$pdf->SetFillColor(235, 245, 255); $pdf->SetDrawColor(186, 220, 255);
$pdf->Rect($startX, $boxY, $boxWidth, $boxHeight, 'DF');
$pdf->SetXY($startX, $boxY + 4);
$pdf->SetFont('Arial', 'B', 8); $pdf->SetTextColor(0, 82, 204); $pdf->Cell($boxWidth, 5, 'STOK AWAL', 0, 1, 'C');
$pdf->SetX($startX);
$pdf->SetFont('Arial', 'B', 11); $pdf->Cell($boxWidth, 7, number_format($first_stock_val, 0, ',', '.'), 0, 0, 'C');

// 2. Total Masuk (Green)
$pdf->SetFillColor(235, 255, 240); $pdf->SetDrawColor(186, 255, 200);
$pdf->Rect($startX + 69.5, $boxY, $boxWidth, $boxHeight, 'DF');
$pdf->SetXY($startX + 69.5, $boxY + 4);
$pdf->SetFont('Arial', 'B', 8); $pdf->SetTextColor(0, 128, 0); $pdf->Cell($boxWidth, 5, 'TOTAL MASUK', 0, 1, 'C');
$pdf->SetX($startX + 69.5);
$pdf->SetFont('Arial', 'B', 11); $pdf->Cell($boxWidth, 7, '+' . number_format($total_in, 0, ',', '.'), 0, 0, 'C');

// 3. Total Keluar (Red)
$pdf->SetFillColor(255, 240, 240); $pdf->SetDrawColor(255, 186, 186);
$pdf->Rect($startX + 139, $boxY, $boxWidth, $boxHeight, 'DF');
$pdf->SetXY($startX + 139, $boxY + 4);
$pdf->SetFont('Arial', 'B', 8); $pdf->SetTextColor(186, 0, 0); $pdf->Cell($boxWidth, 5, 'TOTAL KELUAR', 0, 1, 'C');
$pdf->SetX($startX + 139);
$pdf->SetFont('Arial', 'B', 11); $pdf->Cell($boxWidth, 7, '-' . number_format($total_out, 0, ',', '.'), 0, 0, 'C');

// 4. Saldo Akhir (Grey)
$pdf->SetFillColor(241, 243, 245); $pdf->SetDrawColor(206, 212, 218);
$pdf->Rect($startX + 208.5, $boxY, $boxWidth, $boxHeight, 'DF');
$pdf->SetXY($startX + 208.5, $boxY + 4);
$pdf->SetFont('Arial', 'B', 8); $pdf->SetTextColor(33, 37, 41); $pdf->Cell($boxWidth, 5, 'SALDO AKHIR', 0, 1, 'C');
$pdf->SetX($startX + 208.5);
$pdf->SetFont('Arial', 'B', 11); $pdf->Cell($boxWidth, 7, number_format($current_bal, 0, ',', '.'), 0, 0, 'C');

$pdf->SetY($boxY + $boxHeight + 10);

// Table Config
$pdf->SetDrawColor(222, 226, 230);
$pdf->SetLineWidth(0.1);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(52, 58, 64);
$pdf->SetTextColor(255, 255, 255);

$cols = [35, 55, 45, 25, 25, 92]; // Total 277mm for A4 Landscape
$headers = ['Tanggal', 'Tipe Transaksi', 'No. Transaksi', 'Kuantitas', 'Saldo', 'Pihak Terkait'];

foreach ($headers as $i => $h) {
    $pdf->Cell($cols[$i], 9, $h, 1, 0, 'C', true);
}
$pdf->Ln();

// Table Body
$pdf->SetTextColor(33, 37, 41);
$pdf->SetFont('Arial', '', 8);
$fill = false;
$records = array_reverse($history_records);

foreach ($records as $r) {
    $pdf->SetFillColor(248, 249, 250);
    $pdf->Cell($cols[0], 7, date('d/m/Y H:i', strtotime($r['date'])), 1, 0, 'C', $fill);
    $pdf->Cell($cols[1], 7, $r['type'], 1, 0, 'L', $fill);
    $pdf->Cell($cols[2], 7, (string)$r['transaction_number'], 1, 0, 'C', $fill);
    
    // Qty Color
    if ($r['quantity_in'] > 0) {
        $pdf->SetTextColor(0, 128, 0);
        $qty_text = '+' . number_format($r['quantity'], 0);
    } else {
        $pdf->SetTextColor(186, 0, 0);
        $qty_text = '-' . number_format($r['quantity'], 0);
    }
    $pdf->Cell($cols[3], 7, $qty_text, 1, 0, 'C', $fill);
    
    $pdf->SetTextColor(33, 37, 41);
    $pdf->Cell($cols[4], 7, number_format($r['running_balance'], 0, ',', '.'), 1, 0, 'C', $fill);
    $pdf->Cell($cols[5], 7, iconv('UTF-8', 'windows-1252', $r['counterparty'] ?? '-'), 1, 1, 'L', $fill);
    
    $fill = !$fill;
}

$pdf->Output('I', 'Laporan_Histori_' . $product_code . '.pdf');
exit();
?>
