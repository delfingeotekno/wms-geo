<?php
// debug_serial.php - Debug endpoint to lookup a serial number in the database
// Place this file in views/inbound/ for temporary debugging.

include '../../includes/db_connect.php';
session_start();

// OPTIONAL: Restrict access to admin users only. Adjust as needed.
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    // If you prefer no restriction, comment out the block above.
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Admins only.']);
    exit;
}

$serial = $_GET['serial'] ?? '';
if (empty($serial)) {
    http_response_code(400);
    echo json_encode(['error' => 'Serial parameter is required.']);
    exit;
}

$sql = "SELECT id, product_id, serial_number, status, is_deleted, last_transaction_id, last_transaction_type, warehouse_id FROM serial_numbers WHERE serial_number = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $serial);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Serial number not found.']);
    exit;
}

$row = $result->fetch_assoc();
header('Content-Type: application/json');
echo json_encode($row);

$stmt->close();
$conn->close();
?>
