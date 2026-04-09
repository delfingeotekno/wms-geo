<?php
$page_title = "Detail Surat Jalan Transfer";
include '../../includes/header.php';

if (!isset($_GET['id'])) {
    echo "<script>window.location='index.php';</script>";
    exit;
}

$transfer_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// Get Header
$sql = "SELECT t.*, w1.name as source_name, w2.name as dest_name, u1.username as creator_name, u2.username as receiver_name
        FROM transfers t
        JOIN warehouses w1 ON t.source_warehouse_id = w1.id
        JOIN warehouses w2 ON t.destination_warehouse_id = w2.id
        JOIN users u1 ON t.created_by = u1.id
        LEFT JOIN users u2 ON t.received_by = u2.id
        WHERE t.id = $transfer_id";
$res = $conn->query($sql);

if ($res->num_rows === 0) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Data tidak ditemukan.</div></div>";
    include '../../includes/footer.php';
    exit;
}

$trf = $res->fetch_assoc();

// Cek hak akses detail (harus Admin, atau berada di source/dest warehouse)
if ($_SESSION['warehouse_id'] != 0 && $_SESSION['warehouse_id'] != $trf['source_warehouse_id'] && $_SESSION['warehouse_id'] != $trf['destination_warehouse_id']) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Akses ditolak ke dokumen gudang lain.</div></div>";
    include '../../includes/footer.php';
    exit;
}

// Get Items
$sql_items = "SELECT ti.*, p.product_name, p.product_code 
              FROM transfer_items ti
              JOIN products p ON ti.product_id = p.id
              WHERE ti.transfer_id = $transfer_id";
$res_items = $conn->query($sql_items);
$items = [];
while ($itm = $res_items->fetch_assoc()) {
    // Get Serials for this item if any
    $ti_id = $itm['id'];
    $sql_sn = "SELECT serial_number FROM transfer_serials WHERE transfer_item_id = $ti_id";
    $res_sn = $conn->query($sql_sn);
    $sns = [];
    while ($sn = $res_sn->fetch_assoc()) {
        $sns[] = $sn['serial_number'];
    }
    $itm['serials'] = $sns;
    $items[] = $itm;
}

// Bisakah user menerima ini?
$can_receive = false;
if ($trf['status'] === 'In Transit' && ($_SESSION['role'] === 'admin' || $_SESSION['warehouse_id'] == $trf['destination_warehouse_id'] || $_SESSION['warehouse_id'] == 0)) {
    $can_receive = true;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Transfer Gudang</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= $trf['transfer_no'] ?></li>
                </ol>
            </nav>
            <h2 class="h4 fw-bold mb-0">Detail Mutasi: <?= $trf['transfer_no'] ?></h2>
        </div>
        <div class="d-flex gap-2">
            <a href="download_pdf.php?id=<?= $transfer_id ?>" target="_blank" class="btn btn-primary shadow-sm fw-bold">
                <i class="bi bi-file-pdf me-1"></i> Unduh PDF TAG
            </a>
            <?php if ($can_receive): ?>
                <form action="receive_process.php" method="POST" onsubmit="return confirm('Apakah Anda yakin barang telah diterima dan sesuai? Stok gudang Anda akan ditambahkan.')">
                    <input type="hidden" name="transfer_id" value="<?= $transfer_id ?>">
                    <button type="submit" class="btn btn-success shadow-sm fw-bold">
                        <i class="bi bi-check2-all me-1"></i> Konfirmasi Penerimaan
                    </button>
                </form>
            <?php endif; ?>
            <a href="index.php" class="btn btn-light shadow-sm border"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
        </div>
    </div>

    <!-- Error/Sukses Messages -->
    <?php if (isset($_GET['status'])): ?>
        <?php if ($_GET['status'] == 'received'): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> Mutasi stok berhasil diterima dan ditambahkan ke inventaris.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($_GET['status'] == 'error'): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> Terjadi kesalahan saat memproses penerimaan.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Informasi Surat Jalan -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                    <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-info-circle me-2"></i>Informasi Pengiriman</h6>
                </div>
                <div class="card-body">
                    <table class="table table-borderless table-sm mb-0">
                        <tbody>
                            <tr>
                                <td width="40%" class="text-muted small">Status</td>
                                <td>
                                    <?php if($trf['status'] == 'In Transit'): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning px-2 fw-normal">Di Perjalanan</span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success border border-success px-2 fw-normal">Selesai (Diterima)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted small">Gudang Asal</td>
                                <td class="fw-bold text-dark"><?= $trf['source_name'] ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small">Gudang Tujuan</td>
                                <td class="fw-bold text-dark"><?= $trf['dest_name'] ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small">Dikirim Oleh</td>
                                <td class="text-dark"><?= $trf['creator_name'] ?> <br><small class="text-muted"><?= date('d M Y H:i', strtotime($trf['created_at'])) ?></small></td>
                            </tr>
                            <tr>
                                <td class="text-muted small">Kurir/Resi</td>
                                <td class="text-dark fw-medium"><?= $trf['courier_info'] ?: '-' ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small">Catatan</td>
                                <td class="text-muted fst-italic"><?= $trf['notes'] ?: '-' ?></td>
                            </tr>
                            <?php if($trf['status'] == 'Completed'): ?>
                            <tr>
                                <td colspan="2"><hr class="my-2 border-dashed"></td>
                            </tr>
                            <tr>
                                <td class="text-muted small">Diterima Oleh</td>
                                <td class="text-success fw-bold"><?= $trf['receiver_name'] ?> <br><small class="text-muted"><?= date('d M Y H:i', strtotime($trf['received_at'])) ?></small></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php if ($can_receive): ?>
                <div class="alert alert-info border-0 shadow-sm d-flex align-items-center">
                    <i class="bi bi-info-circle-fill fs-3 me-3 text-info"></i>
                    <div class="small">
                        Gudang Anda adalah pihak penerima untuk surat jalan ini. Silakan cek kondisi fisik barang, dan tekan tombol <strong>Konfirmasi Terima</strong> di atas.
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Rincian Barang -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-box-seam me-2"></i>Daftar Barang Mutasi</h6>
                    <span class="badge bg-light text-dark border"><?= count($items) ?> Jenis Produk</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-3 py-3">Produk</th>
                                    <th class="text-center">Kuantitas</th>
                                    <th>Serial Numbers</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($item['product_name']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($item['product_code']) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary-subtle text-primary border border-primary px-3 fs-6 rounded-pill">
                                            <?= $item['quantity'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (count($item['serials']) > 0): ?>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach($item['serials'] as $sn): ?>
                                                    <span class="badge bg-light text-dark border border-secondary shadow-sm" style="font-family: monospace; font-size: 0.75rem;">
                                                        <i class="bi bi-upc me-1"></i><?= htmlspecialchars($sn) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
