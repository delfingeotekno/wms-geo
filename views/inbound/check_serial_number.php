<?php
include '../../includes/db_connect.php';
header('Content-Type: application/json');

session_start();
$serial = $_GET['serial'] ?? '';
$product_id = $_GET['product_id'] ?? '';

if (empty($serial)) {
    echo json_encode(['exists' => false]);
    exit;
}

// Cek di tabel serial_numbers yang masih aktif (is_deleted=0)
$sql = "SELECT id FROM serial_numbers WHERE serial_number = ? AND product_id = ? AND is_deleted = 0 LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $serial, $product_id);
$stmt->execute();
$result = $stmt->get_result();

echo json_encode(['exists' => $result->num_rows > 0]);