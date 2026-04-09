<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan pengguna memiliki role yang diizinkan
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    header("Location: ../../index.php");
    exit();
}

// Ambil ID detail transaksi dan serial number dari URL
$detail_id = isset($_GET['detail_id']) ? intval($_GET['detail_id']) : 0;
$serial_number = isset($_GET['serial_number']) ? $_GET['serial_number'] : null;

if ($detail_id <= 0) {
    header("Location: index.php");
    exit();
}

// Inisialisasi transaction_id untuk fallback error redirection
$transaction_id = 0;
$conn->begin_transaction();

try {
    // 1. Ambil detail item yang akan dikembalikan (gunakan product_id dan transaction_id)
    $sql_get_item = "SELECT btd.product_id, btd.quantity, btd.transaction_id, bt.warehouse_id 
                     FROM borrowed_transactions_detail btd 
                     JOIN borrowed_transactions bt ON btd.transaction_id = bt.id
                     WHERE btd.id = ? AND btd.return_date IS NULL";
    $stmt_get_item = $conn->prepare($sql_get_item);
    $stmt_get_item->bind_param("i", $detail_id);
    $stmt_get_item->execute();
    $result_item = $stmt_get_item->get_result();
    $item = $result_item->fetch_assoc();
    $stmt_get_item->close();

    if (!$item) {
        throw new Exception("Item tidak ditemukan atau sudah dikembalikan.");
    }

    $product_id = $item['product_id'];
    $quantity_returned = $item['quantity'];
    $transaction_id = $item['transaction_id'];
    $warehouse_id = $item['warehouse_id'];

    // 2. Cek apakah produk memiliki serial number
    $sql_check_serial = "SELECT has_serial FROM products WHERE id = ?";
    $stmt_check = $conn->prepare($sql_check_serial);
    $stmt_check->bind_param("i", $product_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $product = $result_check->fetch_assoc();
    $stmt_check->close();

    if (!$product) {
        throw new Exception("Produk tidak ditemukan.");
    }

    // 3. Perbarui stok atau status serial number berdasarkan tipe produk
    if ($product['has_serial'] && !empty($serial_number)) {
        // Jika produk memiliki serial number, perbarui status di tabel serial_numbers
        $sql_update_serial = "UPDATE serial_numbers SET status = 'Tersedia' WHERE serial_number = ? AND product_id = ? AND warehouse_id = ?";
        $stmt_update_serial = $conn->prepare($sql_update_serial);
        $stmt_update_serial->bind_param("sii", $serial_number, $product_id, $warehouse_id);
        if (!$stmt_update_serial->execute()) {
            throw new Exception("Gagal memperbarui status serial number.");
        }
        $stmt_update_serial->close();
    } else {
        // Jika produk tidak memiliki serial number, tambahkan stok kembali di tabel warehouse_stocks
        $sql_update_stock = "INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock = stock + ?";
        $stmt_update_stock = $conn->prepare($sql_update_stock);
        $stmt_update_stock->bind_param("iiii", $product_id, $warehouse_id, $quantity_returned, $quantity_returned);
        if (!$stmt_update_stock->execute()) {
            throw new Exception("Gagal menambah stok produk.");
        }
        $stmt_update_stock->close();
    }

    // 4. Update tanggal pengembalian di borrowed_transactions_detail - strictly by tenant/warehouse via JOIN
    $sql_update_detail = "UPDATE borrowed_transactions_detail btd
                          JOIN borrowed_transactions bt ON btd.transaction_id = bt.id
                          SET btd.return_date = NOW(), btd.updated_at = NOW() 
                          WHERE btd.id = ? AND bt.warehouse_id = ?";
    $stmt_update_detail = $conn->prepare($sql_update_detail);
    $stmt_update_detail->bind_param("ii", $detail_id, $warehouse_id);
    if (!$stmt_update_detail->execute()) {
        throw new Exception("Gagal mengupdate status pengembalian detail.");
    }
    $stmt_update_detail->close();

    // ----------------------------------------------------
    // 5. LOGIKA PEMBARUAN STATUS TRANSAKSI UTAMA
    // ----------------------------------------------------

    // Periksa jumlah item yang BELUM dikembalikan dalam transaksi ini - strictly by tenant and warehouse
    $sql_check_unreturned = "SELECT COUNT(*) AS unreturned_count FROM borrowed_transactions_detail btd
                             JOIN borrowed_transactions bt ON btd.transaction_id = bt.id
                             WHERE btd.transaction_id = ? AND btd.return_date IS NULL AND bt.warehouse_id = ?";
    $stmt_check = $conn->prepare($sql_check_unreturned);
    $stmt_check->bind_param("ii", $transaction_id, $warehouse_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $check_counts = $result_check->fetch_assoc();
    $stmt_check->close();

    $unreturned_count = $check_counts['unreturned_count'];
    $new_status = '';

    if ($unreturned_count == 0) {
        // Semua detail item sudah dikembalikan
        $new_status = 'sudah kembali';
    } else {
        // Masih ada yang belum kembali
        $new_status = 'beberapa kembali';
    }

    if ($new_status) {
        $sql_update_header = "UPDATE borrowed_transactions SET status = ?, updated_at = NOW() WHERE id = ? AND warehouse_id = ?";
        $stmt_update_header = $conn->prepare($sql_update_header);
        $stmt_update_header->bind_param("sii", $new_status, $transaction_id, $warehouse_id);
        if (!$stmt_update_header->execute()) {
            throw new Exception("Gagal mengupdate status transaksi utama.");
        }
        $stmt_update_header->close();
    }

    $conn->commit();

    // Alihkan kembali ke halaman detail transaksi dengan pesan sukses
    header("Location: detail.php?id=" . $transaction_id . "&status=success_return");
    exit();

} catch (Exception $e) {
    $conn->rollback();

    if ($transaction_id > 0) {
        header("Location: detail.php?id=" . $transaction_id . "&status=error&message=" . urlencode($e->getMessage()));
    } else {
        header("Location: index.php?status=error&message=" . urlencode("Gagal memproses pengembalian. " . $e->getMessage()));
    }
    exit();
}
?>
