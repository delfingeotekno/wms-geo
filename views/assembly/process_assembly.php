<?php
session_start();
include '../../includes/db_connect.php';
// no tenant_id

// Blokir role production (read-only)
if (($_SESSION['role'] ?? '') === 'production') {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // 1. Ambil data dari POST
        $transaction_no = $_POST['transaction_no'];
        $project_name   = $_POST['project_name'];
        $user_id        = $_SESSION['user_id'];
        $warehouse_id   = $_POST['warehouse_id'];
        
        // Data Rakitan yang Dihasilkan (Multi)
        $produced_ids   = $_POST['produced_assembly_id'] ?? [];
        $produced_qtys  = $_POST['produced_total_units'] ?? [];

        // Untuk kompatibilitas tabel lama, kita isi assembly_id & total_units dengan item pertama (jika ada)
        $primary_assembly_id = !empty($produced_ids) ? $produced_ids[0] : null;
        $primary_total_units = !empty($produced_qtys) ? $produced_qtys[0] : 0;

        // 2. Simpan Header Transaksi
        $stmt = $conn->prepare("INSERT INTO assembly_outbound (transaction_no, assembly_id, total_units, project_name, user_id, warehouse_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siisii", $transaction_no, $primary_assembly_id, $primary_total_units, $project_name, $user_id, $warehouse_id);
        $stmt->execute();
        $outbound_id = $conn->insert_id;

        // 3. Simpan Hasil Rakitan ke tabel baru (Multi)
        if (!empty($produced_ids)) {
            $stmt_res = $conn->prepare("INSERT INTO assembly_outbound_results (outbound_id, assembly_id, qty) VALUES (?, ?, ?)");
            foreach ($produced_ids as $idx => $a_id) {
                $a_qty = $produced_qtys[$idx];
                $stmt_res->bind_param("iii", $outbound_id, $a_id, $a_qty);
                $stmt_res->execute();
            }
        }

        // 3. Validasi keberadaan item
        if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
            throw new Exception("Daftar barang keluar tidak boleh kosong.");
        }

        // 4. Loop Komponen (Gabungan Rakitan & Manual)
        foreach ($_POST['product_id'] as $key => $p_id) {
            $qty = $_POST['out_qty'][$key];
            $is_ret = isset($_POST['is_returnable'][$key]) ? $_POST['is_returnable'][$key] : 0;
            $row_id = $_POST['row_id'][$key];
            $serials_str = isset($_POST['serial_ids'][$row_id]) ? $_POST['serial_ids'][$row_id] : '';

            if ($qty <= 0) continue; 

            // Cek stok akhir
            $res = $conn->query("SELECT p.product_name, ps.stock, p.has_serial FROM warehouse_stocks ps JOIN products p ON ps.product_id = p.id WHERE ps.product_id = $p_id AND ps.warehouse_id = $warehouse_id")->fetch_assoc();
            if (!$res) throw new Exception("Stok tidak ditemukan untuk ID: $p_id");
            if ($res['stock'] < $qty) throw new Exception("Stok tidak mencukupi untuk: " . $res['product_name']);

            // Jika berserial, proses per serial number
            if ($res['has_serial'] == 1 && !empty($serials_str)) {
                $serials = explode(',', $serials_str);
                if (count($serials) != $qty) {
                    throw new Exception("Jumlah Serial Number untuk " . $res['product_name'] . " tidak sesuai dengan kuantitas keluar.");
                }

                foreach ($serials as $sn) {
                    $sn = trim($sn);
                    // Ambil serial_id
                    $q_sn = $conn->prepare("SELECT id FROM serial_numbers WHERE serial_number = ? AND product_id = ? AND warehouse_id = ? AND is_deleted = 0");
                    $q_sn->bind_param("sii", $sn, $p_id, $warehouse_id);
                    $q_sn->execute();
                    $sn_res = $q_sn->get_result()->fetch_assoc();
                    $sn_id = $sn_res['id'] ?? null;

                    if (!$sn_id) throw new Exception("Nomor seri $sn tidak ditemukan atau tidak tersedia.");

                    // Simpan ke detail (1 baris per serial, qty 1)
                    $stmt_item = $conn->prepare("INSERT INTO assembly_outbound_items (outbound_id, product_id, serial_number, serial_id, qty_out, is_returnable) VALUES (?, ?, ?, ?, 1, ?)");
                    $stmt_item->bind_param("iisii", $outbound_id, $p_id, $sn, $sn_id, $is_ret);
                    $stmt_item->execute();

                    // Update status serial number
                    $upd_sn = $conn->prepare("UPDATE serial_numbers SET status = 'Keluar', is_deleted = 1, updated_at = NOW() WHERE id = ?");
                    $upd_sn->bind_param("i", $sn_id);
                    $upd_sn->execute();
                }
            } else {
                // Non-serial: Simpan 1 baris untuk total qty
                $stmt_item = $conn->prepare("INSERT INTO assembly_outbound_items (outbound_id, product_id, qty_out, is_returnable) VALUES (?, ?, ?, ?)");
                $stmt_item->bind_param("iiii", $outbound_id, $p_id, $qty, $is_ret);
                $stmt_item->execute();
            }

            // Potong Stok Produk di Gudang (Berlaku untuk serial & non-serial)
            $update_stok = $conn->prepare("UPDATE warehouse_stocks SET stock = stock - ? WHERE product_id = ? AND warehouse_id = ?");
            $update_stok->bind_param("iii", $qty, $p_id, $warehouse_id);
            $update_stok->execute();
        }

        $conn->commit();
        // Redirect ke halaman detail untuk langsung melihat BAST atau cetak
        header("Location: detail.php?id=$outbound_id&status=success");
        
    } catch (Exception $e) {
        $conn->rollback();
        // Mengirim error kembali ke halaman create dengan session (opsional) atau die
        die("Gagal memproses transaksi: " . $e->getMessage());
    }
} else {
    header("Location: index.php");
}