<?php
require_once '../../includes/db_connect.php';
require('../../includes/fpdf/fpdf.php');

session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    die("Akses ditolak.");
}

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filter_status_po = isset($_GET['status_po']) ? $_GET['status_po'] : '';
$filter_status_ri = isset($_GET['status_ri']) ? $_GET['status_ri'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// --- FETCH DATA (Copied Logic from index.php) ---
$sql = "SELECT 
            po.id, po.po_number, po.po_date, po.pic_name, po.grand_total, po.status, 
            v.vendor_name, v.currency,
            (SELECT COUNT(1) FROM inbound_transactions it WHERE it.document_number COLLATE utf8mb4_unicode_ci = po.po_number COLLATE utf8mb4_unicode_ci) as ri_exists
        FROM purchase_orders po 
        JOIN vendors v ON po.vendor_id = v.id
        WHERE 1=1";

if ($search !== '') {
    $sql .= " AND (v.vendor_name LIKE '%$search%' OR po.po_number LIKE '%$search%')";
}
if ($filter_status_po !== '') {
    $sql .= " AND po.status = '" . $conn->real_escape_string($filter_status_po) . "'";
}
if ($filter_date_from !== '') {
    $sql .= " AND po.po_date >= '" . $conn->real_escape_string($filter_date_from) . "'";
}
if ($filter_date_to !== '') {
    $sql .= " AND po.po_date <= '" . $conn->real_escape_string($filter_date_to) . "'";
}

$sql .= " ORDER BY po.id DESC";
$result = $conn->query($sql);

$filtered_rows = [];
if ($result && $result->num_rows > 0) {
    while ($r = $result->fetch_assoc()) {
        if ($filter_status_ri === 'sudah' && $r['ri_exists'] == 0) continue;
        if ($filter_status_ri === 'belum' && $r['ri_exists'] > 0) continue;
        $filtered_rows[] = $r;
    }
}

// --- SETUP PDF ---
class PDF extends FPDF {
    function Header() {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'LAPORAN PURCHASE ORDER', 0, 1, 'C');
        $this->Ln(5);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Halaman ' . $this->PageNo() . ' / {nb} | Dicetak pada: ' . date('d/m/Y H:i'), 0, 0, 'C');
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();

// Sub-header Filters
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(30, 6, 'Filter Pencarian:', 0, 0);
$pdf->Cell(0, 6, ($search ?: '-'), 0, 1);
$pdf->Cell(30, 6, 'Status PO:', 0, 0);
$pdf->Cell(0, 6, ($filter_status_po ?: 'Semua'), 0, 1);
$pdf->Cell(30, 6, 'Status RI:', 0, 0);
$ri_text = 'Semua';
if ($filter_status_ri == 'sudah') $ri_text = 'RI Dibuat';
if ($filter_status_ri == 'belum') $ri_text = 'Belum RI';
$pdf->Cell(0, 6, $ri_text, 0, 1);
$pdf->Cell(30, 6, 'Periode:', 0, 0);
$periode = ($filter_date_from ? date('d/m/Y', strtotime($filter_date_from)) : 'Awal') . ' s/d ' . ($filter_date_to ? date('d/m/Y', strtotime($filter_date_to)) : 'Akhir');
$pdf->Cell(0, 6, $periode, 0, 1);

$pdf->Ln(5);

// Table Header
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(10, 8, 'No', 1, 0, 'C', true);
$pdf->Cell(40, 8, 'No. PO', 1, 0, 'C', true);
$pdf->Cell(25, 8, 'Tanggal', 1, 0, 'C', true);
$pdf->Cell(55, 8, 'Vendor', 1, 0, 'C', true);
$pdf->Cell(30, 8, 'Grand Total', 1, 0, 'C', true);
$pdf->Cell(15, 8, 'Sts PO', 1, 0, 'C', true);
$pdf->Cell(15, 8, 'Sts RI', 1, 1, 'C', true);

// Table Body
$pdf->SetFont('Arial', '', 8);
$no = 1;
foreach ($filtered_rows as $row) {
    $pdf->Cell(10, 7, $no++, 1, 0, 'C');
    $pdf->Cell(40, 7, $row['po_number'], 1, 0, 'C');
    $pdf->Cell(25, 7, date('d/m/Y', strtotime($row['po_date'])), 1, 0, 'C');
    
    // Vendor Name (Truncate if too long)
    $vendor = substr($row['vendor_name'], 0, 30);
    $pdf->Cell(55, 7, $vendor, 1, 0, 'L');
    
    $pdf->Cell(30, 7, $row['currency'] . ' ' . number_format($row['grand_total'], 0, ',', '.'), 1, 0, 'R');
    
    $status_po = substr(strtoupper($row['status']), 0, 4); // PEND, COMP, CANC
    $pdf->Cell(15, 7, $status_po, 1, 0, 'C');
    $pdf->Cell(15, 7, ($row['ri_exists'] > 0 ? 'S' : 'B'), 1, 1, 'C');
}

if (empty($filtered_rows)) {
    $pdf->Cell(190, 8, 'Tidak ada data ditemukan.', 1, 1, 'C');
}

$pdf->Output('I', 'Laporan_PO_' . date('Ymd_His') . '.pdf');
