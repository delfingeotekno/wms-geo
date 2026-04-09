<?php
include '../../includes/db_connect.php';
header('Content-Type: application/json');

$response = [
    'serials' => []
];

if (!isset($_GET['product_id']) || !is_numeric($_GET['product_id'])) {
    echo json_encode($response);
    exit;
}

$product_id = (int) $_GET['product_id'];

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (string)$_SESSION['warehouse_id'];

if ($active_tab === 'all') {
    $warehouse_filter_sql = "";
    $params = [$product_id];
    $param_types = 'i';
} else {
    $filter_warehouse_id = (int)$active_tab;
    $warehouse_filter_sql = "AND sn.warehouse_id = ?";
    $params = [$product_id, $filter_warehouse_id];
    $param_types = 'ii';
}

// Verify product exists
$check_product = $conn->prepare("SELECT id FROM products WHERE id = ?");
$check_product->bind_param("i", $product_id);
$check_product->execute();
if ($check_product->get_result()->num_rows === 0) {
    echo json_encode($response);
    exit;
}

$sql = "
SELECT
    sn.id, -- TAMBAHKAN INI: Ambil ID primary key dari tabel serial_numbers
    sn.serial_number,
    sn.status,
    sn.last_transaction_id,
    sn.last_transaction_type,
    DATE_FORMAT(sn.updated_at, '%d-%m-%Y %H:%i') AS last_update,
    w.name AS warehouse_name,
    
    ot.transaction_number AS outbound_no,
    bt.transaction_number AS borrow_no,
    it.transaction_number AS inbound_no

FROM serial_numbers sn

LEFT JOIN outbound_transactions ot 
    ON sn.last_transaction_id = ot.id AND sn.last_transaction_type = 'OUT'

LEFT JOIN borrowed_transactions bt 
    ON sn.last_transaction_id = bt.id AND sn.last_transaction_type = 'BORROW'

LEFT JOIN inbound_transactions it 
    ON sn.last_transaction_id = it.id AND (sn.last_transaction_type = 'RI' OR sn.last_transaction_type = 'RET')

LEFT JOIN warehouses w
    ON sn.warehouse_id = w.id

WHERE sn.product_id = ?
  AND sn.is_deleted = 0
  $warehouse_filter_sql

ORDER BY sn.updated_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $transaction_number = null;
    $transaction_type = null;

    switch ($row['last_transaction_type']) {
        case 'OUT':
            $transaction_number = $row['outbound_no'];
            $transaction_type = 'outbound';
            break;
        case 'BORROW':
            $transaction_number = $row['borrow_no'];
            $transaction_type = 'borrow';
            break;
        case 'RI':
        case 'RET':
            $transaction_number = $row['inbound_no'];
            $transaction_type = 'inbound';
            break;
    }

    $response['serials'][] = [
        'id'                 => $row['id'], // TAMBAHKAN INI: Kirim ID ke JavaScript
        'serial_number'      => $row['serial_number'],
        'status'             => $row['status'],
        'created_at'         => $row['last_update'],
        'warehouse_name'     => $row['warehouse_name'],
        'transaction_id'     => $row['last_transaction_id'],
        'transaction_number' => $transaction_number,
        'transaction_type'   => $transaction_type,
        'raw_type'           => $row['last_transaction_type']
    ];
}

$stmt->close();
$conn->close();

echo json_encode($response);