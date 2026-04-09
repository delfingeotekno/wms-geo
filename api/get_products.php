<?php
/**
 * API: Get Product Inventory Data
 * Description: Memungkinkan sistem eksternal mengambil data stok produk secara real-time.
 * Security: Memeriksa header X-API-KEY.
 * CORS: Diaktifkan agar dapat diakses dari domain berbeda.
 */

// 1. Pengaturan Header untuk API & CORS
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, X-API-KEY");

// 2. Sertakan Koneksi Database (Relative Path dari folder /api)
require_once '../includes/db_connect.php';

// 3. Konfigurasi API Key (Daftar Kunci yang Diizinkan)
$valid_api_key = "WMS-GEO-SECRET-2026";

// 4. Periksa API Key di Header
$headers = getallheaders();
$api_key = isset($headers['X-API-KEY']) ? $headers['X-API-KEY'] : null;

// Jika API Key tidak ada atau salah
if ($api_key !== $valid_api_key) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized access. Invalid or missing API KEY."
    ]);
    exit();
}

// 5. Query Data Produk Beserta Stok per Gudang
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where_clause = "WHERE p.is_deleted = 0";

if ($search !== '') {
    $where_clause .= " AND (p.product_name LIKE '%$search%' OR p.product_code LIKE '%$search%')";
}

$query = "
    SELECT 
        p.id as product_id,
        p.product_code, 
        p.product_name, 
        b.brand_name,
        w.name as warehouse_name,
        COALESCE(ps.stock, 0) as stock
    FROM products p
    JOIN brands b ON p.brand_id = b.id
    LEFT JOIN warehouse_stocks ps ON p.id = ps.product_id
    LEFT JOIN warehouses w ON ps.warehouse_id = w.id
    $where_clause
    ORDER BY p.id ASC
";

$result = $conn->query($query);
$products_data = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pid = $row['product_id'];
        
        // Cek apakah produk ini sudah masuk ke array
        if (!isset($products_data[$pid])) {
            $products_data[$pid] = [
                "product_code" => $row['product_code'],
                "product_name" => $row['product_name'],
                "brand_name" => $row['brand_name'],
                "total_stock" => 0,
                "warehouse_breakdown" => []
            ];
        }
        
        // Tambahkan stok per gudang jika ada
        if ($row['warehouse_name']) {
            $products_data[$pid]["warehouse_breakdown"][] = [
                "warehouse_name" => $row['warehouse_name'],
                "stock" => (int)$row['stock']
            ];
            $products_data[$pid]["total_stock"] += (int)$row['stock'];
        }
    }
}

// 6. Format ke Array Indeks & Kirim Respon
$final_output = array_values($products_data);
http_response_code(200);
echo json_encode($final_output, JSON_PRETTY_PRINT);

$conn->close();
?>
