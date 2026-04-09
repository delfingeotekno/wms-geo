<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$message = '';
$message_type = '';

// Handle form submissions for adding a new product type
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['type_name'])) {
    $type_name = trim($_POST['type_name']);

    if (empty($type_name)) {
        $message = 'Nama tipe produk harus diisi.';
        $message_type = 'danger';
    } else {
        // Cek apakah tipe produk sudah ada
        $check_stmt = $conn->prepare("SELECT id FROM product_types WHERE type_name = ?");
        $check_stmt->bind_param("s", $type_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $message = 'Tipe produk sudah ada. Mohon gunakan nama lain.';
            $message_type = 'danger';
        } else {
            // Insert the new product type into the database
            $insert_stmt = $conn->prepare("INSERT INTO product_types (type_name) VALUES (?)");
            if ($insert_stmt) {
                $insert_stmt->bind_param("s", $type_name);
                if ($insert_stmt->execute()) {
                    $message = 'Tipe produk berhasil ditambahkan!';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal menambahkan tipe produk: ' . $insert_stmt->error;
                    $message_type = 'danger';
                }
                $insert_stmt->close();
            } else {
                $message = 'Error dalam menyiapkan statement: ' . $conn->error;
                $message_type = 'danger';
            }
        }
    }
}

// Handle deletion of a product type
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    if ($delete_id > 0) {
        // Cek apakah tipe produk digunakan oleh produk mana pun
        $check_stmt = $conn->prepare("SELECT id FROM products WHERE product_type_id = ? LIMIT 1");
        $check_stmt->bind_param("i", $delete_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $message = 'Tidak dapat menghapus tipe produk ini karena masih digunakan oleh produk.';
            $message_type = 'danger';
        } else {
            $delete_stmt = $conn->prepare("DELETE FROM product_types WHERE id = ?");
            if ($delete_stmt) {
                $delete_stmt->bind_param("i", $delete_id);
                if ($delete_stmt->execute()) {
                    $message = 'Tipe produk berhasil dihapus!';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal menghapus tipe produk: ' . $delete_stmt->error;
                    $message_type = 'danger';
                }
                $delete_stmt->close();
            }
        }
    }
}

// Fetch all product types from the database
$types_query = $conn->query("SELECT id, type_name FROM product_types ORDER BY type_name");
$product_types = $types_query->fetch_all(MYSQLI_ASSOC);
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Manajemen Tipe Produk</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Daftar Tipe Produk</h5>
        <button class="btn btn-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addProductTypeModal">
            <i class="bi bi-plus me-2"></i>Tambah Tipe
        </button>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>" role="alert">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover table-striped">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Tipe Produk</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($product_types)): ?>
                        <tr>
                            <td colspan="3" class="text-center">Belum ada data tipe produk.</td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; ?>
                        <?php foreach ($product_types as $type): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= htmlspecialchars($type['type_name']) ?></td>
                                <td>
                                    <a href="edit_product_type.php?id=<?= $type['id'] ?>" class="btn btn-warning btn-sm">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="?delete_id=<?= $type['id'] ?>" class="btn btn-danger btn-sm ms-2" onclick="return confirm('Apakah Anda yakin ingin menghapus tipe produk ini?');">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addProductTypeModal" tabindex="-1" aria-labelledby="addProductTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="addProductTypeModalLabel">Tambah Tipe Produk Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="product_types.php" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="typeName" class="form-label">Nama Tipe Produk</label>
                        <input type="text" class="form-control" id="typeName" name="type_name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary">Simpan Tipe</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>