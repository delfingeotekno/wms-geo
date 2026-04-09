<?php
include '../../includes/db_connect.php';
session_start();

// Blokir role production (read-only)
if (($_SESSION['role'] ?? '') === 'production') {
    header("Location: master_index.php");
    exit();
}
// NO SESSION TENANT

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['assembly_name'];
    $desc = $_POST['description'];
    $finished_product_id = !empty($_POST['finished_product_id']) ? $_POST['finished_product_id'] : null;
    $product_ids = $_POST['product_id'];
    $qtys = $_POST['default_qty'];
    $returnables = $_POST['is_returnable'] ?? []; // Pastikan ini ada

    $conn->begin_transaction();

    try {
        // 1. Simpan ke tabel assemblies
        $stmt = $conn->prepare("INSERT INTO assemblies (assembly_name, description, finished_product_id) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $name, $desc, $finished_product_id);
        $stmt->execute();
        $assembly_id = $conn->insert_id;

        // 2. Simpan detail komponen
        $stmt_detail = $conn->prepare("INSERT INTO assembly_details (assembly_id, product_id, default_quantity, is_returnable) VALUES (?, ?, ?, ?)");
        
        foreach ($product_ids as $index => $p_id) {
            $qty = $qtys[$index];
            $is_ret = isset($returnables[$index]) ? $returnables[$index] : 0;
            $stmt_detail->bind_param("iiii", $assembly_id, $p_id, $qty, $is_ret);
            $stmt_detail->execute();
        }

        $conn->commit();
        header("Location: master_index.php?msg=success");
    } catch (Exception $e) {
        $conn->rollback();
        echo "Gagal menyimpan: " . $e->getMessage();
    }
}