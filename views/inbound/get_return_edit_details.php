<?php
header('Content-Type: application/json');
include '../../includes/db_connect.php';

$return_id = $_GET['return_id'] ?? 0;
$outbound_number = $_GET['outbound_number'] ?? '';

try {
    $sql = "
        SELECT 
            p.id as product_id,
            p.product_code,
            p.product_name,
            p.has_serial,
            otd.serial_number,
            otd.quantity as max_outbound_qty,
            IFNULL(itd.quantity, 0) as current_return_qty
        FROM outbound_transaction_details otd
        JOIN outbound_transactions ot ON otd.transaction_id = ot.id
        JOIN products p ON otd.product_id = p.id
        -- LEFT JOIN ke detail inbound berdasarkan ID transaksi return yang sedang diedit
        LEFT JOIN inbound_transaction_details itd ON itd.product_id = otd.product_id 
            AND itd.transaction_id = ? 
            AND (itd.serial_number = otd.serial_number OR (itd.serial_number IS NULL AND otd.serial_number IS NULL))
        WHERE ot.transaction_number = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $return_id, $outbound_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $details = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['status' => 'success', 'details' => $details]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}