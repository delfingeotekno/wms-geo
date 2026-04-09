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

// Ambil ID merek dari URL
$brand_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($brand_id == 0) {
    header("Location: brands.php");
    exit();
}

// Ambil data merek yang akan diubah untuk mengisi formulir (security check tenant_id)
$brand_query = $conn->prepare("SELECT id, brand_name, brand_code FROM brands WHERE id = ?");
$brand_query->bind_param("i", $brand_id);
$brand_query->execute();
$brand_result = $brand_query->get_result();
$brand = $brand_result->fetch_assoc();

if (!$brand) {
    header("Location: brands.php");
    exit();
}

// Cek jika formulir telah disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $brand_name = trim($_POST['brand_name']);
    $brand_code = trim($_POST['brand_code']);

    if (empty($brand_name) || empty($brand_code)) {
        $message = 'Nama merek dan Kode Merek harus diisi.';
        $message_type = 'danger';
    } else {
        // Cek apakah brand_code sudah ada, kecuali untuk brand yang sedang diedit (filtered by tenant)
        $check_code_stmt = $conn->prepare("SELECT id FROM brands WHERE brand_code = ? AND id != ?");
        $check_code_stmt->bind_param("si", $brand_code, $brand_id);
        $check_code_stmt->execute();
        $check_code_result = $check_code_stmt->get_result();

        if ($check_code_result->num_rows > 0) {
            $message = 'Kode Merek sudah ada. Mohon gunakan kode lain.';
            $message_type = 'danger';
        } else {
            // Gunakan transaksi database untuk memastikan kedua tabel diperbarui
            $conn->begin_transaction();
            try {
                // Simpan kode merek lama
                $old_brand_code = $brand['brand_code'];

                // Perbarui data merek di tabel 'brands'
                $update_brand_stmt = $conn->prepare("UPDATE brands SET brand_name = ?, brand_code = ? WHERE id = ?");
                $update_brand_stmt->bind_param("ssi", $brand_name, $brand_code, $brand_id);
                $update_brand_stmt->execute();
                $update_brand_stmt->close();

                // Ambil semua produk yang terkait dengan merek ini (filtered by tenant)
                $products_query = $conn->prepare("SELECT id, product_code FROM products WHERE brand_id = ?");
                $products_query->bind_param("i", $brand_id);
                $products_query->execute();
                $products_result = $products_query->get_result();

                // Perbarui setiap product_code
                while ($product = $products_result->fetch_assoc()) {
                    $old_product_code = $product['product_code'];
                    
                    // Temukan nomor urut dengan memisahkan string pada tanda hubung terakhir
                    $sequence = strrchr($old_product_code, '-');
                    
                    if ($sequence !== false) {
                        // Gabungkan kode merek baru dengan nomor urut yang lama
                        $new_product_code = $brand_code . $sequence;
                        
                        // Lakukan update untuk setiap produk
                        $update_product_stmt = $conn->prepare("UPDATE products SET product_code = ? WHERE id = ?");
                        $update_product_stmt->bind_param("si", $new_product_code, $product['id']);
                        $update_product_stmt->execute();
                        $update_product_stmt->close();
                    }
                }

                // Commit transaksi jika semua berhasil
                $conn->commit();
                header("Location: brands.php?status=success_edit_brand");
                exit();
            } catch (Exception $e) {
                // Rollback transaksi jika terjadi kesalahan
                $conn->rollback();
                $message = 'Gagal memperbarui merek: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}


?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Ubah Merek</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Formulir Ubah Merek</h5>
        <a href="brands.php" class="btn btn-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-arrow-left me-2"></i>Kembali
        </a>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>" role="alert">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <form action="edit_brand.php?id=<?= $brand['id'] ?>" method="POST">
            <div class="mb-3">
                <label for="brand_name" class="form-label">Nama Merek</label>
                <input type="text" class="form-control" id="brand_name" name="brand_name" value="<?= htmlspecialchars($brand['brand_name']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="brand_code" class="form-label">Kode Merek</label>
                <input type="text" class="form-control" id="brand_code" name="brand_code" value="<?= htmlspecialchars($brand['brand_code']) ?>" required maxlength="10">
            </div>
            <button type="submit" class="btn btn-warning rounded-pill px-4">
                <i class="bi bi-arrow-clockwise me-2"></i>Perbarui Merek
            </button>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
