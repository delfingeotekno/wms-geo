<?php
include __DIR__ . '/../../includes/db_connect.php'; 
header('Content-Type: application/json');

$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
$warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;

if (strlen($keyword) < 2 || $warehouse_id <= 0) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    $query = "SELECT p.id, p.product_code, p.product_name, p.has_serial, ws.stock, p.unit 
              FROM products p
              JOIN warehouse_stocks ws ON p.id = ws.product_id
              WHERE (p.product_name LIKE ? OR p.product_code LIKE ?) AND ws.warehouse_id = ? AND p.is_deleted = 0
              LIMIT 15";
    
    $stmt = $conn->prepare($query);
    $search = "%$keyword%";
    $stmt->bind_param("ssi", $search, $search, $warehouse_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => $row['id'],
            'text' => $row['product_code'] . ' - ' . $row['product_name'] . ' (Stok: ' . $row['stock'] . ')',
            'product_name' => $row['product_name'],
            'stock' => $row['stock'],
            'unit' => $row['unit'],
            'has_serial' => $row['has_serial']
        ];
    }
    
    echo json_encode(['results' => $products]);
} catch (Exception $e) {
    echo json_encode(['results' => []]);
} finally {
    if (isset($conn)) $conn->close();
}
