<?php
// =================================================================
// REQUIRE LIBRARY UNTUK EXCEL (PhpSpreadsheet)
// =================================================================
require '../../vendor/autoload.php'; 

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat; 

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

// =================================================================
// 1. LOGIKA PENGAMBILAN DATA
// =================================================================

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

// Tambahkan kondisi filter
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
// 2. LOGIKA PERHITUNGAN ULANG STOK
// =================================================================

if (!empty($products)) {
    $serial_stock_query = $conn->prepare("SELECT COUNT(*) FROM serial_numbers WHERE product_id = ? AND status = 'Tersedia' AND is_deleted = 0" . $warehouse_filter_sql);

    $nonserial_stock_query = $conn->prepare("SELECT SUM(stock) FROM warehouse_stocks WHERE product_id = ?" . $warehouse_filter_sql);

    foreach ($products as $key => $product) {
        if ($product['has_serial'] == 1) {
            if ($active_tab === 'all') {
                $serial_stock_query->bind_param("i", $product['id']);
            } else {
                $serial_stock_query->bind_param("ii", $product['id'], $filter_warehouse_id);
            }
            $serial_stock_query->execute();
            $result = $serial_stock_query->get_result();
            $available_stock = $result->fetch_row()[0] ?? 0; 
            $products[$key]['stock'] = $available_stock;
        } else {
             if ($active_tab === 'all') {
                $nonserial_stock_query->bind_param("i", $product['id']);
            } else {
                $nonserial_stock_query->bind_param("ii", $product['id'], $filter_warehouse_id);
            }
            $nonserial_stock_query->execute();
            $result = $nonserial_stock_query->get_result();
            $available_stock = $result->fetch_row()[0] ?? 0;
            $products[$key]['stock'] = $available_stock;
        }
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


// =================================================================
// 3. GENERATE EXCEL MENGGUNAKAN PHPSPREADSHEET
// =================================================================

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Laporan Produk');

// --- Header Tabel (Dimulai dari Baris 4) ---
$header = ['No', 'Kode Produk', 'Nama Produk', 'Merek', 'Tipe'];
$col_map = ['A', 'B', 'C', 'D', 'E'];

// Tambahkan header dinamis untuk tiap gudang
$col_char_index = 6; // Mulai dari 'F' untuk gudang
if ($active_tab === 'all' && count($all_warehouses) > 0) {
    foreach ($all_warehouses as $wh) {
        $header[] = $wh['name'];
        $col_map[] = chr(64 + $col_char_index); // Konversi ke A-Z
        $col_char_index++;
    }
}

// Tambahkan Total Stok & Status
$header[] = 'Total Stok';
$col_map[] = chr(64 + $col_char_index++);
$header[] = 'Status';
$col_map[] = chr(64 + $col_char_index++);

$last_col_char = $col_map[count($col_map) - 1]; // Kolom terakhir, misalnya 'H' atau 'K'
$row_start = 4;

// --- Judul Laporan ---
$sheet->setCellValue('A1', 'Daftar Barang (' . $warehouse_name_display . ')');
$sheet->mergeCells("A1:{$last_col_char}1");
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A2', 'Laporan per tanggal: ' . date('d-m-Y'));
$sheet->mergeCells("A2:{$last_col_char}2");
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(3)->setRowHeight(15); 

// Isi Header
foreach ($header as $index => $label) {
    $sheet->setCellValue($col_map[$index] . $row_start, $label);
}

// Style Header
$header_style = [
    'font' => ['bold' => true, 'size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE6E6E6']], // Abu-abu muda
];
$sheet->getStyle("A{$row_start}:{$last_col_char}{$row_start}")->applyFromArray($header_style);

// Atur Lebar Kolom
$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(15);
$sheet->getColumnDimension('C')->setWidth(40);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(20);

// Set lebar kolom gudang dan Total/Status dinamis
$current_ci = 5; // index mulai dari F
if ($active_tab === 'all' && count($all_warehouses) > 0) {
    foreach ($all_warehouses as $wh) {
        $sheet->getColumnDimension($col_map[$current_ci])->setWidth(13);
        $current_ci++;
    }
}
$sheet->getColumnDimension($col_map[$current_ci++])->setWidth(10); // Total Stok
$sheet->getColumnDimension($col_map[$current_ci++])->setWidth(15); // Status

// ==========================================================
// PERBAIKAN AKHIR: Terapkan format TEKS ke Kolom Stok dan Stok Gudang
// Ini adalah solusi paling agresif untuk mencegah Excel menyembunyikan angka nol.
// ==========================================================
$stock_col_start = ($active_tab === 'all' && count($all_warehouses) > 0) ? 'F' : $col_map[5];
$stock_col_end = $col_map[count($col_map) - 2]; // Kolom sebelum Status

for ($col = ord($stock_col_start); $col <= ord($stock_col_end); $col++) {
    $col_letter = chr($col);
    $sheet->getStyle("{$col_letter}" . ($row_start + 1) . ":{$col_letter}" . ($row_start + count($products) + 5))
        ->getNumberFormat()
        ->setFormatCode(NumberFormat::FORMAT_TEXT);
}

// --- Isi Data ---
$row = $row_start + 1;
$no = 1;

foreach ($products as $product) {
    
    // Jamin nilai stok adalah integer 0 jika kosong/NULL.
    $current_stock = intval($product['stock'] ?? 0); 
    
    if ($current_stock < 0) {
        $current_stock = 0; 
    }

    // Hitung Status Stok
    $status_text = 'Safe';
    if ($current_stock <= $product['minimum_stock_level']) {
        $status_text = 'Danger';
    } elseif ($current_stock <= ($product['minimum_stock_level'] + 10)) {
        $status_text = 'Warning';
    }

    $data_row = [
        $no++,
        $product['product_code'],
        $product['product_name'], 
        $product['brand_name'],
        $product['type_name']
    ];

    // Masukkan stok per gudang jika tab 'all'
    if ($active_tab === 'all' && count($all_warehouses) > 0) {
        foreach ($all_warehouses as $wh) {
            $wh_id = $wh['id'];
            $wh_stock = intval($product['wh_stocks'][$wh_id] ?? 0);
            $data_row[] = (string)$wh_stock; // format TEKS
        }
    }

    // Tambahkan Total Stok dan Status
    $data_row[] = (string)$current_stock;
    $data_row[] = $status_text;

    // Masukkan data ke dalam baris Excel
    $sheet->fromArray($data_row, NULL, 'A' . $row);

    // Style Konten (Border dan Alignment)
    $content_style = [
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    ];
    $sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray($content_style);

    // Alignment khusus untuk kolom tertentu
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // No
    
    // Status column letter
    $status_col_letter = $col_map[count($col_map) - 1];
    
    // Semua kolom angka (stok) di center
    for ($col = ord($stock_col_start); $col <= ord($stock_col_end); $col++) {
        $sheet->getStyle(chr($col) . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
    
    $sheet->getStyle($status_col_letter . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Status
    
    // Beri warna background untuk status (opsional)
    if ($status_text == 'Danger') {
        $sheet->getStyle($status_col_letter . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFCCCC'); 
    } elseif ($status_text == 'Warning') {
        $sheet->getStyle($status_col_letter . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFCC'); 
    }
    
    $row++;
}

// --- Output File Excel ---
$filename = 'Laporan_Produk_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;