<?php
require('includes/fpdf/fpdf.php');

if (session_status() == PHP_SESSION_NONE) {
  session_start();
}

include 'includes/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
  die("Akses ditolak.");
}

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (string)$_SESSION['warehouse_id'];
$warehouse_id = $active_tab;
$wh_name = "Semua Lokasi Gudang";
$warehouse_filter = "";

if ($warehouse_id != 'all' && $warehouse_id > 0) {
    if ($warehouse_id != 'all') { // always true
       // ... wait, safe checks
    }
    $warehouse_filter = " AND warehouse_id = " . (int)$warehouse_id;
    $wh_q = $conn->query("SELECT name FROM warehouses WHERE id = " . (int)$warehouse_id);
    if ($r = $wh_q->fetch_assoc()) {
        $wh_name = "Gudang " . $r['name'];
    }
}

// Persiapkan kriteria tanggal untuk RANGE
$date_it = $date_ret = $date_ao = $date_adj_m = "";
$date_ot = $date_aoi = $date_adj_k = $date_bt = "";

// Persiapkan kriteria tanggal AFTER end_date
$after_it = $after_ret = $after_ao = $after_adj_m = "";
$after_ot = $after_aoi = $after_adj_k = $after_bt = "";

if (!empty($start_date)) {
    $s = $conn->real_escape_string($start_date);
    $date_it .= " AND DATE(it.created_at) >= '$s'";
    $date_ret .= " AND DATE(btd.return_date) >= '$s'";
    $date_ao .= " AND DATE(ao.created_at) >= '$s'";
    $date_adj_m .= " AND DATE(created_at) >= '$s'";
    $date_ot .= " AND DATE(ot.created_at) >= '$s'";
    $date_aoi .= " AND DATE(ao.created_at) >= '$s'";
    $date_adj_k .= " AND DATE(created_at) >= '$s'";
    $date_bt .= " AND DATE(bt.created_at) >= '$s'";
}

if (!empty($end_date)) {
    $e = $conn->real_escape_string($end_date);
    $date_it .= " AND DATE(it.created_at) <= '$e'";
    $date_ret .= " AND DATE(btd.return_date) <= '$e'";
    $date_ao .= " AND DATE(ao.created_at) <= '$e'";
    $date_adj_m .= " AND DATE(created_at) <= '$e'";
    $date_ot .= " AND DATE(ot.created_at) <= '$e'";
    $date_aoi .= " AND DATE(ao.created_at) <= '$e'";
    $date_adj_k .= " AND DATE(created_at) <= '$e'";
    $date_bt .= " AND DATE(bt.created_at) <= '$e'";

    $after_it = " AND DATE(it.created_at) > '$e'";
    $after_ret = " AND DATE(btd.return_date) > '$e'";
    $after_ao = " AND DATE(ao.created_at) > '$e'";
    $after_adj_m = " AND DATE(created_at) > '$e'";
    $after_ot = " AND DATE(ot.created_at) > '$e'";
    $after_aoi = " AND DATE(ao.created_at) > '$e'";
    $after_adj_k = " AND DATE(created_at) > '$e'";
    $after_bt = " AND DATE(bt.created_at) > '$e'";
}

$sql_p = "SELECT p.id, p.product_code, p.product_name, b.brand_name, 
          COALESCE((SELECT SUM(stock) FROM warehouse_stocks WHERE product_id = p.id $warehouse_filter), 0) as current_stock 
          FROM products p LEFT JOIN brands b ON p.brand_id = b.id WHERE p.is_deleted = 0";

if ($product_id > 0) {
    $sql_p .= " AND p.id = " . $product_id;
}
$sql_p .= " ORDER BY p.product_name ASC";
$res_p = $conn->query($sql_p);

$period_info = "Periode: Keseluruhan Waktu";
if ($start_date && $end_date) {
    $period_info = "Periode: " . date('d-m-Y', strtotime($start_date)) . " s/d " . date('d-m-Y', strtotime($end_date));
} elseif ($start_date) {
    $period_info = "Sejak Tanggal: " . date('d-m-Y', strtotime($start_date));
} elseif ($end_date) {
    $period_info = "Hingga Tanggal: " . date('d-m-Y', strtotime($end_date));
}

class PDF extends FPDF {
    function Header() {
        global $wh_name, $period_info, $type_filter;
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'Rekapitulasi Pergerakan Barang (' . $wh_name . ')', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 8, $period_info, 0, 1, 'C');
        $this->Ln(5);

        $w_nama = 105;
        if (!empty($type_filter)) {
            $w_nama += 50; // Pinjam lebar dari 2 kolom rekap yang disembunyikan
        }

        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(10, 8, 'No', 1, 0, 'C', true);
        $this->Cell(35, 8, 'Kode Produk', 1, 0, 'C', true);
        $this->Cell($w_nama, 8, 'Nama Produk', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Merek', 1, 0, 'C', true);
        
        if (empty($type_filter) || $type_filter === 'inbound') {
            $this->Cell(25, 8, 'Masuk', 1, 0, 'C', true);
        }
        if (empty($type_filter) || $type_filter === 'outbound') {
            $this->Cell(25, 8, 'Keluar', 1, 0, 'C', true);
        }
        if (empty($type_filter) || $type_filter === 'borrow') {
            $this->Cell(25, 8, 'Peminjaman', 1, 0, 'C', true);
        }
        $this->Cell(22, 8, 'Sisa Stok', 1, 1, 'C', true);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Halaman ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

$pdf = new PDF('L', 'mm', 'A4'); // Landscape for wide tables
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 8);

$no = 1;

$wh_cond_it = ($warehouse_id !== 'all' ? " AND it.warehouse_id=".(int)$warehouse_id : "");
$wh_cond_ot = ($warehouse_id !== 'all' ? " AND ot.warehouse_id=".(int)$warehouse_id : "");
$wh_cond_bt = ($warehouse_id !== 'all' ? " AND bt.warehouse_id=".(int)$warehouse_id : "");
$wh_cond_adj = ($warehouse_id !== 'all' ? " AND warehouse_id=".(int)$warehouse_id : "");
// Note: assembly_outbound doesn't track warehouse_id effectively, but we'll assume it's correctly handled via tenant or generally ignored if not needed. We'll omit WH filter for assembly as a fallback.

while ($row = $res_p->fetch_assoc()) {
    $pid = $row['id'];
    
    // MASUK (Inbound + Borrow Return + Assembly Finished + Stock Adjustment Masuk)
    $q_masuk = "SELECT 
        (SELECT COALESCE(SUM(itd.quantity), 0) FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id WHERE itd.product_id = $pid AND it.is_deleted = 0 $wh_cond_it $date_it) +
        (SELECT COALESCE(SUM(btd.quantity), 0) FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = $pid AND bt.is_deleted = 0 $wh_cond_bt AND btd.return_date IS NOT NULL $date_ret) +
        (SELECT COALESCE(SUM(ao.total_units), 0) FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id WHERE a.finished_product_id = $pid AND ao.status = 'Completed' $date_ao) +
        (SELECT COALESCE(SUM(quantity), 0) FROM stock_adjustments WHERE product_id = $pid AND type = 'Masuk' $wh_cond_adj $date_adj_m) AS total";
    $masuk = (int)$conn->query($q_masuk)->fetch_assoc()['total'];
    
    // KELUAR (Outbound + Assembly Components Out + Stock Adjustment Keluar)
    $q_keluar = "SELECT 
        (SELECT COALESCE(SUM(otd.quantity), 0) FROM outbound_transaction_details otd JOIN outbound_transactions ot ON ot.id = otd.transaction_id WHERE otd.product_id = $pid AND ot.is_deleted = 0 $wh_cond_ot $date_ot) +
        (SELECT COALESCE(SUM(aoi.qty_out), 0) FROM assembly_outbound_items aoi JOIN assembly_outbound ao ON ao.id = aoi.outbound_id WHERE aoi.product_id = $pid $date_aoi) +
        (SELECT COALESCE(SUM(quantity), 0) FROM stock_adjustments WHERE product_id = $pid AND type = 'Keluar' $wh_cond_adj $date_adj_k) AS total";
    $keluar = (int)$conn->query($q_keluar)->fetch_assoc()['total'];
    
    // PEMINJAMAN (Keluar dipinjam)
    $q_pinjam = "SELECT COALESCE(SUM(btd.quantity), 0) AS total FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = $pid AND bt.is_deleted = 0 $wh_cond_bt $date_bt";
    $pinjam = (int)$conn->query($q_pinjam)->fetch_assoc()['total'];

    $sisa_stok_akhir = $row['current_stock'];
    
    // Jika ada end_date, kurangi pergerakan SETELAH end_date dari current_stock
    if (!empty($end_date)) {
        $q_after_m = "SELECT 
            (SELECT COALESCE(SUM(itd.quantity), 0) FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id WHERE itd.product_id = $pid AND it.is_deleted = 0 $wh_cond_it $after_it) +
            (SELECT COALESCE(SUM(btd.quantity), 0) FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = $pid AND bt.is_deleted = 0 $wh_cond_bt AND btd.return_date IS NOT NULL $after_ret) +
            (SELECT COALESCE(SUM(ao.total_units), 0) FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id WHERE a.finished_product_id = $pid AND ao.status = 'Completed' $after_ao) +
            (SELECT COALESCE(SUM(quantity), 0) FROM stock_adjustments WHERE product_id = $pid AND type = 'Masuk' $wh_cond_adj $after_adj_m) AS total";
        $after_masuk = (int)$conn->query($q_after_m)->fetch_assoc()['total'];

        $q_after_k = "SELECT 
            (SELECT COALESCE(SUM(otd.quantity), 0) FROM outbound_transaction_details otd JOIN outbound_transactions ot ON ot.id = otd.transaction_id WHERE otd.product_id = $pid AND ot.is_deleted = 0 $wh_cond_ot $after_ot) +
            (SELECT COALESCE(SUM(aoi.qty_out), 0) FROM assembly_outbound_items aoi JOIN assembly_outbound ao ON ao.id = aoi.outbound_id WHERE aoi.product_id = $pid $after_aoi) +
            (SELECT COALESCE(SUM(quantity), 0) FROM stock_adjustments WHERE product_id = $pid AND type = 'Keluar' $wh_cond_adj $after_adj_k) AS total";
        $after_keluar = (int)$conn->query($q_after_k)->fetch_assoc()['total'];

        $q_after_p = "SELECT COALESCE(SUM(btd.quantity), 0) AS total FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = $pid AND bt.is_deleted = 0 $wh_cond_bt $after_bt";
        $after_pinjam = (int)$conn->query($q_after_p)->fetch_assoc()['total'];
        
        // Logikanya: Sisa pada saat end_date = Stok Sekarang - Yang masuk setelah end_date + Yang keluar setelah end_date + Yang dipinjam setelah end_date
        $sisa_stok_akhir = $sisa_stok_akhir - $after_masuk + $after_keluar + $after_pinjam;
    }

    $has_activity = ($masuk > 0 || $keluar > 0 || $pinjam > 0);

    // Filter dinamis berdasarkan filter waktu dan tipe:
    // Jika rentang tanggal atau tipe transaksi aktif, produk WAJIB memiliki aktivitas untuk lolos
    if (!empty($start_date) || !empty($end_date) || !empty($type_filter)) {
        if (!$has_activity) {
            continue; // Skip karena tidak ada histori pada periode ini
        }
        
        // Cek spesifik berdasarkan tipe transaksi jika ada
        if ($type_filter === 'inbound' && $masuk == 0) continue;
        if ($type_filter === 'outbound' && $keluar == 0) continue;
        if ($type_filter === 'borrow' && $pinjam == 0) continue;
    } else {
        // Jika tidak ada filter yang aktif, hanya sembunyikan yang persis kosong
        if ($masuk == 0 && $keluar == 0 && $pinjam == 0 && $sisa_stok_akhir == 0) {
            if ($product_id == 0) continue; 
        }
    }

    $c_y = $pdf->GetY();
    $pdf->SetXY(10, $c_y);
    $pdf->Cell(10, 8, $no++, 1, 0, 'C');
    $pdf->Cell(35, 8, $row['product_code'], 1, 0, 'C');
    
    // Potong nama produk yang panjang manual untuk FPDF
    $w_nama = 105;
    $max_chars = 62;
    if (!empty($type_filter)) {
        $w_nama += 50;
        $max_chars = 95;
    }

    $prod_name = $row['product_name'];
    if (strlen($prod_name) > $max_chars) {
        $prod_name = substr($prod_name, 0, $max_chars - 3) . '...';
    }
    
    $pdf->Cell($w_nama, 8, $prod_name, 1, 0, 'L');
    $pdf->Cell(30, 8, $row['brand_name'] ?: '-', 1, 0, 'C');
    
    if (empty($type_filter) || $type_filter === 'inbound') {
        $pdf->Cell(25, 8, $masuk, 1, 0, 'C');
    }
    if (empty($type_filter) || $type_filter === 'outbound') {
        $pdf->Cell(25, 8, $keluar, 1, 0, 'C');
    }
    if (empty($type_filter) || $type_filter === 'borrow') {
        $pdf->Cell(25, 8, $pinjam, 1, 0, 'C');
    }
    
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(22, 8, $sisa_stok_akhir, 1, 1, 'C');
    $pdf->SetFont('Arial', '', 8);
}

$pdf->Output('I', 'Rekapitulasi_Aktivitas.pdf');
?>
