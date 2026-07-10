<?php
include '../../includes/db_connect.php';

$outbound_id = isset($_GET['outbound_id']) ? (int)$_GET['outbound_id'] : 0;

header('Content-Type: application/json');

if ($outbound_id === 0) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT aoi.serial_number, aoi.serial_id, p.product_name, sn.status
        FROM assembly_outbound_items aoi
        JOIN products p ON aoi.product_id = p.id
        LEFT JOIN serial_numbers sn ON aoi.serial_id = sn.id
        WHERE aoi.outbound_id = ? 
          AND aoi.is_returnable = 1 
          AND aoi.serial_id IS NOT NULL
          AND (sn.status = 'Keluar' OR aoi.qty_returned < aoi.qty_out)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $outbound_id);
$stmt->execute();
$result = $stmt->get_result();

$serials = [];
while ($row = $result->fetch_assoc()) {
    $serials[] = [
        'serial_id'     => $row['serial_id'],
        'serial_number' => $row['serial_number'],
        'product_name'  => $row['product_name'],
        'status'        => $row['status'] ?: 'Unknown'
    ];
}

echo json_encode($serials);
?>
