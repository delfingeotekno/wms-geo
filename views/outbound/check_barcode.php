<?php
// check_barcode.php
header('Content-Type: application/json');
include __DIR__ . '/../../includes/db_connect.php'; 

$code = trim($_GET['code'] ?? '');

if (!$code) {
    echo json_encode(['success' => false, 'message' => 'Kode tidak terbaca']);
    exit;
}

try {
    // 1. Cek apakah kode ini adalah SERIAL NUMBER
    $sqlSerial = "SELECT s.serial_number, p.id as product_id, p.product_code, p.product_name 
                  FROM serial_numbers s 
                  JOIN products p ON s.product_id = p.id 
                  WHERE s.serial_number = ? AND s.status = 'Tersedia' 
                  LIMIT 1";
    $stmt = $pdo->prepare($sqlSerial);
    $stmt->execute([$code]);
    $serialData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($serialData) {
        echo json_encode([
            'success' => true,
            'data' => [
                'type' => 'serial',
                'product_id' => $serialData['product_id'],
                'product_code' => $serialData['product_code'],
                'product_name' => $serialData['product_name'],
                'serial' => $serialData['serial_number']
            ]
        ]);
        exit;
    }

    // 2. Jika bukan serial, cek apakah ini KODE PRODUK (untuk barang non-serial)
    $sqlProduct = "SELECT id, product_code, product_name, has_serial 
                   FROM products WHERE product_code = ? LIMIT 1";
    $stmt = $pdo->prepare($sqlProduct);
    $stmt->execute([$code]);
    $productData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($productData) {
        // Jika produk ini wajib serial (has_serial = 1) tapi user scan barcode produknya, tolak.
        if ($productData['has_serial'] == 1) {
            echo json_encode([
                'success' => false, 
                'message' => 'Produk ini wajib scan Serial Number (bukan barcode produk).'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'data' => [
                    'type' => 'non-serial',
                    'product_id' => $productData['id'],
                    'product_code' => $productData['product_code'],
                    'product_name' => $productData['product_name'],
                    'serial' => '-'
                ]
            ]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan di database.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}