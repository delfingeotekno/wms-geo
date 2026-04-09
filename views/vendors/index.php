<?php include '../../includes/header.php'; ?>

<?php

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

// Logika Pencarian
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Manajemen Vendor</h1>
    <a href="add.php" class="btn btn-primary rounded-pill">
        <i class="bi bi-plus-lg me-2"></i>Tambah Vendor
    </a>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <form action="" method="GET" class="d-flex shadow-sm rounded-pill overflow-hidden">
            <input type="text" name="search" class="form-control border-0 ps-4" 
                   placeholder="Cari nama vendor..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-white bg-white border-0 pe-4 text-primary">
                <i class="bi bi-search"></i>
            </button>
            <?php if($search !== ''): ?>
                <a href="index.php" class="btn btn-white bg-white border-0 pe-3 text-danger" title="Reset">
                    <i class="bi bi-x-circle"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] == 'deleted'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i> Vendor berhasil dihapus.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($_GET['msg'] == 'error_used'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i> Vendor tidak bisa dihapus karena sudah memiliki riwayat transaksi Purchase Order.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nama Vendor</th>
                        <th>Sales / PIC</th>
                        <th>No. HP</th>
                        <th>Status Pajak</th>
                        <th>TOP</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Query dengan Filter Search
                    $sql = "SELECT * FROM vendors WHERE 1=1";
                    if ($search !== '') {
                        $sql .= " AND vendor_name LIKE '%$search%'";
                    }
                    $sql .= " ORDER BY vendor_name ASC";
                    
                    $result = $conn->query($sql);
                    
                    if ($result->num_rows > 0):
                        while($row = $result->fetch_assoc()):
                    ?>
                    <tr>
                        <td><strong><?= $row['vendor_name'] ?></strong><br><small class="text-muted"><?= $row['website_link'] ?></small></td>
                        <td><?= $row['sales_name'] ?></td>
                        <td><?= $row['phone'] ?></td>
                        <td>
                            <span class="badge <?= $row['tax_status'] == 'PPN' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $row['tax_status'] ?>
                            </span>
                        </td>
                        <td><?= $row['payment_term'] ?> (<?= $row['currency'] ?>)</td>
                        <td>
                            <a href="view.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info" title="Detail Vendor">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Vendor">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="process.php?action=delete&id=<?= $row['id'] ?>" 
                               class="btn btn-sm btn-outline-danger" 
                               onclick="return confirm('Apakah Anda yakin...')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php 
                        endwhile; 
                    else: 
                    ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            Data vendor tidak ditemukan.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>