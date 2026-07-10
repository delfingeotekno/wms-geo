<?php
require('../../includes/fpdf/fpdf.php');
include '../../includes/db_connect.php';

session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager', 'procurement', 'staff'])) {
    die("Unauthorized access");
}

// Params
$end_date_default = date('Y-m-d');
$start_date_default = date('Y-m-d', strtotime('-90 days'));

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $start_date_default;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : $end_date_default;
$active_warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$only_active = isset($_GET['only_active']) && $_GET['only_active'] == '1';
$z_score = isset($_GET['z_score']) ? (float)$_GET['z_score'] : 0.8416;
$brand_id = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;
$product_type_id = isset($_GET['product_type_id']) ? (int)$_GET['product_type_id'] : 0;

// N Days
$datetime1 = new DateTime($start_date);
$datetime2 = new DateTime($end_date);
$interval = $datetime1->diff($datetime2);
$n_days = $interval->days + 1;
if ($n_days < 2) $n_days = 2;

// Warehouse
$wh_name = "Semua Gudang";
if ($active_warehouse_id > 0) {
    if ($r = $conn->query("SELECT name FROM warehouses WHERE id = $active_warehouse_id")->fetch_assoc()) {
        $wh_name = $r['name'];
    }
}

// SQL
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
" . ($brand_id > 0 ? " AND p.brand_id = $brand_id" : "") . "
" . ($product_type_id > 0 ? " AND p.product_type_id = $product_type_id" : "") . "
GROUP BY p.id, p.product_code, p.product_name, t.type_name, p.lead_time_avg, p.lead_time_max, ws.stock, p.minimum_stock_level
" . ($only_active ? "HAVING sum_qty > 0" : "") . "
ORDER BY p.product_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iisssssssssss", $active_warehouse_id, $active_warehouse_id, $start_date, $end_date, $active_warehouse_id, $start_date, $end_date, $active_warehouse_id, $start_date, $end_date, $active_warehouse_id, $start_date, $end_date);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Filter brand/type names for header
$brand_name_header = "Semua Merek";
if ($brand_id > 0) {
    if ($br = $conn->query("SELECT brand_name FROM brands WHERE id = $brand_id")->fetch_assoc()) $brand_name_header = $br['brand_name'];
}
$type_name_header = "Semua Tipe";
if ($product_type_id > 0) {
    if ($tr = $conn->query("SELECT type_name FROM product_types WHERE id = $product_type_id")->fetch_assoc()) $type_name_header = $tr['type_name'];
}

class ROPPDF extends FPDF {
    function Header() {
        global $wh_name, $start_date, $end_date, $n_days;
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'LAPORAN ANALISIS STOK & REORDER POINT (ROP)', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, "Gudang: $wh_name | Periode: $start_date s/d $end_date ($n_days Hari)", 0, 1, 'C');
        $this->Ln(5);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Halaman ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    // Helper to calculate number of lines needed for a MultiCell
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w==0) $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if($nb>0 && $s[$nb-1]=="\n") $nb--;
        $sep = -1;
        $i = 0; $j = 0; $l = 0; $nl = 1;
        while($i<$nb) {
            $c = $s[$i];
            if($c=="\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if($c==' ') $sep = $i;
            $l += $cw[$c];
            if($l>$wmax) {
                if($sep==-1) { if($i==$j) $i++; }
                else $i = $sep+1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }

    // Row function to handle MultiCell cells
    function Row($widths, $data, $fill = false, $isCritical = false) {
        // Calculate the height of the row
        $nb = 0;
        // Check only the columns that can wrap (index 1 is Product Name)
        $nb = max($nb, $this->NbLines($widths[1], $data[1]));
        $h = 5 * $nb;
        if ($h < 7) $h = 7; // Minimum height
        
        // Issue a page break first if needed
        if($this->GetY()+$h > $this->PageBreakTrigger) $this->AddPage($this->CurOrientation);
        
        // Draw the cells
        for($i=0;$i<count($data);$i++) {
            $w=$widths[$i];
            $a=isset($this->aligns[$i]) ? $this->aligns[$i] : (($i == 1) ? 'L' : 'C');
            
            // Save the current position
            $x=$this->GetX();
            $y=$this->GetY();
            
            // Draw the border and fill
            if ($fill) {
                $this->Rect($x, $y, $w, $h, 'DF');
            } else {
                $this->Rect($x, $y, $w, $h);
            }
            
            // Print the text
            if ($i == 1) { // MultiCell for product name
                $this->MultiCell($w, 5, $data[$i], 0, $a);
            } else {
                // For 'Stok Akhir' (index 10), make it bold if critical
                if ($i == 10 && $isCritical) $this->SetFont('Arial', 'B', 8);
                $this->Cell($w, $h, $data[$i], 0, 0, $a);
                if ($i == 10 && $isCritical) $this->SetFont('Arial', '', 8);
            }
            
            // Put the position to the right of the cell
            $this->SetXY($x+$w,$y);
        }
        // Go to the next line
        $this->Ln($h);
    }
}

$pdf = new ROPPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();

// Brand/Type Info
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(30, 6, 'Merek:', 0, 0); $pdf->SetFont('Arial', '', 9); $pdf->Cell(70, 6, $brand_name_header, 0, 0);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(30, 6, 'Tipe Produk:', 0, 0); $pdf->SetFont('Arial', '', 9); $pdf->Cell(70, 6, $type_name_header, 0, 1);
$pdf->Ln(2);

// Header Table
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(230, 230, 230);
$widths = [8, 70, 15, 15, 18, 18, 18, 22, 22, 22, 22, 25];
$header = ['No', 'Nama Produk / Tipe', 'LT Avg', 'LT Max', 'vLT', 'Keluar', 'Avg/Hr', 'StdDev', 'SafetyStock', 'ROP', 'Stok', 'Status'];

foreach($header as $i => $h) {
    if ($i == 1) $pdf->Cell($widths[$i], 8, $h, 1, 0, 'L', true);
    else $pdf->Cell($widths[$i], 8, $h, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('Arial', '', 8);
$i = 1;
foreach ($products as $row) {
    $sum_n = (float) $row['sum_qty'];
    $sum_sq = (float) $row['sum_sq_qty'];
    $avg_daily = $sum_n / $n_days;
    $variance = ($sum_sq - (($sum_n * $sum_n) / $n_days)) / ($n_days - 1);
    if ($variance < 0) $variance = 0;
    $stddev = sqrt($variance);
    $vlead_time = sqrt((float)$row['lead_time_avg']);
    
    $safetyStock = $z_score * $stddev * $vlead_time;
    $rop = ($avg_daily * (float)$row['lead_time_avg']) + $safetyStock;
    
    $stock = (float)$row['current_stock'];
    $isCritical = $stock <= ceil($rop);
    
    if ($isCritical) {
        $pdf->SetFillColor(255, 200, 200);
        $fill = true;
    } else {
        $fill = false;
    }
    
    $statusText = $isCritical ? 'REORDER!' : 'AMAN';
    $name = $row['product_name'] . " (" . ($row['type_name'] ?? '-') . ")";

    // Prepare data row
    $data_row = [
        $i++,
        $name,
        $row['lead_time_avg'],
        $row['lead_time_max'],
        number_format($vlead_time, 2),
        number_format($sum_n, 0),
        number_format($avg_daily, 2),
        number_format($stddev, 2),
        number_format($safetyStock, 2),
        number_format($rop, 2),
        $stock,
        $statusText
    ];

    $pdf->Row($widths, $data_row, $fill, $isCritical);
}

$pdf->Output('I', 'Laporan_ROP.pdf');
?>
