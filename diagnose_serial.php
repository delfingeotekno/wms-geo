<?php
require_once __DIR__ . '/includes/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$serial = '02324783SKY8828';
$output = [];

// 1. Cek record di tabel serial_numbers (tanpa filter apapun)
$stmt = $conn->prepare("SELECT * FROM serial_numbers WHERE serial_number = ?");
$stmt->bind_param('s', $serial);
$stmt->execute();
$res = $stmt->get_result();
$output['serial_numbers_records'] = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2. Cek produk ID 61
$res2 = $conn->query("SELECT id, product_name, stock FROM products WHERE id = 61");
$output['product_61'] = $res2->fetch_assoc();

// 3. Cek transaksi outbound 090/SJ/GTG-JKT/V/2026
$stmt3 = $conn->prepare("SELECT id, transaction_number, is_deleted FROM outbound_transactions WHERE transaction_number = ?");
$txn = '090/SJ/GTG-JKT/V/2026';
$stmt3->bind_param('s', $txn);
$stmt3->execute();
$res3 = $stmt3->get_result();
$output['outbound_transaction'] = $res3->fetch_assoc();
$stmt3->close();

// 4. Cek juga dengan LIKE untuk variasi format
$stmt4 = $conn->prepare("SELECT id, transaction_number, is_deleted FROM outbound_transactions WHERE transaction_number LIKE ?");
$txn_like = '%090%SJ%GTG%';
$stmt4->bind_param('s', $txn_like);
$stmt4->execute();
$res4 = $stmt4->get_result();
$output['outbound_like_search'] = $res4->fetch_all(MYSQLI_ASSOC);
$stmt4->close();

// 5. Cek struktur tabel outbound_transaction_details
$res5 = $conn->query("SHOW COLUMNS FROM outbound_transaction_details");
$output['otd_columns'] = $res5->fetch_all(MYSQLI_ASSOC);

// 6. Cek warehouse_stocks untuk product 61
$res6 = $conn->query("SELECT * FROM warehouse_stocks WHERE product_id = 61");
$output['warehouse_stocks'] = $res6->fetch_all(MYSQLI_ASSOC);

// 7. Cek sample serial_numbers product_id=61
$res7 = $conn->query("SELECT id, serial_number, product_id, is_deleted, status, warehouse_id, last_transaction_id, last_transaction_type FROM serial_numbers WHERE product_id = 61 LIMIT 5");
$output['sample_serials_product_61'] = $res7->fetch_all(MYSQLI_ASSOC);

// 8. Cek total serial count product 61
$res8 = $conn->query("SELECT COUNT(*) as total, SUM(is_deleted=0) as active, SUM(is_deleted=1) as deleted FROM serial_numbers WHERE product_id = 61");
$output['serial_counts_product_61'] = $res8->fetch_assoc();

// 9. Cek apakah serial ini ada di product lain (LIKE search)
$stmt9 = $conn->prepare("SELECT id, serial_number, product_id, is_deleted, status FROM serial_numbers WHERE serial_number LIKE ?");
$serial_like = '%02324783SKY8828%';
$stmt9->bind_param('s', $serial_like);
$stmt9->execute();
$res9 = $stmt9->get_result();
$output['serial_all_products'] = $res9->fetch_all(MYSQLI_ASSOC);
$stmt9->close();

// 10. Cek apakah serial ini ada dengan exact match (case-sensitive check)
$stmt10 = $conn->prepare("SELECT id, serial_number, product_id, is_deleted, status FROM serial_numbers WHERE BINARY serial_number = ?");
$stmt10->bind_param('s', $serial);
$stmt10->execute();
$res10 = $stmt10->get_result();
$output['serial_exact_binary'] = $res10->fetch_all(MYSQLI_ASSOC);
$stmt10->close();

// 11. Cek struktur tabel serial_numbers
$res11 = $conn->query("SHOW COLUMNS FROM serial_numbers");
$output['sn_columns'] = $res11->fetch_all(MYSQLI_ASSOC);

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$conn->close();
