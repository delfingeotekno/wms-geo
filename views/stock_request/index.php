<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Validasi Role (Gudang, Admin, Procurement)
if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
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
    $warehouse_filter_sql = "AND sr.warehouse_id = $filter_warehouse_id";
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

$sql_filter = "WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $sql_filter .= " AND (sr.request_number LIKE ? OR u.name LIKE ?)";
    $search_term = "%" . $search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

if (!empty($status_filter)) {
    $sql_filter .= " AND sr.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$sql = "SELECT 
            sr.*,
            u.name AS requester_name,
            w.name AS warehouse_name,
            SUM(srd.requested_qty) AS total_items,
            CASE
                WHEN sr.status = 'Pending' THEN 'bg-warning text-dark'
                WHEN sr.status = 'Approved' THEN 'bg-success'
                WHEN sr.status = 'Rejected' THEN 'bg-danger'
                ELSE 'bg-info text-dark'
            END AS badge_class
        FROM stock_requests sr
        JOIN users u ON sr.user_id = u.id
        LEFT JOIN warehouses w ON sr.warehouse_id = w.id
        LEFT JOIN stock_request_details srd ON sr.id = srd.stock_request_id
        $sql_filter $warehouse_filter_sql
        GROUP BY sr.id
        ORDER BY sr.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$requests = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Daftar Stock Request</h1>
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
        <h5 class="mb-0 fw-bold text-dark">Data Pengajuan</h5>
        <?php if (in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])): ?>
        <a href="create.php" class="btn btn-primary btn-sm rounded-pill px-3">
            <i class="bi bi-plus-lg me-2"></i>Buat Request
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-8 d-flex gap-2">
                <form action="index.php" method="GET" class="input-group" style="max-width: 400px;">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                    <input type="text" class="form-control" name="search" placeholder="Cari No. Request atau Pemohon..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-outline-secondary" type="submit">
                        <i class="bi bi-search"></i>
                    </button>
                </form>

                <!-- Filter Status -->
                <form action="index.php" method="GET" class="d-flex gap-1">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <?php endif; ?>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="max-width: 150px;">
                        <option value="">-- Semua Status --</option>

                        <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved" <?= $status_filter == 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Rejected" <?= $status_filter == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="requestTable">
                <thead>
                    <tr class="table-secondary">
                        <th>No</th>
                        <th>No. Request</th>
                        <th>Subjek</th>
                        <th>Tanggal Pengajuan</th>
                        <th>ETA Supply</th>
                        <?php if ($active_tab === 'all'): ?>
                        <th>Gudang</th>
                        <?php endif; ?>
                        <th>Pemohon</th>
                        <th>Total Item Qty</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($requests) > 0): ?>
                        <?php foreach ($requests as $i => $req): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($req['request_number']) ?></td>
                                <td><?= htmlspecialchars($req['subject'] ?? '-') ?></td>
                                <td><?= date('d-m-Y', strtotime($req['created_at'])) ?></td>
                                <td><?= date('d-m-Y', strtotime($req['eta_supply'])) ?></td>
                                <?php if ($active_tab === 'all'): ?>
                                <td><?= htmlspecialchars($req['warehouse_name'] ?? '-') ?></td>
                                <?php endif; ?>
                                <td><?= htmlspecialchars($req['requester_name']) ?></td>
                                <td><?= htmlspecialchars($req['total_items'] ?? 0) ?></td>
                                <td>
                                    <span class="badge <?= htmlspecialchars($req['badge_class']) ?>">
                                        <?= htmlspecialchars($req['status']) ?>
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <a href="detail.php?id=<?= $req['id'] ?>" class="btn btn-info btn-sm text-white me-1" title="Detail"><i class="bi bi-eye"></i></a>
                                    
                                    <?php if (in_array($req['status'], ['Draft', 'Pending']) && in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])): ?>
                                        <a href="edit.php?id=<?= $req['id'] ?>" class="btn btn-warning btn-sm me-1" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <a href="delete.php?id=<?= $req['id'] ?>" class="btn btn-danger btn-sm me-1" title="Hapus" onclick="return confirm('Hapus request ini?')"><i class="bi bi-trash"></i></a>
                                    <?php endif; ?>
                                    
                                    <?php if ($req['status'] == 'Pending' && ($_SESSION['role'] == 'procurement' || $_SESSION['role'] == 'admin')): ?>
                                        <a href="review_direct.php?action=Approve&id=<?= $req['id'] ?>" class="btn btn-success btn-sm me-1" title="Approve" onclick="return confirm('Setujui request ini?')"><i class="bi bi-check-lg"></i></a>
                                        <a href="review_direct.php?action=Reject&id=<?= $req['id'] ?>" class="btn btn-danger btn-sm me-1" title="Reject" onclick="return confirm('Tolak request ini?')"><i class="bi bi-x-lg"></i></a>
                                    <?php endif; ?>
                                    
                                    <?php if ($req['status'] == 'Approved' && $_SESSION['role'] == 'admin'): ?>
                                        <a href="review_direct.php?action=Reset_Pending&id=<?= $req['id'] ?>" class="btn btn-warning btn-sm text-dark me-1" title="Reset ke Pending" onclick="return confirm('Kembalikan ke status Pending?')"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center">Tidak ada data stock request.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<!-- Script custom jika butuh DataTables config -->
<script>
$(document).ready(function() {
    $('#requestTable').DataTable({
        "info": true,
        "paging": true,
        "pageLength": 10,
        "lengthChange": true,
        "searching": false, 
        "ordering": true,
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json"
        }
    });
});
</script>
