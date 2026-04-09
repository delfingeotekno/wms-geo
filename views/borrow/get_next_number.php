<?php
// Matikan tampilan error HTML agar tidak merusak format JSON
error_reporting(0);
ini_set('display_errors', 0);

include '../../includes/db_connect.php';

header('Content-Type: application/json');

// Cek apakah koneksi database berhasil
if (!$conn) {
    echo json_encode(['next_id' => 'ERR', 'error' => 'Koneksi database gagal']);
    exit();
}

$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');
$year = date('Y', strtotime($date));
$month = date('n', strtotime($date));

// Pastikan query menargetkan kolom yang benar sesuai struktur tabel Anda
$sql = "SELECT transaction_number FROM borrowed_transactions 
        WHERE warehouse_id = ? AND YEAR(borrow_date) = ? AND MONTH(borrow_date) = ? 
        ORDER BY id DESC LIMIT 1";

try {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception($conn->error);
    }
    
    $stmt->bind_param("iii", $warehouse_id, $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Ambil angka depan (001)
        $parts = explode('/', $row['transaction_number']);
        $last_num = (int)$parts[0];
        $next_id = sprintf("%03d", $last_num + 1);
    } else {
        $next_id = "001";
    }

    echo json_encode(['next_id' => $next_id]);
} catch (Exception $e) {
    echo json_encode(['next_id' => 'ERR', 'error' => $e->getMessage()]);
}
exit();