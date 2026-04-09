<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../index.php");
    exit();
}

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transaction_id == 0) {
    header("Location: index.php");
    exit();
}


$conn->begin_transaction();

try {
    // Fetch details strictly by warehouse
    $details_query = "SELECT td.product_id, td.quantity, ot.warehouse_id 
                      FROM outbound_transaction_details td
                      JOIN outbound_transactions ot ON td.transaction_id = ot.id
                      WHERE td.transaction_id = ?";
    $stmt_details = $conn->prepare($details_query);
    $stmt_details->bind_param("i", $transaction_id);
    $stmt_details->execute();
    $details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_details->close();

    if ($details) {
        foreach ($details as $detail) {
            // Revert stock in warehouse_stocks
            $update_stock_sql = "UPDATE warehouse_stocks SET stock = stock + ? WHERE product_id = ? AND warehouse_id = ?";
            $stmt_stock = $conn->prepare($update_stock_sql);
            $stmt_stock->bind_param("iii", $detail['quantity'], $detail['product_id'], $detail['warehouse_id']);
            if (!$stmt_stock->execute()) {
                throw new Exception("Gagal mengembalikan stok produk.");
            }
            $stmt_stock->close();
        }
    }

    $delete_transaction_sql = "UPDATE outbound_transactions SET is_deleted = 1, deleted_at = NOW() WHERE id = ?";
    $stmt_delete = $conn->prepare($delete_transaction_sql);
    $stmt_delete->bind_param("i", $transaction_id);
    if (!$stmt_delete->execute()) {
        throw new Exception("Gagal menghapus transaksi.");
    }
    $stmt_delete->close();
    
    $conn->commit();
    header("Location: index.php?status=success_delete");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    echo "Error: " . $e->getMessage();
    // Anda bisa mengarahkan pengguna kembali ke halaman index dengan pesan error
}
?>