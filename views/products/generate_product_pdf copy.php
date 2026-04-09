<?php
// Sertakan file pustaka FPDF
require('../../includes/fpdf/fpdf.php');

// Mulai sesi jika belum dimulai
if (session_status() == PHP_SESSION_NONE) {
  session_start();
}

include '../../includes/db_connect.php';

// Cek apakah pengguna sudah login dan memiliki hak akses
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
  header("Location: ../../login.php");
  exit();
}

// Inisialisasi variabel untuk filter dari URL
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$brand_filter = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;
$type_filter = isset($_GET['product_type_id']) ? (int)$_GET['product_type_id'] : 0;
$stock_status_filter = isset($_GET['stock_status']) ? $_GET['stock_status'] : '';

// Persiapkan query SQL dasar
$sql = "SELECT p.*
    FROM products p
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN product_types pt ON p.product_type_id = pt.id
    WHERE 1=1 AND p.is_deleted = 0";

$params = [];
$param_types = '';

// Tambahkan kondisi filter jika ada
if ($search_term) {
  $sql .= " AND (p.product_code LIKE ? OR p.product_name LIKE ?)";
  $search_like = "%" . $search_term . "%";
  $params[] = &$search_like;
  $params[] = &$search_like;
  $param_types .= 'ss';
}
if ($brand_filter > 0) {
  $sql .= " AND p.brand_id = ?";
  $params[] = &$brand_filter;
  $param_types .= 'i';
}
if ($type_filter > 0) {
  $sql .= " AND p.product_type_id = ?";
  $params[] = &$type_filter;
  $param_types .= 'i';
}
if ($stock_status_filter) {
  if ($stock_status_filter == 'danger') {
    $sql .= " AND p.stock <= p.minimum_stock_level";
  } elseif ($stock_status_filter == 'warning') {
    $sql .= " AND p.stock > p.minimum_stock_level AND p.stock <= (p.minimum_stock_level + 10)";
  } elseif ($stock_status_filter == 'safe') {
    $sql .= " AND p.stock > (p.minimum_stock_level + 10)";
  }
}
$sql .= " ORDER BY p.updated_at DESC";

// Siapkan dan jalankan statement
$stmt = $conn->prepare($sql);
if ($param_types) {
  call_user_func_array([$stmt, 'bind_param'], array_merge([$param_types], $params));
}
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


// =================================================================
// 🔥 LOGIKA BARU: Hitung Stok Masuk, Stok Keluar, Stok Tersedia, dan Stok Awal
// =================================================================

if (!empty($products)) {
  // 1. Query untuk menghitung total Stok Masuk (Inbound)
  $inbound_query = $conn->prepare("
        SELECT SUM(td.quantity) 
        FROM inbound_transaction_details td
        JOIN inbound_transactions t ON td.transaction_id = t.id
        WHERE td.product_id = ? AND t.is_deleted = 0
    ");
    
    // 2. Query untuk menghitung total Stok Keluar (Outbound)
  $outbound_query = $conn->prepare("
        SELECT SUM(td.quantity) 
        FROM outbound_transaction_details td
        JOIN outbound_transactions t ON td.transaction_id = t.id
        WHERE td.product_id = ? AND t.is_deleted = 0
    ");

  // 3. Query untuk menghitung Stok Tersedia (Serial Number Status = 'Tersedia')
  $serial_stock_query = $conn->prepare("
        SELECT COUNT(*) 
        FROM serial_numbers 
        WHERE product_id = ? AND status = 'Tersedia' AND is_deleted = 0
    ");


  foreach ($products as $key => $product) {
        $product_id = $product['id'];
        
        // --- 1. Hitung Stok Masuk ---
        $inbound_query->bind_param("i", $product_id);
        $inbound_query->execute();
        $total_in = $inbound_query->get_result()->fetch_row()[0] ?? 0;
        $products[$key]['stock_in'] = $total_in;

        // --- 2. Hitung Stok Keluar ---
        $outbound_query->bind_param("i", $product_id);
        $outbound_query->execute();
        $total_out = $outbound_query->get_result()->fetch_row()[0] ?? 0;
        $products[$key]['stock_out'] = $total_out;
        
        $available_stock = 0;
        
    // --- 3. Hitung Stok Tersedia (Sesuai Aturan) ---
    if ($product['has_serial'] == 1) {
            // Jika berserial: Ambil dari hitungan serial_numbers dengan status 'Tersedia'
      $serial_stock_query->bind_param("i", $product_id);
      $serial_stock_query->execute();
      $available_stock = $serial_stock_query->get_result()->fetch_row()[0] ?? 0;
    } else {
            // Jika non-serial: Ambil langsung dari kolom p.stock
             $available_stock = $product['stock'];
        }

    // Simpan Stok Tersedia
    $products[$key]['stock_available'] = $available_stock;
        
        // --- 4. Hitung Stok Awal: Stok Tersedia - Stok Masuk ---
        $products[$key]['stock_initial'] = $available_stock - $total_in;

        // Perbarui p.stock agar Status Stok tetap dihitung berdasarkan Stok Tersedia
        $products[$key]['stock'] = $available_stock; 
  }
  $inbound_query->close();
    $outbound_query->close();
  $serial_stock_query->close();
}


// --- DEFINISI KELAS PDF BARU (MODIFIKASI) ---
class PDF extends FPDF {
  protected $col_widths;
  // Disesuaikan untuk margin A4 landscape. Total lebar kolom baru = 240mm. Margin: (297-240)/2 = 28.5mm
  const TABLE_START_X = 28.5; 
  
  function setColWidths($widths) {
    $this->col_widths = $widths;
  }

    // Fungsi Row diperbarui untuk 8 kolom
  function Row($data) {
    $h = 5; 

    // 1. Hitung tinggi baris yang dibutuhkan (Berdasarkan kolom 'Nama Produk' pada index 2)
    $nb = 0;
    $w = $this->col_widths[2]; 
    $s = $data[2]; 
    
    $sep = -1;
    $i = 0; $j = 0; $l = 0;
    $line = 0;
        
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $cw = &$this->CurrentFont['cw'];
        
    while ($i < strlen($s)) {
      $c = $s[$i];
      if ($c == "\n") {
        $i++;
        $line++;
        $l = 0; $sep = -1; $j = $i;
        continue;
      }
      if ($c == ' ') $sep = $i;
      $l += $cw[$c];
      if ($l > $wmax) {
        if ($sep == -1) {
          if ($i == $j) $i++;
        } else $i = $sep + 1;
        $sep = -1; $j = $i; $l = 0;
        $line++;
      } else $i++;
    }
    $nb = $line + 1;
    $H = $h * $nb;
    
    // Cek page break
    if ($this->GetY() + $H > $this->PageBreakTrigger)
      $this->AddPage($this->CurOrientation);

    // 1. Set posisi X start baris
    $this->SetX(self::TABLE_START_X);
    
    // 2. Gambar Border untuk semua Cell menggunakan Rect (untuk 8 kolom)
    $x = $this->GetX();
    for($k = 0; $k < count($data); $k++) {
      $this->Rect($x, $this->GetY(), $this->col_widths[$k], $H);
      $x += $this->col_widths[$k];
    }

    // 3. Isi Konten Kolom
    $this->SetX(self::TABLE_START_X);
    $x_start = $this->GetX(); 
    $y = $this->GetY();
    $i = 0;
    
    // Cell 0, 1 (No, Kode Produk) - di tengah vertikal
        $this->SetXY($x_start, $y + ($H - $h) / 2);
    $this->Cell($this->col_widths[$i++], $h, $data[0], 0, 0, 'C'); 
        $this->SetXY($x_start + $this->col_widths[0], $y + ($H - $h) / 2);
    $this->Cell($this->col_widths[$i++], $h, $data[1], 0, 0, 'L');
    
    // Cell 2 (Nama Produk - MultiCell)
    $this->SetXY($x_start + $this->col_widths[0] + $this->col_widths[1], $y);
    $this->MultiCell($this->col_widths[$i++], $h, $data[2], 0, 'L'); 
    
    // Cell 3 (Stok Awal) - di tengah vertikal
    $this->SetXY($x_start + $this->col_widths[0] + $this->col_widths[1] + $this->col_widths[2], $y + ($H - $h) / 2);
    $this->Cell($this->col_widths[$i++], $h, $data[3], 0, 0, 'C');
        
    // Cell 4, 5, 6, 7 (Stok Masuk, Stok Keluar, Stok Tersedia, Status) - di tengah vertikal
        $x_pos_rest = $x_start + $this->col_widths[0] + $this->col_widths[1] + $this->col_widths[2] + $this->col_widths[3];
    $this->SetXY($x_pos_rest, $y + ($H - $h) / 2);
        
    $this->Cell($this->col_widths[$i++], $h, $data[4], 0, 0, 'C'); // Stok Masuk
    $this->Cell($this->col_widths[$i++], $h, $data[5], 0, 0, 'C'); // Stok Keluar
    $this->Cell($this->col_widths[$i++], $h, $data[6], 0, 0, 'C'); // Stok Tersedia
    $this->Cell($this->col_widths[$i++], $h, $data[7], 0, 1, 'C'); // Status

    // Pindahkan kursor Y ke posisi akhir baris
    $this->SetY($y + $H);
  }

  // Footer untuk penomoran halaman
  function Footer() {
    $this->SetY(-15);
    $this->SetFont('Arial', 'I', 8);
    $this->Cell(0, 10, 'Halaman ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
  }
}
// --- AKHIR DEFINISI KELAS PDF BARU ---

// Mulai membuat PDF dengan kelas baru
$pdf = new PDF('L', 'mm', 'A4'); // Landscape
$pdf->AliasNbPages(); 
$pdf->AddPage();

// Judul laporan
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Daftar Barang Gudang Geo', 0, 1, 'C');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 7, 'Laporan per tanggal: ' . date('d-m-Y'), 0, 1, 'C');
$pdf->Ln(10);

// --- LEBAR KOLOM BARU (8 KOLOM, Total 240mm) ---
// No(10) + Kode(30) + Nama(90) + Awal(20) + Masuk(25) + Keluar(25) + Tersedia(20) + Status(20) = 240mm
$w = [10, 30, 90, 20, 25, 25, 20, 20]; 
$pdf->setColWidths($w);

// Header tabel
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(230, 230, 230);

// --- SET POSISI X START HEADER KE TENGAH (28.5mm) ---
$pdf->SetX(PDF::TABLE_START_X);

$pdf->Cell($w[0], 8, 'No', 1, 0, 'C', true);
$pdf->Cell($w[1], 8, 'Kode Produk', 1, 0, 'C', true);
$pdf->Cell($w[2], 8, 'Nama Produk', 1, 0, 'C', true); 
$pdf->Cell($w[3], 8, 'Stok Awal', 1, 0, 'C', true); // Kolom Baru
$pdf->Cell($w[4], 8, 'Stok Masuk', 1, 0, 'C', true); 
$pdf->Cell($w[5], 8, 'Stok Keluar', 1, 0, 'C', true); 
$pdf->Cell($w[6], 8, 'Stok Tersedia', 1, 0, 'C', true); 
$pdf->Cell($w[7], 8, 'Status', 1, 1, 'C', true);

// Isi tabel
$pdf->SetFont('Arial', '', 10);
$i = 1;
foreach ($products as $product) {
  // Tentukan status stok
  $status_text = '';
  if ($product['stock_available'] <= $product['minimum_stock_level']) {
    $status_text = 'Danger';
  } elseif ($product['stock_available'] > $product['minimum_stock_level'] && $product['stock_available'] <= ($product['minimum_stock_level'] + 10)) {
    $status_text = 'Warning';
  } else {
    $status_text = 'Safe';
  }

  // Data yang akan diproses oleh fungsi Row (diperbarui menjadi 8 kolom)
  $data_row = [
    $i++,
    $product['product_code'],
    $product['product_name'], 
    $product['stock_initial'], // Stok Awal
    $product['stock_in'], 
    $product['stock_out'], 
    $product['stock_available'], 
    $status_text
  ];

  $pdf->Row($data_row);
}

// Output PDF
$pdf->Output('I', 'Laporan_Produk.pdf');
exit();
?>