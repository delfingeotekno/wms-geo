<?php
// FILE: get_asset_sn_list.php

// 1. Sertakan file koneksi. Pastikan file ini hanya berisi logika koneksi
// dan mendefinisikan variabel $conn.
include '../../includes/db_connect.php'; 

// 2. CEK KONEKSI KRITIS: Tangani jika $conn tidak terdefinisi (seperti error sebelumnya)
// ATAU jika koneksi gagal (connect_error)
if (!isset($conn) || $conn->connect_error) {
    // Keluarkan header JSON meskipun ada error agar frontend dapat memproses response
    header('Content-Type: application/json');
    http_response_code(500);
    // Masukkan detail error ke dalam response JSON
    $error_detail = isset($conn) ? $conn->connect_error : "Koneksi tidak terdefinisi (Cek db_connect.php)";
    echo json_encode([
        'error' => 'Gagal memuat data SN. Detail: Database Connection Failed: ' . $error_detail,
        'serial_numbers' => []
    ]);
    exit();
}

// Set header setelah include dan pengecekan koneksi
header('Content-Type: application/json');

session_start();
// no tenant_id
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : ($_SESSION['warehouse_id'] ?? 0);

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

if ($product_id === 0) {
    echo json_encode(['serial_numbers' => [], 'error' => 'Product ID tidak valid.']);
    exit();
}

$stmt = null; 

try {
    // --- QUERY PALING SEDERHANA: Hanya SELECT serial_number dari serial_numbers ---
    // Mengambil SN Tersedia (Bekas) filtered by tenant & warehouse.
    $sql = "
        SELECT 
            serial_number
        FROM 
            serial_numbers
        WHERE 
            product_id = ? AND 
            status = 'Tersedia (Bekas)' AND 
            warehouse_id = ?
        ORDER BY 
            created_at DESC
    ";
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        throw new Exception("Error preparing statement: " . $conn->error);
    }
    
    // Bind product_id, warehouse_id
    $stmt->bind_param("ii", $product_id, $warehouse_id); 
    $stmt->execute();
    $result = $stmt->get_result();
    
    $serial_numbers = [];
    while ($row = $result->fetch_assoc()) {
        $serial_numbers[] = [
            'serial_number' => 'S/N: ' . $row['serial_number'],
        ];
    }

    echo json_encode(['serial_numbers' => $serial_numbers]);

} catch (Exception $e) {
    // Log error lengkap di sisi server
    error_log("Database Error in get_asset_sn_list.php: " . $e->getMessage());
    http_response_code(500);
    // Pastikan output terakhir adalah JSON
    echo json_encode(['error' => 'Gagal memuat data SN. Detail: ' . $e->getMessage(), 'serial_numbers' => []]);
} finally {
    if ($stmt) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}