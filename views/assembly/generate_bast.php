<?php
require('../../includes/fpdf/fpdf.php'); // Sesuaikan path ke fpdf.php
include '../../includes/db_connect.php';

$id = $_GET['id'] ?? 0;

// 1. Ambil data Header
$sql_header = "SELECT ao.*, a.assembly_name, u.name as admin_name 
               FROM assembly_outbound ao 
               JOIN assemblies a ON ao.assembly_id = a.id 
               JOIN users u ON ao.user_id = u.id 
               WHERE ao.id = ?";
$stmt = $conn->prepare($sql_header);
$stmt->bind_param("i", $id);
$stmt->execute();
$header = $stmt->get_result()->fetch_assoc();

if (!$header) { die("Data tidak ditemukan!"); }

// 1b. Ambil Daftar Hasil Rakitan (Multi)
$res_sql = "SELECT aor.qty, a.assembly_name 
            FROM assembly_outbound_results aor 
            JOIN assemblies a ON aor.assembly_id = a.id 
            WHERE aor.outbound_id = $id";
$res_query = $conn->query($res_sql);
$produced_assemblies = [];
while($pr = $res_query->fetch_assoc()) {
    $produced_assemblies[] = $pr['assembly_name'] . " (" . $pr['qty'] . " Set)";
}
$produced_text = implode(", ", $produced_assemblies);

// 2. Ambil data Items (Group by product to show serials together)
$items = $conn->query("SELECT aoi.product_id, p.product_name, p.unit, SUM(aoi.qty_out) as qty_out, 
                              GROUP_CONCAT(aoi.serial_number SEPARATOR ', ') as serials
                       FROM assembly_outbound_items aoi 
                       JOIN products p ON aoi.product_id = p.id 
                       WHERE aoi.outbound_id = $id
                       GROUP BY aoi.product_id");

// 3. Inisialisasi Class PDF (Extend FPDF untuk akses CurrentFont / protected properties)
class PDF extends FPDF {
    private $header_data;
    public $produced_text;

    function setHeaderData($data, $produced_text = '') {
        $this->header_data = $data;
        $this->produced_text = $produced_text;
    }

    function Header() {
        // --- HEADER LOGO & JUDUL ---
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(130, 10, 'BERITA ACARA SERAH TERIMA BARANG', 0, 0, 'L');
        $this->Image('../../assets/img/logogeo.png', 147, 6, 55); 
        $this->Ln(12);

        // --- TABEL INFO HEADER ---
        $this->SetFont('Arial', '', 9);
        $this->SetFillColor(255, 255, 255);
        $header = $this->header_data;
        // Row 1
        $this->Cell(25, 6, 'BAST No:', 1, 0, 'L');
        $this->Cell(70, 6, $header['transaction_no'], 1, 0, 'L');
        $this->Cell(25, 6, 'Date:', 1, 0, 'L');
        $this->Cell(70, 6, date('d M Y', strtotime($header['created_at'])), 1, 1, 'L');
        // Row 2
        $this->Cell(25, 6, 'From:', 1, 0, 'L');
        $this->Cell(70, 6, 'TEAM WAREHOUSE', 1, 0, 'L');
        $this->Cell(25, 6, 'To:', 1, 0, 'L');
        $this->Cell(70, 6, 'TEAM PRODUCTION', 1, 1, 'L');
        // Row 3
        $this->Cell(25, 6, 'Subject:', 1, 0, 'L');
        $this->Cell(165, 6, strtoupper($header['project_name']), 1, 1, 'L');
        // Row 4 (Multi Assembly)
        $this->Cell(25, 6, 'Produced:', 1, 0, 'L');
        $this->Cell(165, 6, strtoupper($this->produced_text ?: 'MANUAL OUTBOUND'), 1, 1, 'L');

        $this->Ln(4);
        $this->SetFont('Arial', '', 8.5);
        $this->Cell(0, 5, 'Dengan ini kami serahkan barang, sebagai berikut :', 0, 1);

        // --- TABEL DAFTAR BARANG (Header) ---
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(10, 8, 'No.', 1, 0, 'C');
        $this->Cell(85, 8, 'Description', 1, 0, 'C');
        $this->Cell(18, 8, 'Qty.', 1, 0, 'C');
        $this->Cell(18, 8, 'UOM', 1, 0, 'C');
        $this->Cell(20, 8, 'Check', 1, 0, 'C');
        $this->Cell(39, 8, 'Note', 1, 1, 'C');
    }

    function NbLines($w, $txt) {
        if(empty($txt)) return 1;
        $cw = &$this->CurrentFont['cw'];
        if($w==0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb > 0 && $s[$nb-1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while($i < $nb) {
            $c = $s[$i];
            if($c == "\n") {
                $i++; $sep = -1; $j = $i; $l = 0; $nl++;
                continue;
            }
            if($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 0;
            if($l > $wmax) {
                if($sep == -1) {
                    if($i == $j) $i++;
                } else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }

    function drawItems($items) {
        $this->SetFont('Arial', '', 9);
        $no = 1;
        while($item = $items->fetch_assoc()) {
            $qty_text = $item['qty_out'];
            $uom_text = strtoupper($item['unit'] ?? 'PCS');
            $prod_desc = ' ' . $item['product_name'];
            $note_text = !empty($item['serials']) ? "S/N: " . $item['serials'] : '';
            
            $lines_desc = $this->NbLines(85, $prod_desc);
            $lines_note = $this->NbLines(39, $note_text);
            $maxLines = max($lines_desc, $lines_note);
            $rowH = $maxLines * 5;
            if ($rowH < 8) $rowH = 8;
            
            if($this->GetY() + $rowH > $this->PageBreakTrigger) {
                $this->AddPage($this->CurOrientation);
            }
            
            $startY = $this->GetY();
            $x = 10;
            $this->Rect($x, $startY, 10, $rowH); $x += 10;
            $this->Rect($x, $startY, 85, $rowH); $x += 85;
            $this->Rect($x, $startY, 18, $rowH); $x += 18;
            $this->Rect($x, $startY, 18, $rowH); $x += 18;
            $this->Rect($x, $startY, 20, $rowH); $x += 20;
            $this->Rect($x, $startY, 39, $rowH);

            $this->SetXY(10, $startY);
            $this->Cell(10, $rowH, $no++, 0, 0, 'C');
            $descY = $startY + (($rowH - ($lines_desc * 5)) / 2);
            $this->SetXY(20, $descY);
            $this->MultiCell(85, 5, $prod_desc, 0, 'L');
            $this->SetXY(105, $startY);
            $this->Cell(18, $rowH, $qty_text, 0, 0, 'C');
            $this->SetXY(123, $startY);
            $this->Cell(18, $rowH, $uom_text, 0, 0, 'C');
            $this->SetXY(141, $startY);
            $this->Cell(20, $rowH, '', 0, 0, 'C');
            $noteY = $startY + (($rowH - ($lines_note * 5)) / 2);
            $this->SetXY(161, $noteY);
            $this->MultiCell(39, 5, $note_text, 0, 'L');
            $this->SetY($startY + $rowH);
        }

        // Tambahkan Baris Kosong
        $limit = 5;
        for($i=$no; $i<=$limit; $i++) {
            if($this->GetY() + 6 > $this->PageBreakTrigger) $this->AddPage($this->CurOrientation);
            $this->Cell(10, 6, $i, 1, 0, 'C');
            $this->Cell(85, 6, '', 1, 0, 'L');
            $this->Cell(18, 6, '', 1, 0, 'C');
            $this->Cell(18, 6, '', 1, 0, 'C');
            $this->Cell(20, 6, '', 1, 0, 'C');
            $this->Cell(39, 6, '', 1, 1, 'C');
        }
    }

    function SignatureAndNotes($header) {
        $this->SetFont('Arial', '', 9);
        $this->Cell(95, 6, 'Dibuat Oleh,', 1, 0, 'C');
        $this->Cell(95, 6, 'Diterima Oleh,', 1, 1, 'C');
        $this->Cell(95, 20, '', 1, 0); 
        $this->Cell(95, 20, '', 1, 1); 
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(95, 7, strtoupper($header['admin_name']), 1, 0, 'C');
        $this->Cell(95, 7, '', 1, 1, 'C');
        $this->Ln(4);
        $this->SetFont('Arial', '', 8);
        $this->Cell(190, 5, 'Note(s): BARANG RAKIT UNTUK STOCK', 'LTR', 1, 'L'); 
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(190, 5, 'Barang telah diterima dalam keadaan baik dan lengkap.', 1, 1, 'L');
        $this->Ln(2);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(190, 4, '*Putih = Accounting, Merah = Filling, Kuning = Gudang', 0, 0, 'R');
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->setHeaderData($header, $produced_text);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();
$pdf->drawItems($items);
$pdf->SignatureAndNotes($header);

$pdf->Output('I', 'BAST_'.str_replace('/', '_', $header['transaction_no']).'.pdf');