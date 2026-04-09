<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Logika Notifikasi Alert
$msg = isset($_GET['msg']) ? $_GET['msg'] : '';
$message = '';
$message_type = '';

if ($msg === 'success_delete') {
    $message = "Gudang berhasil dihapus secara permanen.";
    $message_type = "success";
} elseif ($msg === 'has_history') {
    $message = "Gagal menghapus! Gudang ini memiliki riwayat transaksi masuk/keluar terkait.";
    $message_type = "warning";
} elseif ($msg === 'has_stock') {
    $message = "Gagal menghapus! Masih ada stok barang yang tersimpan di gudang ini.";
    $message_type = "warning";
} elseif ($msg === 'error_delete') {
    $message = "Terjadi kesalahan sistem saat mencoba menghapus data.";
    $message_type = "danger";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Gudang - WMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .card { border: none; border-radius: 12px; }
        .table thead th { 
            background-color: #f1f4f9; 
            text-transform: uppercase; 
            font-size: 0.75rem; 
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: none;
        }
        .search-box { border-radius: 20px; padding-left: 40px; }
        .search-icon { position: absolute; left: 15px; top: 10px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="container">
            <div class="row align-items-center mb-4 g-3">
                <div class="col-md-6">
                    <h1 class="fw-bold h3 mb-1"><i class="bi bi-houses-fill me-2 text-primary"></i>Manajemen Gudang</h1>
                    <p class="text-muted small mb-0">Kelola lokasi penyimpanan barang Anda di sini.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="create.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                        <i class="bi bi-plus-lg me-2"></i>Tambah Gudang Baru
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?> alert-dismissible fade show border-0 shadow-sm mb-3" role="alert">
                    <i class="bi bi-info-circle-fill me-2"></i> <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card shadow-sm p-3 border-start border-primary border-4">
                        <?php 
                        $res = mysqli_query($conn, "SELECT COUNT(*) as total FROM warehouses");
                        $count = mysqli_fetch_assoc($res);
                        ?>
                        <div class="small text-muted fw-bold">TOTAL GUDANG</div>
                        <div class="h4 fw-bold mb-0"><?= $count['total'] ?></div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white py-3 border-0">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <div class="position-relative">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" id="searchInput" class="form-control search-box" placeholder="Cari nama gudang atau lokasi...">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="warehouseTable">
                            <thead>
                                <tr>
                                    <th class="ps-4">No.</th>
                                    <th>Nama Gudang</th>
                                    <th>Lokasi / Alamat</th>
                                    <th>Deskripsi</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center pe-4">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                $query = mysqli_query($conn, "SELECT * FROM warehouses ORDER BY id DESC");
                                while ($row = mysqli_fetch_assoc($query)):
                                    $status_badge = $row['is_active'] ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
                                    $status_text = $row['is_active'] ? 'Aktif' : 'Non-Aktif';
                                ?>
                                <tr>
                                    <td class="ps-4 text-muted small"><?= $no++ ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['name']) ?></div>
                                    </td>
                                    <td><i class="bi bi-geo-alt me-1 text-muted"></i> <?= htmlspecialchars($row['location'] ?: '-') ?></td>
                                    <td class="text-muted small"><?= htmlspecialchars($row['description'] ?: '-') ?></td>
                                    <td class="text-center">
                                        <span class="badge rounded-pill <?= $status_badge ?> px-3"><?= $status_text ?></span>
                                    </td>
                                    <td class="text-center pe-4">
                                        <div class="btn-group border rounded-pill overflow-hidden shadow-sm">
                                            <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-white btn-sm border-0 py-1" title="Edit">
                                                <i class="bi bi-pencil-square text-primary"></i>
                                            </a>
                                            <a href="javascript:void(0)" onclick="confirmDelete(<?= $row['id'] ?>)" class="btn btn-white btn-sm border-0 py-1" title="Hapus">
                                                <i class="bi bi-trash text-danger"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('searchInput').addEventListener('keyup', function() {
        let filter = this.value.toLowerCase();
        let rows = document.querySelectorAll('#warehouseTable tbody tr');
        
        rows.forEach(row => {
            let text = row.innerText.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });

    function confirmDelete(id) {
        if (confirm('Apakah Anda yakin ingin MENGHAPUS PERMANEN gudang ini? Tindakan ini tidak dapat dibatalkan.')) {
            window.location.href = 'delete.php?id=' + id;
        }
    }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php include '../../includes/footer.php'; ?>