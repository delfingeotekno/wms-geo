<?php
include '../../includes/db_connect.php';
$id = $_GET['id'] ?? 0;
$wh_id = $_GET['warehouse_id'] ?? 0;
$sql = "SELECT ad.*, p.product_name, p.has_serial,
        IFNULL((SELECT stock FROM warehouse_stocks WHERE product_id = p.id AND warehouse_id = ?), 0) as stock 
        FROM assembly_details ad 
        JOIN products p ON ad.product_id = p.id WHERE ad.assembly_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $wh_id, $id);
$stmt->execute();
echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));