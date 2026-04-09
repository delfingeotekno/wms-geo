<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan hanya admin yang bisa mengakses halaman ini
if (!in_array($_SESSION['role'], ['admin', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$message = '';
$message_type = '';

// Ambil ID tipe produk dari URL
$type_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($type_id == 0) {
    header("Location: product_types.php");
    exit();
}

// Cek jika formulir telah disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $type_name = trim($_POST['type_name']);

    if (empty($type_name)) {
        $message = 'Nama tipe produk harus diisi.';
        $message_type = 'danger';
    } else {
        // Perbarui data tipe produk (filtered by tenant)
        $update_stmt = $conn->prepare("UPDATE product_types SET type_name = ? WHERE id = ?");
        if ($update_stmt) {
            $update_stmt->bind_param("si", $type_name, $type_id);
            if ($update_stmt->execute()) {
                header("Location: product_types.php?status=success_edit_type");
                exit();
            } else {
                $message = 'Gagal memperbarui tipe produk: ' . $update_stmt->error;
                $message_type = 'danger';
            }
            $update_stmt->close();
        }
    }
}

// Ambil data tipe produk yang akan diubah untuk mengisi formulir (security check tenant_id)
$type_query = $conn->prepare("SELECT id, type_name FROM product_types WHERE id = ?");
$type_query->bind_param("i", $type_id);
$type_query->execute();
$type_result = $type_query->get_result();
$type = $type_result->fetch_assoc();

if (!$type) {
    header("Location: product_types.php");
    exit();
}
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Ubah Tipe Produk</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Formulir Ubah Tipe Produk</h5>
        <a href="product_types.php" class="btn btn-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-arrow-left me-2"></i>Kembali
        </a>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>" role="alert">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <form action="edit_product_type.php?id=<?= $type['id'] ?>" method="POST">
            <div class="mb-3">
                <label for="type_name" class="form-label">Nama Tipe Produk</label>
                <input type="text" class="form-control" id="type_name" name="type_name" value="<?= htmlspecialchars($type['type_name']) ?>" required>
            </div>
            <button type="submit" class="btn btn-warning rounded-pill px-4">
                <i class="bi bi-arrow-clockwise me-2"></i>Perbarui Tipe
            </button>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>