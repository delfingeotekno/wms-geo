<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    exit();
}

// Ambil daftar gudang untuk tab filter
$warehouses_query = $conn->query("SELECT id, name FROM warehouses ORDER BY name ASC");
$warehouses_list = $warehouses_query->fetch_all(MYSQLI_ASSOC);

// Tentukan tab aktif
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';

$warehouse_filter_sql = "";
if ($active_tab !== 'all') {
    $filter_warehouse_id = (int)$active_tab;
    $warehouse_filter_sql = "AND t.warehouse_id = $filter_warehouse_id";
}

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

$sql_filter = "WHERE t.is_deleted = 0";
if ($search) {
    $sql_filter .= " AND (t.transaction_number LIKE '%$search%' OR t.recipient LIKE '%$search%')";
}

// PERUBAHAN 1: Menambahkan kolom 'status' ke dalam query SQL dan filter tenant/warehouse
$sql = "SELECT t.*, u.name AS creator_name, w.name AS warehouse_name FROM outbound_transactions t JOIN users u ON t.created_by = u.id LEFT JOIN warehouses w ON t.warehouse_id = w.id " . $sql_filter . " $warehouse_filter_sql ORDER BY t.created_at DESC";
$result = $conn->query($sql);
$transactions = $result->fetch_all(MYSQLI_ASSOC);
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Daftar Barang Keluar</h1>
</div>

<ul class="nav nav-tabs border-0">
  <li class="nav-item">
    <a class="nav-link <?= ($active_tab === 'all') ? 'active bg-white text-dark fw-bold border-bottom-0' : 'text-muted' ?>" 
       href="?tab=all<?= $search ? '&search='.urlencode($search) : '' ?>">
       Semua Lokasi Gudang
    </a>
  </li>
  <?php foreach ($warehouses_list as $wh): ?>
  <li class="nav-item">
    <a class="nav-link <?= ($active_tab == $wh['id']) ? 'active bg-white text-dark fw-bold border-bottom-0' : 'text-muted' ?>" 
       href="?tab=<?= $wh['id'] ?><?= $search ? '&search='.urlencode($search) : '' ?>">
       <?= htmlspecialchars($wh['name']) ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="card shadow-sm mb-4" style="border-top-left-radius: 0;">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Data Transaksi</h5>
        <a href="create.php" class="btn btn-primary btn-sm rounded-pill px-3">
            <i class="bi bi-plus-lg me-2"></i>Tambah Data
        </a>
    </div>
    <div class="card-body">
        <?php if ($status == 'success_outbound'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Transaksi barang keluar berhasil disimpan.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($status == 'success_edit'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Transaksi barang keluar berhasil diperbarui.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($status == 'success_delete'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Transaksi barang keluar berhasil dihapus.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($status == 'success_complete'): ?>
             <div class="alert alert-success alert-dismissible fade show" role="alert">
                Status transaksi berhasil diubah menjadi Selesai.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <div class="col-md-6">
                <form action="index.php" method="GET" class="input-group">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                    <input type="text" class="form-control" name="search" placeholder="Cari nomor transaksi atau penerima..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="outboundTable">
                <thead class="table-secondary">
                    <tr>
                        <th>No</th>
                        <th>No. Transaksi</th>
                        <th>Tanggal</th>
                        <?php if ($active_tab === 'all'): ?>
                        <th>Gudang</th>
                        <?php endif; ?>
                        <th>Penerima</th>
                        <th>No. PO</th>
                        <th>Referensi</th>
                        <th>No. BAST</th>
                        <th>Total Item</th>
                        <th>Dibuat Oleh</th>
                        <th>Status</th> <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($transactions) > 0): ?>
                        <?php foreach ($transactions as $i => $transaction): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($transaction['transaction_number']) ?></td>
                                <td><?= date('d-m-Y', strtotime($transaction['transaction_date'])) ?></td>
                                <?php if ($active_tab === 'all'): ?>
                                <td><?= htmlspecialchars($transaction['warehouse_name'] ?? '-') ?></td>
                                <?php endif; ?>
                                <td><?= htmlspecialchars($transaction['recipient']) ?></td>
                                <td><?= htmlspecialchars($transaction['po_number']) ?></td>
                                <td><?= htmlspecialchars($transaction['reference']) ?></td>
                                <td><?= htmlspecialchars($transaction['bast_number']) ?></td>
                                <td><?= htmlspecialchars($transaction['total_items']) ?></td>
                                <td><?= htmlspecialchars($transaction['creator_name']) ?></td>
                                
                                <td>
                                    <?php 
                                    $status_text = htmlspecialchars($transaction['status'] ?? 'Pending'); // Ambil dari DB atau default 'Pending'
                                    $badge_class = ($status_text == 'Completed' || $status_text == 'Selesai') ? 'bg-success' : 'bg-warning';
                                    echo "<span class='badge {$badge_class}'>{$status_text}</span>";
                                    ?>
                                </td>
                                
                                <td class="text-nowrap">
                                    <a href="detail.php?id=<?= $transaction['id'] ?>" class="btn btn-info btn-sm" title="Detail"><i class="bi bi-eye"></i></a>
                                    
                                    <?php if (($transaction['status'] ?? 'Pending') != 'Completed' && ($transaction['status'] ?? 'Pending') != 'Selesai'): ?>
                                        <a href="#" class="btn btn-success btn-sm" title="Selesaikan Transaksi" data-bs-toggle="modal" data-bs-target="#completeModal" data-id="<?= $transaction['id'] ?>">
                                            <i class="bi bi-check2-circle"></i> Selesai
                                        </a>
                                        <a href="edit.php?id=<?= $transaction['id'] ?>" class="btn btn-warning btn-sm" title="Edit"><i class="bi bi-pencil"></i></a>
                                        
                                        <a href="#" class="btn btn-danger btn-sm" title="Hapus" data-bs-toggle="modal" data-bs-target="#deleteModal" data-id="<?= $transaction['id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled title="Sudah Selesai">Selesai</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="text-center">Tidak ada data transaksi barang keluar.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">Konfirmasi Hapus</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        Apakah Anda yakin ingin menghapus transaksi ini?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <a id="confirmDelete" class="btn btn-danger">Hapus</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="completeModal" tabindex="-1" aria-labelledby="completeModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="completeModalLabel">Konfirmasi Selesai</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        Apakah Anda yakin ingin menandai transaksi ini sebagai **Selesai**? Status tidak dapat dikembalikan.
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <a id="confirmComplete" class="btn btn-success">Selesai</a>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Logic untuk Modal Hapus
                var deleteModal = document.getElementById('deleteModal');
                deleteModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var transactionId = button.getAttribute('data-id');
                    var confirmDeleteButton = document.getElementById('confirmDelete');
                    confirmDeleteButton.href = 'delete.php?id=' + transactionId;
                });
                
                // Logic untuk Modal Selesai (BARU)
                var completeModal = document.getElementById('completeModal');
                completeModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var transactionId = button.getAttribute('data-id');
                    var confirmCompleteButton = document.getElementById('confirmComplete');
                    // Ganti 'complete.php' dengan file PHP yang akan memproses perubahan status
                    confirmCompleteButton.href = 'complete.php?id=' + transactionId; 
                });
            });
        </script>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>