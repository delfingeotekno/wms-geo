<?php
include '../../includes/db_connect.php';

header('Content-Type: application/json');

session_start();


$response = [
    'results' => []
];

if (isset($_GET['term']) && !empty($_GET['term'])) {
    $term = '%' . $_GET['term'] . '%';

    // Kueri database diubah untuk menyertakan filter (tanpa tenant)
    $sql = "SELECT id, product_code, product_name, has_serial, unit, stock FROM products WHERE is_deleted = 0 AND (product_code LIKE ? OR product_name LIKE ?) ORDER BY product_name LIMIT 10";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $term, $term);
    $stmt->execute();
    $result = $stmt->get_result();

    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => $row['id'],
            'product_code' => $row['product_code'],
            'product_name' => $row['product_name'],
            'has_serial' => (int)$row['has_serial'],
            'unit' => $row['unit'],
            'stock' => (int)$row['stock'] // Tambahkan data stock
        ];
    }
    $response['results'] = $products;
    $stmt->close();
}

echo json_encode($response);
?>