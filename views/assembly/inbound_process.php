<?php
session_start();
include '../../includes/db_connect.php';
// no tenant_id

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $outbound_id  = $_POST['outbound_id'];
    $result_ids   = $_POST['result_id'] ?? [];
    $assembly_ids = $_POST['assembly_id'] ?? [];
    $qty_receives = $_POST['qty_receive'] ?? [];
    
    $note         = $_POST['note'];
    $warehouse_id = $_POST['warehouse_id'];
    $user_id      = $_SESSION['user_id'];

    $conn->begin_transaction();
    try {
        if (empty($outbound_id)) throw new Exception("ID transaksi tidak ditemukan.");

        // --- 1. Handle Serial Number Returns (Global for this transaction) ---
        $selected_serials = $_POST['returned_serial_id'] ?? [];
        if (!empty($selected_serials)) {
            foreach ($selected_serials as $sid) {
                $sid = (int)$sid;
                // Ambil info produk dari serial ini
                $q_sn_info = $conn->query("SELECT product_id FROM serial_numbers WHERE id = $sid");
                $sn_info = $q_sn_info->fetch_assoc();
                
                if ($sn_info) {
                    $p_id = $sn_info['product_id'];
                    
                    // A. Update status serial ke 'Tersedia'
                    $conn->query("UPDATE serial_numbers SET status = 'Tersedia', warehouse_id = $warehouse_id, last_transaction_id = $outbound_id, last_transaction_type = 'RI', updated_at = NOW() WHERE id = $sid");
                    
                    // B. Update qty_returned di item tracker (khusus baris serial ini)
                    $conn->query("UPDATE assembly_outbound_items SET qty_returned = 1 WHERE outbound_id = $outbound_id AND serial_id = $sid");
                    
                    // C. Tambah Stok Gudang (Quantity)
                    $conn->query("INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES ($p_id, $warehouse_id, 1) ON DUPLICATE KEY UPDATE stock = stock + 1");
                    
                    // D. Log History (Penerimaan Komponen Kembali)
                    $stmt_hist = $conn->prepare("INSERT INTO assembly_inbound_history (outbound_id, product_id, warehouse_id, quantity, type, note, user_id) VALUES (?, ?, ?, ?, 'Returnable Component', ?, ?)");
                    $hist_note = "Return SN: $sid";
                    $qty_one = 1;
                    $stmt_hist->bind_param("iiiisi", $outbound_id, $p_id, $warehouse_id, $qty_one, $hist_note, $user_id);
                    $stmt_hist->execute();
                    $stmt_hist->close();
                }
            }
        }

        $total_batch_received = 0;

        foreach ($result_ids as $key => $res_id) {
            $received_now = (int)$qty_receives[$key];
            if ($received_now <= 0) continue;

            $a_id = (int)$assembly_ids[$key];
            $total_batch_received += $received_now;

            // 2. Update received_qty di rincian results (Skip jika virtual row result_id = 0)
            if ($res_id > 0) {
                $conn->query("UPDATE assembly_outbound_results SET received_qty = received_qty + $received_now WHERE id = $res_id");
            }

            // 3. Ambil Product ID untuk rakitan ini
            if ($a_id > 0) {
                $prod_data = $conn->query("SELECT finished_product_id FROM assemblies WHERE id = $a_id")->fetch_assoc();
                $finished_product_id = $prod_data['finished_product_id'] ?? null;

                if ($finished_product_id) {
                    // 4. Tambah Stok Barang Jadi
                    $stmt_stock = $conn->prepare("INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock = stock + ?");
                    $stmt_stock->bind_param("iiii", $finished_product_id, $warehouse_id, $received_now, $received_now);
                    $stmt_stock->execute();
                    $stmt_stock->close();

                    // 4a. Log History (Penerimaan Hasil Jadi)
                    $stmt_hist = $conn->prepare("INSERT INTO assembly_inbound_history (outbound_id, product_id, warehouse_id, quantity, type, note, user_id) VALUES (?, ?, ?, ?, 'Finished Product', ?, ?)");
                    $stmt_hist->bind_param("iiiisi", $outbound_id, $finished_product_id, $warehouse_id, $received_now, $note, $user_id);
                    $stmt_hist->execute();
                    $stmt_hist->close();
                }

                // 5. Pengembalian Barang Returnable NON-SERIAL khusus untuk template ini
                $sql_master = "SELECT ad.product_id, ad.default_quantity, p.has_serial 
                               FROM assembly_details ad 
                               JOIN products p ON ad.product_id = p.id
                               WHERE ad.assembly_id = $a_id AND ad.is_returnable = 1";
                $res_master = $conn->query($sql_master);
                
                if ($res_master && $res_master->num_rows > 0) {
                    while ($rm = $res_master->fetch_assoc()) {
                        if ($rm['has_serial'] == 1) continue; // Serial sudah ditangani di atas secara manual

                        $p_id = $rm['product_id'];
                        $ret_qty = $rm['default_quantity'] * $received_now;

                        // Update warehouse stock (Quantity only for non-serial)
                        $conn->query("INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES ($p_id, $warehouse_id, $ret_qty) ON DUPLICATE KEY UPDATE stock = stock + $ret_qty");

                        // Log History (Penerimaan Komponen Kembali Non-Serial)
                        $stmt_hist = $conn->prepare("INSERT INTO assembly_inbound_history (outbound_id, product_id, warehouse_id, quantity, type, note, user_id) VALUES (?, ?, ?, ?, 'Returnable Component', ?, ?)");
                        $hist_note = "Return Non-Serial from Template ID: $a_id";
                        $stmt_hist->bind_param("iiiisi", $outbound_id, $p_id, $warehouse_id, $ret_qty, $hist_note, $user_id);
                        $stmt_hist->execute();
                        $stmt_hist->close();

                        // Update item tracker di transaksi aslinya (Untuk total qty returned)
                        $conn->query("UPDATE assembly_outbound_items SET qty_returned = qty_returned + $ret_qty WHERE outbound_id = $outbound_id AND product_id = $p_id AND serial_id IS NULL");
                    }
                }
            }
        }

        if ($total_batch_received > 0) {
            // 5. Update Header Global
            $conn->query("UPDATE assembly_outbound SET total_received = total_received + $total_batch_received WHERE id = $outbound_id");
            
            // 6. Cek apakah sudah selesai total (Gunakan IFNULL agar manual order tetap bisa selesai)
            $check_status = $conn->query("SELECT (IFNULL(SUM(qty), 0) - IFNULL(SUM(received_qty), 0)) as sisa FROM assembly_outbound_results WHERE outbound_id = $outbound_id")->fetch_assoc();
            
            // Ambil data header untuk cek manual
            $header = $conn->query("SELECT total_units, total_received FROM assembly_outbound WHERE id = $outbound_id")->fetch_assoc();
            
            $is_completed = false;
            // Jika ada template, sisa BOM harus 0
            $has_templates = ($conn->query("SELECT COUNT(*) FROM assembly_outbound_results WHERE outbound_id = $outbound_id")->fetch_row()[0] > 0);
            
            if ($has_templates) {
                if ($check_status['sisa'] <= 0) $is_completed = true;
            } else {
                // Jika manual, cek total_received vs total_units
                if ($header['total_received'] >= $header['total_units']) $is_completed = true;
            }

            if ($is_completed) {
                $conn->query("UPDATE assembly_outbound SET status = 'Completed' WHERE id = $outbound_id");
            }
        }

        $conn->commit();
        header("Location: index.php?status=inbound_success");

    } catch (Exception $e) {
        $conn->rollback();
        die("Gagal memproses barang masuk: " . $e->getMessage());
    }
}