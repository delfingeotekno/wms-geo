<?php
session_start();
include '../../includes/db_connect.php';

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$transfer_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// 1. Get Header (Check status and access)
$sql = "SELECT source_warehouse_id, status FROM transfers WHERE id = $transfer_id";
$res = $conn->query($sql);

if ($res->num_rows === 0) {
    die("Data tidak ditemukan.");
}

$trf = $res->fetch_assoc();
$source_wh = $trf['source_warehouse_id'];

if ($trf['status'] !== 'In Transit') {
    die("Hanya transaksi 'In Transit' yang dapat dihapus/dibatalkan.");
}

// 2. Begin Transaction
$conn->begin_transaction();

try {
    // 3. Get Items
    $sql_items = "SELECT id, product_id, quantity FROM transfer_items WHERE transfer_id = $transfer_id";
    $res_items = $conn->query($sql_items);
    
    $stmt_stock = $conn->prepare("UPDATE warehouse_stocks SET stock = stock + ? WHERE product_id = ? AND warehouse_id = ?");
    $stmt_sn_get = $conn->prepare("SELECT serial_number FROM transfer_serials WHERE transfer_item_id = ?");
    $stmt_sn_upd = $conn->prepare("UPDATE serial_numbers SET status = 'Tersedia', updated_at = NOW(), last_transaction_id = NULL WHERE serial_number = ? AND product_id = ? AND warehouse_id = ?");

    while ($item = $res_items->fetch_assoc()) {
        $ti_id = $item['id'];
        $p_id = $item['product_id'];
        $qty = $item['quantity'];

        // Kembalikan stok ke Gudang Asal
        $stmt_stock->bind_param("iii", $qty, $p_id, $source_wh);
        $stmt_stock->execute();

        // Kembalikan status SN ke Tersedia
        $stmt_sn_get->bind_param("i", $ti_id);
        $stmt_sn_get->execute();
        $res_sns = $stmt_sn_get->get_result();
        
        while ($sn_row = $res_sns->fetch_assoc()) {
            $sn = $sn_row['serial_number'];
            $stmt_sn_upd->bind_param("sii", $sn, $p_id, $source_wh);
            $stmt_sn_upd->execute();
        }
    }

    // 4. Delete Data
    // We do it in reverse order of dependencies
    $conn->query("DELETE FROM transfer_serials WHERE transfer_item_id IN (SELECT id FROM transfer_items WHERE transfer_id = $transfer_id)");
    $conn->query("DELETE FROM transfer_items WHERE transfer_id = $transfer_id");
    $conn->query("DELETE FROM transfers WHERE id = $transfer_id");

    $conn->commit();
    header("Location: index.php?status=deleted");

} catch (Exception $e) {
    $conn->rollback();
    echo "Gagal menghapus: " . $e->getMessage();
}
?>
