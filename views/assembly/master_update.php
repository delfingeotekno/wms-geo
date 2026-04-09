<?php
session_start();
include '../../includes/db_connect.php';

// Blokir role production (read-only)
if (($_SESSION['role'] ?? '') === 'production') {
    header("Location: master_index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assembly_id = $_POST['assembly_id'];
    $name = $_POST['assembly_name'];
    $desc = $_POST['description'];
    $finished_product_id = !empty($_POST['finished_product_id']) ? $_POST['finished_product_id'] : null;
    $product_ids = $_POST['product_id'] ?? [];
    $qtys = $_POST['default_qty'] ?? [];

    $conn->begin_transaction();

    try {
        // 1. Update Tabel Utama
        $stmt = $conn->prepare("UPDATE assemblies SET assembly_name = ?, description = ?, finished_product_id = ? WHERE id = ?");
        $stmt->bind_param("ssii", $name, $desc, $finished_product_id, $assembly_id);
        $stmt->execute();

        // 2. Hapus Detail Lama
        $conn->query("DELETE FROM assembly_details WHERE assembly_id = $assembly_id");

        // 3. Masukkan Detail Baru
        $stmt_detail = $conn->prepare("INSERT INTO assembly_details (assembly_id, product_id, default_quantity, is_returnable) VALUES (?, ?, ?, ?)");
        $returnables = $_POST['is_returnable'] ?? [];
        foreach ($product_ids as $index => $p_id) {
            $qty = $qtys[$index];
            $is_ret = isset($returnables[$index]) ? $returnables[$index] : 0;
            $stmt_detail->bind_param("iiii", $assembly_id, $p_id, $qty, $is_ret);
            $stmt_detail->execute();
        }

        $conn->commit();
        header("Location: master_index.php?msg=updated");
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
}