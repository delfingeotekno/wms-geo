<?php
include '../../includes/db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['serial_number']) || !isset($_POST['product_id']) || !isset($_POST['transaction_type'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request or missing data.']);
    exit();
}

session_start();
$warehouse_id = $_SESSION['warehouse_id'];

$serial_number = $_POST['serial_number'];
$product_id = (int)$_POST['product_id'];
$transaction_type = $_POST['transaction_type'];

try {
    // Memulai transaksi database
    $conn->begin_transaction();

    if ($transaction_type === 'RETURN') {
        // Logika untuk transaksi RETURN: UPDATE status - strictly by tenant
        $new_status = 'Masuk';
        $sql = "UPDATE serial_numbers SET status = ?, warehouse_id = ? WHERE serial_number = ? AND product_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sisi", $new_status, $warehouse_id, $serial_number, $product_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            // Jika tidak ada baris yang di-update, mungkin serial number tidak ditemukan atau tak punya akses
            throw new Exception("Serial number ${serial_number} not found, already updated, or no access.");
        }

        $stmt->close();

    } elseif ($transaction_type === 'NEW') {
        // Logika untuk transaksi NEW: INSERT data baru
        $new_status = 'Keluar';
        $sql = "INSERT INTO serial_numbers (product_id, serial_number, status, warehouse_id) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issi", $product_id, $serial_number, $new_status, $warehouse_id);
        $stmt->execute();
        $stmt->close();
    } else {
        // Jenis transaksi tidak valid
        throw new Exception("Invalid transaction type.");
    }
    
    // Commit transaksi jika semuanya berhasil
    $conn->commit();
    echo json_encode(['success' => true, 'message' => "Serial number ${serial_number} successfully updated."]);
} catch (Exception $e) {
    // Rollback transaksi jika ada error
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
exit();
?>
