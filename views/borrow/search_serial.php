<?php
session_start();
include '../../includes/db_connect.php';
$product_id    = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$warehouse_id  = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$serial_term   = isset($_GET['term']) ? trim($_GET['term']) : ''; 
$single_serial = isset($_GET['serial']) ? trim($_GET['serial']) : ''; 

$response = ['results' => [], 'product' => null, 'error' => null];

// Validasi Warehouse ID wajib ada
if ($warehouse_id <= 0) {
    $response['error'] = 'Silakan pilih lokasi gudang terlebih dahulu.';
    echo json_encode($response);
    exit();
}

// Skenario 1: Lookup langsung (Barcode Scan tanpa pilih produk dulu)
if (!empty($single_serial) && $product_id === 0) {
    // Tambahkan JOIN ke warehouse_stocks untuk mendapatkan info stok spesifik gudang tersebut
    $sql = "SELECT p.id, p.product_code, p.product_name, p.has_serial, sn.serial_number, ps.stock as stock
            FROM serial_numbers sn
            JOIN products p ON sn.product_id = p.id
            LEFT JOIN warehouse_stocks ps ON p.id = ps.product_id AND ps.warehouse_id = ?
            WHERE sn.serial_number = ? 
            AND sn.warehouse_id = ? 
            AND (sn.status = 'Tersedia' OR sn.status = 'Tersedia (Bekas)') 
            AND sn.is_deleted = 0 LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("isi", $warehouse_id, $single_serial, $warehouse_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $response['product'] = $result->fetch_assoc();
        } else {
            $response['error'] = 'Serial Number tidak ditemukan di lokasi gudang ini.';
        }
        $stmt->close();
    }
} 
// Skenario 2: Saran serial (Autocomplete saat mengetik SN setelah produk dipilih)
else if ($product_id > 0) {
    $sql = "SELECT serial_number, status FROM serial_numbers 
            WHERE product_id = ? 
            AND warehouse_id = ? 
            AND serial_number LIKE ? 
            AND (status = 'Tersedia' OR status = 'Tersedia (Bekas)') 
            AND is_deleted = 0
            ORDER BY serial_number LIMIT 500"; 

    $search_param = '%' . $serial_term . '%';
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("iis", $product_id, $warehouse_id, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $response['results'][] = $row;
        }
        $stmt->close();
    }
} else {
    $response['error'] = 'Parameter pencarian tidak lengkap.';
}

echo json_encode($response);
$conn->close();
?>