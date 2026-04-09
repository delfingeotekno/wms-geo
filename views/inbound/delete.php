<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($transaction_id === 0) {
    header("Location: index.php");
    exit();
}

$conn->begin_transaction();

try {
    // Ambil header transaksi
    $trans_query = $conn->query("SELECT transaction_type, document_number FROM inbound_transactions WHERE id = $transaction_id");
    $transaction = $trans_query->fetch_assoc();

    // Ambil detail transaksi dan informasigudang
    $details_query = "SELECT td.product_id, td.quantity, it.warehouse_id 
                      FROM inbound_transaction_details td
                      JOIN inbound_transactions it ON td.transaction_id = it.id
                      WHERE td.transaction_id = ?";
    $stmt_details = $conn->prepare($details_query);
    $stmt_details->bind_param("i", $transaction_id);
    $stmt_details->execute();
    $details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_details->close();

    if ($details) {
        foreach ($details as $detail) {
            $update_stock_sql = "UPDATE warehouse_stocks SET stock = stock - ? WHERE product_id = ? AND warehouse_id = ?";
            $stmt_stock = $conn->prepare($update_stock_sql);
            $stmt_stock->bind_param("iii", $detail['quantity'], $detail['product_id'], $detail['warehouse_id']);
            if (!$stmt_stock->execute()) {
                throw new Exception("Gagal mengembalikan stok produk di gudang.");
            }
            $stmt_stock->close();

            // Update PO Received Quantity
            if ($transaction && $transaction['transaction_type'] === 'RI' && !empty($transaction['document_number'])) {
                $po_id_query = $conn->query("SELECT id FROM purchase_orders WHERE po_number = '{$transaction['document_number']}'");
                if ($po_id_query && $po_id_row = $po_id_query->fetch_assoc()) {
                    $po_id = $po_id_row['id'];
                    $update_po_item_sql = "UPDATE po_items SET received_quantity = received_quantity - ? WHERE po_id = ? AND product_id = ?";
                    $stmt_po_qty = $conn->prepare($update_po_item_sql);
                    $stmt_po_qty->bind_param("iii", $detail['quantity'], $po_id, $detail['product_id']);
                    $stmt_po_qty->execute();
                    $stmt_po_qty->close();
                }
            }
        }
    }

    $delete_transaction_sql = "UPDATE inbound_transactions SET is_deleted = 1, deleted_at = NOW() WHERE id = ?";
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