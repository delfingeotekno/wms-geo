<?php
include '../../includes/db_connect.php';
header('Content-Type: application/json');

session_start();
$user_id = $_SESSION['user_id'] ?? 1;

$warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;

// VALIDASI
if (!isset($_POST['transaction_type'], $_POST['transaction_number'], $_POST['items_json'])) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$transaction_type   = $_POST['transaction_type'];
$transaction_number = $_POST['transaction_number'];
$items              = json_decode($_POST['items_json'], true);

if (!$items) {
    echo json_encode(['success' => false, 'message' => 'Item kosong']);
    exit;
}

$conn->begin_transaction();

try {
    // 1. SIMPAN TRANSAKSI UTAMA
    $stmt = $conn->prepare("
        INSERT INTO inbound_transactions 
        (transaction_number, transaction_type, created_at, created_by, warehouse_id)
        VALUES (?, ?, NOW(), ?, ?)
    ");
    $stmt->bind_param("ssii", $transaction_number, $transaction_type, $user_id, $warehouse_id);
    $stmt->execute();

    $transaction_id = $conn->insert_id;

    // 2. PREPARE STATEMENT
    $stmt_detail = $conn->prepare("
        INSERT INTO inbound_transaction_details 
        (transaction_id, product_id, quantity, serial_number)
        VALUES (?, ?, ?, ?)
    ");

    $stmt_stock = $conn->prepare("
        INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock = stock + ?
    ");

    // PERBAIKAN: Update status menjadi 'Tersedia' untuk Return
    // Kita hapus filter 'is_deleted = 0' dan status sebelumnya agar lebih fleksibel mencari data
    $stmt_update_serial = $conn->prepare("
        UPDATE serial_numbers 
        SET status = 'Tersedia',
            updated_at = NOW(),
            last_transaction_id = ?,
            warehouse_id = ?
        WHERE serial_number = ? 
          AND product_id = ?
    ");

    $stmt_insert_serial = $conn->prepare("
        INSERT INTO serial_numbers
        (product_id, serial_number, status, is_deleted, last_transaction_id, created_at, updated_at, warehouse_id)
        VALUES (?, ?, 'Tersedia', 0, ?, NOW(), NOW(), ?)
    ");

    // 3. LOOP ITEM
    foreach ($items as $item) {
        $product_id = (int)$item['product_id'];
        $qty        = (int)$item['quantity'];
        $serial     = !empty($item['serial_number']) ? trim($item['serial_number']) : null;

        // DETAIL
        $stmt_detail->bind_param("iiis", $transaction_id, $product_id, $qty, $serial);
        $stmt_detail->execute();

        // UPDATE STOCK
        $stmt_stock->bind_param("iiii", $product_id, $warehouse_id, $qty, $qty);
        $stmt_stock->execute();

        // SERIAL LOGIC
        if ($serial) {
            if ($transaction_type === 'RETURN') {
                // Eksekusi Update status menjadi Tersedia, update warehouse info maybe?
                $stmt_update_serial->bind_param("issi", $transaction_id, $warehouse_id, $serial, $product_id);
                $stmt_update_serial->execute();

                // Opsional: Jika Anda ingin sistem error jika SN yang direturn tidak ada di database sama sekali
                if ($stmt_update_serial->affected_rows === 0) {
                     // Jika tidak ada baris yang berubah, bisa jadi karena statusnya memang sudah 'Tersedia' 
                     // atau SN tersebut tidak ditemukan. Kita cek apakah SN ada:
                     $check_sn = $conn->query("SELECT id FROM serial_numbers WHERE serial_number = '$serial' AND product_id = $product_id");
                     if ($check_sn->num_rows === 0) {
                        throw new Exception("Serial $serial untuk produk ini tidak terdaftar di sistem.");
                     }
                }

            } else { // RI (Barang Masuk Baru)
                $stmt_insert_serial->bind_param("isii", $product_id, $serial, $transaction_id, $warehouse_id);
                $stmt_insert_serial->execute();
            }
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Transaksi berhasil disimpan']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}