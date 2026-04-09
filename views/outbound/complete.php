<?php
include '../../includes/db_connect.php';
session_start();

// Cek hak akses
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$transaction_id = (int)$_GET['id'];

$new_status = 'Completed'; // Ganti dengan status yang Anda gunakan

// Query untuk update status
$stmt = $conn->prepare("UPDATE outbound_transactions SET status = ?, updated_at = NOW() WHERE id = ?");
$stmt->bind_param("si", $new_status, $transaction_id);

if ($stmt->execute()) {
    // Redirect kembali ke index dengan pesan sukses
    header("Location: index.php?status=success_complete");
} else {
    // Redirect dengan pesan error jika gagal
    header("Location: index.php?status=error_complete"); 
}

$stmt->close();
$conn->close();
?>