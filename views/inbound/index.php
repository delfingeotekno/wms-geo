<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: /wms-geo/index.php");
    exit();
}

// Inisialisasi variabel pencarian
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

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

$transactions_query = "SELECT 
    t.id, 
    t.transaction_number, 
    t.transaction_type, 
    t.transaction_date, 
    t.sender, 
    t.total_items, 
    u.name AS created_by, 
    t.notes,
    w.name AS warehouse_name
FROM inbound_transactions t
JOIN users u ON t.created_by = u.id
LEFT JOIN warehouses w ON t.warehouse_id = w.id
WHERE t.is_deleted = 0 $warehouse_filter_sql";

// Menambahkan filter pencarian jika ada input
if (!empty($search)) {
    $transactions_query .= " AND (t.transaction_number LIKE '%$search%' OR t.sender LIKE '%$search%')";
}

$transactions_query .= " ORDER BY t.created_at DESC";

// Eksekusi query
$transactions = $conn->query($transactions_query)->fetch_all(MYSQLI_ASSOC);
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Data Barang Masuk</h1>
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
        <?php if (isset($_GET['status']) && $_GET['status'] == 'success_inbound'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Transaksi barang masuk berhasil disimpan.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['status']) && $_GET['status'] == 'success_delete'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Transaksi barang masuk berhasil dihapus.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <div class="col-md-6">
                <form action="index.php" method="GET" class="input-group">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                    <input type="text" id="searchInput" class="form-control" name="search" placeholder="Cari nomor transaksi atau pengirim..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="inboundTable">
                <thead>
                    <tr class="table-secondary">
                        <th>No</th>
                        <th>No.Transaksi</th>
                        <th>Tipe</th>
                        <th>Tanggal</th>
                        <?php if ($active_tab === 'all'): ?>
                        <th>Gudang</th>
                        <?php endif; ?>
                        <th>Vendor</th>
                        <th>Total Item</th>
                        <th>Dibuat Oleh</th>
                        <th>Catatan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($transactions) > 0): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= htmlspecialchars($transaction['transaction_number']) ?></td>
                                <td><?= htmlspecialchars($transaction['transaction_type']) ?></td>
                                <td><?= date('d-m-Y', strtotime($transaction['transaction_date'])) ?></td>
                                <?php if ($active_tab === 'all'): ?>
                                <td><?= htmlspecialchars($transaction['warehouse_name'] ?? '-') ?></td>
                                <?php endif; ?>
                                <td><?= htmlspecialchars($transaction['sender']) ?></td>
                                <td><?= htmlspecialchars($transaction['total_items']) ?></td>
                                <td><?= htmlspecialchars($transaction['created_by']) ?></td>
                                <td><?= htmlspecialchars($transaction['notes']) ?></td>
                                <td class="text-nowrap">
                                    <a href="detail.php?id=<?= $transaction['id'] ?>" class="btn btn-info btn-sm text-white me-1" title="Detail"><i class="bi bi-eye"></i></a>
                                    <a href="edit.php?id=<?= $transaction['id'] ?>" class="btn btn-warning btn-sm me-1" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <a href="delete.php?id=<?= $transaction['id'] ?>" class="btn btn-danger btn-sm" title="Hapus" onclick="return confirm('Apakah Anda yakin ingin menghapus transaksi ini? Stok produk akan dikurangi.')"><i class="bi bi-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center">Tidak ada data transaksi yang ditemukan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Nonaktifkan fitur searching bawaan DataTables karena pencarian sudah dihandle oleh PHP (server-side)
    var table = $('#inboundTable').DataTable({
        "info": true,
        "paging": true,
        "pageLength": 10,
        "lengthChange": true,
        "searching": false, // MATIKAN PENCARIAN BAWAAN DATATABLES
        "ordering": true,
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json"
        },
        "columnDefs": [
            { "orderable": false, "targets": [8] }
        ]
    });
});
</script>