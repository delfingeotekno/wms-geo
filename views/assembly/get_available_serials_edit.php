<?php
include '../../includes/db_connect.php';

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
$outbound_id = isset($_GET['outbound_id']) ? (int)$_GET['outbound_id'] : 0;

header('Content-Type: application/json');

if ($product_id === 0 || $warehouse_id === 0) {
    echo json_encode([]);
    exit;
}

// 1. Ambil Serial yang sedang diproses transaksi ini (agar tetap muncul sebagai pilihan)
$current_serials = [];
if ($outbound_id > 0) {
    $sql_curr = "SELECT serial_number FROM assembly_outbound_items WHERE outbound_id = ? AND product_id = ?";
    $stmt_curr = $conn->prepare($sql_curr);
    $stmt_curr->bind_param("ii", $outbound_id, $product_id);
    $stmt_curr->execute();
    $res_curr = $stmt_curr->get_result();
    while($row = $res_curr->fetch_assoc()) {
        $current_serials[] = $row['serial_number'];
    }
}

// 2. Ambil Serial yang tersedia di gudang
$sql = "SELECT serial_number 
        FROM serial_numbers 
        WHERE product_id = ? 
        AND warehouse_id = ? 
        AND (status = 'Tersedia' OR status = 'Tersedia (Bekas)') 
        AND is_deleted = 0 
        ORDER BY serial_number ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $product_id, $warehouse_id);
$stmt->execute();
$result = $stmt->get_result();

$available_serials = [];
while ($row = $result->fetch_assoc()) {
    $available_serials[] = $row['serial_number'];
}

// Gabungkan dan Hilangkan Duplikat
$final_list = array_unique(array_merge($current_serials, $available_serials));
sort($final_list);

// Format ke bentuk objects
$output = [];
foreach($final_list as $sn) {
    $output[] = ['serial_number' => $sn];
}

echo json_encode($output);
?>
