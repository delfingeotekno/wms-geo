<?php
// search_serials.php
header('Content-Type: application/json');

// Menggunakan file koneksi yang di-include
include '../../includes/db_connect.php';

// --- Logika Utama ---
if (!isset($_GET['product_id']) || empty($_GET['product_id']) || !isset($_GET['term'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Product ID atau Term pencarian tidak valid.', 'serials' => []]);
    // Tutup koneksi
    $conn->close();
    exit;
}

$productId = $_GET['product_id'];
$searchTerm = '%' . trim($_GET['term']) . '%';
$serials = [];

try {
    // Query: Mencari serial number yang tersedia untuk product_id tertentu
    $stmt = $conn->prepare("
        SELECT 
            serial_number, 
            status 
        FROM serial_numbers
        WHERE 
            product_id = ? AND 
            serial_number LIKE ? AND
            (status = 'Tersedia' OR status = 'Tersedia (Bekas)')
        LIMIT 10
    ");
    
    // Bind parameter (s: string, i: integer)
    $stmt->bind_param("is", $productId, $searchTerm);
    $stmt->execute();
    
    // Ambil hasilnya
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $serials[] = $row;
    }

    $stmt->close();

    echo json_encode(['success' => true, 'serials' => $serials]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Kesalahan database: ' . $e->getMessage(), 'serials' => []]);
}

// Tutup koneksi
$conn->close();
?>