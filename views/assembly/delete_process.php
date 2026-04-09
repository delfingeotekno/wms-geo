<?php
session_start();
include '../../includes/db_connect.php';

// Blokir role production (read-only)
if (($_SESSION['role'] ?? '') === 'production') {
    header("Location: index.php");
    exit();
}

$id = $_GET['id'] ?? 0;

if ($id) {
    $conn->begin_transaction();
    try {
        // 0. Cek Validitas & Ambil Warehouse ID
        $check = $conn->query("SELECT warehouse_id FROM assembly_outbound WHERE id = $id");
        if ($check->num_rows == 0) die("Akses ditolak atau data tidak ditemukan.");
        $wh_row = $check->fetch_assoc();
        $warehouse_id = $wh_row['warehouse_id'];

        // 1. Ambil semua item dari transaksi ini untuk proses RESTOCK
        $items = $conn->query("SELECT product_id, qty_out FROM assembly_outbound_items WHERE outbound_id = $id");
        
        while ($item = $items->fetch_assoc()) {
            $p_id = $item['product_id'];
            $qty = $item['qty_out'];
            
            // Kembalikan stok ke tabel warehouse_stocks
            $conn->query("UPDATE warehouse_stocks SET stock = stock + $qty WHERE product_id = $p_id AND warehouse_id = $warehouse_id");
        }

        // 2. Hapus detail item
        $conn->query("DELETE FROM assembly_outbound_items WHERE outbound_id = $id");

        // 3. Hapus header transaksi
        $conn->query("DELETE FROM assembly_outbound WHERE id = $id AND warehouse_id = $warehouse_id");

        $conn->commit();
        header("Location: index.php?status=deleted");
    } catch (Exception $e) {
        $conn->rollback();
        die("Gagal menghapus: " . $e->getMessage());
    }
}