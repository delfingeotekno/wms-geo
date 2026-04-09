<?php
include '../../includes/db_connect.php';
$outbound_id = $_GET['outbound_id'] ?? 0;

$sql = "SELECT aoi.id as item_id, aoi.product_id, p.product_name, p.product_code, aoi.qty_out 
        FROM assembly_outbound_items aoi
        JOIN products p ON aoi.product_id = p.id
        WHERE aoi.outbound_id = ? AND aoi.is_returnable = 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $outbound_id);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while($row = $res->fetch_assoc()) {
    $items[] = $row;
}
echo json_encode($items);
?>
