<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

$message = "";
$message_type = "";

// 1. Ambil data gudang berdasarkan ID
if (isset($_GET['id'])) {
    $id = mysqli_real_escape_string($conn, $_GET['id']);
    $query = mysqli_query($conn, "SELECT * FROM warehouses WHERE id = '$id'");
    $data = mysqli_fetch_assoc($query);

    if (!$data) {
        echo "<script>window.location.href='index.php';</script>";
        exit;
    }
} else {
    echo "<script>window.location.href='index.php';</script>";
    exit;
}

// 2. Logika Update Data
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name   = mysqli_real_escape_string($conn, $_POST['warehouse_name']);
    $loc    = mysqli_real_escape_string($conn, $_POST['location']);
    $desc   = mysqli_real_escape_string($conn, $_POST['description']);
    $active = isset($_POST['is_active']) ? 1 : 0;
    
    $update = "UPDATE warehouses SET 
               name = '$name', 
               location = '$loc', 
               description = '$desc', 
               is_active = '$active' 
               WHERE id = '$id'";

    if (mysqli_query($conn, $update)) {
        $message = "Perubahan data gudang berhasil disimpan!";
        $message_type = "success";
        // Refresh data terbaru
        $query = mysqli_query($conn, "SELECT * FROM warehouses WHERE id = '$id'");
        $data = mysqli_fetch_assoc($query);
    } else {
        $message = "Gagal memperbarui data: " . mysqli_error($conn);
        $message_type = "danger";
    }
}
?>

<div class="main-content">
    <div class="container-fluid my-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="fw-bold h3 mb-1"><i class="bi bi-pencil-square me-2 text-warning"></i>Edit Gudang</h1>
                <p class="text-muted small mb-0">Perbarui informasi lokasi gudang #WH-<?= $data['id'] ?></p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-xl-8 col-lg-10">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i> <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form action="" method="POST">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-geo-alt me-2 text-warning"></i>Informasi Wilayah</h6>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-4">
                                <div class="col-md-12">
                                    <label for="warehouse_name" class="form-label small fw-bold text-muted text-uppercase mb-1">Nama Gudang <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-building text-warning"></i></span>
                                        <input type="text" class="form-control border-start-0 fw-semibold" id="warehouse_name" name="warehouse_name" 
                                               value="<?= htmlspecialchars($data['name']) ?>" required>
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <label for="location" class="form-label small fw-bold text-muted text-uppercase mb-1">Lokasi / Alamat</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-pin-map text-warning"></i></span>
                                        <input type="text" class="form-control border-start-0" id="location" name="location" 
                                               value="<?= htmlspecialchars($data['location']) ?>" placeholder="Alamat lengkap...">
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <label for="description" class="form-label small fw-bold text-muted text-uppercase mb-1">Catatan Tambahan</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($data['description']) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-body bg-light rounded shadow-sm py-3">
                            <div class="d-flex align-items-center justify-content-between px-2">
                                <div class="d-flex align-items-center">
                                    <div class="bg-warning bg-opacity-10 p-2 rounded me-3 text-warning">
                                        <i class="bi bi-toggle-on fs-5"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0 fw-bold">Status Gudang</h6>
                                        <small class="text-muted">Jika non-aktif, gudang tidak dapat dipilih di transaksi baru.</small>
                                    </div>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" 
                                           <?= $data['is_active'] ? 'checked' : '' ?> style="width: 2.5em; height: 1.25em; cursor: pointer;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-center mt-4 pt-2">
                        <a href="index.php" class="btn btn-light rounded-pill px-4 me-2 border shadow-sm">Batal</a>
                        <button type="submit" class="btn btn-warning btn-lg rounded-pill px-5 shadow fw-bold">
                            <i class="bi bi-save me-2"></i> Perbarui Data
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>