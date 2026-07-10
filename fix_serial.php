<?php
require_once __DIR__ . '/includes/db_connect.php';
header('Content-Type: text/plain; charset=utf-8');

$serial = '02324783SKY8828';
$product_id = 61;
$transaction_id = 541; // outbound transaction 090/SJ/GTG-JKT/V/2026

// 1. Cek dulu apakah serial sudah ada
$check = $conn->prepare("SELECT id FROM serial_numbers WHERE serial_number = ?");
$check->bind_param('s', $serial);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();

if ($exists) {
    echo "Serial $serial sudah ada di serial_numbers (ID: {$exists['id']}). Tidak perlu insert.\n";
    exit;
}

// 2. Ambil warehouse_id dari outbound transaction
$stmt_wh = $conn->prepare("SELECT warehouse_id FROM outbound_transactions WHERE id = ?");
$stmt_wh->bind_param('i', $transaction_id);
$stmt_wh->execute();
$wh_row = $stmt_wh->get_result()->fetch_assoc();
$warehouse_id = $wh_row ? (int)$wh_row['warehouse_id'] : 1;
$stmt_wh->close();

echo "Warehouse ID dari transaksi: $warehouse_id\n";

// 3. Cek apakah serial ada di outbound_transaction_details
$stmt_otd = $conn->prepare("SELECT id, serial_number, serial_number_id FROM outbound_transaction_details WHERE transaction_id = ? AND serial_number = ?");
$stmt_otd->bind_param('is', $transaction_id, $serial);
$stmt_otd->execute();
$otd_row = $stmt_otd->get_result()->fetch_assoc();
$stmt_otd->close();

if ($otd_row) {
    echo "Serial ditemukan di outbound_transaction_details (ID: {$otd_row['id']})\n";
} else {
    echo "Serial TIDAK ditemukan di outbound_transaction_details untuk transaksi $transaction_id\n";
    // Cek semua detail transaksi 541
    $res_all = $conn->query("SELECT id, product_id, serial_number, serial_number_id, quantity FROM outbound_transaction_details WHERE transaction_id = $transaction_id LIMIT 10");
    echo "Sample detail transaksi $transaction_id:\n";
    while ($r = $res_all->fetch_assoc()) {
        echo "  - OTD ID: {$r['id']}, product_id: {$r['product_id']}, serial: {$r['serial_number']}, sn_id: {$r['serial_number_id']}, qty: {$r['quantity']}\n";
    }
}

// 4. INSERT serial ke tabel serial_numbers
$insert = $conn->prepare("INSERT INTO serial_numbers (product_id, warehouse_id, serial_number, status, last_transaction_id, last_transaction_type, is_deleted) VALUES (?, ?, ?, 'Keluar', ?, 'OUT', 0)");
$insert->bind_param('iisi', $product_id, $warehouse_id, $serial, $transaction_id);

if ($insert->execute()) {
    $new_id = $conn->insert_id;
    echo "\n✅ SUCCESS: Serial $serial berhasil di-INSERT ke serial_numbers!\n";
    echo "   ID baru: $new_id\n";
    echo "   Product ID: $product_id\n";
    echo "   Warehouse ID: $warehouse_id\n";
    echo "   Status: Keluar\n";
    echo "   Last Transaction: OUT #$transaction_id\n";
    
    // 5. Update outbound_transaction_details jika serial_number_id kosong
    if ($otd_row && empty($otd_row['serial_number_id'])) {
        $upd = $conn->prepare("UPDATE outbound_transaction_details SET serial_number_id = ? WHERE id = ?");
        $upd->bind_param('ii', $new_id, $otd_row['id']);
        $upd->execute();
        echo "   Updated OTD serial_number_id -> $new_id\n";
        $upd->close();
    }
} else {
    echo "\n❌ ERROR: Gagal insert serial: " . $insert->error . "\n";
}

$insert->close();
$conn->close();
