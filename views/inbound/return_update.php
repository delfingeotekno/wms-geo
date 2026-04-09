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
        // Hapus detail lama
        $sql_del = "DELETE FROM inbound_transaction_details WHERE transaction_id = ?";
        $st_del = $conn->prepare($sql_del);
        $st_del->bind_param("i", $return_id);
        $st_del->execute();

        // Masukkan detail baru dan update stok baru (stok ditambah qty return baru)
        foreach ($items as $item) {
            $sql_ins = "INSERT INTO inbound_transaction_details (transaction_id, product_id, quantity, serial_number) 
                        VALUES (?, ?, ?, ?)";
            $st_ins = $conn->prepare($sql_ins);
            $st_ins->bind_param("iids", $return_id, $item['product_id'], $item['quantity'], $item['serial_number']);
            $st_ins->execute();

            $sql_add_stock = "UPDATE products SET stock = stock + ? WHERE id = ?";
            $st_add = $conn->prepare($sql_add_stock);
            $st_add->bind_param("di", $item['quantity'], $item['product_id']);
            $st_add->execute();
        }

        // Jika semua berhasil
        $conn->commit();
        header("Location: views/borrow/index.php?status=success_update");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        die("Gagal memperbarui data: " . $e->getMessage());
    }
} else {
    header("Location: index.php");
    exit();
}