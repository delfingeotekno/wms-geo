<?php
session_start();
include '../../includes/db_connect.php';
require('../../includes/fpdf/fpdf.php');

if (!isset($_SESSION['user_id'])) {
  header("Location: ../../login.php");
  exit();
}

$user_name = $_SESSION['name']; // Nama user login

$packing_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($packing_id == 0) {
  die("ID packing slip tidak valid.");
}

// 🔹 Ambil data dari tabel packing_slips
$query = "SELECT 
  ps.*, 
  u.name AS creator_name 
FROM packing_slips ps
JOIN users u ON ps.created_by = u.id
WHERE ps.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $packing_id);
$stmt->execute();
$packing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$packing) {
  die("Packing slip tidak ditemukan.");
}

// 🔹 Ambil data item dari outbound_transaction_details
$query = "SELECT 
  d.*, 
  p.product_code, 
  p.product_name, 
  p.unit, 
  p.has_serial
FROM outbound_transaction_details d
JOIN products p ON d.product_id = p.id
WHERE d.transaction_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $packing['transaction_id']);
$stmt->execute();
$details = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 🔹 Grouping item by product
$grouped_details = [];
foreach ($details as $row) {
  $key = $row['product_id'];
  if (!isset($grouped_details[$key])) {
    $grouped_details[$key] = [
      'product_name' => $row['product_name'],
      'product_code' => $row['product_code'],
      'unit' => $row['unit'],
      'quantity' => 0,
      'serial_numbers' => [],
    ];
  }

  $grouped_details[$key]['quantity'] += $row['quantity'];
  if ($row['has_serial'] == 1 && !empty($row['serial_number'])) {
    $grouped_details[$key]['serial_numbers'][] = $row['serial_number'];
  }
}
$final_details = array_values($grouped_details);

class PDF extends FPDF {
  private $packing;
  private $details;
  private $user_name;
  private $logo_path = '../../assets/img/logogeo.png';

  function setData($packing, $details, $user_name) {
    $this->packing = $packing;
    $this->details = $details;
    $this->user_name = $user_name;
  }

  function Header() {
    $this->Image($this->logo_path, 140, 3, 60);

    // Judul utama PACKING SLIP
    $this->SetFont('Arial', 'B', 16);
    $this->Cell(80, 8, 'PACKING SLIP', 0, 1, 'L');
    $this->Ln(3);
    
    // Informasi transaksi dalam kotak (Mengikuti format PACKING SLIP)
    $this->SetFont('Arial', '', 9);
    // Baris 1: PACKING SLIP No & Date
    $this->Cell(15, 6, 'PS No:', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(80, 6, $this->packing['packing_slip_number'], 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(15, 6, 'Date:', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $ps_date_formatted = date('d M Y', strtotime($this->packing['packing_slip_date']));
    $this->Cell(80, 6, $ps_date_formatted, 1, 1, 'L');

    // Baris 2: To & Location
    $this->SetFont('Arial', '', 9);
    $this->Cell(15, 6, 'To:', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(80, 6, $this->packing['recipient'] ?: '-', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(15, 6, 'Location:', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(80, 6, $this->packing['location'], 1, 1, 'L');

    // Baris 3: Subject & Ref
    $this->SetFont('Arial', '', 9);
    $this->Cell(15, 6, 'Subject:', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(80, 6, 'PENGIRIMAN BARANG', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(15, 6, 'Ref:', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(80, 6, $this->packing['reference'] ?: '-', 1, 1, 'L');


    $this->Ln(3);
    $this->MultiCell(0, 6, 'Dengan ini kami lampirkan surat jalan dengan informasi sebagai berikut:', 0, 'L');
    $this->Ln(1);

    // Table Header 10, 85, 11.5, 13.5, 12, 12, 46
    $this->SetFont('Arial', 'B', 9);
    $this->SetFillColor(255,255,255);
    $this->Cell(10, 7, 'No.', 1, 0, 'C', true);
    $this->Cell(85, 7, 'Description', 1, 0, 'C', true);
    $this->Cell(11.5, 7, 'Qty', 1, 0, 'C', true);
    $this->Cell(13.5, 7, 'UOM', 1, 0, 'C', true);
    $this->Cell(12, 7, 'Check', 1, 0, 'C', true);
    $this->Cell(12, 7, 'Sign', 1, 0, 'C', true);
    $this->Cell(46, 7, 'Note', 1, 1, 'C', true);
  }

  function ItemTableBody() {
    $this->SetFont('Arial', '', 9);
    $i = 1;

    foreach ($this->details as $row) {
      $desc = $row['product_name'];
      $qty = $row['quantity'];
      $unit = $row['unit'];
      $note = !empty($row['serial_numbers']) ? "S/N: " . implode("\nS/N: ", $row['serial_numbers']) : '';

      $nb = max($this->NbLines(70, $desc), $this->NbLines(40, $note));
      $h = 5 * $nb;

      $this->CheckPageBreak($h);
      $y = $this->GetY();
      $x = $this->GetX();

      // Kolom No
      $this->Rect($x, $y, 10, $h);
      $this->SetXY($x, $y + ($h - 5) / 2); // Center vertical
      $this->Cell(10, 5, $i++, 0, 0, 'C');
      $x += 10;

      // Kolom Description
      $this->Rect($x, $y, 85, $h);
      $this->SetXY($x + 0.5, $y + 0.5); // MultiCell start position
      $this->MultiCell(84, 5, $desc, 0, 'L');
      $x += 85;

      // Kolom Qty
      $this->SetXY($x, $y + ($h - 5) / 2); // Center vertical
      $this->Rect($x, $y, 11.5, $h);
      $this->Cell(11.5, 5, $qty, 0, 0, 'C');
      $x += 11.5;

      // Kolom UOM
      $this->SetXY($x, $y + ($h - 5) / 2); // Center vertical
      $this->Rect($x, $y, 13.5, $h);
      $this->Cell(13.5, 5, $unit, 0, 0, 'C');
      $x += 13.5;

      // Kolom Check
      $this->SetXY($x, $y + ($h - 5) / 2); // Center vertical
      $this->Rect($x, $y, 12, $h);
      $this->Cell(12, 5, '', 0, 0, 'C');
      $x += 12;

      // Kolom Sign
      $this->SetXY($x, $y + ($h - 5) / 2); // Center vertical
      $this->Rect($x, $y, 12, $h);
      $this->Cell(12, 5, '', 0, 0, 'C');
      $x += 12;

      // Kolom Note
      $this->Rect($x, $y, 46, $h);
      $this->SetXY($x + 0.5, $y + 0.5); // MultiCell start position
      $this->MultiCell(45, 5, $note, 0, 'L');
      
      $this->SetY($y + $h);
    }

    // Tambahkan baris kosong hingga 5
    $total_rows = count($this->details);
    $h_empty = 6;
    for ($j = $total_rows; $j < 5; $j++) {
      $this->CheckPageBreak($h_empty);
      $this->Cell(10, $h_empty, $i++, 1, 0, 'C');
      $this->Cell(85, $h_empty, '', 1, 0);
      $this->Cell(11.5, $h_empty, '', 1, 0);
      $this->Cell(13.5, $h_empty, '', 1, 0);
      $this->Cell(12, $h_empty, '', 1, 0);
      $this->Cell(12, $h_empty, '', 1, 0);
      $this->Cell(46, $h_empty, '', 1, 1);
    }
        
        // --- PERBAIKAN DI SINI: MENGAMBIL NILAI koli_count ---
        
        $koli_count = !empty($this->packing['koli_count']) ? $this->packing['koli_count'] : 0;
        $koli_text = $koli_count . ' Koli';
        
    // Footer Jumlah
    $this->SetFont('Arial', 'B', 9);
    $this->Cell(144, 7, 'Jumlah', 1, 0, 'R');
    $this->Cell(46, 7, $koli_text, 1, 1, 'C'); // Menggunakan nilai dinamis $koli_text
  }

  function SignatureSection() {
    $this->Ln(5);
    $this->SetFont('Arial', 'B', 9);
    $this->Cell(47.5, 6, 'Dibuat Oleh', 1, 0, 'C');
    $this->Cell(47.5, 6, 'Mengetahui', 1, 0, 'C');
    $this->Cell(48.8, 6, 'Pengirim', 1, 0, 'C');
    $this->Cell(46.2, 6, 'Penerima', 1, 1, 'C');

    $this->Cell(47.5, 20, '', 1, 0, 'C');
    $this->Cell(47.5, 20, '', 1, 0, 'C');
    $this->Cell(48.8, 20, '', 1, 0, 'C');
    $this->Cell(46.2, 20, '', 1, 1, 'C');

    $this->SetFont('Arial', 'B', 9);
    // Menggunakan nama dari database jika ada, jika tidak, gunakan default
    $creator_name = !empty($this->packing['creator_name']) ? $this->packing['creator_name'] : 'Dibuat Oleh';
    $this->Cell(47.5, 6, $creator_name, 1, 0, 'C');
    $this->Cell(47.5, 6, 'Mengetahui', 1, 0, 'C');
    $this->Cell(48.8, 6, '', 1, 0, 'C');
    $this->Cell(46.2, 6, '', 1, 1, 'C');
  }

  // ... (Fungsi NoteSection, CheckPageBreak, NbLines tetap sama) ...
    function NoteSection() {
        // ... (Fungsi NoteSection Anda sebelumnya) ...
    }

    function CheckPageBreak($h) {
        if ($this->GetY() + $h > $this->PageBreakTrigger)
            $this->AddPage($this->CurOrientation);
    }

    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n")
            $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) $i++;
                } else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }
}

// 🔹 Generate PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->setData($packing, $final_details, $user_name);
$pdf->SetMargins(10, 10, 10);
$pdf->AddPage();
$pdf->ItemTableBody();
$pdf->SignatureSection();
$pdf->NoteSection();
$pdf->Output('I', 'packing_slip_' . str_replace('/', '_', $packing['packing_slip_number']) . '.pdf');
?>