<?php
session_start();
include '../../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $project_name = $_POST['project_name'];
    $product_ids = $_POST['product_id'] ?? [];
    $out_qtys = $_POST['out_qty'] ?? [];

    // Mulai Database Transaction
    $conn->begin_transaction();

    try {
        // --- 1. VALIDASI AWAL ---
        if (empty($id)) throw new Exception("ID Transaksi tidak valid.");
        if (empty($product_ids)) throw new Exception("Daftar barang tidak boleh kosong.");

        // Ambil Warehouse ID Transaksi
        $res_wh = $conn->query("SELECT warehouse_id FROM assembly_outbound WHERE id = $id");
        if ($res_wh->num_rows == 0) throw new Exception("Transaksi tidak ditemukan.");
        $warehouse_id = $res_wh->fetch_assoc()['warehouse_id'];

        // --- 2. RESTORE (KEMBALIKAN) DATA LAMA ---
        // A. Ambil data sebelum diedit untuk mengembalikan stok & status serial
        $sql_old = "SELECT product_id, qty_out, serial_number FROM assembly_outbound_items WHERE outbound_id = ?";
        $stmt_old = $conn->prepare($sql_old);
        $stmt_old->bind_param("i", $id);
        $stmt_old->execute();
        $res_old = $stmt_old->get_result();

        while ($old = $res_old->fetch_assoc()) {
            $p_id_old = $old['product_id'];
            $qty_old = $old['qty_out'];
            $sn_old = $old['serial_number'];

            // Tambahkan kembali stok
            $conn->query("UPDATE warehouse_stocks SET stock = stock + $qty_old WHERE product_id = $p_id_old AND warehouse_id = $warehouse_id");

            // Jika ada serial, kembalikan statusnya
            if ($sn_old) {
                $conn->query("UPDATE serial_numbers SET status = 'Tersedia' WHERE serial_number = '$sn_old' AND product_id = $p_id_old");
            }
        }

        // --- 3. UPDATE HEADER & RESULTS ---
        // A. Header
        $sql_update_header = "UPDATE assembly_outbound SET project_name = ? WHERE id = ?";
        $stmt_header = $conn->prepare($sql_update_header);
        $stmt_header->bind_param("si", $project_name, $id);
        $stmt_header->execute();

        // B. Clear & Insert Results (Multi-Template)
        $conn->query("DELETE FROM assembly_outbound_results WHERE outbound_id = $id");
        $produced_ids   = $_POST['produced_assembly_id'] ?? [];
        $produced_qtys  = $_POST['produced_total_units'] ?? [];
        if (!empty($produced_ids)) {
            $stmt_res = $conn->prepare("INSERT INTO assembly_outbound_results (outbound_id, assembly_id, qty) VALUES (?, ?, ?)");
            foreach ($produced_ids as $idx => $a_id) {
                $a_qty = $produced_qtys[$idx];
                $stmt_res->bind_param("iii", $id, $a_id, $a_qty);
                $stmt_res->execute();
            }
            // Update primary assembly_id & total_units for backward compatibility
            $conn->query("UPDATE assembly_outbound SET assembly_id = {$produced_ids[0]}, total_units = {$produced_qtys[0]} WHERE id = $id");
        }

        // --- 4. HAPUS DETAIL LAMA ---
        $conn->query("DELETE FROM assembly_outbound_items WHERE outbound_id = $id");

        // --- 5. PROSES DETAIL BARU & POTONG STOK & SERIAL ---
        foreach ($product_ids as $key => $p_id) {
            $row_id = $_POST['row_id'][$key];
            $qty_new = (int)$out_qtys[$key];
            $is_ret = isset($_POST['is_returnable'][$key]) ? $_POST['is_returnable'][$key] : 0;
            
            // Ambil data Serial dari input hidden row_id
            $serial_str = $_POST['serial_ids'][$row_id] ?? '';
            $serials = $serial_str ? explode(',', $serial_str) : [];

            if (!empty($serials)) {
                // Jika berserial, simpan tiap serial sebagai 1 baris
                $stmt_ins = $conn->prepare("INSERT INTO assembly_outbound_items (outbound_id, product_id, serial_number, qty_out, is_returnable) VALUES (?, ?, ?, 1, ?)");
                foreach ($serials as $sn) {
                    $sn = trim($sn);
                    $stmt_ins->bind_param("iisi", $id, $p_id, $sn, $is_ret);
                    $stmt_ins->execute();
                    // Update status serial ke 'Keluar'
                    $conn->query("UPDATE serial_numbers SET status = 'Keluar' WHERE serial_number = '$sn' AND product_id = $p_id");
                }
            } else {
                // Non-serial
                $stmt_ins = $conn->prepare("INSERT INTO assembly_outbound_items (outbound_id, product_id, qty_out, is_returnable) VALUES (?, ?, ?, ?)");
                $stmt_ins->bind_param("iiii", $id, $p_id, $qty_new, $is_ret);
                $stmt_ins->execute();
            }

            // Potong stok kembali
            $conn->query("UPDATE warehouse_stocks SET stock = stock - $qty_new WHERE product_id = $p_id AND warehouse_id = $warehouse_id");
        }

        // Jika semua berhasil
        $conn->commit();
        header("Location: index.php?status=updated");

    } catch (Exception $e) {
        // Jika ada satu saja yang gagal, batalkan semua perubahan stok
        $conn->rollback();
        die("Gagal memperbarui data: " . $e->getMessage());
    }
} else {
    header("Location: index.php");
}
?>