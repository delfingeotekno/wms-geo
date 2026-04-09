<?php
include 'includes/db_connect.php';
include 'includes/header.php';

// Proteksi Halaman
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    header("Location: /wms-geo/index.php");
    exit();
}

// Fungsi pembantu untuk memformat tanggal
function date_indo($date_str) {
    $hari = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
    $bulan = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    $d = new DateTime($date_str);
    $hari_str = $hari[$d->format('w')];
    $bulan_str = $bulan[$d->format('n') - 1];
    return $hari_str . ", " . $d->format('d') . " " . $bulan_str . " " . $d->format('Y') . " " . $d->format('H:i');
}

// Ambil parameter dari URL
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date   = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$warehouse_filter = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;

// Ambil daftar gudang
$warehouses_query = $conn->query("SELECT id, name FROM warehouses ORDER BY name ASC");
$warehouses_list = $warehouses_query->fetch_all(MYSQLI_ASSOC);

// Query Info Produk
$product_name = '';
$unit = 'unit';
if ($product_id > 0) {
    $sql_product = "SELECT product_name, unit FROM products WHERE id = ?";
    $stmt = $conn->prepare($sql_product);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product_data = $stmt->get_result()->fetch_assoc();
    $product_name = $product_data['product_name'] ?? 'Produk Tidak Ditemukan';
    $unit = $product_data['unit'] ?? 'unit';
    $stmt->close();
}

// Inisialisasi untuk Query
$history_records = [];
$total_in = 0;
$total_out = 0;
$initial_balance = 0; // Saldo sebelum filter tanggal
$sql_parts = [];
$all_params = [];
$all_types = "";

if ($product_id > 0) {
    // --- 1. HITUNG TOTAL TRANSAKSI SEUMUR HIDUP UNTUK MENDAPATKAN DISCREPANCY (STOK AWAL/MANUAL UPDATE) ---
    $sql_discrepancy = [];
    $p_disc = [];
    $t_disc = "";

    // Inbound
    $cond_it = $warehouse_filter > 0 ? " AND it.warehouse_id = $warehouse_filter" : "";
    $sql_discrepancy[] = "SELECT SUM(itd.quantity) as q_in, 0 as q_out FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id WHERE itd.product_id = ? AND it.is_deleted = 0" . $cond_it;
    $p_disc[] = $product_id; $t_disc .= "i";
    // Outbound
    $cond_ot = $warehouse_filter > 0 ? " AND ot.warehouse_id = $warehouse_filter" : "";
    $sql_discrepancy[] = "SELECT 0 as q_in, SUM(otd.quantity) as q_out FROM outbound_transaction_details otd JOIN outbound_transactions ot ON ot.id = otd.transaction_id WHERE otd.product_id = ? AND ot.is_deleted = 0" . $cond_ot;
    $p_disc[] = $product_id; $t_disc .= "i";
    // Borrow
    $cond_bt = $warehouse_filter > 0 ? " AND bt.warehouse_id = $warehouse_filter" : "";
    $sql_discrepancy[] = "SELECT 0 as q_in, SUM(btd.quantity) as q_out FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = ? AND bt.is_deleted = 0" . $cond_bt;
    $p_disc[] = $product_id; $t_disc .= "i";
    // Pinjaman Kembali (Masuk)
    $sql_discrepancy[] = "SELECT SUM(btd.quantity) as q_in, 0 as q_out FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = ? AND btd.return_date IS NOT NULL AND bt.is_deleted = 0" . $cond_bt;
    $p_disc[] = $product_id; $t_disc .= "i";
    // Assembly Out (Components)
    $cond_ao = $warehouse_filter > 0 ? " AND ao.warehouse_id = $warehouse_filter" : "";
    $sql_discrepancy[] = "SELECT 0 as q_in, SUM(aoi.qty_out) as q_out FROM assembly_outbound_items aoi JOIN assembly_outbound ao ON ao.id = aoi.outbound_id WHERE aoi.product_id = ?" . $cond_ao;
    $p_disc[] = $product_id; $t_disc .= "i";
    // Assembly In (Finished Products)
    $sql_discrepancy[] = "SELECT SUM(ao.total_units) as q_in, 0 as q_out FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id WHERE a.finished_product_id = ? AND ao.status = 'Completed'" . $cond_ao;
    $p_disc[] = $product_id; $t_disc .= "i";
    // Transfer Keluar
    $cond_tr_out = $warehouse_filter > 0 ? " AND tr.source_warehouse_id = $warehouse_filter" : "";
    $sql_discrepancy[] = "SELECT 0 as q_in, SUM(ti.quantity) as q_out FROM transfer_items ti JOIN transfers tr ON tr.id = ti.transfer_id WHERE ti.product_id = ?" . $cond_tr_out;
    $p_disc[] = $product_id; $t_disc .= "i";
    // Transfer Masuk
    $cond_tr_in = $warehouse_filter > 0 ? " AND tr.destination_warehouse_id = $warehouse_filter" : "";
    $sql_discrepancy[] = "SELECT SUM(ti.quantity) as q_in, 0 as q_out FROM transfer_items ti JOIN transfers tr ON tr.id = ti.transfer_id WHERE ti.product_id = ? AND tr.status = 'Completed'" . $cond_tr_in;
    $p_disc[] = $product_id; $t_disc .= "i";
    // Adjustments (Manual)
    $cond_adj = $warehouse_filter > 0 ? " AND warehouse_id = $warehouse_filter" : "";
    $sql_discrepancy[] = "SELECT SUM(IF(type='Masuk', quantity, 0)) as q_in, SUM(IF(type='Keluar', quantity, 0)) as q_out FROM stock_adjustments WHERE product_id = ?" . $cond_adj;
    $p_disc[] = $product_id; $t_disc .= "i";

    $final_sql_disc = "SELECT (SUM(q_in) - SUM(q_out)) as total_movements FROM (" . implode(" UNION ALL ", $sql_discrepancy) . ") as sub";
    $stmt_disc = $conn->prepare($final_sql_disc);
    $stmt_disc->bind_param($t_disc, ...$p_disc);
    $stmt_disc->execute();
    $total_movements = (int)$stmt_disc->get_result()->fetch_assoc()['total_movements'];
    $stmt_disc->close();

    // Ambil stok produk saat ini dari tabel products
    $current_actual_stock = 0;
    if ($warehouse_filter > 0) {
        $res_stock = $conn->query("SELECT stock FROM warehouse_stocks WHERE product_id = $product_id AND warehouse_id = $warehouse_filter");
    } else {
        $res_stock = $conn->query("SELECT stock FROM products WHERE id = $product_id");
    }
    if ($row_s = $res_stock->fetch_assoc()) {
        $current_actual_stock = (int)$row_s['stock'];
    }

    // Saldo Awal Sistem (Stok Awal + Manual Adjustment)
    $system_start_balance = $current_actual_stock - $total_movements;
    $initial_balance = $system_start_balance;

    // --- 2. JIKA ADA FILTER TANGGAL, HITUNG SALDO SEBELUM START_DATE ---
    if (!empty($start_date)) {
        $bal_sql_parts = [];
        $bal_params = [];
        $bal_types = "";

        $bal_sql_parts[] = "SELECT SUM(itd.quantity) as q_in, 0 as q_out FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id WHERE itd.product_id = ? AND it.is_deleted = 0 AND DATE(it.created_at) < ?" . $cond_it;
        $bal_params[] = $product_id; $bal_params[] = $start_date; $bal_types .= "is";
        
        $bal_sql_parts[] = "SELECT 0 as q_in, SUM(otd.quantity) as q_out FROM outbound_transaction_details otd JOIN outbound_transactions ot ON ot.id = otd.transaction_id WHERE otd.product_id = ? AND ot.is_deleted = 0 AND DATE(ot.created_at) < ?" . $cond_ot;
        $bal_params[] = $product_id; $bal_params[] = $start_date; $bal_types .= "is";
        
        $bal_sql_parts[] = "SELECT 0 as q_in, SUM(btd.quantity) as q_out FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = ? AND bt.is_deleted = 0 AND DATE(bt.created_at) < ?" . $cond_bt;
        $bal_params[] = $product_id; $bal_params[] = $start_date; $bal_types .= "is";

        $bal_sql_parts[] = "SELECT SUM(btd.quantity) as q_in, 0 as q_out FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id WHERE btd.product_id = ? AND btd.return_date IS NOT NULL AND bt.is_deleted = 0 AND DATE(btd.return_date) < ?" . $cond_bt;
        $bal_params[] = $product_id; $bal_params[] = $start_date; $bal_types .= "is";

        $bal_sql_parts[] = "SELECT 0 as q_in, SUM(aoi.qty_out) as q_out FROM assembly_outbound_items aoi JOIN assembly_outbound ao ON ao.id = aoi.outbound_id WHERE aoi.product_id = ? AND DATE(ao.created_at) < ?" . $cond_ao;
        $bal_params[] = $product_id; $bal_params[] = $start_date; $bal_types .= "is";

        $bal_sql_parts[] = "SELECT SUM(ao.total_units) as q_in, 0 as q_out FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id WHERE a.finished_product_id = ? AND ao.status = 'Completed' AND DATE(ao.created_at) < ?" . $cond_ao;
        $bal_params[] = $product_id; $bal_params[] = $start_date; $bal_types .= "is";

        $bal_sql_parts[] = "SELECT 0 as q_in, SUM(ti.quantity) as q_out FROM transfer_items ti JOIN transfers tr ON tr.id = ti.transfer_id WHERE ti.product_id = ? AND DATE(tr.created_at) < ?" . $cond_tr_out;
        $bal_params[] = $product_id; $bal_params[] = $start_date; $bal_types .= "is";

        $bal_sql_parts[] = "SELECT SUM(ti.quantity) as q_in, 0 as q_out FROM transfer_items ti JOIN transfers tr ON tr.id = ti.transfer_id WHERE ti.product_id = ? AND tr.status = 'Completed' AND DATE(tr.received_at) < ?" . $cond_tr_in;
        $bal_params[] = $product_id; $bal_params[] = $start_date; $bal_types .= "is";

        $bal_sql_parts[] = "SELECT SUM(IF(type='Masuk', quantity, 0)) as q_in, SUM(IF(type='Keluar', quantity, 0)) as q_out FROM stock_adjustments WHERE product_id = ? AND DATE(created_at) < ?" . $cond_adj;
        $bal_params[] = $product_id; $bal_params[] = $start_date; $bal_types .= "is";

        $bal_final_sql = "SELECT (SUM(q_in) - SUM(q_out)) as prev_movements FROM (" . implode(" UNION ALL ", $bal_sql_parts) . ") as sub";
        $stmt_bal = $conn->prepare($bal_final_sql);
        $stmt_bal->bind_param($bal_types, ...$bal_params);
        $stmt_bal->execute();
        $prev_movements = (int)$stmt_bal->get_result()->fetch_assoc()['prev_movements'];
        $stmt_bal->close();

        $initial_balance = $system_start_balance + $prev_movements;
    }

    // --- 3. QUERY UTAMA UNTUK DAFTAR HISTORI ---

    
    // 1. Inbound (Masuk)
    if (empty($type_filter) || $type_filter == 'Masuk') {
        $where = "WHERE itd.product_id = ? AND it.is_deleted = 0" . $cond_it;
        $p = [$product_id]; $t = "i";
        if (!empty($start_date)) { $where .= " AND DATE(it.created_at) >= ?"; $p[] = $start_date; $t .= "s"; }
        if (!empty($end_date))   { $where .= " AND DATE(it.created_at) <= ?"; $p[] = $end_date; $t .= "s"; }

        $sql_parts[] = "(SELECT it.created_at AS date, 'Masuk' COLLATE utf8mb4_unicode_ci AS type, it.transaction_number COLLATE utf8mb4_unicode_ci AS transaction_number, it.id AS transaction_id, SUM(itd.quantity) as quantity, it.sender COLLATE utf8mb4_unicode_ci AS counterparty, SUM(itd.quantity) AS quantity_in, 0 AS quantity_out 
                        FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id $where GROUP BY it.id)";
        $all_params = array_merge($all_params, $p); $all_types .= $t;
    }

    // 2. Outbound (Keluar)
    if (empty($type_filter) || $type_filter == 'Keluar') {
        $where = "WHERE otd.product_id = ? AND ot.is_deleted = 0" . $cond_ot;
        $p = [$product_id]; $t = "i";
        if (!empty($start_date)) { $where .= " AND DATE(ot.created_at) >= ?"; $p[] = $start_date; $t .= "s"; }
        if (!empty($end_date))   { $where .= " AND DATE(ot.created_at) <= ?"; $p[] = $end_date; $t .= "s"; }

        $sql_parts[] = "(SELECT ot.created_at AS date, 'Keluar' COLLATE utf8mb4_unicode_ci AS type, ot.transaction_number COLLATE utf8mb4_unicode_ci AS transaction_number, ot.id AS transaction_id, SUM(otd.quantity) as quantity, ot.recipient COLLATE utf8mb4_unicode_ci AS counterparty, 0 AS quantity_in, SUM(otd.quantity) AS quantity_out 
                        FROM outbound_transaction_details otd JOIN outbound_transactions ot ON ot.id = otd.transaction_id $where GROUP BY ot.id)";
        $all_params = array_merge($all_params, $p); $all_types .= $t;
    }

    // 3. Borrow (Pinjam)
    // --- 3a. Keluar (Peminjaman) ---
    if (empty($type_filter) || $type_filter == 'Keluar' || $type_filter == 'Peminjaman') {
        $where = "WHERE btd.product_id = ? AND bt.is_deleted = 0" . $cond_bt;
        $p = [$product_id]; $t = "i";
        if (!empty($start_date)) { $where .= " AND DATE(bt.created_at) >= ?"; $p[] = $start_date; $t .= "s"; }
        if (!empty($end_date))   { $where .= " AND DATE(bt.created_at) <= ?"; $p[] = $end_date; $t .= "s"; }

        $sql_parts[] = "(SELECT bt.created_at AS date, 'Peminjaman' COLLATE utf8mb4_unicode_ci AS type, bt.transaction_number COLLATE utf8mb4_unicode_ci AS transaction_number, bt.id AS transaction_id, SUM(btd.quantity) as quantity, bt.borrower_name COLLATE utf8mb4_unicode_ci AS counterparty, 0 AS quantity_in, SUM(btd.quantity) AS quantity_out 
                        FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id $where GROUP BY bt.id)";
        $all_params = array_merge($all_params, $p); $all_types .= $t;
    }

    // --- 3b. Masuk (Pinjaman Kembali) ---
    if (empty($type_filter) || $type_filter == 'Masuk' || $type_filter == 'Peminjaman') {
        $where_ret = "WHERE btd.product_id = ? AND btd.return_date IS NOT NULL AND bt.is_deleted = 0" . $cond_bt;
        $p_ret = [$product_id]; $t_ret = "i";
        if (!empty($start_date)) { $where_ret .= " AND DATE(btd.return_date) >= ?"; $p_ret[] = $start_date; $t_ret .= "s"; }
        if (!empty($end_date))   { $where_ret .= " AND DATE(btd.return_date) <= ?"; $p_ret[] = $end_date; $t_ret .= "s"; }

        $sql_parts[] = "(SELECT btd.return_date AS date, 'Masuk (Pinjaman Kembali)' COLLATE utf8mb4_unicode_ci AS type, bt.transaction_number COLLATE utf8mb4_unicode_ci AS transaction_number, bt.id AS transaction_id, SUM(btd.quantity) as quantity, bt.borrower_name COLLATE utf8mb4_unicode_ci AS counterparty, SUM(btd.quantity) AS quantity_in, 0 AS quantity_out 
                        FROM borrowed_transactions_detail btd JOIN borrowed_transactions bt ON bt.id = btd.transaction_id $where_ret GROUP BY bt.id)";
        $all_params = array_merge($all_params, $p_ret); $all_types .= $t_ret;
    }

    // 4. Assembly (Perakitan)
    // --- 4a. Keluar (Komponen) ---
    if (empty($type_filter) || $type_filter == 'Keluar' || $type_filter == 'Perakitan') {
        $where_ao = "WHERE aoi.product_id = ?" . $cond_ao;
        $p_ao = [$product_id]; $t_ao = "i";
        if (!empty($start_date)) { $where_ao .= " AND DATE(ao.created_at) >= ?"; $p_ao[] = $start_date; $t_ao .= "s"; }
        if (!empty($end_date))   { $where_ao .= " AND DATE(ao.created_at) <= ?"; $p_ao[] = $end_date; $t_ao .= "s"; }

        $sql_parts[] = "(SELECT ao.created_at AS date, 'Keluar (Perakitan)' COLLATE utf8mb4_unicode_ci AS type, ao.transaction_no COLLATE utf8mb4_unicode_ci AS transaction_number, ao.id AS transaction_id, SUM(aoi.qty_out) AS quantity, ao.project_name COLLATE utf8mb4_unicode_ci AS counterparty, 0 AS quantity_in, SUM(aoi.qty_out) AS quantity_out 
                        FROM assembly_outbound_items aoi JOIN assembly_outbound ao ON ao.id = aoi.outbound_id $where_ao GROUP BY ao.id)";
        $all_params = array_merge($all_params, $p_ao); $all_types .= $t_ao;
    }

    // --- 4b. Masuk (Hasil Rakit) ---
    if (empty($type_filter) || $type_filter == 'Masuk' || $type_filter == 'Perakitan') {
        $where_ai = "WHERE a.finished_product_id = ? AND ao.status = 'Completed'" . $cond_ao;
        $p_ai = [$product_id]; $t_ai = "i";
        if (!empty($start_date)) { $where_ai .= " AND DATE(ao.created_at) >= ?"; $p_ai[] = $start_date; $t_ai .= "s"; }
        if (!empty($end_date))   { $where_ai .= " AND DATE(ao.created_at) <= ?"; $p_ai[] = $end_date; $t_ai .= "s"; }

        $sql_parts[] = "(SELECT ao.created_at AS date, 'Masuk (Perakitan)' COLLATE utf8mb4_unicode_ci AS type, ao.transaction_no COLLATE utf8mb4_unicode_ci AS transaction_number, ao.id AS transaction_id, SUM(ao.total_units) AS quantity, ao.project_name COLLATE utf8mb4_unicode_ci AS counterparty, SUM(ao.total_units) AS quantity_in, 0 AS quantity_out 
                        FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id $where_ai GROUP BY ao.id)";
        $all_params = array_merge($all_params, $p_ai); $all_types .= $t_ai;
    }

    // 5. Transfer (Mutasi Antar Gudang)
    // --- 5a. Keluar (Transfer Out) ---
    if (empty($type_filter) || $type_filter == 'Keluar' || $type_filter == 'Mutasi') {
        $where_tr_out = "WHERE ti.product_id = ?" . $cond_tr_out;
        $p_tr_out = [$product_id]; $t_tr_out = "i";
        if (!empty($start_date)) { $where_tr_out .= " AND DATE(tr.created_at) >= ?"; $p_tr_out[] = $start_date; $t_tr_out .= "s"; }
        if (!empty($end_date))   { $where_tr_out .= " AND DATE(tr.created_at) <= ?"; $p_tr_out[] = $end_date; $t_tr_out .= "s"; }

        $sql_parts[] = "(SELECT tr.created_at AS date, 'Keluar (Mutasi)' COLLATE utf8mb4_unicode_ci AS type, tr.transfer_no COLLATE utf8mb4_unicode_ci AS transaction_number, tr.id AS transaction_id, SUM(ti.quantity) AS quantity, (SELECT name FROM warehouses WHERE id = tr.destination_warehouse_id) COLLATE utf8mb4_unicode_ci AS counterparty, 0 AS quantity_in, SUM(ti.quantity) AS quantity_out 
                        FROM transfer_items ti JOIN transfers tr ON tr.id = ti.transfer_id $where_tr_out GROUP BY tr.id)";
        $all_params = array_merge($all_params, $p_tr_out); $all_types .= $t_tr_out;
    }

    // --- 5b. Masuk (Transfer In) ---
    if (empty($type_filter) || $type_filter == 'Masuk' || $type_filter == 'Mutasi') {
        $where_tr_in = "WHERE ti.product_id = ? AND tr.status = 'Completed'" . $cond_tr_in;
        $p_tr_in = [$product_id]; $t_tr_in = "i";
        if (!empty($start_date)) { $where_tr_in .= " AND DATE(tr.received_at) >= ?"; $p_tr_in[] = $start_date; $t_tr_in .= "s"; }
        if (!empty($end_date))   { $where_tr_in .= " AND DATE(tr.received_at) <= ?"; $p_tr_in[] = $end_date; $t_tr_in .= "s"; }

        $sql_parts[] = "(SELECT tr.received_at AS date, 'Masuk (Mutasi)' COLLATE utf8mb4_unicode_ci AS type, tr.transfer_no COLLATE utf8mb4_unicode_ci AS transaction_number, tr.id AS transaction_id, SUM(ti.quantity) AS quantity, (SELECT name FROM warehouses WHERE id = tr.source_warehouse_id) COLLATE utf8mb4_unicode_ci AS counterparty, SUM(ti.quantity) AS quantity_in, 0 AS quantity_out 
                        FROM transfer_items ti JOIN transfers tr ON tr.id = ti.transfer_id $where_tr_in GROUP BY tr.id)";
        $all_params = array_merge($all_params, $p_tr_in); $all_types .= $t_tr_in;
    }

    // 6. Adjustments (Penyesuaian Manual)
    if (empty($type_filter) || $type_filter == 'Masuk' || $type_filter == 'Keluar') {
        $where_adj = "WHERE product_id = ?" . $cond_adj;
        $p_adj = [$product_id]; $t_adj = "i";
        if (!empty($start_date)) { $where_adj .= " AND DATE(created_at) >= ?"; $p_adj[] = $start_date; $t_adj .= "s"; }
        if (!empty($end_date))   { $where_adj .= " AND DATE(created_at) <= ?"; $p_adj[] = $end_date; $t_adj .= "s"; }

        $sql_parts[] = "(SELECT created_at AS date, CONCAT(type, ' (Penyesuaian)') COLLATE utf8mb4_unicode_ci AS type, '-' COLLATE utf8mb4_unicode_ci AS transaction_number, id AS transaction_id, quantity, reason COLLATE utf8mb4_unicode_ci AS counterparty, (IF(type='Masuk', quantity, 0)) AS quantity_in, (IF(type='Keluar', quantity, 0)) AS quantity_out 
                        FROM stock_adjustments $where_adj)";
        $all_params = array_merge($all_params, $p_adj); $all_types .= $t_adj;
    }

    if (!empty($sql_parts)) {
        // Urutkan ASC untuk menghitung running balance, nanti baru di-reverse untuk tampilan
        $final_sql = implode(" UNION ALL ", $sql_parts) . " ORDER BY date ASC, transaction_id ASC";
        $stmt = $conn->prepare($final_sql);
        if (!empty($all_types)) {
            $stmt->bind_param($all_types, ...$all_params);
        }
        $stmt->execute();
        $history_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Hitung Running Balance & Identifikasi Stok Awal Absolut (paling awal dalam sistem)
        $current_bal = $initial_balance;
        $first_stock_val = 0;
        
        // 1. Cek dulu apakah ada Saldo Awal Sistem (Discrepancy/Registrasi Awal)
        if ($system_start_balance > 0) {
            $first_stock_val = $system_start_balance;
        } else {
            // 2. Jika tidak ada discrepancy, cari transaksi masuk paling pertama dalam SEJARAH produk (tanpa filter tanggal)
            $sql_first = "
                SELECT quantity_in FROM (
                    SELECT it.created_at as d, itd.quantity as quantity_in FROM inbound_transaction_details itd JOIN inbound_transactions it ON it.id = itd.transaction_id WHERE itd.product_id = ? AND it.is_deleted = 0" . $cond_it . "
                    UNION ALL
                    SELECT created_at as d, quantity as quantity_in FROM stock_adjustments WHERE product_id = ? AND type = 'Masuk'" . $cond_adj . "
                    UNION ALL
                    SELECT ao.created_at as d, ao.total_units as quantity_in FROM assembly_outbound ao JOIN assemblies a ON a.id = ao.assembly_id WHERE a.finished_product_id = ? AND ao.status = 'Completed'" . $cond_ao . "
                ) as all_in ORDER BY d ASC LIMIT 1";
            $stmt_f = $conn->prepare($sql_first);
            $stmt_f->bind_param("iii", $product_id, $product_id, $product_id);
            $stmt_f->execute();
            $res_f = $stmt_f->get_result()->fetch_assoc();
            $first_stock_val = (int)($res_f['quantity_in'] ?? 0);
            $stmt_f->close();
        }

        foreach ($history_records as &$record) {
            $total_in += $record['quantity_in'];
            $total_out += $record['quantity_out'];
            
            $current_bal = $current_bal + $record['quantity_in'] - $record['quantity_out'];
            $record['running_balance'] = $current_bal;
        }
        unset($record);

        // Balik urutan menjadi DESC untuk tampilan
        $history_records = array_reverse($history_records);
    }
}

?>

<div class="container-fluid my-4">
    <div class="content-header mb-4">
        <h1 class="fw-bold">HISTORI TRANSAKSI</h1>
        <p class="text-muted">Histori terperinci untuk: **<?= htmlspecialchars($product_name) ?>**
            <?php if ($warehouse_filter > 0): ?>
                <?php 
                    $wh_name = 'Tidak Diketahui';
                    foreach($warehouses_list as $w) {
                        if ($w['id'] == $warehouse_filter) { $wh_name = $w['name']; break; }
                    }
                ?>
                <br><span class="badge bg-secondary mt-2">Filter Gudang: <?= htmlspecialchars($wh_name) ?></span>
            <?php else: ?>
                <br><span class="badge bg-secondary mt-2">Filter Gudang: Global (Semua Gudang)</span>
            <?php endif; ?>
        </p>
    </div>

    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body bg-light rounded">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                <div class="col-md-2">
                    <label class="form-label fw-bold">Lokasi Gudang</label>
                    <select name="warehouse_id" class="form-select">
                        <option value="0">Global (Semua)</option>
                        <?php foreach ($warehouses_list as $wh): ?>
                            <option value="<?= $wh['id'] ?>" <?= $warehouse_filter == $wh['id'] ? 'selected' : '' ?>><?= htmlspecialchars($wh['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Dari Tanggal</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Sampai Tanggal</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Tipe Transaksi</label>
                    <select name="type" class="form-select">
                        <option value="">-- Semua Tipe --</option>
                        <option value="Masuk" <?= $type_filter == 'Masuk' ? 'selected' : '' ?>>Masuk</option>
                        <option value="Keluar" <?= $type_filter == 'Keluar' ? 'selected' : '' ?>>Keluar</option>
                        <option value="Peminjaman" <?= $type_filter == 'Peminjaman' ? 'selected' : '' ?>>Peminjaman</option>
                        <option value="Perakitan" <?= $type_filter == 'Perakitan' ? 'selected' : '' ?>>Perakitan</option>
                        <option value="Mutasi" <?= $type_filter == 'Mutasi' ? 'selected' : '' ?>>Mutasi Antar Gudang</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100 mb-2"><i class="bi bi-funnel me-2"></i>Filter</button>
                    <div class="d-flex gap-2">
                        <a href="?product_id=<?= $product_id ?>" class="btn btn-outline-secondary w-100">Reset</a>
                        <a href="generate_history_pdf.php?product_id=<?= $product_id ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&type=<?= $type_filter ?>&warehouse_id=<?= $warehouse_filter ?>" target="_blank" class="btn btn-danger w-100">
                            <i class="bi bi-file-earmark-pdf"></i> PDF
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($product_id == 0): ?>
        <div class="alert alert-warning text-center">Silakan pilih produk dari daftar produk.</div>
    <?php elseif (empty($history_records)): ?>
        <div class="alert alert-info text-center">Tidak ditemukan histori transaksi.</div>
    <?php else: ?>
        
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card bg-primary-subtle border-primary shadow-sm h-100">
                    <div class="card-body text-center p-3">
                        <h6 class="text-primary text-uppercase small fw-bold mb-1">Stok Awal</h6>
                        <h3 class="fw-bold mb-0"><?= number_format($first_stock_val, 0, ',', '.') ?></h3>
                        <small class="text-muted"><?= htmlspecialchars($unit) ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success-subtle border-success shadow-sm h-100">
                    <div class="card-body text-center p-3">
                        <h6 class="text-success text-uppercase small fw-bold mb-1">Total Masuk</h6>
                        <h3 class="fw-bold mb-0">+<?= number_format($total_in, 0, ',', '.') ?></h3>
                        <small class="text-muted"><?= htmlspecialchars($unit) ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger-subtle border-danger shadow-sm h-100">
                    <div class="card-body text-center p-3">
                        <h6 class="text-danger text-uppercase small fw-bold mb-1">Total Keluar</h6>
                        <h3 class="fw-bold mb-0">-<?= number_format($total_out, 0, ',', '.') ?></h3>
                        <small class="text-muted"><?= htmlspecialchars($unit) ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-dark border-dark shadow-sm h-100">
                    <div class="card-body text-center text-white p-3">
                        <h6 class="text-white-50 text-uppercase small fw-bold mb-1">Saldo Akhir</h6>
                        <h3 class="fw-bold mb-0"><?= number_format($current_bal, 0, ',', '.') ?></h3>
                        <small class="text-white-50"><?= htmlspecialchars($unit) ?></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Tanggal & Waktu</th>
                                <th>Tipe</th>
                                <th>Nomor Transaksi</th>
                                <th>Kuantitas</th> 
                                <th>Saldo</th>
                                <th>Pihak Terkait</th> 
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history_records as $record): ?>
                                <?php
                                    $badge = [
                                        'Masuk'=>'success', 
                                        'Keluar'=>'danger', 
                                        'Peminjaman'=>'info text-dark',
                                        'Masuk (Pinjaman Kembali)'=>'success',
                                        'Keluar (Perakitan)'=>'warning text-dark',
                                        'Masuk (Perakitan)'=>'primary',
                                        'Masuk (Penyesuaian)'=>'info',
                                        'Keluar (Penyesuaian)'=>'secondary',
                                        'Masuk (Mutasi)'=>'success text-white',
                                        'Keluar (Mutasi)'=>'danger'
                                    ][$record['type']] ?? 'secondary';

                                    $path = 'inbound';
                                    if (strpos($record['type'], 'Keluar') !== false) $path = 'outbound';
                                    if ($record['type'] == 'Peminjaman') $path = 'borrow';
                                    if (strpos($record['type'], 'Perakitan') !== false) $path = 'assembly';
                                    if (strpos($record['type'], 'Mutasi') !== false) $path = 'transfer';
                                ?>
                                <tr>
                                    <td class="small"><?= date_indo($record['date']) ?></td>
                                    <td><span class="badge bg-<?= $badge ?>"><?= $record['type'] ?></span></td>
                                    <td class="fw-bold">
                                        <?php if ($record['transaction_number'] != '-'): ?>
                                            <a href="/wms-geo/views/<?= $path ?>/detail.php?id=<?= $record['transaction_id'] ?>">
                                                <?= htmlspecialchars($record['transaction_number']) ?>
                                            </a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?= ($record['quantity_in'] > 0) ? 'text-success' : 'text-danger' ?> fw-bold">
                                        <?= ($record['quantity_in'] > 0 ? '+' : '-') . number_format($record['quantity'], 0, ',', '.') ?>
                                    </td>
                                    <td class="fw-bold text-dark">
                                        <?= number_format($record['running_balance'], 0, ',', '.') ?>
                                    </td>
                                    <td><?= htmlspecialchars($record['counterparty'] ?? '-') ?></td> 
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <a href="/wms-geo/views/products/index.php" class="btn btn-secondary mt-3">Kembali</a>
</div>

<?php include 'includes/footer.php'; ?>