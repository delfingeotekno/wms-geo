<?php
header('Content-Type: application/json');
session_start();
include '../../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
    exit;
}

$edit_id = isset($data['edit_id']) ? (int)$data['edit_id'] : 0;
$source_wh = (int)$data['source_warehouse_id'];
$dest_wh = (int)$data['dest_warehouse_id'];
$courier = $data['courier_info'];
$notes = $data['notes'];
$items = $data['items'];
$user_id = $_SESSION['user_id'];

if ($source_wh === $dest_wh) {
    echo json_encode(['success' => false, 'message' => 'Gudang asal dan tujuan tidak boleh sama.']);
    exit;
}

$conn->begin_transaction();

try {
    $final_transfer_no = "";

    if ($edit_id > 0) {
        // --- LOGIKA EDIT (REVERSE DULU) ---
        $res_chk = $conn->query("SELECT source_warehouse_id, transfer_no, status FROM transfers WHERE id = $edit_id");
        $old_trf = $res_chk->fetch_assoc();
        if (!$old_trf) throw new Exception("Data transfer tidak ditemukan.");
        if ($old_trf['status'] !== 'In Transit') throw new Exception("Hanya status In Transit yang dapat diubah.");
        
        $old_source_wh = $old_trf['source_warehouse_id'];
        $final_transfer_no = $old_trf['transfer_no'];

        // Reverse Stock & SN
        $res_old_items = $conn->query("SELECT id, product_id, quantity FROM transfer_items WHERE transfer_id = $edit_id");
        while ($oi = $res_old_items->fetch_assoc()) {
            $conn->query("UPDATE warehouse_stocks SET stock = stock + " . $oi['quantity'] . " WHERE product_id = " . $oi['product_id'] . " AND warehouse_id = $old_source_wh");
            $conn->query("UPDATE serial_numbers SET status = 'Tersedia', last_transaction_id = NULL WHERE product_id = " . $oi['product_id'] . " AND warehouse_id = $old_source_wh AND (status = 'In Transit' OR status = 'Di Perjalanan') AND serial_number IN (SELECT serial_number FROM transfer_serials WHERE transfer_item_id = " . $oi['id'] . ")");
        }
        
        // Clean old mappings
        $conn->query("DELETE FROM transfer_serials WHERE transfer_item_id IN (SELECT id FROM transfer_items WHERE transfer_id = $edit_id)");
        $conn->query("DELETE FROM transfer_items WHERE transfer_id = $edit_id");

        // Update Header
        $stmt_upd = $conn->prepare("UPDATE transfers SET source_warehouse_id = ?, destination_warehouse_id = ?, courier_info = ?, notes = ?, updated_at = NOW() WHERE id = ?");
        $stmt_upd->bind_param("iissi", $source_wh, $dest_wh, $courier, $notes, $edit_id);
        $stmt_upd->execute();
        $transfer_id = $edit_id;

    } else {
        // --- LOGIKA CREATE BARU ---
        // 0. Generate Transfer No
        $stmt_wh = $conn->query("SELECT name FROM warehouses WHERE id = $source_wh");
        $wh_name = $stmt_wh->fetch_assoc()['name'];
        $wh_code = "XXX";
        if (strpos(strtolower($wh_name), 'jakarta') !== false) { $wh_code = "JKT"; }
        else if (strpos(strtolower($wh_name), 'batam') !== false) { $wh_code = "BTM"; }
        else { $wh_code = strtoupper(substr($wh_name, 0, 3)); }

        $month_romans = ['01'=>'I','02'=>'II','03'=>'III','04'=>'IV','05'=>'V','06'=>'VI','07'=>'VII','08'=>'VIII','09'=>'IX','10'=>'X','11'=>'XI','12'=>'XII'];
        $rom_month = $month_romans[date('m')];
        $year = date('Y');

        $sql_last_no = "SELECT transfer_no FROM transfers WHERE transfer_no LIKE '%/TAG/GTG-%' AND transfer_no LIKE '%/$year' ORDER BY id DESC LIMIT 1";
        $res_last = $conn->query($sql_last_no);
        $urut = 1;
        if ($res_last && $res_last->num_rows > 0) {
            $last_no = $res_last->fetch_assoc()['transfer_no'];
            $parts = explode('/', $last_no);
            if (isset($parts[0]) && is_numeric($parts[0])) { $urut = (int)$parts[0] + 1; }
        }
        $final_transfer_no = str_pad($urut, 4, '0', STR_PAD_LEFT) . "/TAG/GTG-" . $wh_code . "/" . $rom_month . "/" . $year;

        $stmt_trf = $conn->prepare("INSERT INTO transfers (transfer_no, source_warehouse_id, destination_warehouse_id, status, courier_info, notes, created_by, created_at) VALUES (?, ?, ?, 'In Transit', ?, ?, ?, NOW())");
        $stmt_trf->bind_param("siissi", $final_transfer_no, $source_wh, $dest_wh, $courier, $notes, $user_id);
        $stmt_trf->execute();
        $transfer_id = $conn->insert_id;
    }

    // Statements
    $stmt_str = $conn->prepare("SELECT stock FROM warehouse_stocks WHERE product_id = ? AND warehouse_id = ?");
    $stmt_upd_stk = $conn->prepare("UPDATE warehouse_stocks SET stock = stock - ? WHERE product_id = ? AND warehouse_id = ?");
    $stmt_itm = $conn->prepare("INSERT INTO transfer_items (transfer_id, product_id, quantity) VALUES (?, ?, ?)");
    $stmt_sn_chk = $conn->prepare("SELECT id FROM serial_numbers WHERE serial_number = ? AND product_id = ? AND warehouse_id = ? AND status = 'Tersedia' AND is_deleted = 0");
    $stmt_sn_upd = $conn->prepare("UPDATE serial_numbers SET status = 'In Transit', updated_at = NOW(), last_transaction_id = ? WHERE id = ?");
    $stmt_sn_map = $conn->prepare("INSERT INTO transfer_serials (transfer_item_id, serial_number) VALUES (?, ?)");

    // Apply New Items
    foreach ($items as $item) {
        $p_id = (int)$item['product_id'];
        $qty = (int)$item['quantity'];
        $sns = $item['serials'] ?? [];

        $stmt_str->bind_param("ii", $p_id, $source_wh);
        $stmt_str->execute();
        $res_stk = $stmt_str->get_result();
        if ($res_stk->num_rows === 0) throw new Exception("Produk ID $p_id tidak ada di gudang asal.");
        $row_stk = $res_stk->fetch_assoc();
        if ($row_stk['stock'] < $qty) throw new Exception("Stok produk ID $p_id tidak mencukupi di gudang asal.");

        $stmt_upd_stk->bind_param("iii", $qty, $p_id, $source_wh);
        $stmt_upd_stk->execute();

        $stmt_itm->bind_param("iii", $transfer_id, $p_id, $qty);
        $stmt_itm->execute();
        $item_id = $conn->insert_id;

        foreach ($sns as $sn) {
            $stmt_sn_chk->bind_param("sii", $sn, $p_id, $source_wh);
            $stmt_sn_chk->execute();
            $res_sn = $stmt_sn_chk->get_result();
            if ($res_sn->num_rows === 0) throw new Exception("Serial Number '$sn' tidak tersedia di Gudang Asal.");
            $sn_db_id = $res_sn->fetch_assoc()['id'];

            $stmt_sn_upd->bind_param("ii", $transfer_id, $sn_db_id);
            $stmt_sn_upd->execute();
            $stmt_sn_map->bind_param("is", $item_id, $sn);
            $stmt_sn_map->execute();
        }
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
