<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}


$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transaction_id == 0) {
    header("Location: index.php");
    exit();
}

// 1. Ambil data Transaksi
$transaction_query = "SELECT 
    t.*,
    u.name AS creator_name
FROM outbound_transactions t
JOIN users u ON t.created_by = u.id
WHERE t.id = ? AND t.is_deleted = 0";

$stmt = $conn->prepare($transaction_query);
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transaction) {
    header("Location: index.php");
    exit();
}

// 2. Cek apakah Packing Slip sudah dibuat untuk transaksi ini
$ps_query = "SELECT ps.id, ps.packing_slip_number 
             FROM packing_slips ps 
             JOIN outbound_transactions ot ON ps.transaction_id = ot.id
             WHERE ps.transaction_id = ?";
$stmt_ps = $conn->prepare($ps_query);
$stmt_ps->bind_param("i", $transaction_id);
$stmt_ps->execute();
$packing_slip_data = $stmt_ps->get_result()->fetch_assoc();
$stmt_ps->close();

$has_packing_slip = !empty($packing_slip_data);
$ps_id = $packing_slip_data['id'] ?? null;
$ps_number = $packing_slip_data['packing_slip_number'] ?? null;

// 3. Ambil Detail Produk Transaksi
$details_query = "SELECT 
    td.*,
    p.product_code,
    p.product_name,
    p.has_serial
FROM outbound_transaction_details td
JOIN products p ON td.product_id = p.id
JOIN outbound_transactions ot ON td.transaction_id = ot.id
WHERE td.transaction_id = ?";

$stmt_details = $conn->prepare($details_query);
$stmt_details->bind_param("i", $transaction_id);
$stmt_details->execute();
$details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();

// Path relatif ke foto
$photo_path = $transaction['photo_url'] ? '../../uploads/outbound_photos/' . htmlspecialchars($transaction['photo_url']) : null;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Transaksi - WMS Geo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" xintegrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/wms-geo/assets/css/style.css">
</head>
<body>

<div class="container-fluid my-4">
    <div class="content-header mb-4">
        <h1 class="fw-bold">Detail Transaksi</h1>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark">Transaksi #<?= htmlspecialchars($transaction['transaction_number']) ?></h5>
            <div>
                <?php if ($has_packing_slip): ?>
                    <a href="create_packing_slip.php?transaction_id=<?= $transaction['id'] ?>" class="btn btn-warning btn-sm rounded-pill px-3 me-2">
                        <i class="bi bi-pencil-square me-2"></i>Review/Ubah Packing Slip
                    </a>
                    <a href="generate_packing_slip_pdf.php?id=<?= $ps_id ?>" target="_blank" class="btn btn-success btn-sm rounded-pill px-3 me-2">
                        <i class="bi bi-download me-2"></i>Unduh Packing Slip (<?= htmlspecialchars($ps_number) ?>)
                    </a>
                <?php else: ?>
                    <a href="create_packing_slip.php?transaction_id=<?= $transaction['id'] ?>" class="btn btn-info btn-sm rounded-pill px-3 me-2">
                        <i class="bi bi-box me-2"></i>Buat Packing Slip
                    </a>
                <?php endif; ?>
                
                <a href="generate_pdf_bast.php?id=<?= $transaction['id'] ?>" target="_blank" class="btn btn-warning btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-file-earmark-text me-2"></i>Unduh BAST
                </a>
                <a href="download_pdf.php?id=<?= $transaction['id'] ?>" target="_blank" class="btn btn-primary btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-file-earmark-pdf me-2"></i>Unduh Surat Jalan
                </a>
                <a href="generate_qc_pdf.php?id=<?= $transaction['id'] ?>" target="_blank" class="btn btn-dark btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-patch-check me-2"></i>Unduh QC
                </a>
                <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-2"></i>Kembali
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>No. Transaksi:</strong> <?= htmlspecialchars($transaction['transaction_number']) ?><br>
                    <strong>Tanggal:</strong> <?= date('d-m-Y', strtotime($transaction['transaction_date'])) ?><br>
                    <strong>Penerima:</strong> <?= htmlspecialchars($transaction['recipient']) ?><br>
                    <strong>Subjek:</strong> <?= htmlspecialchars($transaction['subject']) ?><br>
                </div>
                <div class="col-md-6">
                    <strong>Dibuat Oleh:</strong> <?= htmlspecialchars($transaction['creator_name']) ?><br>
                    <strong>PO Number:</strong> <?= htmlspecialchars($transaction['po_number']) ?: '-' ?><br>
                    <strong>Reference:</strong> <?= htmlspecialchars($transaction['reference']) ?: '-' ?><br>
                    <strong>Total Item:</strong> <?= htmlspecialchars($transaction['total_items']) ?><br>
                    <strong>Catatan:</strong> <?= nl2br(htmlspecialchars($transaction['notes'])) ?>
                </div>
            </div>

            <hr>

            <h5 class="fw-bold mt-4">Foto Bukti Transaksi</h5>
            <?php if ($photo_path): ?>
                <div class="card p-3 mb-4 bg-light text-center">
                    <img src="<?= $photo_path ?>" class="img-fluid rounded shadow-sm mx-auto d-block" alt="Foto Bukti Transaksi" style="max-width: 400px; max-height: 400px; object-fit: contain;">
                    <div class="mt-3">
                        <a href="<?= $photo_path ?>" download="<?= htmlspecialchars($transaction['transaction_number']) ?>_bukti.jpg" class="btn btn-success rounded-pill px-4">
                            <i class="bi bi-download me-2"></i>Unduh Foto
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info text-center" role="alert">
                    Tidak ada foto bukti transaksi yang tersedia.
                </div>
            <?php endif; ?>
            <hr>

            <h5 class="fw-bold mt-4">Daftar Produk</h5>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr class="table-secondary">
                            <th>No</th>
                            <th>Kode Produk</th>
                            <th>Nama Produk</th>
                            <th>Kuantitas</th>
                            <th>Serial Number</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; ?>
                        <?php foreach ($details as $detail): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= htmlspecialchars($detail['product_code']) ?></td>
                                <td><?= htmlspecialchars($detail['product_name']) ?></td>
                                <td><?= htmlspecialchars($detail['quantity']) ?></td>
                                <td><?= $detail['serial_number'] ? htmlspecialchars($detail['serial_number']) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($details)): ?>
                            <tr>
                                <td colspan="5" class="text-center">Tidak ada detail produk untuk transaksi ini.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" xintegrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<?php include '../../includes/footer.php'; ?>