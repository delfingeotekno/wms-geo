<?php
include '../../includes/db_connect.php';

header('Content-Type: application/json');

// Gunakan kunci 'error' agar sinkron dengan JavaScript: check.error
$response = ['is_available' => false, 'message' => '', 'error' => '', 'stock' => 0];

// Ambil parameter
$product_id     = intval($_GET['product_id'] ?? 0);
$serial_number  = trim($_GET['serial_number'] ?? '');
$warehouse_id   = intval($_GET['warehouse_id'] ?? 0); 
$transaction_id = intval($_GET['transaction_id'] ?? 0);

// Validasi Input Dasar
if ($product_id <= 0 || $warehouse_id <= 0) {
    $response['error'] = 'Gudang atau produk belum dipilih.';
    echo json_encode($response);
    exit();
}

// 1. CEK IDENTITAS PRODUK
$sql_check_type = "SELECT has_serial, product_name FROM products WHERE id = ? AND is_deleted = 0 LIMIT 1";
$stmt_type = $conn->prepare($sql_check_type);
$stmt_type->bind_param("i", $product_id);
$stmt_type->execute();
$product_info = $stmt_type->get_result()->fetch_assoc();

if (!$product_info) {
    $response['error'] = 'Produk tidak ditemukan, sudah dihapus, atau tidak punya akses.';
    echo json_encode($response);
    exit();
}

$has_serial = intval($product_info['has_serial']);

// --- LOGIKA UNTUK BARANG BERSERIAL ---
if ($has_serial === 1) {
    if (empty($serial_number)) {
        $response['error'] = 'Produk serial wajib mengisi Serial Number.';
        echo json_encode($response);
        exit();
    }

    // Cek detail SN di sistem
    $sql_sn = "SELECT status, warehouse_id FROM serial_numbers 
               WHERE product_id = ? AND serial_number = ? AND is_deleted = 0 LIMIT 1";
    $stmt_sn = $conn->prepare($sql_sn);
    $stmt_sn->bind_param("is", $product_id, $serial_number);
    $stmt_sn->execute();
    $res_sn = $stmt_sn->get_result();

    if ($res_sn->num_rows === 0) {
        $response['error'] = "Serial Number tidak terdaftar untuk produk ini.";
    } else {
        $row_sn = $res_sn->fetch_assoc();
        $sn_warehouse = intval($row_sn['warehouse_id']);
        $sn_status = $row_sn['status'];
        
        // 1. Cek Lokasi Gudang
        if ($sn_warehouse !== $warehouse_id) {
            $response['error'] = "Unit berada di gudang lain (Lokasi ID: $sn_warehouse).";
        } 
        // 2. Cek Status Ketersediaan
        elseif ($sn_status === 'Tersedia' || $sn_status === 'Tersedia (Bekas)') {
            $response['is_available'] = true;
            $response['message'] = 'Serial number siap dipinjam.';
        }
        else {
            // 3. Logika Khusus EDIT: Jika SN ini milik transaksi yang sedang diedit
            if ($transaction_id > 0) {
                $sql_edit = "SELECT 1 FROM borrowed_transactions_detail 
                             WHERE transaction_id = ? AND product_id = ? AND serial_number = ?";
                $stmt_edit = $conn->prepare($sql_edit);
                $stmt_edit->bind_param("iis", $transaction_id, $product_id, $serial_number);
                $stmt_edit->execute();
                
                if ($stmt_edit->get_result()->num_rows > 0) {
                    $response['is_available'] = true;
                    $response['message'] = 'Unit ini bagian dari transaksi ini.';
                } else {
                    $response['error'] = "SN sedang dalam status: $sn_status";
                }
                $stmt_edit->close();
            } else {
                $response['error'] = "Serial Number sudah dipinjam/keluar ($sn_status).";
            }
        }
    }
    $stmt_sn->close();
} 
// --- LOGIKA UNTUK BARANG NON-SERIAL ---
else {
    $sql_stock = "SELECT stock as stock FROM warehouse_stocks WHERE product_id = ? AND warehouse_id = ? LIMIT 1";
    $stmt_stock = $conn->prepare($sql_stock);
    $stmt_stock->bind_param("ii", $product_id, $warehouse_id);
    $stmt_stock->execute();
    $res_stock = $stmt_stock->get_result();

    if ($res_stock->num_rows > 0) {
        $row_stock = $res_stock->fetch_assoc();
        $current_stock = intval($row_stock['stock']);
        
        if ($current_stock > 0) {
            $response['is_available'] = true;
            $response['stock'] = $current_stock;
            $response['message'] = "Stok tersedia: $current_stock";
        } else {
            $response['error'] = "Stok di gudang ini kosong.";
        }
    } else {
        $response['error'] = "Produk tidak tersedia di gudang terpilih.";
    }
    $stmt_stock->close();
}

// Kembalikan Response
echo json_encode($response);
$conn->close();
exit();