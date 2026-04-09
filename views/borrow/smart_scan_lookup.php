<?php
// FILE: views/borrow/smart_scan_lookup.php
include '../../includes/db_connect.php';

header('Content-Type: application/json');

$barcode = trim($_GET['barcode'] ?? '');
$warehouse_id = intval($_GET['warehouse_id'] ?? 0);

if (empty($barcode) || $warehouse_id <= 0) {
    echo json_encode(['found' => false, 'error' => 'Barcode atau Gudang tidak valid.']);
    exit();
}

$response = [
    'found' => false,
    'match_type' => '', // 'serial_number' | 'product_code'
    'product' => null,
    'serial_number' => ''
];

try {
    // 1. Cek Apakah ini Serial Number yang tersedia di Gudang terpilih
    $sql_sn = "SELECT sn.serial_number, sn.product_id, p.product_name, p.product_code, p.has_serial 
               FROM serial_numbers sn
               JOIN products p ON sn.product_id = p.id
               WHERE sn.serial_number = ? 
               AND sn.warehouse_id = ? 
               AND (sn.status = 'Tersedia' OR sn.status = 'Tersedia (Bekas)') 
               AND sn.is_deleted = 0 LIMIT 1";
               
    $stmt_sn = $conn->prepare($sql_sn);
    $stmt_sn->bind_param("si", $barcode, $warehouse_id);
    $stmt_sn->execute();
    $res_sn = $stmt_sn->get_result();

    if ($res_sn->num_rows > 0) {
        $row = $res_sn->fetch_assoc();
        
        $response['found'] = true;
        $response['match_type'] = 'serial_number';
        $response['serial_number'] = $row['serial_number'];
        $response['product'] = [
            'id' => intval($row['product_id']),
            'product_code' => $row['product_code'],
            'product_name' => $row['product_name'],
            'has_serial' => 1,
            'stock' => 1 // Satu SN adalah 1 stok
        ];
        echo json_encode($response);
        exit();
    }

    // 2. Jika bukan serial, Cek Apakah ini Kode Produk (Product Code)
    $sql_prod = "SELECT p.id, p.product_code, p.product_name, p.has_serial, IFNULL(ps.stock, 0) as stock
                 FROM products p
                 LEFT JOIN warehouse_stocks ps ON p.id = ps.product_id AND ps.warehouse_id = ?
                 WHERE p.product_code = ? AND p.is_deleted = 0 LIMIT 1";

    $stmt_prod = $conn->prepare($sql_prod);
    $stmt_prod->bind_param("is", $warehouse_id, $barcode);
    $stmt_prod->execute();
    $res_prod = $stmt_prod->get_result();

    if ($res_prod->num_rows > 0) {
        $row = $res_prod->fetch_assoc();
        
        // Cek apakah stok tersedia
        $stock = intval($row['stock']);
        if ($stock <= 0) {
             echo json_encode(['found' => false, 'error' => 'Produk ditemukan, tetapi stok di gudang terpilih kosong.']);
             exit();
        }

        $response['found'] = true;
        $response['match_type'] = 'product_code';
        $response['product'] = [
            'id' => intval($row['id']),
            'product_code' => $row['product_code'],
            'product_name' => $row['product_name'],
            'has_serial' => intval($row['has_serial']),
            'stock' => $stock
        ];
        echo json_encode($response);
        exit();
    }

    echo json_encode(['found' => false, 'error' => 'Barcode tidak dikenali sebagai Serial Number atau Kode Produk di gudang ini.']);

} catch (Exception $e) {
    echo json_encode(['found' => false, 'error' => 'API Error: ' . $e->getMessage()]);
}
?>
