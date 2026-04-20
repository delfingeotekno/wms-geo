<?php
session_start();
include '../../includes/db_connect.php';
require('../../includes/fpdf/fpdf.php');

if (!isset($_SESSION['user_id'])) {
  header("Location: ../../login.php");
  exit();
}

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transaction_id == 0) {
  die("ID transaksi tidak valid.");
}

// 1. Ambil data utama transaksi
$transaction_query = "SELECT 
  t.*,
  u.name AS creator_name
FROM inbound_transactions t
JOIN users u ON t.created_by = u.id
WHERE t.id = ? AND t.is_deleted = 0";
$stmt = $conn->prepare($transaction_query);
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transaction) {
  die("Transaksi tidak ditemukan.");
}

// 2. Ambil data detail item dan unit produk, termasuk serial number - filter by tenant and warehouse via JOIN
$details_query = "SELECT 
  td.*,
  p.product_code,
  p.product_name,
  p.has_serial,
  p.unit
FROM inbound_transaction_details td
JOIN products p ON td.product_id = p.id
JOIN inbound_transactions it ON td.transaction_id = it.id
WHERE td.transaction_id = ?";
$stmt_details = $conn->prepare($details_query);
$stmt_details->bind_param("i", $transaction_id);
$stmt_details->execute();
$details_raw = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();

// --- START: LOGIKA PENGELOMPOKAN SERIAL NUMBER ---
$grouped_details = [];

foreach ($details_raw as $row) {
  // Kunci pengelompokan (gunakan ID produk agar item yang sama dikelompokkan)
  $key = $row['product_id'];

  if (!isset($grouped_details[$key])) {
    // Jika produk baru, inisialisasi baris baru
    $grouped_details[$key] = [
      'product_code' => $row['product_code'],
      'product_name' => $row['product_name'],
      'has_serial' => $row['has_serial'],
      'unit' => $row['unit'],
      'quantity' => 0,
      'serial_numbers' => []
    ];
  }
  
  // Tambahkan kuantitas
  $grouped_details[$key]['quantity'] += $row['quantity'];
  
  // Tambahkan serial number (jika ada)
  if (!empty($row['serial_number'])) {
    $grouped_details[$key]['serial_numbers'][] = $row['serial_number'];
  }
}

// Format ulang array untuk digunakan di ItemTable
$details = [];
foreach ($grouped_details as $item) {
  if ($item['has_serial'] == 1 && !empty($item['serial_numbers'])) {
    // Pecah array serial_numbers menjadi beberapa baris (maksimal 15 item per baris)
    // Hal ini untuk menghindari error jika string terlalu panjang hingga melewati batas satu halaman penuh PDF
    $chunks = array_chunk($item['serial_numbers'], 15);
    $total_chunks = count($chunks);
    foreach ($chunks as $index => $chunk) {
        $notes = ($index === 0 ? 'S/N: ' : '') . implode(", ", $chunk);
        $details[] = [
            'product_name' => ($index === 0) ? $item['product_name'] : '', // Kosongkan nama produk untuk kelanjutan baris
            'quantity' => ($index === 0) ? $item['quantity'] : '',
            'unit' => ($index === 0) ? $item['unit'] : '',
            'notes' => $notes, 
            'is_continuation' => ($index > 0),
            'is_last' => ($index === $total_chunks - 1)
        ];
    }
  } else {
    // Jika tidak ada S/N
    $details[] = [
      'product_name' => $item['product_name'],
      'quantity' => $item['quantity'],
      'unit' => $item['unit'],
      'notes' => '', 
      'is_continuation' => false,
      'is_last' => true
    ];
  }
}
// --- END: LOGIKA PENGELOMPOKAN SERIAL NUMBER ---

// Logika untuk menentukan label berdasarkan tipe transaksi
$transaction_label = "No. Transaksi";
$document_label = "Nomor Dokumen";
$main_title = "RECEIVE ITEMS"; // Default title

if ($transaction['transaction_type'] === 'RI') {
  $transaction_label = "RI No";
  $document_label = "PO No";
  $main_title = "RECEIVE ITEMS";
} elseif ($transaction['transaction_type'] === 'RET') {
  $transaction_label = "RET No";
  $document_label = "DN No";
  $main_title = "RETURN ITEMS";
} elseif ($transaction['transaction_type'] === 'new') {
  $transaction_label = "ID Transaksi";
  $document_label = "Referensi Dokumen";
  $main_title = "NEW INVENTORY";
}

// Menentukan nilai reference yang akan ditampilkan
$display_reference = !empty($transaction['reference']) ? $transaction['reference'] : 'Stock Warehouse';

// 3. Buat PDF
class PDF extends FPDF {
  private $transaction;
  private $transaction_label;
  private $document_label;
  private $display_reference;
  private $main_title; // Tambahkan properti untuk judul utama

  function setTransactionData($transaction, $transaction_label, $document_label, $display_reference, $main_title) {
    $this->transaction = $transaction;
    $this->transaction_label = $transaction_label;
    $this->document_label = $document_label;
    $this->display_reference = $display_reference;
    $this->main_title = $main_title;
  }

  function Header() {
    // Logo
    $this->Image('../../assets/img/logogeo.png', 140, 3, 60); 
    
    // Judul utama yang dinamis
    $this->SetFont('Arial', 'B', 16);
    $this->Cell(0, 8, $this->main_title, 0, 1, 'L');
    $this->Ln(5);

    // Informasi transaksi dalam kotak yang menyatu
    $this->SetFont('Arial', '', 9);
    $y = $this->GetY();
    $x = $this->GetX();

    // Menggambar kotak informasi dalam satu tabel virtual
    // Baris 1: RI No. dan RI Date
    $this->SetFont('Arial', '', 9);
    $this->Cell(25, 6, $this->transaction_label, 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(70, 6, $this->transaction['transaction_number'], 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(25, 6, 'Date:', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(70, 6, date('d M Y', strtotime($this->transaction['transaction_date'])), 1, 1, 'L');
    
    // Baris 2: PO No. dan Ref.
    $this->SetFont('Arial', '', 9);
    $this->Cell(25, 6, $this->document_label, 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(70, 6, $this->transaction['document_number'], 1, 0, 'L');
    $this->Cell(25, 6, 'Ref:', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(70, 6, $this->display_reference, 1, 1, 'L');

    // Baris 3: From
    $this->SetFont('Arial', '', 9);
    $this->Cell(25, 6, 'From:', 1, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(165, 6, $this->transaction['sender'], 1, 1, 'L');

    $this->Ln(4); // Menyesuaikan baris untuk memulai konten

    // Deskripsi tambahan
    $this->SetFont('Arial', '', 9);
    $this->Cell(0, 6, 'Dengan ini kami lampirkan surat jalan dengan informasi sebagai berikut:', 0, 1);
    $this->Ln(2);
  }
  
    // Fungsi pembantu untuk menghitung tinggi yang dibutuhkan oleh MultiCell
    function GetStringHeight($width, $height, $text) {
        $nb = $this->NbLines($width, $text);
        return $height * $nb;
    }

    // Fungsi pembantu untuk menghitung jumlah baris teks
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb>0 and $s[$nb-1]=="\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i<$nb) {
            $c = $s[$i];
            if($c=="\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if($c==' ')
                $sep = $i;
            $l += $cw[$c];
            if($l>$wmax) {
                if($sep==-1) {
                    if($i==$j)
                        $i++;
                } else
                    $i = $sep+1;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else
                $i++;
        }
        return $nl;
    }
    
  // Tabel Item
  function ItemTable($header, $data) {
    $this->SetFont('Arial','B',9);
    $this->SetFillColor(255,255,255);
    $this->SetDrawColor(0,0,0);
    $this->SetLineWidth(.2);
    
    // Lebar kolom
    $w = array(10, 85, 13, 13, 13, 56);
    
    // Cetak Header
    for($i = 0; $i < count($header); $i++) {
      $this->Cell($w[$i], 6, $header[$i], 1, 0, 'C', true);
    }
    $this->Ln();

    $this->SetFont('Arial','',9);
    $this->SetFillColor(255,255,255);
    $this->SetTextColor(0);
    
$i = 1;
  foreach($data as $row) {
    $x_start = $this->GetX();
    $y_start = $this->GetY();
    
    // Hitung tinggi yang dibutuhkan oleh MultiCell Note (tinggi baris 5mm)
    $this->SetFont('Arial','',9);
    $note_height = $this->GetStringHeight($w[5], 5, $row['notes']);
    // Hitung tinggi yang dibutuhkan oleh Description (nama produk panjang)
    $desc_height = $this->GetStringHeight($w[1], 5, $row['product_name']);
    // Pastikan tinggi minimum adalah 6 (untuk item tanpa S/N dan nama pendek)
    $h = max(6, $note_height, $desc_height);
        
        // Periksa apakah baris baru akan melebihi batas halaman
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage();
            // Cetak ulang header
            $this->SetFont('Arial','B',9);
            for($j = 0; $j < count($header); $j++) {
                $this->Cell($w[$j], 6, $header[$j], 1, 0, 'C', true);
            }
            $this->Ln();
            $this->SetFont('Arial','',9);
            
            $x_start = $this->GetX();
            $y_start = $this->GetY();
        }

        // --- MENGGAMBAR BORDER KOTAK (SIMULASI 1 BARIS) ---
        // Alih-alih Rect, kita gambar garis per garis. Jika continuation, border atas dihilangkan. Jika bukan last, border bawah dihilangkan.
        $total_width = array_sum($w);
        
        // Garis batas Kiri dan Kanan
        $this->Line($x_start, $y_start, $x_start, $y_start + $h); // Kiri
        $this->Line($x_start + $total_width, $y_start, $x_start + $total_width, $y_start + $h); // Kanan
        
        // Garis batas Atas
        if (!isset($row['is_continuation']) || !$row['is_continuation']) {
            $this->Line($x_start, $y_start, $x_start + $total_width, $y_start); 
        }
        
        // Garis batas Bawah
        if (!isset($row['is_last']) || $row['is_last']) {
            $this->Line($x_start, $y_start + $h, $x_start + $total_width, $y_start + $h);
        }
        
        // Garis pemisah Vertikal:
        $x_current = $x_start;
        for ($k = 0; $k < count($w) - 1; $k++) {
            $x_current += $w[$k];
            $this->Line($x_current, $y_start, $x_current, $y_start + $h);
        }

    // --- MENEMPATKAN TEKS DI TENGAH VERTICAL (Semua Cell tanpa Border) ---
        
    // 1. Kolom NO
    $this->SetXY($x_start, $y_start + ($h - 6) / 2); 
    $row_num = '';
    if (!isset($row['is_continuation']) || !$row['is_continuation']) {
        $row_num = $i++;
    }
    $this->Cell($w[0], 6, $row_num, 0, 0, 'C'); 
    
    // 2. Kolom Description (MultiCell untuk nama panjang)
    $this->SetXY($x_start + $w[0], $y_start + ($h - $desc_height) / 2);
    $this->MultiCell($w[1], 5, $row['product_name'], 0, 'L');
        
    // 3. Kolom Qty
    $this->SetXY($x_start + $w[0] + $w[1], $y_start + ($h - 6) / 2);
    $this->Cell($w[2], 6, $row['quantity'], 0, 0, 'C');
    
    // 4. Kolom UOM
    $this->SetXY($x_start + $w[0] + $w[1] + $w[2], $y_start + ($h - 6) / 2);
    $this->Cell($w[3], 6, $row['unit'], 0, 0, 'C');
    
    // 5. Kolom Check
    $this->SetXY($x_start + $w[0] + $w[1] + $w[2] + $w[3], $y_start + ($h - 6) / 2);
    $this->Cell($w[4], 6, '', 0, 0, 'C');
    
    // 6. Kolom Note (MultiCell tanpa border)
    $this->SetXY($x_start + $w[0] + $w[1] + $w[2] + $w[3] + $w[4], $y_start + 0.5); // Posisikan di Y_start (sedikit margin)
    $this->MultiCell($w[5], 5, $row['notes'], 0, 'L'); // Border diatur 0
    
    // Posisikan kursor setelah baris
    $this->SetY($y_start + $h);
  }
    
    // Tambahkan bagian tanda tangan langsung di bawah tabel
    $this->Ln(0); // Tambahkan sedikit spasi atau biarkan 0 jika ingin langsung menempel
    $this->SetFont('Arial', 'B', 9);
    $this->SetLineWidth(0.2); // Ketebalan garis

    // Dibuat Oleh / Diterima Oleh (Header Tanda Tangan)
    $y_signature_header = $this->GetY();
    $x_signature_start = $this->GetX();

    $this->Cell(63, 6, 'Dibuat Oleh', 'TLR', 0, 'C'); // Top, Left, Right border
    $this->Cell(64, 6, 'Diperiksa Oleh', 'TLR', 0, 'C'); 
    $this->Cell(63, 6, 'Diterima Oleh', 'TR', 1, 'C'); // Top, Right border, pindah baris

    // Kotak Tanda Tangan (Area Kosong)
    $this->Cell(63, 12, '', 'LR', 0, 'C'); // Left, Right border
    $this->Cell(64, 12, '', 'LR', 0, 'C'); 
    $this->Cell(63, 12, '', 'R', 1, 'C'); // Right border, pindah baris

    // Nama dan Jabatan
    $this->SetFont('Arial', '', 9);
    $creator_name = isset($this->transaction['creator_name']) && !empty($this->transaction['creator_name']) ? $this->transaction['creator_name'] : 'Admin';
    $this->Cell(63, 6, $creator_name, 'BLR', 0, 'C'); // Bottom, Left, Right border
    $this->Cell(64, 6, '', 'BLR', 0, 'C'); 
    $this->Cell(63, 6, 'Warehouse', 'BR', 1, 'C'); // Bottom, Right border, pindah baris
    
    // Pastikan ada baris kosong setelah tanda tangan jika catatan mengikuti
    $this->Ln(2);
    
    // Bagian catatan yang baru
    $x = $this->GetX();
    $y = $this->GetY();
    $width = 190;
    
    // Kotak catatan
    $this->Rect($x, $y, $width, 12);
    
    // Baris pertama
    $this->SetFont('Arial', 'B', 9);
    $this->SetXY($x, $y); // Posisikan kursor kembali ke awal kotak
    $this->Cell(13, 6, 'Note(s):', 0, 0, 'L');
    $this->SetFont('Arial', '', 9);
    $this->Cell(100, 6, $this->transaction['notes'], 0, 1, 'L');
    
    // Gambar garis tengah horizontal
    $this->Line($x, $y + 6, $x + $width, $y + 6);
    
    // Baris kedua
    $this->SetFont('Arial', '', 9);
    $this->SetX($x); // Posisikan kursor untuk baris berikutnya
    $this->Cell(130, 6, 'Barang telah diterima dalam keadaan baik dan lengkap.', 0, 1, 'L');

    $this->Ln(2);
    
    // Keterangan warna
    $this->SetFont('Arial', 'I', 7);
    $this->Cell(0, 5, '*Putih : Accounting, Merah = Filling, Kuning = Gudang', 0, 0, 'R');
  }

}

// 4. Inisialisasi dan Hasilkan PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->setTransactionData($transaction, $transaction_label, $document_label, $display_reference, $main_title); // Tambahkan parameter $main_title
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);

$header = array('No.', 'Description', 'Qty', 'UOM', 'Check', 'Note');
$pdf->ItemTable($header, $details);

$pdf->Output('I', 'transaksi_' . $transaction['transaction_number'] . '.pdf');