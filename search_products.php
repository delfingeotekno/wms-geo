<?php
// Sertakan file koneksi database Anda
include '../../includes/db_connect.php'; // Sesuaikan path ini jika search_products.php berada di direktori yang sama

// Set header untuk memberitahu browser bahwa respons adalah JSON
header('Content-Type: application/json');

// Ambil term pencarian dari parameter GET
$term = isset($_GET['term']) ? $_GET['term'] : '';

$products = [
    'results' => [] // Inisialisasi array untuk menyimpan hasil
];

// Hanya proses jika term pencarian memiliki minimal 2 karakter
if (strlen($term) >= 2) {
    // Siapkan term pencarian untuk kueri LIKE
    $search_term = '%' . $term . '%';
    
    // Kueri SQL untuk mencari produk berdasarkan nama atau kode produk
    // Ambil juga 'stock' karena digunakan di tampilan hasil pencarian
    $sql = "SELECT id, product_name, product_code, stock FROM products WHERE is_deleted = 0 AND (product_name LIKE ? OR product_code LIKE ?) LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ss", $search_term, $search_term); // Bind parameter
        $stmt->execute(); // Jalankan kueri
        $result = $stmt->get_result(); // Dapatkan hasil

        // Loop melalui hasil dan tambahkan ke array products
        while ($row = $result->fetch_assoc()) {
            $products['results'][] = [
                'id' => $row['id'],
                'product_name' => htmlspecialchars($row['product_name']),
                'product_code' => htmlspecialchars($row['product_code']),
                'stock' => (int)$row['stock'] // Pastikan stok adalah integer
            ];
        }
        $stmt->close(); // Tutup statement
    }
}

// Kembalikan hasil dalam format JSON
echo json_encode($products);
?>