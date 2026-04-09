<?php
include '../../includes/db_connect.php';

$outbound_id = isset($_GET['outbound_id']) ? (int)$_GET['outbound_id'] : 0;

header('Content-Type: application/json');

if ($outbound_id === 0) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT aor.*, a.assembly_name, a.finished_product_id, p.product_name 
        FROM assembly_outbound_results aor 
        JOIN assemblies a ON aor.assembly_id = a.id 
        LEFT JOIN products p ON a.finished_product_id = p.id
        WHERE aor.outbound_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $outbound_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'id'            => $row['id'],
        'assembly_id'   => $row['assembly_id'],
        'assembly_name' => $row['assembly_name'],
        'product_name'  => $row['product_name'] ?: 'Produk Tidak Terhubung',
        'qty_ordered'   => (int)$row['qty'],
        'qty_received'  => (int)$row['received_qty'],
        'remaining'     => (int)($row['qty'] - $row['received_qty'])
    ];
}

echo json_encode($items);
?>
