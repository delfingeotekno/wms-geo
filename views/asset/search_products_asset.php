<?php
include '../../includes/db_connect.php';
header('Content-Type: application/json');

session_start();
// no tenant_id
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : ($_SESSION['warehouse_id'] ?? 0);

$searchTerm = isset($_GET['term']) ? '%' . $_GET['term'] . '%' : '';

if (empty($searchTerm)) {
    echo json_encode(['results' => []]);
    exit();
}

try {
    // 1. Kueri untuk menghitung stok SN yang berstatus 'Tersedia (Bekas)' (filtered by tenant and warehouse)
    $stock_subquery = "
        SELECT 
            product_id, 
            COUNT(id) AS stock_count 
        FROM 
            serial_numbers 
        WHERE 
            status = 'Tersedia (Bekas)' AND warehouse_id = $warehouse_id
        GROUP BY 
            product_id
    ";

    // 2. Kueri utama: Hanya tampilkan produk yang has_serial = 1 dan cocok dengan kriteria pencarian
    $sql = "
        SELECT 
            p.id, 
            p.product_code, 
            p.product_name, 
            p.has_serial,
            COALESCE(s.stock_count, 0) AS stock  -- Ambil hitungan SN Bekas
        FROM 
            products p
        LEFT JOIN 
            ($stock_subquery) s ON p.id = s.product_id
        WHERE 
            p.has_serial = 1 AND
            (p.product_code LIKE ? OR p.product_name LIKE ?)
        LIMIT 10
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Error preparing statement: " . $conn->error);
    }

    $stmt->bind_param("ss", $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }

    echo json_encode(['results' => $products]);

} catch (Exception $e) {
    // Error handling
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'results' => []]);
}

$stmt->close();
$conn->close();
?>