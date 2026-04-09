<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan pengguna sudah login dan memiliki peran 'admin'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../../index.php"); // Arahkan ke halaman utama jika tidak berwenang
    exit();
}

$message = '';
$message_type = '';

// Inisialisasi variabel untuk mempertahankan nilai input setelah POST gagal
$name = $_POST['name'] ?? '';
$username = $_POST['username'] ?? '';
$role = $_POST['role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']); // Kata sandi yang diinput pengguna
    $role = $_POST['role'];

    // Validasi field yang diperlukan
    if (empty($username) || empty($name) || empty($password) || empty($role)) {
        $message = 'Semua field wajib diisi.';
        $message_type = 'danger';
    } else {
        // Enkripsi password sebelum menyimpannya ke database
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (username, name, password, role) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            // Perbarui bind_param
            $stmt->bind_param("ssss", $username, $name, $hashed_password, $role);
            if ($stmt->execute()) {
                header("Location: index.php?status=success_add");
                exit();
            } else {
                // Tangani error jika username sudah ada atau masalah database lainnya
                if ($conn->errno == 1062) { // Kode error MySQL untuk duplicate entry
                    $message = 'Username sudah ada. Silakan pilih username lain.';
                    $message_type = 'danger';
                } else {
                    $message = 'Gagal menambahkan user. Error: ' . $stmt->error;
                }
            }
            $stmt->close();
        } else {
            $message = 'Error dalam menyiapkan statement SQL: ' . $conn->error;
            $message_type = 'danger';
        }
    }
}
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Tambah User Baru</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Formulir User</h5>
        <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-arrow-left me-2"></i>Kembali
        </a>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>" role="alert">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form action="create.php" method="POST">
            <div class="mb-3">
                <label for="name" class="form-label">Nama Lengkap</label>
                <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($name) ?>">
            </div>
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" required value="<?= htmlspecialchars($username) ?>">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="role" class="form-label">Role</label>
                <select class="form-select" id="role" name="role" required>
                    <option value="" disabled selected>Pilih Role</option>
                    <option value="admin" <?= ($role == 'admin') ? 'selected' : '' ?>>Admin</option>
                    <option value="staff" <?= ($role == 'staff') ? 'selected' : '' ?>>Staff</option>
                    <option value="marketing" <?= ($role == 'marketing') ? 'selected' : '' ?>>Marketing</option> 
                    <option value="procurement" <?= ($role == 'procurement') ? 'selected' : '' ?>>Procurement</option>
                    <option value="production" <?= ($role == 'production') ? 'selected' : '' ?>>Production</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-save me-2"></i>Simpan User
            </button>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>