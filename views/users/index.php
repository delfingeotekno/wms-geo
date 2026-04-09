<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Cek peran (role) pengguna yang sedang login
// Hanya peran 'admin' yang diizinkan untuk mengakses halaman ini
if ($_SESSION['role'] != 'admin') {
    header("Location: ../../index.php");
    exit();
}

$status_message = '';
$status_type = '';

// Mengambil pesan status dari URL (setelah redirect)
if (isset($_GET['status'])) {
    if ($_GET['status'] == 'success_add') {
        $status_message = 'User berhasil ditambahkan!';
        $status_type = 'success';
    } elseif ($_GET['status'] == 'success_edit') {
        $status_message = 'User berhasil diperbarui!';
        $status_type = 'warning';
    } elseif ($_GET['status'] == 'success_delete') {
        $status_message = 'User berhasil dihapus!';
        $status_type = 'danger';
    } elseif ($_GET['status'] == 'error_delete') {
        $status_message = 'Gagal menghapus user. Terjadi kesalahan.';
        $status_type = 'danger';
    }
}

// Mengambil semua data user dari database
$users = [];
$sql = "SELECT id, username, name, role, created_at FROM users ORDER BY created_at DESC"; // Menambahkan 'name' untuk kelengkapan
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}

// Fungsi helper untuk menampilkan badge role
function getRoleBadge($role) {
    $role = strtolower($role);
    switch ($role) {
        case 'admin':
            return '<span class="badge bg-danger">Admin</span>';
        case 'staff':
            return '<span class="badge bg-primary">Staff Gudang</span>';
        case 'marketing':
            return '<span class="badge bg-success">Marketing</span>'; // Badge untuk Marketing
        case 'production':
            return '<span class="badge bg-info text-dark">Production</span>';
        case 'procurement':
            return '<span class="badge bg-warning text-dark">Procurement</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($role) . '</span>';
    }
}
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Manajemen User</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Daftar User</h5>
        <a href="create.php" class="btn btn-primary btn-sm rounded-pill px-3">
            <i class="bi bi-plus me-2"></i>Tambah User
        </a>
    </div>
    <div class="card-body">
        <?php if ($status_message): ?>
            <div class="alert alert-<?= $status_type ?> alert-dismissible fade show" role="alert">
                <?= $status_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover table-striped">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Username</th>
                        <th>Nama</th> <th>Role</th>
                        <th>Tanggal Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" class="text-center">Belum ada data user.</td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= htmlspecialchars($user['username']) ?></td>
                                <td><?= htmlspecialchars($user['name']) ?></td> <td><?= getRoleBadge($user['role']) ?></td> <td><?= date('d-m-Y H:i:s', strtotime($user['created_at'])) ?></td>
                                <td>
                                    <?php 
                                    $current_user_id = $_SESSION['user_id'] ?? null; 
                                    $is_self = ($user['id'] == $current_user_id);
                                    ?>
                                    
                                    <a href="edit.php?id=<?= $user['id'] ?>" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    
                                    <?php if (!$is_self): ?>
                                    <a href="delete.php?id=<?= $user['id'] ?>" class="btn btn-danger btn-sm ms-2" onclick="return confirm('Apakah Anda yakin ingin menghapus user <?= htmlspecialchars($user['username']) ?>?');" title="Hapus">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                    <?php else: ?>
                                    <button class="btn btn-danger btn-sm ms-2" disabled title="Tidak bisa menghapus akun sendiri">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>