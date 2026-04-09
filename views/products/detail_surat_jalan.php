<?php
// Menggunakan path relatif yang benar
include 'includes/db_connect.php';
include 'includes/header.php';

// Pastikan pengguna sudah login dan memiliki peran yang diizinkan
if (!isset($_SESSION['user_id'])) {
    header("Location: /wms-geo/login.php");
    exit();
}
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: /wms-geo/index.php");
    exit();
}

// Fungsi pembantu untuk memformat tanggal (dari kode Anda sebelumnya)
function date_indo($date_str) {
    $hari = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
    $bulan = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    $d = new DateTime($date_str);
    $hari_str = $hari[$d->format('w')];
    $bulan_str = $bulan[$d->format('n') - 1];
    return $hari_str . ", " . $d->format('d') . " " . $bulan_str . " " . $d->format('Y') . " " . $d->format('H:i');
}

// Ambil ID atau Nomor Transaksi dari parameter URL
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$transaction_number = isset($_GET['nomor']) ? trim($_GET['nomor']) : '';

$transaction_data = null;
$details_records = [];

// 1. Tentukan kondisi WHERE berdasarkan parameter yang ada
$where_clause = '';
$bind_types = '';
$bind_vars = [];

if ($transaction_id > 0) {
    $where_clause = "ot.id = ?";
    $bind_types = "i";
    $bind_vars[] = &$transaction_id;
} elseif (!empty($transaction_number)) {
    $where_clause = "ot.transaction_number = ?";
    $bind_types = "s";
    $bind_vars[] = &$transaction_number;
} else {
    // Jika tidak ada parameter ID atau Nomor, tampilkan error
    $error_message = "Parameter ID atau Nomor Transaksi tidak valid.";
}

// 2. Query Utama untuk mengambil Detail Surat Jalan (Outbound Transaction)
if ($where_clause) {
    $sql_transaction = "
        SELECT 
            ot.id, 
            ot.transaction_number, 
            ot.created_at, 
            ot.recipient, 
            ot.notes, 
            u.username AS created_by
        FROM outbound_transactions ot
        LEFT JOIN users u ON ot.created_by = u.id
        WHERE $where_clause AND ot.is_deleted = 0
    ";

    $stmt = $conn->prepare($sql_transaction);
    if (!$stmt) {
        $error_message = "Prepare failed: " . $conn->error;
    } else {
        // Bind parameter secara dinamis
        $stmt->bind_param($bind_types, ...$bind_vars);
        $stmt->execute();
        $result = $stmt->get_result();
        $transaction_data = $result->fetch_assoc();
        $stmt->close();

        // 3. Query Detail Produk dan Serial yang terkait
        if ($transaction_data) {
            $sql_details = "
                SELECT 
                    p.product_code,
                    p.product_name,
                    otd.quantity,
                    pt.unit,
                    GROUP_CONCAT(ots.serial_number ORDER BY ots.serial_number ASC) AS serial_numbers_list
                FROM outbound_transaction_details otd
                JOIN products p ON otd.product_id = p.id
                JOIN product_types pt ON p.product_type_id = pt.id
                LEFT JOIN outbound_transaction_serials ots ON otd.id = ots.transaction_detail_id
                WHERE otd.transaction_id = ?
                GROUP BY p.id, otd.quantity, pt.unit
            ";
            $stmt_details = $conn->prepare($sql_details);
            $stmt_details->bind_param("i", $transaction_data['id']);
            $stmt_details->execute();
            $result_details = $stmt_details->get_result();
            $details_records = $result_details->fetch_all(MYSQLI_ASSOC);
            $stmt_details->close();
        } else {
            $error_message = "Transaksi Keluar tidak ditemukan.";
        }
    }
}
?>

<div class="container-fluid my-4">
    <div class="content-header mb-4">
        <h1 class="fw-bold">DETAIL SURAT JALAN / BARANG KELUAR</h1>
        <p class="text-muted">Rincian lengkap transaksi barang keluar.</p>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger text-center"><?= htmlspecialchars($error_message) ?></div>
    <?php elseif ($transaction_data): ?>
        <div class="row g-4">
            <div class="col-md-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-primary text-white fw-bold">
                        Informasi Utama Transaksi
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless table-sm">
                            <tr>
                                <th style="width: 40%;">Nomor Transaksi:</th>
                                <td><span class="badge bg-danger fs-6"><?= htmlspecialchars($transaction_data['transaction_number']) ?></span></td>
                            </tr>
                            <tr>
                                <th>Tanggal & Waktu:</th>
                                <td><?= date_indo($transaction_data['created_at']) ?></td>
                            </tr>
                            <tr>
                                <th>Penerima (Recipient):</th>
                                <td><?= htmlspecialchars($transaction_data['recipient']) ?></td>
                            </tr>
                            <tr>
                                <th>Dibuat Oleh:</th>
                                <td><?= htmlspecialchars($transaction_data['created_by']) ?></td>
                            </tr>
                            <tr>
                                <th>Catatan:</th>
                                <td><?= htmlspecialchars($transaction_data['notes'] ?: '-') ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white fw-bold">
                        Daftar Produk yang Keluar
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Kode Produk</th>
                                        <th>Nama Produk</th>
                                        <th class="text-end">Kuantitas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_quantity = 0;
                                    foreach ($details_records as $detail): 
                                        $total_quantity += $detail['quantity'];
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($detail['product_code']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($detail['product_name']) ?>
                                            <?php if (!empty($detail['serial_numbers_list'])): ?>
                                                <small class="d-block text-muted mt-1 fst-italic">
                                                    Serial: <?= implode(', ', array_map('trim', explode(',', $detail['serial_numbers_list']))) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold text-danger">
                                            <?= number_format($detail['quantity'], 0, ',', '.') ?> <?= htmlspecialchars($detail['unit']) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="2" class="text-end">TOTAL KELUAR:</th>
                                        <th class="text-end text-danger display-6">
                                            <?= number_format($total_quantity, 0, ',', '.') ?>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
    <a href="/wms-geo/views/outbound/index.php" class="btn btn-secondary mt-3"><i class="bi bi-arrow-left me-2"></i>Kembali ke Daftar Barang Keluar</a>
    <button type="button" class="btn btn-info mt-3"><i class="bi bi-printer me-2"></i>Cetak Surat Jalan</button>
</div>

<?php include 'includes/footer.php'; ?>

<style>
    .display-6 { font-size: 2rem; }
</style>