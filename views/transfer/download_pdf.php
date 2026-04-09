<?php
session_start();
include '../../includes/db_connect.php';
require('../../includes/fpdf/fpdf.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("ID tidak valid.");
}

// 1. Fetch Header
$sql_req = "SELECT 
                t.*, 
                w1.name AS source_name, 
                w2.name AS dest_name,
                u.name AS creator_name
            FROM transfers t 
            JOIN warehouses w1 ON t.source_warehouse_id = w1.id 
            JOIN warehouses w2 ON t.destination_warehouse_id = w2.id 
            JOIN users u ON t.created_by = u.id
            WHERE t.id = ?";
$stmt = $conn->prepare($sql_req);
$stmt->bind_param("i", $id);
$stmt->execute();
$trf = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$trf) {
    die("Data tidak ditemukan.");
}

// 2. Fetch Details
$sql_det = "SELECT ti.*, p.product_name, p.product_code, p.unit 
            FROM transfer_items ti 
            JOIN products p ON ti.product_id = p.id 
            WHERE ti.transfer_id = ?";
$stmt_det = $conn->prepare($sql_det);
$stmt_det->bind_param("i", $id);
$stmt_det->execute();
$res_items = $stmt_det->get_result();
$details = [];
while ($row = $res_items->fetch_assoc()) {
    // Get Serials
    $ti_id = $row['id'];
    $sql_sn = "SELECT serial_number FROM transfer_serials WHERE transfer_item_id = $ti_id";
    $res_sn = $conn->query($sql_sn);
    $sns = [];
    while($sn = $res_sn->fetch_assoc()){
        $sns[] = $sn['serial_number'];
    }
    $row['serials'] = $sns;
    $details[] = $row;
}
$stmt_det->close();

class PDF extends FPDF {
    private $trf;
    
    function setTrfData($trf) {
        $this->trf = $trf;
    }

    function Header() {
        if (file_exists('../../assets/img/logogeo.png')) {
            $this->Image('../../assets/img/logogeo.png', 140, 3, 60); 
        }
        
        $this->SetFont('Arial', 'B', 18);
        $this->SetY(10);
        $this->Cell(0, 10, 'TRANSFER ANTAR GUDANG', 0, 1, 'L');
        $this->Ln(2);

        $this->SetFont('Arial', '', 10);
        $w1 = 20; $w2 = 80; $w3 = 20; $w4 = 70;
        $h = 7;

        // Baris 1
        $this->Cell($w1, $h, ' TAG No:', 1, 0, 'L');
        $this->SetFont('Arial', '', 10);
        $this->Cell($w2, $h, ' ' . $this->trf['transfer_no'], 1, 0, 'L');
        $this->SetFont('Arial', '', 10);
        $this->Cell($w3, $h, ' Date:', 1, 0, 'L');
        $this->Cell($w4, $h, ' ' . date('d M Y', strtotime($this->trf['created_at'])), 1, 1, 'L');

        // Baris 2 - Remove GEO prefix
        $this->Cell($w1, $h, ' From:', 1, 0, 'L');
        $this->Cell($w2, $h, ' ' . strtoupper($this->trf['source_name']), 1, 0, 'L');
        $this->Cell($w3, $h, ' To:', 1, 0, 'L');
        $this->Cell($w4, $h, ' ' . strtoupper($this->trf['dest_name']), 1, 1, 'L');

        $this->Ln(5);
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 7, 'Dengan ini kami lampirkan surat jalan dengan informasi sebagai berikut:', 0, 1, 'L');
    }

    function ItemTable($header, $data) {
        $this->SetFont('Arial', '', 10);
        $w = array(10, 85, 12, 12, 23.5, 47.5); // sum = 190
        
        for($i=0; $i<count($header); $i++) {
            $this->Cell($w[$i], 8, $header[$i], 1, 0, 'C');
        }
        $this->Ln();

        $this->SetFont('Arial', '', 9);
        $idx = 1;
        foreach($data as $row) {
            $x = $this->GetX();
            $y = $this->GetY();
            $sns_text = "";
            if (count($row['serials']) > 0) {
                // List SN satu-per-satu dengan baris baru (seperti pada Receive Items)
                $sns_text = "S/N: " . implode("\nS/N: ", $row['serials']);
            }
            
            $nb1 = $this->NbLines($w[1], $row['product_name']);
            $nb2 = $this->NbLines($w[5], $sns_text);
            $lines = max($nb1, $nb2, 1);
            $h = $lines * 6;

            if($this->GetY() + $h > 240) {
                $this->AddPage();
                $this->SetFont('Arial', 'B', 10);
                for($k=0; $k<count($header); $k++) $this->Cell($w[$k], 8, $header[$k], 1, 0, 'C');
                $this->Ln();
                $this->SetFont('Arial', '', 9);
                $x = $this->GetX(); $y = $this->GetY();
            }

            $this->Rect($x, $y, array_sum($w), $h);
            $xc = $x;
            for($col=0; $col<count($w)-1; $col++) { $xc += $w[$col]; $this->Line($xc, $y, $xc, $y+$h); }

            $this->SetXY($x, $y);
            $this->Cell($w[0], $h, $idx++, 0, 0, 'C');
            
            $this->SetXY($x + $w[0], $y);
            $this->MultiCell($w[1], 6, $row['product_name'], 0, 'L');
            
            $this->SetXY($x + $w[0] + $w[1], $y);
            $this->Cell($w[2], $h, $row['quantity'], 0, 0, 'C');
            $this->Cell($w[3], $h, $row['unit'] ?: 'PCS', 0, 0, 'C');
            $this->Cell($w[4], $h, $row['product_code'], 0, 0, 'C');
            
            $this->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3] + $w[4], $y);
            $this->MultiCell($w[5], 6, $sns_text, 0, 'L');
            $this->SetY($y + $h);
        }

        // --- 2 Baris Kosong ---
        for($r=0; $r<2; $r++){
            $x = $this->GetX();
            $y = $this->GetY();
            $h_empty = 7;
            $this->Rect($x, $y, array_sum($w), $h_empty);
            $xc = $x;
            for($col=0; $col<count($w)-1; $col++) { $xc += $w[$col]; $this->Line($xc, $y, $xc, $y+$h_empty); }
            $this->SetY($y + $h_empty);
        }

        // --- SIGNATURE SECTION (Langsung Menyatu/Tanpa Ln) ---
        $ws = 190 / 4;
        $this->SetFont('Arial', 'B', 10);
        $y_sig = $this->GetY();
        $this->Cell($ws, 7, 'Dibuat Oleh', 1, 0, 'C');
        $this->Cell($ws, 7, 'Mengetahui', 1, 0, 'C');
        $this->Cell($ws, 7, 'Pengirim', 1, 0, 'C');
        $this->Cell($ws, 7, 'Penerima', 1, 1, 'C');

        $hs = 20;
        $this->Cell($ws, $hs, '', 1, 0, 'C');
        $this->Cell($ws, $hs, '', 1, 0, 'C');
        $this->Cell($ws, $hs, '', 1, 0, 'C');
        $this->Cell($ws, $hs, '', 1, 1, 'C');

        $this->SetFont('Arial', 'B', 9);
        $this->Cell($ws, 7, ($this->trf['creator_name'] ?: 'Winda'), 1, 0, 'C');
        $this->Cell($ws, 7, 'Meri Mentari', 1, 0, 'C');
        $this->Cell($ws, 7, '', 1, 0, 'C');
        $this->Cell($ws, 7, '', 1, 1, 'C');
    }

    function FooterBar() {
        $this->Ln(3);
        $x = $this->GetX();
        $y = $this->GetY();
        $width = 190;
        
        // Kotak Note(s)
        $this->Rect($x, $y, $width, 14);
        // Garis horizontal antara Note(s) dengan isi
        $this->Line($x, $y + 7, $x + $width, $y + 7);

        $this->SetFont('Arial', 'I', 11);
        $this->Cell(17, 7, 'Note(s):', 0, 0, 'L');
        $this->SetFont('Arial', '', 10);
        $this->Cell(170, 7, ' ' . ($this->trf['notes'] ?: '-'), 0, 1, 'L');

        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 7, 'Barang telah diterima dalam keadaan baik dan lengkap.', 0, 1, 'L');
        
        $this->Ln(1);
        $this->SetFont('Arial', '', 8);
        $this->Cell(95, 6, '*Bila ada perubahan dalam surat jalan ini wajib di Paraf', 0, 0, 'L');
        $this->Cell(95, 6, '**Putih/Merah = Kantor; Kuning = Customer; Hijau = Gudang', 0, 1, 'R');
    }

    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w==0) $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb>0 and $s[$nb-1]=="\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while($i<$nb) {
            $c = $s[$i];
            if($c=="\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if($c==' ') $sep = $i;
            $l += $cw[$c];
            if($l>$wmax) {
                if($sep==-1) { if($i==$j) $i++; } else $i = $sep+1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->setTrfData($trf);
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->ItemTable(['No.', 'Description', 'Qty.', 'UOM', 'ITEM CODE', 'Note'], $details);
$pdf->FooterBar();
$pdf->Output('I', 'TAG_' . str_replace('/', '_', $trf['transfer_no']) . '.pdf');
?>
