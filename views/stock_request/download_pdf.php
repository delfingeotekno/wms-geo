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
                sr.*, 
                u.name AS requester_name, 
                w.name AS warehouse_name 
            FROM stock_requests sr 
            JOIN users u ON sr.user_id = u.id 
            LEFT JOIN warehouses w ON sr.warehouse_id = w.id 
            WHERE sr.id = ?";
$stmt = $conn->prepare($sql_req);
$stmt->bind_param("i", $id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    die("Data tidak ditemukan.");
}

// 2. Fetch Details
$sql_det = "SELECT srd.*, p.product_name AS catalog_name, p.product_code, b.brand_name FROM stock_request_details srd LEFT JOIN products p ON srd.product_id = p.id LEFT JOIN brands b ON p.brand_id = b.id WHERE srd.stock_request_id = ?";
$stmt_det = $conn->prepare($sql_det);
$stmt_det->bind_param("i", $id);
$stmt_det->execute();
$details = $stmt_det->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_det->close();

class PDF extends FPDF {
    private $req;
    
    function setRequestData($request) {
        $this->req = $request;
    }

    function Header() {
        // Logo Top Left
        if (file_exists('../../assets/img/logogeo.png')) {
            $this->Image('../../assets/img/logogeo.png', 10, 3, 60); // Adjust size as needed
        }
        
        // Title Top Right (Underlined)
        $this->SetFont('Arial', 'B', 14);
        $this->SetXY(110, 8);
        $this->Cell(90, 8, 'FORM REQUEST STOCK PROCUREMENT', 0, 1, 'R');
        $this->Ln(4);

        // Header Information Grid (Table styling)
        $this->SetFont('Arial', '', 9);
        $y_grid = $this->GetY();
        
        // Left Column
        $this->SetXY(10, $y_grid);
        $this->Cell(30, 6, 'Reference No.', 1, 0, 'L');
        $this->Cell(65, 6, ': ' . ($this->req['request_number'] ?? '-'), 1, 1, 'L');
        
        $this->SetX(10);
        $this->Cell(30, 6, 'Subject', 1, 0, 'L');
        $this->Cell(65, 6, ': ' . ($this->req['subject'] ?? '-'), 1, 1, 'L');
        
        $this->SetX(10);
        $this->Cell(30, 6, 'Company', 1, 0, 'L');
        $this->Cell(65, 6, ': PT. GEO TEKNO GLOBALINDO', 1, 0, 'L');

        // Right Column
        $this->SetXY(105, $y_grid);
        $this->Cell(30, 6, 'Department', 1, 0, 'L');
        $this->Cell(65, 6, ': Procurement', 1, 1, 'L');
        
        $this->SetX(105);
        $this->Cell(30, 6, 'Date', 1, 0, 'L');
        $this->Cell(65, 6, ': ' . date('d/m/Y', strtotime($this->req['created_at'])), 1, 1, 'L');
        
        $this->SetX(105);
        $this->Cell(30, 6, 'Note', 1, 0, 'L');
        $this->Cell(65, 6, ': ' . strtoupper($this->req['subject'] ?? 'STOCK'), 1, 1, 'L');

        $this->Ln(4);
    }

    // Helper to calculate String Height
    function GetStringHeight($width, $height, $text) {
        $nb = $this->NbLines($width, $text);
        return $height * $nb;
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

    function ItemTable($header, $data) {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(255, 255, 255);
        
        // Column Widths
        // Total MUST fit ~190mm
        $w = array(8, 25, 20, 15, 60, 25, 37); // sum = 190
        
        // Render Header
        for ($i = 0; $i < count($header); $i++) {
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
        }
        $this->Ln();

        $this->SetFont('Arial', '', 8);
        $i = 1;
        foreach ($data as $row) {
            $x_start = $this->GetX();
            $y_start = $this->GetY();

            $item_name = $row['catalog_name'] ? $row['catalog_name'] : $row['item_name_manual'];
            // If catalog item, use brand name as spec
            $spec = $row['catalog_name'] ? ($row['brand_name'] ?? '-') : ($row['specification'] ?? '-');
            $notes = $row['item_notes'] ?? '-';

            // Calculate heights
            $h_name = $this->GetStringHeight($w[4], 5, $item_name);
            $h_notes = $this->GetStringHeight($w[6], 5, $notes);
            $h = max(6, $h_name, $h_notes);

            // Page Break Check
            if ($this->GetY() + $h > $this->PageBreakTrigger) {
                $this->AddPage();
                // Render Header again
                $this->SetFont('Arial', 'B', 8);
                for ($j = 0; $j < count($header); $j++) {
                    $this->Cell($w[$j], 7, $header[$j], 1, 0, 'C', true);
                }
                $this->Ln();
                $this->SetFont('Arial', '', 8);
                $x_start = $this->GetX();
                $y_start = $this->GetY();
            }

            // Draw Box and partition lines
            $this->Rect($x_start, $y_start, array_sum($w), $h);
            $x_current = $x_start;
            for ($k = 0; $k < count($w) - 1; $k++) {
                $x_current += $w[$k];
                $this->Line($x_current, $y_start, $x_current, $y_start + $h);
            }

            // 1. NO.
            $this->SetXY($x_start, $y_start);
            $this->Cell($w[0], $h, $i++, 0, 0, 'C');

            // 2. Stock Warehouse
            $this->Cell($w[1], $h, $row['current_stock'] ?? '0', 0, 0, 'C');

            // 3. Req Stock
            $this->Cell($w[2], $h, $row['requested_qty'] ?? '0', 0, 0, 'C');

            // 4. UoM
            $this->Cell($w[3], $h, $row['unit'] ?? '-', 0, 0, 'C');

            // 5. ITEM NAME (MultiCell)
            $this->SetXY($x_start + $w[0] + $w[1] + $w[2] + $w[3], $y_start + 0.5);
            $this->MultiCell($w[4], 5, $item_name, 0, 'L');

            // 6. SPEC
            $this->SetXY($x_start + $w[0] + $w[1] + $w[2] + $w[3] + $w[4], $y_start);
            $this->Cell($w[5], $h, $spec, 0, 0, 'C');

            // 7. NOTES (MultiCell)
            $this->SetXY($x_start + $w[0] + $w[1] + $w[2] + $w[3] + $w[4] + $w[5], $y_start + 0.5);
            $this->MultiCell($w[6], 5, $notes, 0, 'L');

            $this->SetY($y_start + $h);
        }

        // --- TAMBAH 2 BARIS KOSONG ---
        for ($r = 0; $r < 2; $r++) {
            $x_start = $this->GetX();
            $y_start = $this->GetY();
            $h_empty = 6; // tinggi baris kosong

            $this->Rect($x_start, $y_start, array_sum($w), $h_empty);
            $x_current = $x_start;
            for ($k = 0; $k < count($w) - 1; $k++) {
                $x_current += $w[$k];
                $this->Line($x_current, $y_start, $x_current, $y_start + $h_empty);
            }
            $this->SetY($y_start + $h_empty);
        }

        $this->Ln(4);
    }

    function SignatureSection($eta_supply, $requester_name) {
        $this->SetFont('Arial', '', 9);
        $this->Cell(30, 6, 'ETA Supply', 0, 0, 'L');
        $this->Cell(100, 6, ': ' . date('d/m/Y', strtotime($eta_supply)), 0, 1, 'L');
        $this->Ln(4);

        $this->MultiCell(0, 5, 'Demikian form permintaan pengadaan barang ini dibuat dengan sebenar-benarnya sesuai dengan kebutuhan dan keperluan perusahaan. Atas perhatian dan kesempatannya, saya ucapkan terima kasih.', 0, 'L');
        $this->Ln(6);

        // Signatures Grid
        $w_sig = 47.5; // 190 / 4
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($w_sig, 6, 'Diajukan', 1, 0, 'C');
        $this->Cell($w_sig, 6, 'Diketahui', 1, 0, 'C');
        $this->Cell($w_sig, 6, 'Diproses', 1, 0, 'C');
        $this->Cell($w_sig, 6, 'Disetujui', 1, 1, 'C');

        // Signature area height
        $this->Cell($w_sig, 15, '', 1, 0, 'C');
        $this->Cell($w_sig, 15, '', 1, 0, 'C');
        $this->Cell($w_sig, 15, '', 1, 0, 'C');
        $this->Cell($w_sig, 15, '', 1, 1, 'C');

        // Bottom Names
        $this->SetFont('Arial', '', 9);
        $this->Cell($w_sig, 6, $requester_name, 1, 0, 'C');
        $this->Cell($w_sig, 6, 'Sarwindah', 1, 0, 'C'); // Matching image placeholder
        $this->Cell($w_sig, 6, '', 1, 0, 'C');
        $this->Cell($w_sig, 6, 'Meri Mentari', 1, 1, 'C'); // Matching image placeholder
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->setRequestData($request);
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);

$header = array('NO.', 'Stock Warehouse', 'Req Stock', 'UoM', 'ITEM NAME', 'SPEC', 'NOTES');
$pdf->ItemTable($header, $details);
$pdf->SignatureSection($request['eta_supply'], $request['requester_name']);

$pdf->Output('I', 'Stock_Request_' . $request['request_number'] . '.pdf');
?>
