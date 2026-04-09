<?php
include '../../includes/db_connect.php';

header('Content-Type: application/json');

$response = [
    'results' => []
];

// Menangkap input pencarian dan warehouse_id
$term = isset($_GET['q']) ? $_GET['q'] : (isset($_GET['term']) ? $_GET['term'] : '');
$warehouse_id = intval($_GET['warehouse_id'] ?? 0);

// NO SESSION TENANT

if (!empty($term) && $warehouse_id > 0) {
    $search_term = '%' . $term . '%';

    /**
     * Mengambil data produk dasar dan stok gudang.
     * Menggunakan tabel warehouse_stocks dan filter tenant_id.
     */
    $sql = "SELECT p.id, p.product_code, p.product_name, p.has_serial, p.unit, 
                IFNULL(ps.stock, 0) as non_serial_stock
            FROM products p
            LEFT JOIN warehouse_stocks ps ON p.id = ps.product_id AND ps.warehouse_id = ?
            WHERE p.is_deleted = 0
            AND (p.product_code LIKE ? OR p.product_name LIKE ?) 
            ORDER BY p.product_name 
            LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $warehouse_id, $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();

    $products = [];
    
    // Prepare query stok serial di awal (reusable di dalam loop) - add tenant_id
    $serial_stock_query = $conn->prepare("SELECT COUNT(*) FROM serial_numbers 
                                         WHERE product_id = ? 
                                         AND warehouse_id = ? 
                                         AND (status = 'Tersedia' OR status = 'Tersedia (Bekas)') 
                                         AND is_deleted = 0");

    while ($row = $result->fetch_assoc()) {
        $has_serial = (int)$row['has_serial'];
        
        if ($has_serial === 1) {
            // Hitung SN yang tersedia HANYA di gudang ini
            $serial_stock_query->bind_param("ii", $row['id'], $warehouse_id);
            $serial_stock_query->execute();
            $serial_res = $serial_stock_query->get_result();
            $actual_stock = (int)$serial_res->fetch_row()[0];
            $serial_res->free();
        } else {
            // Gunakan stok dari tabel warehouse_stocks
            $actual_stock = (int)$row['non_serial_stock'];
        }

        // Menampilkan semua produk walau stok 0 kesepakatan user
        $products[] = [
            'id' => (int)$row['id'],
            'product_code' => $row['product_code'],
            'product_name' => $row['product_name'],
            'has_serial' => $has_serial,
            'unit' => $row['unit'],
            'stock' => $actual_stock 
        ];
    }
    
    $serial_stock_query->close();
    $response['results'] = $products;
    $stmt->close();
}

echo json_encode($response);
$conn->close();
exit();