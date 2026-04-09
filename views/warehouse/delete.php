<?php
session_start();
include '../../includes/db_connect.php';

// Proteksi Halaman
if ($_SESSION['role'] != 'admin') {
    header("Location: ../../index.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // 1. Cek apakah gudang ini memilki keterikatan data transaksi
    // Cek Inbound
    $check_inbound = $conn->query("SELECT COUNT(*) as count FROM inbound_transactions WHERE warehouse_id = $id");
    $inbound_count = $check_inbound->fetch_assoc()['count'];

    // Cek Outbound
    $check_outbound = $conn->query("SELECT COUNT(*) as count FROM outbound_transactions WHERE warehouse_id = $id");
    $outbound_count = $check_outbound->fetch_assoc()['count'];

    // Cek Stok Produk
    $check_stock = $conn->query("SELECT COUNT(*) as count FROM warehouse_stocks WHERE warehouse_id = $id AND stock > 0");
    $stock_count = $check_stock->fetch_assoc()['count'];

    if ($inbound_count > 0 || $outbound_count > 0) {
        // PERINGATAN: Tidak boleh dihapus karena ada histori transaksi
        header("Location: index.php?msg=has_history");
        exit();
    }

    if ($stock_count > 0) {
        // PERINGATAN: Masih ada barang mengendap di gudang ini
        header("Location: index.php?msg=has_stock");
        exit();
    }

    // 2. Jika bersih dari transaksi, lakukan HARD DELETE
    $conn->begin_transaction();
    try {
        // Hapus link stok kosong (jika ada records stock 0)
        $conn->query("DELETE FROM warehouse_stocks WHERE warehouse_id = $id");
        
        // Hapus serial numbers (jika ada records kosong/tersedia di gudang ini)
        $conn->query("DELETE FROM serial_numbers WHERE warehouse_id = $id");

        // Hapus Gudang
        $delete_q = $conn->prepare("DELETE FROM warehouses WHERE id = ?");
        $delete_q->bind_param("i", $id);
        $delete_q->execute();

        if ($delete_q->affected_rows > 0) {
            $conn->commit();
            header("Location: index.php?msg=success_delete");
        } else {
            throw new Exception("Gagal menghapus data dari tabel.");
        }
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: index.php?msg=error_delete");
    }
} else {
    header("Location: index.php");
}
exit();
?>
