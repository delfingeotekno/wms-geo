<?php
// Pastikan file koneksi dan header disertakan
include '../../includes/db_connect.php';
include '../../includes/header.php';

// --- TAMPILKAN PESAN DARI PROCESS FILE ---
if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-danger container my-3">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
    unset($_SESSION['error_message']); // Hapus pesan setelah ditampilkan
}
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success container my-3">' . htmlspecialchars($_SESSION['success_message']) . '</div>';
    unset($_SESSION['success_message']); 
}

// Cek otentikasi dan role pengguna
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Ambil ID Transaksi Keluar dari URL
$transaction_id = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;
if ($transaction_id == 0) {
    header("Location: index.php");
    exit();
}

$transaction = null;
$details = [];
$packing_slip_data = null; // Variabel untuk menyimpan data PS jika sudah ada
$is_edit_mode = false; // Flag untuk menentukan mode

// 1. Query untuk mengambil data Transaksi Keluar (Header)
$transaction_query = "SELECT 
    t.id, 
    t.transaction_number,
    t.reference,
    t.recipient, 
    t.subject,   
    t.transaction_date,
    t.notes 
FROM outbound_transactions t
WHERE t.id = ? AND t.is_deleted = 0";

$stmt = $conn->prepare($transaction_query);
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transaction) {
    die('<div class="alert alert-danger">Error: Data Transaksi Keluar tidak ditemukan atau ID tidak valid.</div>');
}

// --- LOGIKA Pengecekan Packing Slip yang Sudah Ada ---
$ps_check_query = "SELECT * FROM packing_slips WHERE transaction_id = ?";
$stmt_ps_check = $conn->prepare($ps_check_query);
$stmt_ps_check->bind_param("i", $transaction_id);
$stmt_ps_check->execute();
$packing_slip_data = $stmt_ps_check->get_result()->fetch_assoc();
$stmt_ps_check->close();

if ($packing_slip_data) {
    // KONDISI 1: PACKING SLIP SUDAH ADA (Review/Update Mode)
    $is_edit_mode = true;
    // Gunakan data yang tersimpan sebagai nilai default form
    $default_packing_slip_number = $packing_slip_data['packing_slip_number'];
    $default_date = $packing_slip_data['packing_slip_date'];
    $default_koli = $packing_slip_data['koli_count'];
    $default_recipient = $packing_slip_data['recipient'];
    $default_subject = $packing_slip_data['subject'];
    $default_location = $packing_slip_data['location'];
    $default_notes = $packing_slip_data['notes'];
    $form_action = "process_packing_slip.php?action=update";

} else {
    // KONDISI 2: PACKING SLIP BELUM ADA (Create Mode)
    
    // === LOGIKA PENOMORAN PACKING SLIP (Generasi Otomatis) ===
    $last_ps_query = "SELECT packing_slip_number FROM packing_slips ORDER BY id DESC LIMIT 1";
    $last_ps_result = $conn->query($last_ps_query);
    $last_number = null;

    if ($last_ps_result && $last_ps_result->num_rows > 0) {
        $last_ps_row = $last_ps_result->fetch_assoc();
        $last_number = $last_ps_row['packing_slip_number'];
    }

    if ($last_number && preg_match('/(\d+)\//', $last_number, $matches)) {
        $last_sequence = (int)$matches[1];
        $next_sequence = $last_sequence + 1;
    } else {
        $next_sequence = 168; // Angka awal jika belum ada data
    }

    $next_sequence_str = str_pad($next_sequence, 4, '0', STR_PAD_LEFT);

    $roman_month = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 
        7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'
    ];
    $current_month_roman = $roman_month[(int)date('m')];
    $current_year = date('Y');
    $site_code = 'GTG-JKT'; 

    $default_packing_slip_number = "{$next_sequence_str}/PS/{$site_code}/{$current_month_roman}/{$current_year}";
    
    // Nilai Default yang KOSONG atau Diisi Manual
    $default_date = date('Y-m-d');
    $default_koli = '';           
    $default_recipient = '';      
    $default_subject = '';        
    $default_location = '';       
    $default_notes = '';          
    $form_action = "process_packing_slip.php?action=create";
}


// 2. Query untuk mengambil Detail Produk Transaksi dan Serial Number (SN)
$details_query = "
SELECT 
    SUM(td.quantity) AS quantity,
    p.id AS product_id,
    p.product_code,
    p.product_name,
    p.unit,
    GROUP_CONCAT(td.serial_number SEPARATOR '\n') AS serial_numbers 
FROM outbound_transaction_details td
JOIN products p ON td.product_id = p.id
WHERE td.transaction_id = ?
GROUP BY p.id, p.product_code, p.product_name, p.unit
";

$stmt_details = $conn->prepare($details_query);
$stmt_details->bind_param("i", $transaction_id);
$stmt_details->execute();
$details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_edit_mode ? 'Review/Ubah' : 'Buat' ?> Packing Slip - Transaksi #<?= htmlspecialchars($transaction['transaction_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/wms-geo/assets/css/style.css"> 
    <style>
        .required-field::after {
            content: " *";
            color: red;
        }
        .serial-list {
            font-size: 0.75rem;
            color: #495057;
            white-space: pre-wrap; 
            max-height: 100px;
            overflow-y: auto;
        }
        /* Style untuk mode review/read-only */
        .form-control[readonly] {
            background-color: #e9ecef !important;
            opacity: 1;
        }
    </style>
</head>
<body>

<div class="container my-4">
    <div class="content-header mb-4 d-flex justify-content-between align-items-center">
        <h1 class="fw-bold"><?= $is_edit_mode ? 'Review & Ubah' : 'Buat' ?> Packing Slip</h1>
        <a href="detail.php?id=<?= $transaction_id ?>" class="btn btn-secondary rounded-pill px-3">
            <i class="bi bi-arrow-left me-2"></i>Kembali ke Detail Transaksi
        </a>
    </div>
    
    <?php if ($is_edit_mode): ?>
        <div class="alert alert-warning d-flex align-items-center" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <div>
                Packing Slip **#<?= htmlspecialchars($default_packing_slip_number) ?>** sudah dibuat. Anda sedang dalam **Mode Edit/Review**.
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-lg mb-4 border-primary">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Data Referensi (Transaksi Keluar)</h5>
            <small>Transaksi #<?= htmlspecialchars($transaction['transaction_number']) ?></small>
        </div>
        <div class="card-body bg-light">
            <div class="row">
                <div class="col-md-6">
                    <strong>No. Transaksi:</strong> <?= htmlspecialchars($transaction['transaction_number']) ?><br>
                    <strong>Ref. Transaksi:</strong> <?= htmlspecialchars($transaction['reference']) ?: '-' ?><br>
                </div>
                <div class="col-md-6">
                    <strong>Tanggal Transaksi:</strong> <?= date('d-m-Y', strtotime($transaction['transaction_date'])) ?><br>
                    <strong>Catatan:</strong> <?= htmlspecialchars($transaction['notes']) ?: 'Tidak ada' ?>
                </div>
            </div>
        </div>
    </div>

    <form id="packingSlipForm" method="POST" action="<?= $form_action ?>">
        <input type="hidden" name="transaction_id" value="<?= $transaction_id ?>">
        <?php if ($is_edit_mode): ?>
            <input type="hidden" name="packing_slip_id" value="<?= $packing_slip_data['id'] ?>">
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-card-list me-2"></i>Informasi Packing Slip</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="packing_slip_number" class="form-label required-field">Nomor Packing Slip</label>
                        <input type="text" class="form-control fw-bold" id="packing_slip_number" name="packing_slip_number" 
                               value="<?= htmlspecialchars($default_packing_slip_number) ?>" required 
                               readonly>
                        <div class="form-text">Nomor tergenerasi otomatis.</div>
                    </div>

                    <div class="col-md-4">
                        <label for="packing_slip_date" class="form-label required-field">Tanggal PS</label>
                        <input type="date" class="form-control" id="packing_slip_date" name="packing_slip_date" 
                               value="<?= htmlspecialchars($default_date) ?>" required
                               <?= $is_edit_mode ? 'readonly' : '' ?>>
                    </div>

                    <div class="col-md-4">
                        <label for="koli_count" class="form-label required-field">Total Jumlah Koli</label>
                        <input type="number" class="form-control" id="koli_count" name="koli_count" 
                               value="<?= $is_edit_mode ? htmlspecialchars($default_koli) : '' ?>" min="1" required 
                               placeholder="Total koli untuk semua barang">
                        <div class="form-text">Total fisik boks/paket yang dikirim.</div>
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-md-4">
                        <label for="recipient" class="form-label required-field">To</label>
                        <input type="text" class="form-control" id="recipient" name="recipient" 
                               value="<?= $is_edit_mode ? htmlspecialchars($default_recipient) : '' ?>" required 
                               placeholder="Nama Ekpedisi">
                    </div>

                    <div class="col-md-4">
                        <label for="subject" class="form-label required-field">Subjek</label>
                        <input type="text" class="form-control" id="subject" name="subject" 
                               value="<?= $is_edit_mode ? htmlspecialchars($default_subject) : '' ?>" required 
                               placeholder="Subjek Pengiriman">
                    </div>
                    
                    <div class="col-md-4">
                        <label for="location" class="form-label required-field">Location</label>
                        <input type="text" class="form-control" id="location" name="location" 
                               value="<?= $is_edit_mode ? htmlspecialchars($default_location) : '' ?>" required
                               placeholder="Lokasi Pengiriman">
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-12">
                        <label for="notes" class="form-label">Catatan Tambahan (Opsional)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                 placeholder="Informasi penting untuk kurir atau penerima..."><?= $is_edit_mode ? htmlspecialchars($default_notes) : '' ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-box-seam me-2"></i>Detail Produk (Total Qty dan SN)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle">
                        <thead class="table-secondary">
                            <tr>
                                <th class="text-center">#</th>
                                <th>Kode Produk</th>
                                <th>Nama Produk</th>
                                <th>Unit</th>
                                <th style="width: 120px;">Qty Transaksi</th>
                                <th>Nomor Serial (SN)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($details)): ?>
                                <?php $item_index = 0; foreach ($details as $detail): ?>
                                    <tr>
                                        <td class="text-center"><?= $item_index + 1 ?></td>
                                        <td><?= htmlspecialchars($detail['product_code']) ?></td>
                                        <td><?= htmlspecialchars($detail['product_name']) ?></td>
                                        <td><?= htmlspecialchars($detail['unit'] ?: '-') ?></td>
                                        <td class="text-center">
                                            <?= htmlspecialchars($detail['quantity']) ?>
                                            <input type="hidden" name="items[<?= $item_index ?>][product_id]" value="<?= htmlspecialchars($detail['product_id']) ?>">
                                            <input type="hidden" name="items[<?= $item_index ?>][quantity]" value="<?= htmlspecialchars($detail['quantity']) ?>">
                                            <input type="hidden" name="items[<?= $item_index ?>][serial_numbers]" value="<?= htmlspecialchars($detail['serial_numbers']) ?>">
                                        </td>
                                        <td>
                                            <?php if (!empty($detail['serial_numbers'])): ?>
                                                <div class="serial-list"><?= nl2br(htmlspecialchars($detail['serial_numbers'])) ?></div>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Tidak menggunakan SN.</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php $item_index++; endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Tidak ada produk dalam transaksi ini.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="text-center mb-5">
            <?php if ($is_edit_mode): ?>
                <button type="submit" class="btn btn-warning btn-lg rounded-pill px-5 shadow-sm me-3">
                    <i class="bi bi-arrow-repeat me-2"></i>Update dan Review Packing Slip
                </button>
                <a href="generate_packing_slip_pdf.php?id=<?= $packing_slip_data['id'] ?>" target="_blank" class="btn btn-primary btn-lg rounded-pill px-5 shadow-sm me-3">
                    <i class="bi bi-file-earmark-pdf me-2"></i>Lihat PDF (Tanpa Simpan)
                </a>
            <?php else: ?>
                <button type="submit" class="btn btn-success btn-lg rounded-pill px-5 shadow-sm me-3">
                    <i class="bi bi-save me-2"></i>Simpan dan Review Packing Slip
                </button>
            <?php endif; ?>
            <a href="detail.php?id=<?= $transaction_id ?>" class="btn btn-outline-secondary btn-lg rounded-pill px-5">
                <i class="bi bi-x-circle me-2"></i>Batalkan
            </a>
        </div>
    </form>
</div>

<script>
    document.getElementById('packingSlipForm').addEventListener('submit', function(event) {
        // Form disubmit, kita biarkan process_packing_slip.php yang menangani redirect setelah berhasil
        // Jika Anda ingin melakukan validasi front-end tambahan, bisa dilakukan di sini.
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php 
include '../../includes/footer.php'; 
?>