<?php 
include '../../includes/db_connect.php'; 
include '../../includes/header.php'; 

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

// Logika Pencarian
$user_role = $_SESSION['role'] ?? 'staff';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .action-gap {
        display: flex;
        gap: 5px;
        justify-content: center;
        align-items: center;
    }

    .btn-action {
        width: 34px;
        height: 34px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px !important;
        transition: all 0.2s;
        background-color: #fff;
        border: 1px solid #dee2e6;
        color: #6c757d;
        text-decoration: none;
    }

    .btn-action:hover {
        background-color: #f8f9fa;
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    .btn-approve-modern {
        width: auto !important;
        padding: 0 12px;
        background-color: #198754 !important;
        color: white !important;
        border: none;
        font-size: 12px;
        font-weight: 600;
        height: 34px;
    }

    #poTable thead th {
        background-color: #f8f9fa;
        text-transform: uppercase;
        font-size: 11px;
        color: #555;
        padding: 15px 10px;
        border-bottom: 2px solid #eee;
    }
    
    .po-number-link {
        color: #0d6efd;
        text-decoration: none;
        font-weight: bold;
    }

    .search-container .input-group {
        background: #fff;
        border-radius: 50px;
        overflow: hidden;
        border: 1px solid #dee2e6;
    }
    .search-input {
        border: none !important;
        box-shadow: none !important;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h3 mb-0 text-gray-800 fw-bold">Data Purchase Order</h2>
        <p class="text-muted small">Kelola pesanan pembelian barang ke vendor</p>
    </div>
    <a href="create.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
        <i class="bi bi-plus-lg me-2"></i>Buat PO Baru
    </a>
</div>

<div class="row g-3 mb-4 align-items-center">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 rounded-4 p-3 bg-primary text-white h-100">
            <div class="d-flex align-items-center">
                <i class="bi bi-file-earmark-text fs-2 opacity-50 me-3"></i>
                <div>
                    <?php $total_po = $conn->query("SELECT COUNT(id) as total FROM purchase_orders")->fetch_assoc()['total']; ?>
                    <h4 class="mb-0 fw-bold"><?= $total_po ?></h4>
                    <span class="small">Total PO</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 rounded-4 p-3 bg-warning text-dark h-100">
            <div class="d-flex align-items-center">
                <i class="bi bi-clock-history fs-2 opacity-50 me-3"></i>
                <div>
                    <?php $pending_po = $conn->query("SELECT COUNT(id) as total FROM purchase_orders WHERE status = 'Pending'")->fetch_assoc()['total']; ?>
                    <h4 class="mb-0 fw-bold"><?= $pending_po ?></h4>
                    <span class="small">Pending</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 search-container">
        <form action="" method="GET">
            <div class="input-group shadow-sm">
                <span class="input-group-text bg-white border-0 ps-3">
                    <i class="bi bi-search text-muted"></i>
                </span>
                <input type="text" name="search" class="form-control search-input py-2" 
                        placeholder="Cari nomor PO atau nama vendor..." 
                        value="<?= htmlspecialchars($search) ?>">
                <?php if($search !== ''): ?>
                    <a href="index.php" class="btn btn-link text-decoration-none text-muted border-0">
                        <i class="bi bi-x-circle-fill"></i>
                    </a>
                <?php endif; ?>
                <button class="btn btn-primary px-4 ms-2" type="submit">Cari</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="poTable">
                <thead>
                    <tr>
                        <th class="ps-4">No. PO</th>
                        <th>Tanggal</th>
                        <th>Vendor</th>
                        <th>Grand Total</th>
                        <th>Status PO</th>
                        <th>Status RI</th> <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Query efisien dengan pemilihan kolom spesifik dan subquery RI
                    $sql = "SELECT 
                                po.id, po.po_number, po.po_date, po.pic_name, po.grand_total, po.status, 
                                v.vendor_name, v.currency,
                                (SELECT COUNT(1) FROM inbound_transactions it WHERE it.document_number COLLATE utf8mb4_unicode_ci = po.po_number COLLATE utf8mb4_unicode_ci) as ri_exists
                            FROM purchase_orders po 
                            JOIN vendors v ON po.vendor_id = v.id
                            WHERE 1=1";

                    if ($search !== '') {
                        // Menambahkan filter untuk vendor_name ATAU po_number
                        $sql .= " AND (v.vendor_name LIKE '%$search%' OR po.po_number LIKE '%$search%')";
                    }
                    
                    $sql .= " ORDER BY po.id DESC";
                    $result = $conn->query($sql);

                    if ($result && $result->num_rows > 0):
                        while($row = $result->fetch_assoc()):
                            $status_class = ($row['status'] == 'Pending') ? 'bg-warning text-dark' : (($row['status'] == 'Completed') ? 'bg-success text-white' : 'bg-danger text-white');
                            
                            // Logika Warna Status RI
                            $ri_status_class = ($row['ri_exists'] > 0) ? 'bg-info text-white' : 'bg-secondary text-white';
                            $ri_text = ($row['ri_exists'] > 0) ? 'RI DIBUAT' : 'BELUM RI';
                    ?>
                    <tr>
                        <td class="ps-4">
                            <a href="detail.php?id=<?= $row['id'] ?>" class="po-number-link"><?= $row['po_number'] ?></a>
                            <div class="text-muted small" style="font-size: 10px;">PIC: <?= htmlspecialchars($row['pic_name']) ?></div>
                        </td>
                        <td class="small"><?= date('d M Y', strtotime($row['po_date'])) ?></td>
                        <td class="small fw-bold text-dark"><?= htmlspecialchars($row['vendor_name']) ?></td>
                        <td>
                            <span class="text-muted small"><?= $row['currency'] ?></span>
                            <span class="fw-bold"><?= number_format($row['grand_total'], 0, ',', '.') ?></span>
                        </td>
                        <td>
                            <span class="badge rounded-pill <?= $status_class ?> px-3 py-2" style="font-size: 10px;">
                                <?= strtoupper($row['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge rounded-pill <?= $ri_status_class ?> px-3 py-2" style="font-size: 10px;">
                                <?= $ri_text ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-gap">
                                <a href="detail.php?id=<?= $row['id'] ?>" class="btn-action" title="Lihat">
                                    <i class="bi bi-eye text-info"></i>
                                </a>

                                <?php if($row['status'] == 'Pending'): ?>
                                    <a href="edit.php?id=<?= $row['id'] ?>" class="btn-action" title="Edit">
                                        <i class="bi bi-pencil-square text-primary"></i>
                                    </a>

                                    <?php if ($user_role == 'admin'): ?>
                                    <button class="btn-action btn-approve-modern shadow-sm" onclick="confirmApprove(<?= $row['id'] ?>)">
                                        <i class="bi bi-check-circle-fill me-1"></i> Approve
                                    </button>
                                    <?php endif; ?>

                                    <button class="btn-action" onclick="confirmCancel(<?= $row['id'] ?>)" title="Batalkan">
                                        <i class="bi bi-x-lg text-danger"></i>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if($row['status'] == 'Completed' && $user_role == 'admin'): ?>
                                    <button class="btn-action border-danger" onclick="confirmUnapprove(<?= $row['id'] ?>)" title="Batal Approve (Kembalikan ke Pending)">
                                        <i class="bi bi-arrow-counterclockwise text-danger"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <div class="mb-3">
                                <i class="bi bi-search fs-1 opacity-25"></i>
                            </div>
                            <p class="mb-0">Tidak ditemukan data Purchase Order.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function confirmApprove(id) {
    Swal.fire({
        title: 'Setujui Purchase Order?',
        text: "Pastikan data pesanan sudah diperiksa dengan benar.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Setujui!',
        cancelButtonText: 'Batal',
        reverseButtons: true,
        customClass: { popup: 'rounded-4' }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'process_po.php?action=approve&id=' + id;
        }
    })
}

function confirmCancel(id) {
    Swal.fire({
        title: 'Batalkan Purchase Order?',
        text: "Tindakan ini tidak dapat dibatalkan!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Batalkan PO',
        cancelButtonText: 'Tutup',
        reverseButtons: true,
        customClass: { popup: 'rounded-4' }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'process_po.php?action=cancel&id=' + id;
        }
    })
}

function confirmUnapprove(id) {
    Swal.fire({
        title: 'Batalkan Approval?',
        text: "Status PO akan kembali menjadi PENDING.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Kembalikan ke Pending',
        cancelButtonText: 'Tutup',
        reverseButtons: true,
        customClass: { popup: 'rounded-4' }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'process_po.php?action=unapprove&id=' + id;
        }
    })
}

<?php if (isset($_GET['msg'])): ?>
    const msg = "<?= $_GET['msg'] ?>";
    let icon = 'success';
    let title = 'Berhasil!';
    let text = '';

    if(msg === 'po_approved') text = "Purchase Order berhasil disetujui.";
    else if(msg === 'po_cancelled') { text = "Purchase Order telah dibatalkan."; icon = 'info'; title = 'Dibatalkan'; }
    else if(msg === 'po_created') text = "Purchase Order baru berhasil dibuat.";
    else if(msg === 'po_unapproved') { text = "Status PO berhasil dikembalikan ke Pending."; icon = 'success'; }
    
    if(text !== "") {
        Swal.fire({
            icon: icon, title: title, text: text,
            showConfirmButton: false, timer: 2500,
            customClass: { popup: 'rounded-4' }
        });
    }
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>