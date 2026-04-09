<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: /wms-geo/index.php");
    exit();
}

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transaction_id == 0) {
    header("Location: /wms-geo/index.php");
    exit();
}


$conn->begin_transaction();

try {
    // 1. Ambil detail item yang akan dikembalikan stoknya
    $details_query = "SELECT btd.product_id, btd.quantity, btd.serial_number, bt.warehouse_id 
                      FROM borrowed_transactions_detail btd
                      JOIN borrowed_transactions bt ON btd.transaction_id = bt.id
                      WHERE btd.transaction_id = ? AND btd.return_date IS NULL";
    $stmt_details = $conn->prepare($details_query);
    $stmt_details->bind_param("i", $transaction_id);
    $stmt_details->execute();
    $details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_details->close();

    if ($details) {
        foreach ($details as $detail) {
            // Update warehouse_stocks - increment quantity because borrow is being deleted
            $update_product_sql = "UPDATE warehouse_stocks SET stock = stock + ? WHERE product_id = ? AND warehouse_id = ?";
            $stmt_product = $conn->prepare($update_product_sql);
            $stmt_product->bind_param("iii", $detail['quantity'], $detail['product_id'], $detail['warehouse_id']);
            if (!$stmt_product->execute()) {
                throw new Exception("Gagal mengembalikan stok produk.");
            }
            $stmt_product->close();

            // Reset serial number status if applicable
            if (!empty($detail['serial_number'])) {
                $reset_sn_sql = "UPDATE serial_numbers SET status = 'Tersedia', last_transaction_id = NULL, last_transaction_type = NULL 
                                 WHERE product_id = ? AND serial_number = ? AND warehouse_id = ?";
                $stmt_sn = $conn->prepare($reset_sn_sql);
                $stmt_sn->bind_param("isi", $detail['product_id'], $detail['serial_number'], $detail['warehouse_id']);
                $stmt_sn->execute();
                $stmt_sn->close();
            }
        }
    }

    // 2. Mark transaction as deleted
    $delete_transaction_sql = "UPDATE borrowed_transactions SET is_deleted = 1, deleted_at = NOW() WHERE id = ?";
    $stmt_delete = $conn->prepare($delete_transaction_sql);
    $stmt_delete->bind_param("i", $transaction_id);
    if (!$stmt_delete->execute()) {
        throw new Exception("Gagal menghapus transaksi.");
    }
    $stmt_delete->close();
    
    $conn->commit();
    header("Location: /wms-geo/index.php?status=success_delete");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    echo "Error: " . $e->getMessage();
    // Anda bisa mengarahkan pengguna kembali ke halaman index dengan pesan error
}
?>