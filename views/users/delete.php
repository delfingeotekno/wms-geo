<?php
// Cek jika pengguna sudah login, alihkan jika bukan admin
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Perbaiki path include file
include '../../includes/db_connect.php';

// Periksa apakah pengguna yang login adalah admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: ../../index.php");
    exit();
}

// Ambil ID user dari URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Pastikan ID user valid
if ($user_id > 0) {
    // Siapkan query DELETE with tenant check
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    
    // Periksa apakah statement berhasil disiapkan
    if ($stmt) {
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            // Jika berhasil, alihkan dengan pesan sukses
            header("Location: index.php?status=success_delete");
            exit();
        } else {
            // Jika gagal, alihkan dengan pesan error
            header("Location: index.php?status=error_delete");
            exit();
        }
        $stmt->close();
    } else {
        // Handle error jika prepared statement gagal
        header("Location: index.php?status=error_delete");
        exit();
    }
} else {
    // Jika tidak ada ID user, alihkan kembali ke halaman utama
    header("Location: index.php");
    exit();
}
?>