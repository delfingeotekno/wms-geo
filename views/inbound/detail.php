<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$transaction_id = $_GET['id'] ?? 0;

// 1. Ambil data transaksi dari database.
// Termasuk 'transaction_type', 'reference', dan 'creator_name'.
$transaction_query = "SELECT t.*, u.name AS creator_name
FROM inbound_transactions t
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

$details_query = "SELECT td.*, p.product_code, p.product_name, p.has_serial
FROM inbound_transaction_details td
JOIN products p ON td.product_id = p.id
WHERE td.transaction_id = ?";

$stmt_details = $conn->prepare($details_query);
$stmt_details->bind_param("i", $transaction_id);
$stmt_details->execute();
$details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();

// 2. Tentukan label berdasarkan data yang sudah diambil.
$transaction_label = "No. Transaksi";
$document_label = "Nomor Dokumen";
if ($transaction['transaction_type'] === 'RI') {
    $transaction_label = "RI No";
    $document_label = "PO No";
} elseif ($transaction['transaction_type'] === 'RET') {
    $transaction_label = "RET No";
    $document_label = "DN No";
}

?>

<style>
/* CSS tambahan untuk menggabungkan garis tabel */
.table-bordered th,
.table-bordered td {
    border-color: #dee2e6 !important;
}

.table.table-bordered thead th {
    border-bottom: 2px solid #dee2e6 !important;
}
</style>

<div class="content-header mb-4">
    <h1 class="fw-bold">Detail Transaksi</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Transaksi #<?= htmlspecialchars($transaction['transaction_number']) ?></h5>
        <div>
            <a href="download_pdf.php?id=<?= $transaction['id'] ?>" class="btn btn-primary btn-sm rounded-pill px-3 me-2">
                <i class="bi bi-file-earmark-pdf me-2"></i>Unduh PDF
            </a>
            <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-2"></i>Kembali
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-6">
                <strong><?= htmlspecialchars($transaction_label) ?>: </strong><?= htmlspecialchars($transaction['transaction_number']) ?><br>
                <strong>Tipe Transaksi:</strong><?= htmlspecialchars($transaction['transaction_type']) ?><br>
                <strong><?= htmlspecialchars($document_label) ?>: </strong><?= htmlspecialchars($transaction['document_number']) ?><br>
                <strong>Tanggal: </strong><?= date('d-m-Y', strtotime($transaction['transaction_date'])) ?><br>
                <strong>From: </strong><?= htmlspecialchars($transaction['sender']) ?>
            </div>
            <div class="col-md-6">
                <strong>Dibuat Oleh: </strong><?= htmlspecialchars($transaction['creator_name']) ?><br>
                <strong>Ref: </strong><?= htmlspecialchars($transaction['reference']) ?><br>
                <strong>Total Item: </strong><?= htmlspecialchars($transaction['total_items']) ?><br>
                <strong>Catatan: </strong><?= nl2br(htmlspecialchars($transaction['notes'])) ?><br>
            </div>
        </div>

        <?php if (!empty($transaction['proof_photo_path'])): ?>
            <hr class="my-4">
            <h5 class="fw-bold mt-4">Foto Transaksi</h5>
            <?php
                $imagePath = '../../uploads/inbound_photos/' . $transaction['proof_photo_path'];
                if (file_exists($imagePath)):
            ?>
                <div class="mb-3">
                    <img src="<?= htmlspecialchars($imagePath) ?>" class="img-fluid rounded shadow-sm" alt="Foto Transaksi" style="max-height: 200px; max-width: 100%; object-fit: cover;">
                </div>
                <a href="<?= htmlspecialchars($imagePath) ?>" download class="btn btn-success btn-sm rounded-pill px-3">
                    <i class="bi bi-download me-2"></i>Unduh Gambar
                </a>
            <?php else: ?>
                <div class="alert alert-warning" role="alert">
                    <strong>Peringatan:</strong> File gambar tidak ditemukan di server. Pastikan file "<?= htmlspecialchars($transaction['proof_photo_path']) ?>" ada di folder `uploads/inbound_photos`.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <hr class="my-4">
            <div class="alert alert-info" role="alert">
                Tidak ada foto transaksi yang tersedia.
            </div>
        <?php endif; ?>

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
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>