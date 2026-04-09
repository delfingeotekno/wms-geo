<?php
include '../../includes/db_connect.php';
include '../../includes/db_connect.php';
include '../../includes/header.php';

$message = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = mysqli_real_escape_string($conn, $_POST['warehouse_name']);
    $loc  = mysqli_real_escape_string($conn, $_POST['location']);
    $desc = mysqli_real_escape_string($conn, $_POST['description']);
    
    $query = "INSERT INTO warehouses (name, location, description, is_active) VALUES ('$name', '$loc', '$desc', 1)";
    if (mysqli_query($conn, $query)) {
        $message = "Gudang berhasil ditambahkan!";
        $message_type = "success";
    } else {
        $message = "Gagal menambahkan gudang: " . mysqli_error($conn);
        $message_type = "danger";
    }
}
?>

<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="fw-bold h3 mb-1"><i class="bi bi-houses me-2 text-primary"></i>Tambah Gudang</h1>
                <p class="text-muted small mb-0">Daftarkan lokasi penyimpanan atau titik distribusi baru.</p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-xl-8 col-lg-10">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show border-0 shadow-sm" role="alert">
                        <i class="bi bi-info-circle-fill me-2"></i> <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form action="" method="POST">
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-building me-2 text-primary"></i>Detail Lokasi</h6>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-4">
                                <div class="col-md-12">
                                    <label for="warehouse_name" class="form-label small fw-bold text-muted text-uppercase mb-1">Nama Gudang <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-house-door text-primary"></i></span>
                                        <input type="text" class="form-control border-start-0" id="warehouse_name" name="warehouse_name" placeholder="Misal: Gudang Utama Jakarta" required>
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <label for="location" class="form-label small fw-bold text-muted text-uppercase mb-1">Lokasi / Alamat</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-geo-alt text-primary"></i></span>
                                        <input type="text" class="form-control border-start-0" id="location" name="location" placeholder="Alamat lengkap atau koordinat lokasi...">
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <label for="description" class="form-label small fw-bold text-muted text-uppercase mb-1">Catatan / Deskripsi</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" placeholder="Informasi tambahan mengenai gudang ini..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-body bg-light rounded shadow-sm py-3">
                            <div class="d-flex align-items-center justify-content-between px-2">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary bg-opacity-10 p-2 rounded me-3 text-primary">
                                        <i class="bi bi-shield-check fs-5"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0 fw-bold">Status Operasional</h6>
                                        <small class="text-muted">Gudang akan otomatis berstatus aktif setelah dibuat.</small>
                                    </div>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" checked disabled style="width: 2.5em; height: 1.25em;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-center mt-4 pt-2">
                        <button type="reset" class="btn btn-light rounded-pill px-4 me-2 border shadow-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary btn-lg rounded-pill px-5 shadow">
                            <i class="bi bi-cloud-check me-2"></i> Simpan Gudang Baru
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>