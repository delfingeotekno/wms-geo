<?php
session_start();
include 'includes/db_connect.php';

// Pastikan pengguna sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location:  /wms-geo/login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_SESSION['user_id'];
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    $error = false;
    $update_query = "UPDATE users SET name = ?, username = ? WHERE id = ?";
    $params = [$name, $username, $user_id];
    $param_types = "ssi";

    // Validasi input
    if (empty($name) || empty($username)) {
        $_SESSION['error_message'] = "Nama dan Username tidak boleh kosong.";
        $error = true;
    }

    // Cek apakah username sudah digunakan oleh orang lain
    if (!$error) {
        $check_username_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check_username_stmt->bind_param("si", $username, $user_id);
        $check_username_stmt->execute();
        $check_username_result = $check_username_stmt->get_result();
        if ($check_username_result->num_rows > 0) {
            $_SESSION['error_message'] = "Username sudah digunakan. Silakan pilih username lain.";
            $error = true;
        }
        $check_username_stmt->close();
    }

    // Jika ada input password baru, hash dan validasi
    if (!empty($new_password)) {
        if ($new_password !== $confirm_password) {
            $_SESSION['error_message'] = "Konfirmasi kata sandi tidak cocok.";
            $error = true;
        } else {
            // Hash password baru
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_query = "UPDATE users SET name = ?, username = ?, password = ? WHERE id = ?";
            $params = [$name, $username, $hashed_password, $user_id];
            $param_types = "sssi";
        }
    }
    
    if (!$error) {
        // Lakukan update database
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param($param_types, ...$params);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Profil berhasil diperbarui.";
            // Perbarui session name jika berhasil
            $_SESSION['name'] = $name;
        } else {
            $_SESSION['error_message'] = "Gagal memperbarui profil: " . $stmt->error;
        }
        $stmt->close();
    }

    // Arahkan kembali ke halaman profil
    header("Location: /wms-geo/profile.php");
    exit();
} else {
    // Jika bukan metode POST, arahkan kembali ke halaman profil
    header("Location: /wms-geo/profile.php");
    exit();
}
?>