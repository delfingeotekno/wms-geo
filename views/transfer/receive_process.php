<?php
session_start();
include '../../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['transfer_id'])) {
    header("Location: index.php");
    exit;
}

$transfer_id = (int)$_POST['transfer_id'];
$user_id = $_SESSION['user_id'];

// Get Transfer Data
$sql = "SELECT source_warehouse_id, destination_warehouse_id, status FROM transfers WHERE id = $transfer_id";
$res = $conn->query($sql);

if ($res->num_rows === 0) {
    header("Location: index.php?status=error");
    exit;
}

$trf = $res->fetch_assoc();
$dest_wh = $trf['destination_warehouse_id'];

// Validasi status
if ($trf['status'] !== 'In Transit') {
    die("Transfer sudah diproses atau dibatalkan.");
}

// Bisakah user menerima ini?
if ($_SESSION['warehouse_id'] != 0 && $_SESSION['warehouse_id'] != $dest_wh) {
    if ($_SESSION['role'] !== 'admin') {
        die("Anda tidak memiliki akses ke gudang tujuan ini.");
    }
}

$conn->begin_transaction();

try {
    // 1. Update Header
    $stmt_upd = $conn->prepare("UPDATE transfers SET status = 'Completed', received_by = ?, received_at = NOW() WHERE id = ?");
    $stmt_upd->bind_param("ii", $user_id, $transfer_id);
    $stmt_upd->execute();

    // 2. Loop Items
    $sql_items = "SELECT id, product_id, quantity FROM transfer_items WHERE transfer_id = $transfer_id";
    $res_items = $conn->query($sql_items);
    
    $stmt_stock = $conn->prepare("INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock = stock + ?");
    $stmt_sn_get = $conn->prepare("SELECT serial_number FROM transfer_serials WHERE transfer_item_id = ?");
    $stmt_sn_upd = $conn->prepare("UPDATE serial_numbers SET status = 'Tersedia', warehouse_id = ?, updated_at = NOW() WHERE serial_number = ? AND product_id = ?");

    while ($item = $res_items->fetch_assoc()) {
        $ti_id = $item['id'];
        $p_id = $item['product_id'];
        $qty = $item['quantity'];

        // Tambah stok di Gudang Tujuan
        $stmt_stock->bind_param("iiii", $p_id, $dest_wh, $qty, $qty);
        $stmt_stock->execute();

        // Update SN jika ada
        $stmt_sn_get->bind_param("i", $ti_id);
        $stmt_sn_get->execute();
        $res_sns = $stmt_sn_get->get_result();
        
        while ($sn_row = $res_sns->fetch_assoc()) {
            $sn = $sn_row['serial_number'];
            $stmt_sn_upd->bind_param("isi", $dest_wh, $sn, $p_id);
            $stmt_sn_upd->execute();
        }
    }

    $conn->commit();
    header("Location: detail.php?id=$transfer_id&status=received");

} catch (Exception $e) {
    $conn->rollback();
    header("Location: detail.php?id=$transfer_id&status=error");
}
?>
