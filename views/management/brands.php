<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$message = '';
$message_type = '';

// Handle form submissions for adding a new brand
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['brand_name'])) {
    $brand_name = trim($_POST['brand_name']);
    $brand_code = trim($_POST['brand_code']);

    if (empty($brand_name) || empty($brand_code)) {
        $message = 'Nama Brand dan Kode Brand harus diisi.';
        $message_type = 'danger';
    } else {
        // Check if brand_code already exists
        $check_code_stmt = $conn->prepare("SELECT id FROM brands WHERE brand_code = ?");
        $check_code_stmt->bind_param("s", $brand_code);
        $check_code_stmt->execute();
        $check_code_result = $check_code_stmt->get_result();

        if ($check_code_result->num_rows > 0) {
            $message = 'Kode Brand sudah ada. Mohon gunakan kode lain.';
            $message_type = 'danger';
        } else {
            // Insert the new brand into the database
            $insert_stmt = $conn->prepare("INSERT INTO brands (brand_name, brand_code) VALUES (?, ?)");
            if ($insert_stmt) {
                $insert_stmt->bind_param("ss", $brand_name, $brand_code);
                if ($insert_stmt->execute()) {
                    $message = 'Brand berhasil ditambahkan!';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal menambahkan Brand: ' . $insert_stmt->error;
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

// Handle deletion of a brand
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    if ($delete_id > 0) {
        // Check if the brand is used by any products
        $check_stmt = $conn->prepare("SELECT id FROM products WHERE brand_id = ? LIMIT 1");
        $check_stmt->bind_param("i", $delete_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $message = 'Tidak dapat menghapus brand ini karena masih digunakan oleh produk.';
            $message_type = 'danger';
        } else {
            $delete_stmt = $conn->prepare("DELETE FROM brands WHERE id = ?");
            if ($delete_stmt) {
                $delete_stmt->bind_param("i", $delete_id);
                if ($delete_stmt->execute()) {
                    $message = 'Merek berhasil dihapus!';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal menghapus merek: ' . $delete_stmt->error;
                    $message_type = 'danger';
                }
                $delete_stmt->close();
            }
        }
    }
}

// Fetch all brands from the database
$brands_query = $conn->query("SELECT id, brand_name, brand_code FROM brands ORDER BY brand_name");
$brands = $brands_query->fetch_all(MYSQLI_ASSOC);
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Manajemen Brand</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Daftar Brand</h5>
        <button class="btn btn-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addBrandModal">
            <i class="bi bi-plus me-2"></i>Tambah Brand
        </button>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>" role="alert">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Nama Brand</th>
                    <th>Kode Brand</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; ?>
                <?php foreach ($brands as $brand): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($brand['brand_name']) ?></td>
                        <td><?= htmlspecialchars($brand['brand_code']) ?></td>
                        <td>
                        <a href="edit_brand.php?id=<?= $brand['id'] ?>" class="btn btn-warning btn-sm">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="?delete_id=<?= $brand['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin menghapus merek ini?');">
                            <i class="bi bi-trash"></i>
                        </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addBrandModal" tabindex="-1" aria-labelledby="addBrandModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="addBrandModalLabel">Tambah Brand Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="brands.php" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="brandName" class="form-label">Nama Brand</label>
                        <input type="text" class="form-control" id="brandName" name="brand_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="brandCode" class="form-label">Kode Brand</label>
                        <input type="text" class="form-control" id="brandCode" name="brand_code" required maxlength="10">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary">Simpan Brand</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>