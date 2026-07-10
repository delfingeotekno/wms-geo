<?php
include '../../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $return_id = $_POST['return_id'];
    $transaction_date = $_POST['transaction_date'];
    $notes = $conn->real_escape_string($_POST['notes']);
    $proof_photo_input = $_POST['proof_photo_data'] ?? ''; // Bisa Base64 baru atau nama file lama
    $items = json_decode($_POST['items_json'], true);

    if (empty($items)) {
        die("Error: Tidak ada item yang dikembalikan.");
    }

    // Mulai Transaksi Database
    $conn->begin_transaction();

    try {
        // --- LANGKAH 1: PROSES FOTO ---
        $final_photo_name = "";
        
        // Cek apakah user mengunggah foto baru (Base64)
        if (strpos($proof_photo_input, 'data:image') === 0) {
            $img_parts = explode(";base64,", $proof_photo_input);
            $img_type_aux = explode("image/", $img_parts[0]);
            $img_type = $img_type_aux[1];
            $img_base64 = base64_decode($img_parts[1]);
            
            $final_photo_name = "RET_" . time() . "_" . uniqid() . "." . $img_type;
            $upload_path = "../../uploads/inbound_photos/" . $final_photo_name;
            
            file_put_contents($upload_path, $img_base64);
        } else {
            // Jika tidak ada foto baru, gunakan nama file yang lama
            $final_photo_name = $proof_photo_input;
        }

        // --- LANGKAH 2: REVERT STOK LAMA ---
        // Kita kembalikan stok ke kondisi sebelum return ini ada (stok dikurangi qty return lama)
        $sql_old = "SELECT product_id, quantity FROM inbound_transaction_details WHERE transaction_id = ?";
        $stmt_old = $conn->prepare($sql_old);
        $stmt_old->bind_param("i", $return_id);
        $stmt_old->execute();
        $old_items = $stmt_old->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($old_items as $old) {
            $sql_revert = "UPDATE products SET stock = stock - ? WHERE id = ?";
            $st_revert = $conn->prepare($sql_revert);
            $st_revert->bind_param("di", $old['quantity'], $old['product_id']);
            $st_revert->execute();
        }

        // --- LANGKAH 3: UPDATE HEADER ---
        // Nama kolom foto disesuaikan dengan yang ada di return_edit.php (proof_photo_path)
        $sql_update_header = "UPDATE inbound_transactions 
                              SET transaction_date = ?, notes = ?, proof_photo_path = ?, total_items = ? 
                              WHERE id = ?";
        $total_qty = array_sum(array_column($items, 'quantity'));
        $st_header = $conn->prepare($sql_update_header);
        $st_header->bind_param("sssdi", $transaction_date, $notes, $final_photo_name, $total_qty, $return_id);
        $st_header->execute();

        // --- LANGKAH 4: SINKRONISASI DETAIL ---
        // Ambil serial number lama sebelum detail dihapus
        $sql_old_sns = "SELECT product_id, serial_number FROM inbound_transaction_details WHERE transaction_id = ? AND serial_number IS NOT NULL AND serial_number != ''";
        $stmt_old_sns = $conn->prepare($sql_old_sns);
        $stmt_old_sns->bind_param("i", $return_id);
        $stmt_old_sns->execute();
        $old_sns = $stmt_old_sns->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_old_sns->close();

        // Hapus detail lama
        $sql_del = "DELETE FROM inbound_transaction_details WHERE transaction_id = ?";
        $st_del = $conn->prepare($sql_del);
        $st_del->bind_param("i", $return_id);
        $st_del->execute();
        $st_del->close();

        // Revert status serial number lama ke 'Keluar' (karena mungkin dibatalkan pengembaliannya)
        foreach ($old_sns as $old_sn) {
            $stmt_revert_sn = $conn->prepare("UPDATE serial_numbers SET status = 'Keluar', updated_at = NOW() WHERE serial_number = ? AND product_id = ?");
            $stmt_revert_sn->bind_param("si", $old_sn['serial_number'], $old_sn['product_id']);
            $stmt_revert_sn->execute();
            $stmt_revert_sn->close();
        }

        // Ambil warehouse_id dari transaksi return
        $wh_query = $conn->query("SELECT warehouse_id FROM inbound_transactions WHERE id = $return_id");
        $wh_row = $wh_query->fetch_assoc();
        $warehouse_id = (int)$wh_row['warehouse_id'];

        // Masukkan detail baru dan update stok baru (stok ditambah qty return baru)
        foreach ($items as $item) {
            $sql_ins = "INSERT INTO inbound_transaction_details (transaction_id, product_id, quantity, serial_number) 
                        VALUES (?, ?, ?, ?)";
            $st_ins = $conn->prepare($sql_ins);
            $st_ins->bind_param("iids", $return_id, $item['product_id'], $item['quantity'], $item['serial_number']);
            $st_ins->execute();
            $st_ins->close();

            $sql_add_stock = "UPDATE products SET stock = stock + ? WHERE id = ?";
            $st_add = $conn->prepare($sql_add_stock);
            $st_add->bind_param("di", $item['quantity'], $item['product_id']);
            $st_add->execute();
            $st_add->close();

            // Sinkronkan status serial number baru ke 'Tersedia' dengan fallback insert jika belum ada
            if (!empty($item['serial_number'])) {
                $stmt_sn_upd = $conn->prepare("UPDATE serial_numbers SET status = 'Tersedia', warehouse_id = ?, last_transaction_id = ?, last_transaction_type = 'RET', updated_at = NOW() WHERE serial_number = ? AND product_id = ?");
                $stmt_sn_upd->bind_param("iisi", $warehouse_id, $return_id, $item['serial_number'], $item['product_id']);
                $stmt_sn_upd->execute();
                
                if ($stmt_sn_upd->affected_rows === 0) {
                    $stmt_chk = $conn->prepare("SELECT id FROM serial_numbers WHERE serial_number = ? AND product_id = ?");
                    $stmt_chk->bind_param("si", $item['serial_number'], $item['product_id']);
                    $stmt_chk->execute();
                    $res_chk = $stmt_chk->get_result();
                    
                    if ($res_chk->num_rows === 0) {
                        // Sisipkan baru jika belum ada
                        $stmt_ins_sn = $conn->prepare("INSERT INTO serial_numbers (product_id, warehouse_id, serial_number, status, last_transaction_id, last_transaction_type, is_deleted, created_at, updated_at) VALUES (?, ?, ?, 'Tersedia', ?, 'RET', 0, NOW(), NOW())");
                        $stmt_ins_sn->bind_param("iisi", $item['product_id'], $warehouse_id, $item['serial_number'], $return_id);
                        $stmt_ins_sn->execute();
                        $stmt_ins_sn->close();
                    } else {
                        // Re-aktifkan
                        $stmt_reactivate = $conn->prepare("UPDATE serial_numbers SET status = 'Tersedia', warehouse_id = ?, is_deleted = 0, last_transaction_id = ?, last_transaction_type = 'RET', updated_at = NOW() WHERE serial_number = ? AND product_id = ?");
                        $stmt_reactivate->bind_param("iisi", $warehouse_id, $return_id, $item['serial_number'], $item['product_id']);
                        $stmt_reactivate->execute();
                        $stmt_reactivate->close();
                    }
                    $stmt_chk->close();
                }
                $stmt_sn_upd->close();
            }
        }

        // Jika semua berhasil
        $conn->commit();
        header("Location: index.php?status=success_edit_return");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        die("Gagal memperbarui data: " . $e->getMessage());
    }
} else {
    header("Location: index.php");
    exit();
}