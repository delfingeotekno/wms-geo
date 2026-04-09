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

        $total_batch_received = 0;

        foreach ($result_ids as $key => $res_id) {
            $received_now = (int)$qty_receives[$key];
            if ($received_now <= 0) continue;

            $a_id = (int)$assembly_ids[$key];
            $total_batch_received += $received_now;

            // 1. Update received_qty di rincian results
            $conn->query("UPDATE assembly_outbound_results SET received_qty = received_qty + $received_now WHERE id = $res_id");

            // 2. Ambil Product ID untuk rakitan ini
            $prod_data = $conn->query("SELECT finished_product_id FROM assemblies WHERE id = $a_id")->fetch_assoc();
            $finished_product_id = $prod_data['finished_product_id'];

            if ($finished_product_id) {
                // 3. Tambah Stok Barang Jadi
                $stmt_stock = $conn->prepare("INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock = stock + ?");
                $stmt_stock->bind_param("iiii", $finished_product_id, $warehouse_id, $received_now, $received_now);
                $stmt_stock->execute();
            }

            // 4. Pengembalian Barang Returnable khusus untuk template ini
            $sql_master = "SELECT product_id, default_quantity FROM assembly_details WHERE assembly_id = $a_id AND is_returnable = 1";
            $res_master = $conn->query($sql_master);
            
            if ($res_master && $res_master->num_rows > 0) {
                while ($rm = $res_master->fetch_assoc()) {
                    $p_id = $rm['product_id'];
                    $ret_qty = $rm['default_quantity'] * $received_now;

                    // Update warehouse stock
                    $conn->query("INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES ($p_id, $warehouse_id, $ret_qty) ON DUPLICATE KEY UPDATE stock = stock + $ret_qty");

                    // Update item tracker di transaksi aslinya
                    $conn->query("UPDATE assembly_outbound_items SET qty_returned = qty_returned + $ret_qty WHERE outbound_id = $outbound_id AND product_id = $p_id");
                }
            }
        }

        if ($total_batch_received > 0) {
            // 5. Update Header Global
            $conn->query("UPDATE assembly_outbound SET total_received = total_received + $total_batch_received WHERE id = $outbound_id");
            
            // 6. Cek apakah sudah selesai total
            $check_status = $conn->query("SELECT (SUM(qty) - SUM(received_qty)) as sisa FROM assembly_outbound_results WHERE outbound_id = $outbound_id")->fetch_assoc();
            if ($check_status['sisa'] <= 0) {
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