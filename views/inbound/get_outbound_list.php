<?php
include '../../includes/db_connect.php';

$term = isset($_GET['term']) ? $_GET['term'] : '';
$warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;

if (strlen($term) < 2 || $warehouse_id <= 0) {
    echo json_encode([]);
    exit;
}

$search = "%{$term}%";
// Cari berdasarkan nomor transaksi atau nama penerima
$sql = "SELECT transaction_number, recipient FROM outbound_transactions 
        WHERE is_deleted = 0 
        AND warehouse_id = ? 
        AND (transaction_number LIKE ? OR recipient LIKE ?) 
        ORDER BY id DESC LIMIT 20";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $warehouse_id, $search, $search);
$stmt->execute();
$result = $stmt->get_result();

$outbounds = [];
while ($row = $result->fetch_assoc()) {
    $outbounds[] = $row;
}

echo json_encode($outbounds);
$stmt->close();
?>