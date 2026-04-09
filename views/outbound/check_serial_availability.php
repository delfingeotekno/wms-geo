<?php
// FILE: check_serial_availability.php
include __DIR__ . '/../../includes/db_connect.php'; 
header('Content-Type: application/json');

$response = [
    'success' => false, // Menggunakan key 'success' agar sinkron dengan JS sebelumnya
    'message' => 'Data tidak ditemukan',
    'data' => null
];

$warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
// Kita gunakan satu parameter 'code' agar bisa menerima scan apa saja (SN atau Kode Produk)
$code = isset($_GET['code']) ? trim($_GET['code']) : null;

if ($code) {
    try {
        if ($conn->connect_error) {
            throw new Exception("Database Connection Failed");
        }

        // TAHAP 1: Cek apakah 'code' adalah SERIAL NUMBER - strictly by tenant and warehouse
        $querySerial = "
            SELECT s.serial_number, s.status, p.id as product_id, p.product_code, p.product_name, p.has_serial
            FROM serial_numbers s
            JOIN products p ON s.product_id = p.id
            WHERE s.serial_number = ? AND s.status IN ('Tersedia', 'Tersedia (Bekas)') AND s.warehouse_id = ?
            LIMIT 1
        ";
        
        $stmt = $conn->prepare($querySerial);
        $stmt->bind_param("si", $code, $warehouse_id);
        $stmt->execute();
        $resSerial = $stmt->get_result()->fetch_assoc();

        if ($resSerial) {
            $response['success'] = true;
            $response['message'] = 'Serial number ditemukan.';
            $response['data'] = [
                'type' => 'serial',
                'product_id' => $resSerial['product_id'],
                'product_code' => $resSerial['product_code'],
                'product_name' => $resSerial['product_name'],
                'serial' => $resSerial['serial_number'],
                'status' => $resSerial['status']
            ];
        } else {
            // TAHAP 2: Jika bukan SN, cek apakah ini KODE PRODUK (untuk non-serial) - strictly by tenant
            $queryProd = "
                SELECT p.id, p.product_code, p.product_name, p.has_serial, ws.stock 
                FROM products p
                JOIN warehouse_stocks ws ON p.id = ws.product_id
                WHERE p.product_code = ? AND ws.warehouse_id = ?
                LIMIT 1
            ";
            $stmtProd = $conn->prepare($queryProd);
            $stmtProd->bind_param("si", $code, $warehouse_id);
            $stmtProd->execute();
            $resProd = $stmtProd->get_result()->fetch_assoc();

            if ($resProd) {
                // Jika produk ini HARUS pakai serial tapi user cuma scan barcode produk
                if ($resProd['has_serial'] == 1) {
                    $response['message'] = "Produk " . $resProd['product_name'] . " wajib menggunakan scan Serial Number!";
                } else if ($resProd['stock'] <= 0) {
                    $response['message'] = "Stok produk non-serial habis.";
                } else {
                    $response['success'] = true;
                    $response['message'] = 'Produk non-serial ditemukan.';
                    $response['data'] = [
                        'type' => 'non-serial',
                        'product_id' => $resProd['id'],
                        'product_code' => $resProd['product_code'],
                        'product_name' => $resProd['product_name'],
                        'serial' => '-',
                        'status' => 'Tersedia'
                    ];
                }
            } else {
                $response['message'] = "Kode atau Serial Number tidak terdaftar atau tidak tersedia.";
            }
        }

    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
    } finally {
        if (isset($stmt)) $stmt->close();
        if (isset($conn)) $conn->close();
    }
}

echo json_encode($response);