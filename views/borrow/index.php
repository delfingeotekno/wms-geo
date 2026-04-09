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
    $warehouse_filter_sql = "AND bt.warehouse_id = $filter_warehouse_id";
}

$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

$sql_filter = "WHERE bt.is_deleted = 0";
$params = [];
$types = "";

if (!empty($search)) {
    $sql_filter .= " AND (bt.transaction_number LIKE ? OR bt.borrower_name LIKE ?)";
    $search_term = "%" . $search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

$sql = "SELECT 
            bt.*,
            u.name AS creator_name,
            w.name AS warehouse_name,
            SUM(btd.quantity) AS total_items,
            MAX(btd.return_date) AS latest_return_date,
            COUNT(btd.id) AS total_details,
            COUNT(btd.return_date) AS returned_details,
            -- Tentukan status transaksi berdasarkan item yang dikembalikan
            CASE
                WHEN COUNT(btd.id) = 0 THEN 'Belum Ada Item'
                WHEN COUNT(btd.return_date) = 0 THEN 'dipinjam'
                WHEN COUNT(btd.return_date) < COUNT(btd.id) THEN 'beberapa kembali'
                ELSE 'sudah kembali'
            END AS status_text,
            CASE
                WHEN COUNT(btd.id) = 0 THEN 'bg-secondary'
                WHEN COUNT(btd.return_date) = 0 THEN 'bg-danger'
                WHEN COUNT(btd.return_date) < COUNT(btd.id) THEN 'bg-warning text-dark'
                ELSE 'bg-success'
            END AS badge_class
        FROM borrowed_transactions bt
        JOIN users u ON bt.user_id = u.id
        LEFT JOIN warehouses w ON bt.warehouse_id = w.id
        LEFT JOIN borrowed_transactions_detail btd ON bt.id = btd.transaction_id
        $sql_filter $warehouse_filter_sql
        GROUP BY bt.id
        ORDER BY bt.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$transactions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Daftar Peminjaman Barang</h1>
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
        <?php if ($status == 'success_borrow'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Transaksi peminjaman berhasil disimpan.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($status == 'success_return'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Barang berhasil dikembalikan.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <div class="col-md-6">
                <form action="index.php" method="GET" class="input-group">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                    <input type="text" class="form-control" name="search" placeholder="Cari nomor transaksi atau peminjam..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit" id="search-button">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="borrowTable">
                <thead>
                    <tr class="table-secondary">
                        <th>No</th>
                        <th>No. Transaksi</th>
                        <th>Tanggal Peminjaman</th>
                        <?php if ($active_tab === 'all'): ?>
                        <th>Gudang</th>
                        <?php endif; ?>
                        <th>Peminjam</th>
                        <th>Total Item</th>
                        <th>Tanggal Pengembalian</th>
                        <th>Status</th>
                        <th>Dibuat Oleh</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($transactions) > 0): ?>
                        <?php foreach ($transactions as $i => $transaction): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($transaction['transaction_number']) ?></td>
                                <td><?= date('d-m-Y', strtotime($transaction['borrow_date'])) ?></td>
                                <?php if ($active_tab === 'all'): ?>
                                <td><?= htmlspecialchars($transaction['warehouse_name'] ?? '-') ?></td>
                                <?php endif; ?>
                                <td><?= htmlspecialchars($transaction['borrower_name']) ?></td>
                                <td><?= htmlspecialchars($transaction['total_items'] ?? 0) ?></td>
                                <td>
                                    <?php 
                                        if ($transaction['latest_return_date']) {
                                            echo date('d-m-Y', strtotime($transaction['latest_return_date']));
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <span class="badge <?= htmlspecialchars($transaction['badge_class']) ?>">
                                        <?= ucwords(htmlspecialchars($transaction['status_text'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($transaction['creator_name']) ?></td>
                                <td class="text-nowrap">
                                    <a href="detail.php?id=<?= $transaction['id'] ?>" class="btn btn-info btn-sm text-white me-1" title="Detail"><i class="bi bi-eye"></i></a>
                                    <a href="edit.php?id=<?= $transaction['id'] ?>" class="btn btn-warning btn-sm me-1" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <a href="delete.php?id=<?= $transaction['id'] ?>" class="btn btn-danger btn-sm" title="Hapus" onclick="return confirm('Apakah Anda yakin ingin menghapus transaksi ini? Stok produk akan dikembalikan.')"><i class="bi bi-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center">Tidak ada data transaksi peminjaman.</td>
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
    $('#borrowTable').DataTable({
        "info": true,
        "paging": true,
        "pageLength": 10,
        "lengthChange": true,
        "searching": false, // Pencarian dihandle oleh PHP
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