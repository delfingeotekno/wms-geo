<?php
require_once '../../includes/db_connect.php';
require('../../includes/fpdf/fpdf.php');

session_start();

// 1. Cek Autentikasi & Ambil Nama User Login
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    die("Akses ditolak.");
}
$nama_pemeriksa = isset($_SESSION['name']) ? strtoupper($_SESSION['name']) : 'STAF QC';

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transaction_id == 0) die("ID Transaksi tidak valid.");

// 2. Ambil Data Transaksi Utama (Vessel = Reference)
$query_tx = "SELECT t.*, u.name as creator_name 
             FROM outbound_transactions t 
             JOIN users u ON t.created_by = u.id 
             WHERE t.id = ? AND t.is_deleted = 0";
$stmt = $conn->prepare($query_tx);
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();

if (!$transaction) die("Transaksi tidak ditemukan.");

// 3. Ambil Detail Produk & Serial Number
$query_details = "SELECT td.*, p.product_code, p.product_name, p.unit
                  FROM outbound_transaction_details td 
                  JOIN products p ON td.product_id = p.id 
                  WHERE td.transaction_id = ?";
$stmt_det = $conn->prepare($query_details);
$stmt_det->bind_param("i", $transaction_id);
$stmt_det->execute();
$details = $stmt_det->get_result()->fetch_all(MYSQLI_ASSOC);

class QC_PDF extends FPDF {
    function Header() {
        // --- KOTAK HEADER ATAS (LOGO & JUDUL) ---
        $this->SetLineWidth(0.4);
        // Rect(x, y, w, h) - Membuat kotak luar untuk logo dan judul
        $this->Rect(10, 8, 190, 25); 

        // Logo
        $logoPath = '../../assets/img/logogeo.png'; 
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 15, 10, 60); 
        }

        // Judul di dalam kotak
        $this->SetY(13);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 7, 'FORM PEMERIKSAAN', 0, 1, 'C');
        $this->Cell(0, 7, 'QC & QTY', 0, 1, 'C');
        
        // Geser posisi ke bawah kotak sebelum mulai Grid Info
        $this->SetY(33); 
    }

    function NbLines($w, $txt) {
        $cw=&$this->CurrentFont['cw'];
        if($w==0) $w=$this->w-$this->rMargin-$this->x;
        $wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
        $s=str_replace("\r",'',(string)$txt);
        $nb=strlen($s);
        if($nb>0 and $s[$nb-1]=="\n") $nb--;
        $sep=-1; $i=0; $j=0; $l=0; $nl=1;
        while($i<$nb) {
            $c=$s[$i];
            if($c=="\n") { $i++; $sep=-1; $j=$i; $l=0; $nl++; continue; }
            if($c==' ') $sep=$i;
            $l+=$cw[$c];
            if($l>$wmax) {
                if($sep==-1) { if($i==$j) $i++; }
                else $i=$sep+1;
                $sep=-1; $j=$i; $l=0; $nl++;
            } else $i++;
        }
        return $nl;
    }

    function CheckPageBreak($h) {
        if($this->GetY() + $h > 275) {
            $this->AddPage($this->CurOrientation);
            return true;
        }
        return false;
    }
}

$pdf = new QC_PDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->AddPage();

// --- GRID HEADER INFO (CLIENT / VESSEL / SUBJECT) ---
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetLineWidth(0.3);
$currentY = $pdf->GetY();

$pdf->Cell(20, 8, ' NO', 1, 0);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(85, 8, ' ' . $transaction['transaction_number'], 1, 0);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(35, 8, ' ' . date('d / m - Y', strtotime($transaction['transaction_date'])), 1, 0, 'C');

// Tanda Tangan - Nama Dinamis dari Session
$pdf->SetXY(150, $currentY);
$pdf->Cell(25, 5, 'PEMERIKSA,', 'TLR', 0, 'C');
$pdf->Cell(25, 5, 'MENGETAHUI,', 'TLR', 1, 'C');
$pdf->SetXY(150, $currentY + 5);
$pdf->Cell(25, 19, '', 'LR', 0); $pdf->Cell(25, 19, '', 'LR', 1);
$pdf->SetXY(150, $currentY + 24);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(25, 8, '[ ' . $nama_pemeriksa . ' ]', 'BLR', 0, 'C'); 
$pdf->Cell(25, 8, '[ WINDA & MERI M ]', 'BLR', 1, 'C');

$pdf->SetXY(10, $currentY + 8);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(20, 8, ' CLIENT', 1, 0);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(120, 8, ' ' . ($transaction['recipient'] ?: '-'), 1, 1);

$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(20, 8, ' VESSEL', 1, 0);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(120, 8, ' ' . ($transaction['reference'] ?: '-'), 1, 1); // Mapping Vessel ke Reference

$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(20, 8, ' SUBJECT', 1, 0);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(120, 8, ' ' . ($transaction['subject'] ?: '-'), 1, 1);

$pdf->Ln(5);

// --- TABEL DATA ---
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(10, 10, 'No', 1, 0, 'C');
$pdf->Cell(75, 10, 'ITEM PENDUKUNG', 1, 0, 'C');
$pdf->Cell(12, 10, 'QTY', 1, 0, 'C');
$pdf->Cell(15, 10, 'UOM', 1, 0, 'C');

$xQ = $pdf->GetX(); $yQ = $pdf->GetY();
$pdf->Cell(30, 5, 'QUALITY', 1, 1, 'C');
$pdf->SetXY($xQ, $yQ + 5);
$pdf->Cell(15, 5, 'GOOD', 1, 0, 'C');
$pdf->Cell(15, 5, 'DEFECT', 1, 0, 'C');
$pdf->SetXY($xQ + 30, $yQ);
$pdf->Cell(48, 10, 'NOTE', 1, 1, 'C');

// --- ISI TABEL (Note Sejajar Item) ---
$pdf->SetFont('Arial', '', 8);
$no = 1;
foreach ($details as $item) {
    $noteText = !empty($item['serial_number']) ? "S/N: " . $item['serial_number'] : "";
    $lItem = $pdf->NbLines(75, $item['product_name']);
    $lNote = $pdf->NbLines(48, $noteText);
    $rowH = max(8, max($lItem, $lNote) * 5); 

    $pdf->CheckPageBreak($rowH);
    $currY = $pdf->GetY();

    $pdf->Cell(10, $rowH, $no++, 1, 0, 'C');
    
    $currX = $pdf->GetX();
    $pdf->MultiCell(75, 5, $item['product_name'], 0, 'L');
    $pdf->SetXY($currX, $currY);
    $pdf->Cell(75, $rowH, '', 1, 0);

    $pdf->Cell(12, $rowH, $item['quantity'], 1, 0, 'C');
    $pdf->Cell(15, $rowH, strtoupper($item['unit'] ?: 'UNIT'), 1, 0, 'C');
    $pdf->Cell(15, $rowH, '', 1, 0); 
    $pdf->Cell(15, $rowH, '', 1, 0); 
    
    $currX = $pdf->GetX();
    $pdf->MultiCell(48, 5, $noteText, 0, 'L');
    $pdf->SetXY($currX, $currY);
    $pdf->Cell(48, $rowH, '', 1, 1);
}

$filename = 'QC_' . str_replace('/', '-', $transaction['transaction_number']) . '.pdf';
$pdf->Output('I', $filename);