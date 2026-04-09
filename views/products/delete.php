<?php
session_start();
include '../../includes/db_connect.php';

// Pastikan hanya admin yang bisa menghapus produk
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../../index.php");
    exit();
}

// Pastikan request menggunakan metode POST dan ID produk tersedia
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id'])) {
    $product_id = intval($_POST['id']);

    // Mulai transaksi database (walaupun hanya satu update, tetap baik untuk praktik)
    $conn->begin_transaction();

    try {
        // Lakukan soft delete pada produk
        // Update is_deleted = 1 untuk produk dengan id yang diberikan
        // dan yang belum di-soft delete (is_deleted = 0)
        $stmt = $conn->prepare("UPDATE products SET is_deleted = 1 WHERE id = ? AND is_deleted = 0");
        $stmt->bind_param("i", $product_id);

        if ($stmt->execute()) {
            // Periksa apakah ada baris yang terpengaruh
            if ($stmt->affected_rows === 0) {
                // Jika 0 baris terpengaruh, berarti produk tidak ditemukan atau sudah dihapus
                throw new Exception("Produk tidak ditemukan atau sudah dihapus secara logis.");
            }
            $conn->commit(); // Commit transaksi jika berhasil
            $stmt->close();
            header("Location: index.php?status=success_delete");
            exit();
        } else {
            // Jika eksekusi query gagal
            throw new Exception("Gagal melakukan soft delete pada produk: " . $stmt->error);
        }

    } catch (Exception $e) {
        // Rollback transaksi jika terjadi kesalahan
        $conn->rollback();
        error_log("Error deleting product ID {$product_id}: " . $e->getMessage()); // Log error untuk debugging
        $_SESSION['error_message'] = "Gagal menghapus produk. Terjadi kesalahan: " . $e->getMessage(); // Simpan pesan error detail ke sesi
        header("Location: index.php?status=error_delete");
        exit();
    }
} else {
    // Jika tidak ada ID atau metode request salah, kembali ke halaman utama
    header("Location: index.php");
    exit();
}

$conn->close();
?>