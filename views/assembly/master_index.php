<?php include '../../includes/header.php'; ?>

<div class="container-fluid">
    <!-- REMOVED TENANT_ID -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center">
            <a href="index.php" class="btn btn-outline-secondary btn-sm me-3">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
            <h1 class="h3 mb-0 text-gray-800">Master Data Rakitan (BOM)</h1>
        </div>
        
        <?php if ($_SESSION['role'] != 'production'): ?>
        <a href="master_create.php" class="btn btn-primary shadow-sm">
            <i class="bi bi-plus-circle me-2"></i> Tambah Rakitan Baru
        </a>
        <?php endif; ?>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th width="50">No</th>
                            <th>Nama Rakitan</th>
                            <th>Deskripsi</th>
                            <th width="150">Jumlah Komponen</th>
                            <?php if ($_SESSION['role'] != 'production'): ?>
                            <th width="120">Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql = "SELECT a.*, (SELECT COUNT(*) FROM assembly_details WHERE assembly_id = a.id) as total_item 
                                FROM assemblies a ORDER BY assembly_name ASC";
                        $res = $conn->query($sql);
                        $no = 1;
                        if ($res->num_rows > 0):
                            while($row = $res->fetch_assoc()):
                        ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><strong><?= htmlspecialchars($row['assembly_name']) ?></strong></td>
                            <td><?= htmlspecialchars($row['description']) ?></td>
                            <td><span class="badge bg-secondary"><?= $row['total_item'] ?> Item</span></td>
                            <?php if ($_SESSION['role'] != 'production'): ?>
                            <td>
                                <a href="master_edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button onclick="deleteAssembly(<?= $row['id'] ?>)" class="btn btn-sm btn-danger" title="Hapus">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php 
                            endwhile; 
                        else:
                        ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Belum ada data rakitan.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function deleteAssembly(id) {
    if (confirm('Apakah Anda yakin ingin menghapus data rakitan ini? Menghapus master data tidak akan menghapus riwayat transaksi yang sudah terjadi.')) {
        window.location.href = 'master_delete.php?id=' + id;
    }
}
</script>