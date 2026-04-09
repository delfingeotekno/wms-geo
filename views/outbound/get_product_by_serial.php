<?php
// get_product_by_serial.php
header('Content-Type: application/json');

// Menggunakan file koneksi yang di-include
include '../../includes/db_connect.php';

// --- Logika Utama ---
if (!isset($_GET['serial']) || empty($_GET['serial'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Serial Number tidak boleh kosong.']);
    // Tutup koneksi
    $conn->close();
    exit;
}

$serialNumber = trim($_GET['serial']);
$resultData = null;

try {
    // Query: Mencari serial number yang statusnya 'Tersedia' atau 'Tersedia (Bekas)'
    // dan mengambil detail produk yang terkait.
    $stmt = $conn->prepare("
        SELECT 
            sn.serial_number, 
            sn.status, 
            p.id AS product_id, 
            p.product_code, 
            p.product_name, 
            p.unit,
            p.stock
        FROM serial_numbers sn
        JOIN products p ON sn.product_id = p.id
        WHERE 
            sn.serial_number = ? AND 
            p.has_serial = 1 AND
            (sn.status = 'Tersedia' OR sn.status = 'Tersedia (Bekas)')
    ");
    
    // Bind parameter
    $stmt->bind_param("s", $serialNumber);
    $stmt->execute();
    
    // Ambil hasilnya
    $result = $stmt->get_result();
    $resultData = $result->fetch_assoc();

    $stmt->close();

    if ($resultData) {
        // SN ditemukan dan tersedia. Kembalikan detail produk dan SN.
        echo json_encode([
            'success' => true, 
            'message' => 'Serial Number ditemukan.', 
            'data' => $resultData
        ]);
    } else {
        // SN tidak ditemukan, sudah terjual, atau produk tidak bertipe serial.
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Serial Number tidak ditemukan atau tidak tersedia untuk transaksi keluar.']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Kesalahan database: ' . $e->getMessage()]);
}

// Tutup koneksi
$conn->close();
?>