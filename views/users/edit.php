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

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($user_id == 0) {
    header("Location: index.php");
    exit();
}

// Tangani permintaan POST (saat formulir dikirim)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $name = trim($_POST['name']);
    $role = $_POST['role'];
    $password = trim($_POST['password']); // Kata sandi yang diinput (bisa kosong)

    // Validasi field yang diperlukan
    if (empty($username) || empty($name) || empty($role)) {
        $message = 'Username, Nama, dan role wajib diisi.';
        $message_type = 'danger';
    } else {

        // Logika untuk menangani password
        if (!empty($password)) {
            // Jika password diisi, enkripsi password baru
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET username = ?, name = ?, password = ?, role = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssi", $username, $name, $hashed_password, $role, $user_id);
        } else {
            // Jika password kosong, jangan ubah password di database
            $sql = "UPDATE users SET username = ?, name = ?, role = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssi", $username, $name, $role, $user_id);
        }

        if ($stmt->execute()) {
            header("Location: index.php?status=success_edit");
            exit();
        } else {
            // Tangani error jika username sudah ada
            if ($conn->errno == 1062) { // Kode error MySQL untuk duplicate entry
                $message = 'Username sudah digunakan. Silakan pilih username lain.';
                $message_type = 'danger';
            } else {
                $message = 'Gagal memperbarui user: ' . $stmt->error;
                $message_type = 'danger';
            }
        }
        $stmt->close();
    }
}

// Ambil data user yang akan diubah untuk ditampilkan di formulir
$user_query = $conn->prepare("SELECT id, username, name, role FROM users WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user = $user_result->fetch_assoc();
$user_query->close();

if (!$user) {
    header("Location: index.php");
    exit();
}
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Ubah User</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Formulir Ubah User</h5>
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

        <form action="edit.php?id=<?= $user['id'] ?>" method="POST">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="name" class="form-label">Nama Lengkap</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password (isi jika ingin diubah)</label>
                <input type="password" class="form-control" id="password" name="password">
            </div>
            <div class="mb-3">
                <label for="role" class="form-label">Role</label>
                <select class="form-select" id="role" name="role" required>
                    <option value="admin" <?= ($user['role'] == 'admin') ? 'selected' : '' ?>>Admin</option>
                    <option value="staff" <?= ($user['role'] == 'staff') ? 'selected' : '' ?>>Staff</option>
                    <option value="marketing" <?= ($user['role'] == 'marketing') ? 'selected' : '' ?>>Marketing</option> 
                    <option value="procurement" <?= ($user['role'] == 'procurement') ? 'selected' : '' ?>>Procurement</option>
                    <option value="production" <?= ($user['role'] == 'production') ? 'selected' : '' ?>>Production</option>
                </select>
            </div>

            <button type="submit" class="btn btn-warning rounded-pill px-4">
                <i class="bi bi-arrow-clockwise me-2"></i>Perbarui User
            </button>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>