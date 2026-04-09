<?php
// Response JSON
header('Content-Type: application/json');

// Koneksi DB
include '../../includes/db_connect.php';

// Validasi parameter
if (!isset($_GET['txn_number']) || empty($_GET['txn_number'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Nomor transaksi tidak ditemukan.'
    ]);
    exit();
}



$transaction_number = trim($_GET['txn_number']);
$response = [
    'status' => 'error',
    'message' => 'Transaksi tidak ditemukan.',
    'header' => null,
    'details' => []
];

$conn->begin_transaction();

try {
    // --- 1. Ambil Header Transaksi Outbound - filtered by tenant and warehouse
    $header_sql = "
        SELECT 
            ot.id AS transaction_id,
            ot.transaction_number,
            ot.recipient,
            ot.reference,
            ot.notes,
            ot.transaction_date
        FROM outbound_transactions ot
        WHERE ot.transaction_number = ? AND ot.is_deleted = 0
        LIMIT 1
    ";

    $stmt_header = $conn->prepare($header_sql);
    $stmt_header->bind_param("s", $transaction_number);
    $stmt_header->execute();
    $result_header = $stmt_header->get_result();

    if ($result_header->num_rows === 0) {
        throw new Exception("Transaksi keluar dengan nomor {$transaction_number} tidak ditemukan atau sudah dihapus.");
    }

    $header = $result_header->fetch_assoc();
    $stmt_header->close();

    // --- 2. Ambil Detail Item Transaksi Outbound ---
    // Ambil informasi produk, kuantitas, serial number (jika ada)
    $details_sql = "
        SELECT 
            otd.product_id,
            otd.serial_number,
            otd.quantity,
            p.product_code,
            p.product_name AS product_name,
            p.has_serial
        FROM outbound_transaction_details otd
        INNER JOIN products p ON otd.product_id = p.id
        INNER JOIN outbound_transactions ot ON otd.transaction_id = ot.id
        WHERE ot.transaction_number = ?
        ORDER BY p.product_name ASC
    ";

    $stmt_details = $conn->prepare($details_sql);
    $stmt_details->bind_param("s", $transaction_number);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    $details = $result_details->fetch_all(MYSQLI_ASSOC);
    $stmt_details->close();

    if (empty($details)) {
        throw new Exception("Transaksi ditemukan, namun tidak ada detail item yang tercatat.");
    }

    // --- 3. Siapkan Data untuk Response ---
    $response = [
        'status' => 'success',
        'message' => 'Data transaksi berhasil dimuat.',
        'header' => [
            'recipient' => $header['recipient'],
            'reference' => $header['reference'] ?? '',
            'notes' => $header['notes'] ?? '',
            'transaction_date' => $header['transaction_date']
        ],
        'details' => $details
    ];

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();

} finally {
    $conn->close();
}

// Keluarkan response JSON
echo json_encode($response);
exit;