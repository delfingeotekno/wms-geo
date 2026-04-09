<?php
// Sertakan file pustaka FPDF
require('../../includes/fpdf/fpdf.php');

// Pastikan koneksi database sudah diinisialisasi (sesuai include di atas)
include '../../includes/db_connect.php';

// Cek apakah product_id ada
if (!isset($_GET['product_id']) || !is_numeric($_GET['product_id'])) {
    die("Product ID tidak valid.");
}

// ==========================================================
// 1. Ambil Parameter dan Inisialisasi Filter
// ==========================================================
$product_id = (int)$_GET['product_id'];
$search_term = $_GET['search'] ?? ''; // Ambil parameter pencarian
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (string)$_SESSION['warehouse_id'];

// Logika filter warehouse
if ($active_tab === 'all') {
    $warehouse_filter_sql = "";
} else {
    $filter_warehouse_id = (int)$active_tab;
    $warehouse_filter_sql = " AND warehouse_id = " . $filter_warehouse_id; // Safe because we cast to int
}

// 1.1. Ambil Nama Produk
$stmt = $conn->prepare("SELECT product_name FROM products WHERE id = ? AND is_deleted = 0");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product_name = $stmt->get_result()->fetch_row()[0] ?? 'Produk Tidak Ditemukan';
$stmt->close();

// ==========================================================
// 2. Ambil Daftar Serial Number dengan Filter Pencarian
// ==========================================================
$sql = "SELECT serial_number, status, created_at 
        FROM serial_numbers 
        WHERE product_id = ? AND is_deleted = 0" . $warehouse_filter_sql;

// Array untuk menampung parameter bind
$params = [$product_id];
$types = "i";

// Tambahkan kondisi filter jika search_term ada
if (!empty($search_term)) {
    // Tambahkan klausa WHERE tambahan untuk mencari di kolom serial_number atau status
    $sql .= " AND (serial_number LIKE ? OR status LIKE ?)";
    
    // Siapkan nilai LIKE
    $like_search = "%" . $search_term . "%";
    
    // Tambahkan parameter ke array
    $params[] = $like_search;
    $params[] = $like_search;
    $types .= "ss"; // Tambahkan dua string (s)
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);

// Menggunakan call_user_func_array untuk bind_param
// Karena bind_param tidak menerima array langsung di PHP
$bind_params = [];
$bind_params[] = &$types; // Parameter pertama harus selalu tipe data
for ($i = 0; $i < count($params); $i++) {
    $bind_params[] = &$params[$i]; // Parameter data harus berupa referensi
}
call_user_func_array([$stmt, 'bind_param'], $bind_params);

$stmt->execute();
$serials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


// --- DEFINISI KELAS PDF UNTUK SERIAL NUMBER ---
class SerialPDF extends FPDF {
    // Properti diubah menjadi public untuk menghindari error akses
    public $col_widths = [15, 80, 45, 50]; 
    protected $product_name;

    function setProductName($name) {
        $this->product_name = $name;
    }
    
    // Header halaman PDF
    function Header() {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 7, 'List Serial Number', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Produk: ' . $this->product_name, 0, 1, 'C');
        $this->Cell(0, 5, 'Tanggal Cetak: ' . date('d-m-Y'), 0, 1, 'C');
        $this->Ln(5);

        // Header Tabel
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        $this->Cell($this->col_widths[0], 7, 'No', 1, 0, 'C', true);
        $this->Cell($this->col_widths[1], 7, 'Serial Number', 1, 0, 'C', true);
        $this->Cell($this->col_widths[2], 7, 'Status Stok', 1, 0, 'C', true);
        $this->Cell($this->col_widths[3], 7, 'Tanggal Ditambahkan', 1, 1, 'C', true);
        $this->SetFont('Arial', '', 10);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Halaman ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}
// --- AKHIR DEFINISI KELAS PDF ---

// Mulai membuat PDF
$pdf = new SerialPDF('P', 'mm', 'A4'); // Potrait
$pdf->AliasNbPages(); 
$pdf->setProductName($product_name);
$pdf->AddPage();

// Isi tabel
$i = 1;
foreach ($serials as $serial) {
    // Akses properti menggunakan $pdf->col_widths[index]
    $pdf->Cell($pdf->col_widths[0], 6, $i++, 1, 0, 'C');
    $pdf->Cell($pdf->col_widths[1], 6, $serial['serial_number'], 1, 0, 'L');
    $pdf->Cell($pdf->col_widths[2], 6, $serial['status'], 1, 0, 'C');
    $pdf->Cell($pdf->col_widths[3], 6, date('d-m-Y H:i', strtotime($serial['created_at'])), 1, 1, 'C');
}

// Output PDF
$pdf->Output('I', 'Serial_Number_' . str_replace(' ', '_', $product_name) . '.pdf');
exit();
?>