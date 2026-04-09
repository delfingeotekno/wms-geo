<?php
include '../../includes/db_connect.php';

// Endpoint Security
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
// We now filter explicitly by warehouse_id instead of session
$warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;

if ($product_id === 0 || $warehouse_id === 0) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// 1. Check Product is Serialized
$prod_query = $conn->query("SELECT has_serial FROM products WHERE id = $product_id");
if($prod_query->num_rows === 0 || (int)$prod_query->fetch_assoc()['has_serial'] !== 1) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// 2. Safely Fetch Serials
$sql = "SELECT serial_number, status 
        FROM serial_numbers 
        WHERE product_id = ? 
        AND warehouse_id = ? 
        AND (status = 'Tersedia' OR status = 'Tersedia (Bekas)') 
        AND is_deleted = 0 
        ORDER BY serial_number ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $product_id, $warehouse_id);
$stmt->execute();
$result = $stmt->get_result();

$serials = [];
while ($row = $result->fetch_assoc()) {
    $serials[] = $row;
}

// 3. Output as JSON array for AJAX Data consumption
header('Content-Type: application/json');
echo json_encode($serials);
$stmt->close();
?>
