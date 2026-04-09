<?php
// FILE: search_products_manual.php
include __DIR__ . '/../../includes/db_connect.php'; 
header('Content-Type: application/json');

$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($keyword) < 2) {
    echo json_encode([]);
    exit;
}

$warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$transaction_id = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;

try {
    $stock_sql = "IFNULL(ws.stock, 0)";
    if ($transaction_id > 0) {
        $stock_sql = "(IFNULL(ws.stock, 0) + IFNULL((SELECT SUM(quantity) FROM outbound_transaction_details WHERE transaction_id = $transaction_id AND product_id = p.id), 0))";
    }

    // Cari produk berdasarkan nama atau kode, tetap tampilkan meski stok 0 (LEFT JOIN)
    $query = "SELECT p.id, p.product_code, p.product_name, p.has_serial, $stock_sql AS stock 
              FROM products p
              LEFT JOIN warehouse_stocks ws ON p.id = ws.product_id AND ws.warehouse_id = ?
              WHERE (p.product_name LIKE ? OR p.product_code LIKE ?) AND p.is_deleted = 0
              LIMIT 20";
    
    $stmt = $conn->prepare($query);
    $search = "%$keyword%";
    $stmt->bind_param("iss", $warehouse_id, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    echo json_encode($products);
} catch (Exception $e) {
    echo json_encode([]);
} finally {
    if (isset($conn)) $conn->close();
}