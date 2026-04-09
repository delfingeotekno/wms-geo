<?php
// Sertakan file pustaka FPDF
require('../../includes/fpdf/fpdf.php');

// Mulai sesi jika belum dimulai
if (session_status() == PHP_SESSION_NONE) {
  session_start();
}

include '../../includes/db_connect.php';

// Cek apakah pengguna sudah login dan memiliki hak akses
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff', 'marketing', 'procurement'])) {
  header("Location: ../../login.php");
  exit();
}

// Inisialisasi variabel untuk filter dari URL
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$brand_filter = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;
$type_filter = isset($_GET['product_type_id']) ? (int)$_GET['product_type_id'] : 0;
$stock_status_filter = isset($_GET['stock_status']) ? $_GET['stock_status'] : '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (string)$_SESSION['warehouse_id'];

// Logika filter warehouse untuk perhitungan stok
$warehouse_name_display = 'Semua Gudang';

if ($active_tab === 'all') {
    $warehouse_filter_sql = "";
} else {
    $filter_warehouse_id = (int)$active_tab;
    $warehouse_filter_sql = " AND warehouse_id = ?";
    
    $wh_query = $conn->prepare("SELECT name FROM warehouses WHERE id = ?");
    $wh_query->bind_param("i", $filter_warehouse_id);
    $wh_query->execute();
    $wh_result = $wh_query->get_result();
    if ($wh_row = $wh_result->fetch_assoc()) {
        $warehouse_name_display = 'Gudang ' . $wh_row['name'];
    }
    $wh_query->close();
}

// Ambil semua data gudang untuk kolom dinamis (jika tab all)
$all_warehouses = [];
$wh_list_query = $conn->query("SELECT id, name FROM warehouses ORDER BY name");
if ($wh_list_query) {
    while ($row = $wh_list_query->fetch_assoc()) {
        $all_warehouses[] = $row;
    }
}

// Persiapkan query SQL dasar
$sql = "SELECT p.*, b.brand_name, pt.type_name, p.has_serial
    FROM products p
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN product_types pt ON p.product_type_id = pt.id
    WHERE 1=1 AND p.is_deleted = 0";

$params = [];
$param_types = '';

if ($active_tab !== 'all') {
    $sql .= " AND (p.warehouse_id IS NULL OR p.warehouse_id = ?)";
    $params[] = &$filter_warehouse_id;
    $param_types .= 'i';
}

// Tambahkan kondisi filter jika ada (sama seperti di index.php)
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
// LOGIKA: Hitung Ulang Stok untuk Produk Berserial (Sesuai Aturan)
// =================================================================

if (!empty($products)) {
  // Query untuk menghitung jumlah serial yang 'Tersedia'
  $serial_stock_query = $conn->prepare("SELECT COUNT(*) FROM serial_numbers WHERE product_id = ? AND status = 'Tersedia' AND is_deleted = 0" . $warehouse_filter_sql);
  $nonserial_stock_query = $conn->prepare("SELECT SUM(stock) FROM warehouse_stocks WHERE product_id = ?" . $warehouse_filter_sql);

  foreach ($products as $key => $product) {
        $available_stock = 0;

    // Cek jika produk memiliki serial
    if ($product['has_serial'] == 1) {
      // Hitung ulang stok dari tabel serial_numbers
        if ($active_tab === 'all') {
            $serial_stock_query->bind_param("i", $product['id']);
        } else {
            $serial_stock_query->bind_param("ii", $product['id'], $filter_warehouse_id);
        }
      $serial_stock_query->execute();
      $result = $serial_stock_query->get_result();
      $available_stock = $result->fetch_row()[0] ?? 0;
    } else {
        if ($active_tab === 'all') {
            $nonserial_stock_query->bind_param("i", $product['id']);
        } else {
            $nonserial_stock_query->bind_param("ii", $product['id'], $filter_warehouse_id);
        }
        $nonserial_stock_query->execute();
        $result = $nonserial_stock_query->get_result();
        $available_stock = $result->fetch_row()[0] ?? 0;
    }
        
    // Timpa nilai 'stock' di array $products dengan nilai Stok Tersedia yang benar
    $products[$key]['stock'] = $available_stock;
  }
  $serial_stock_query->close();
  $nonserial_stock_query->close();
  
  // Jika tab all, kita juga butuh stok per gudang untuk setiap produk
  if ($active_tab === 'all') {
      $wh_serial_q = $conn->prepare("SELECT COUNT(*) FROM serial_numbers WHERE product_id = ? AND warehouse_id = ? AND status = 'Tersedia' AND is_deleted = 0");
      $wh_nonserial_q = $conn->prepare("SELECT SUM(stock) FROM warehouse_stocks WHERE product_id = ? AND warehouse_id = ?");
      
      foreach ($products as $key => $product) {
          $products[$key]['wh_stocks'] = [];
          
          foreach ($all_warehouses as $wh) {
              $wh_stock = 0;
              if ($product['has_serial'] == 1) {
                  $wh_serial_q->bind_param("ii", $product['id'], $wh['id']);
                  $wh_serial_q->execute();
                  $res = $wh_serial_q->get_result();
                  $wh_stock = $res->fetch_row()[0] ?? 0;
              } else {
                  $wh_nonserial_q->bind_param("ii", $product['id'], $wh['id']);
                  $wh_nonserial_q->execute();
                  $res = $wh_nonserial_q->get_result();
                  $wh_stock = $res->fetch_row()[0] ?? 0;
              }
              $products[$key]['wh_stocks'][$wh['id']] = $wh_stock;
          }
      }
      $wh_serial_q->close();
      $wh_nonserial_q->close();
  }
}


// --- DEFINISI KELAS PDF BARU (Diperbaiki untuk perataan vertikal) ---
class PDF extends FPDF {
  protected $col_widths;
  public $table_start_x = 26; // Default margin
  public $is_header = false; // Flag for header row background
  
  function setColWidths($widths) {
    $this->col_widths = $widths;
  }

  // Fungsi bantu untuk menghitung jumlah baris yang terbungkus oleh MultiCell
  function NbLines($w, $txt) {
    // Computes the number of lines a MultiCell of width w will take
    $cw = &$this->CurrentFont['cw'];
    if($w==0)
        $w = $this->w-$this->rMargin-$this->x;
    $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
    $s = str_replace("\r", '', (string)$txt);
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
            }
            else
                $i = $sep+1;
            $sep = -1;
            $j = $i;
            $l = 0;
            $nl++;
        }
        else
            $i++;
    }
    return $nl;
  }

  // Fungsi untuk membuat baris dengan MultiCell
  function Row($data) {
    $h = 6; // Tinggi dasar sedikit dinaikkan agar rapih

    // 1. Hitung tinggi baris maksimal berdasarkan teks yang membungkus (MultiCell)
    $nbLinesMax = 1;
    
    for ($i = 0; $i < count($data); $i++) {
        // Teks yang bisa wrap: Nama(2), Merek(3), Tipe(4)
        // Jika sedang memproses Header, nama gudang (index 5++) juga bisa wrap
        if ($i == 2 || $i == 3 || $i == 4 || ($this->is_header && $i >= 5 && $i < (count($data) - 2))) {
            $linesForThisCol = $this->NbLines($this->col_widths[$i], (string)$data[$i]);
            if ($linesForThisCol > $nbLinesMax) {
                $nbLinesMax = $linesForThisCol;
            }
        }
    }
    
    $nb = $nbLinesMax;
    $H = $h * $nb;
    
    // Cek apakah halaman cukup, jika tidak, tambahkan halaman baru
    if ($this->GetY() + $H > $this->PageBreakTrigger)
      $this->AddPage($this->CurOrientation);

    // 2. Gambar Border (dan Background jika Header) untuk semua Cell
    $this->SetX($this->table_start_x);
    $x = $this->GetX();
    for($k = 0; $k < count($data); $k++) {
      if ($this->is_header) {
          $this->Rect($x, $this->GetY(), $this->col_widths[$k], $H, 'DF'); // Fill if header
      } else {
          $this->Rect($x, $this->GetY(), $this->col_widths[$k], $H);
      }
      $x += $this->col_widths[$k];
    }

    // 3. Isi Konten Kolom
    $this->SetX($this->table_start_x);
    $x_start = $this->GetX(); // Posisi X awal baris
    $y = $this->GetY();
    
    // Hitung offset vertikal untuk perataan tengah (untuk kolom non-MultiCell)
    $y_offset = ($H - $h) / 2;
    
    $current_x = $x_start;
    for ($i = 0; $i < count($data); $i++) {
        $w_col = $this->col_widths[$i];
        
        if ($i == 2 || $i == 3 || $i == 4 || ($this->is_header && $i >= 5 && $i < (count($data) - 2))) {
            // Kolom Nama, Merek, Tipe dicetak pakai MultiCell karena panjang teksnya bisa wrap
            // Juga kolom string dinamis di header (nama gudang) bisa wrap
            $this->SetXY($current_x, $y);
            
            // Hitung padding vertikal spesifik untuk kolom ini, siapa tahu dia cuma 1 baris sementara cell lain 3 baris
            $thisNb = $this->NbLines($w_col, (string)$data[$i]);
            $this_y_offset = ($H - ($thisNb * $h)) / 2;
            $this->SetY($y + $this_y_offset);
            $this->SetX($current_x);
            
            // Perataan teks (Header = C, Body = L)
            $align = $this->is_header ? 'C' : 'L';
            $this->MultiCell($w_col, $h, $data[$i], 0, $align);
        } else {
            // Kolom Lain - Header 0 (Center), dan 5++ (Center)
            $align = 'C';
            if ($i == 1 && !$this->is_header) { // Kode Produk Body
                $align = 'L';
            }
            
            $this->SetXY($current_x, $y + $y_offset);
            $this->Cell($w_col, $h, $data[$i], 0, 0, $align);
        }
        
        $current_x += $w_col;
    }

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
// Gunakan ukuran kertas Folio/F4 (215 x 330 mm) Landscape agar teks muat lebih banyak ke samping
if ($active_tab === 'all' && count($all_warehouses) > 0) {
    $pdf = new PDF('L', 'mm', [215, 330]); 
} else {
    $pdf = new PDF('L', 'mm', 'A4'); 
}
$pdf->AliasNbPages(); 
$pdf->AddPage();

// Judul laporan
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Daftar Barang (' . $warehouse_name_display . ')', 0, 1, 'C');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 7, 'Laporan per tanggal: ' . date('d-m-Y'), 0, 1, 'C');
$pdf->Ln(10);

// --- LEBAR KOLOM YANG DIPERBAIKI ---
// Lebar Default (Kertas A4 Landscape = 297mm) -> Total tabel = 245mm
$w = [10, 30, 90, 35, 35, 25, 20]; 

if ($active_tab === 'all' && count($all_warehouses) > 0) {
    // Pada Kertas Legal/F4 Landscape (lebar 330mm) -> Total aman tabel = 310mm
    $wh_col_width = 15; // lebar per kolom gudang
    
    // Dasar: No(10), Kode(30), Nama(80), Merek(40), Tipe(40) = 200mm
    $w = [10, 30, 80, 40, 40];
    
    // Tambahkan kolom gudang
    foreach ($all_warehouses as $wh) {
        $w[] = $wh_col_width;
    }
    
    // Tambahkan Total(20) dan Status(15) = 235mm + luas gudang
    $w[] = 20;
    $w[] = 15;
}

$pdf->setColWidths($w);

// Header tabel
$pdf->SetFont('Arial', 'B', 9); // Gunakan font 9 agar tidak terlalu sempit
$pdf->SetFillColor(230, 230, 230);

// --- SET POSISI X START HEADER KE TENGAH ATAU KIRI ---
if ($active_tab === 'all' && count($all_warehouses) > 0) {
    // Hitung total lebar tabel
    $total_width = array_sum($w);
    // Lebar kertas Folio Landscape = 330mm
    // Sisa margin = 330 - total_width
    $margin_kiri = (330 - $total_width) / 2;
    $pdf->table_start_x = ($margin_kiri > 5) ? $margin_kiri : 5; // Minimal margin 5mm
    $pdf->SetX($pdf->table_start_x);
} else {
    // Kertas A4 Landscape = 297mm. Lebar tabel fix = 245mm
    $pdf->table_start_x = (297 - 245) / 2;
    $pdf->SetX($pdf->table_start_x);
}

// Persiapkan data Header
$header_row = ['No', 'Kode Produk', 'Nama Produk', 'Merek', 'Tipe'];

// Render kolom gudang-gudang jika tab all
if ($active_tab === 'all' && count($all_warehouses) > 0) {
    foreach ($all_warehouses as $wh) {
        $header_row[] = $wh['name']; // Nama lengkap, akan wrap otomatis
    }
}

$header_row[] = 'Total Stok';
$header_row[] = 'Status';

// Cetak Header menggunakan Row (agar bisa MultiCell wrap)
$pdf->is_header = true;
$pdf->Row($header_row);
$pdf->is_header = false;

// Isi tabel
$pdf->SetFont('Arial', '', 10);
$i = 1;
foreach ($products as $product) {
  $status_text = '';
  if ($product['stock'] <= $product['minimum_stock_level']) {
    $status_text = 'Danger';
  } elseif ($product['stock'] > $product['minimum_stock_level'] && $product['stock'] <= ($product['minimum_stock_level'] + 10)) {
    $status_text = 'Warning';
  } else {
    $status_text = 'Safe';
  }

  // Base Data
  $data_row = [
    $i++,
    $product['product_code'],
    $product['product_name'], 
    $product['brand_name'],
    $product['type_name']
  ];
  
  // Masukkan data stok gudang spesifik (Jika tab All)
  if ($active_tab === 'all' && count($all_warehouses) > 0) {
      foreach ($all_warehouses as $wh) {
          $wh_id = $wh['id'];
          $data_row[] = $product['wh_stocks'][$wh_id] ?? 0;
      }
  }

  // Tambahkan Total Stok dan Status
  $data_row[] = $product['stock'];
  $data_row[] = $status_text;

  $pdf->Row($data_row);
}

// Output PDF
$pdf->Output('I', 'Laporan_Produk.pdf');
exit();
?>