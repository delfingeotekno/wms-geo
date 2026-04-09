<?php
include '../../includes/db_connect.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $new_serial = isset($_POST['new_serial']) ? trim($_POST['new_serial']) : '';

    if ($id <= 0 || empty($new_serial)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
        exit;
    }

    // Update nomor serial
    $sql = "UPDATE serial_numbers SET serial_number = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $new_serial, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui: ' . $conn->error]);
    }
    $stmt->close();
    $conn->close();
    exit;
}