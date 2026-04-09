<?php
include '../../includes/db_connect.php';
session_start();
$po_id = $_GET['po_id'] ?? '';
$sql = "SELECT 
            pi.id, 
            pi.po_id,
            pi.product_id,
            (pi.qty - pi.received_quantity) AS qty,
            pi.price,
            pi.subtotal,
            p.product_name, 
            p.product_code, 
            p.has_serial 
        FROM po_items pi 
        JOIN products p ON pi.product_id = p.id 
        JOIN purchase_orders po ON pi.po_id = po.id
        WHERE pi.po_id = ? AND pi.qty > pi.received_quantity";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $po_id);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while($row = $result->fetch_assoc()) { $items[] = $row; }
echo json_encode($items);