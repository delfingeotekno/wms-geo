<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan pengguna memiliki role yang diizinkan
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
header("Location: ./../index.php");
exit();
}

$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($transaction_id <= 0) {
header("Location: ../../index.php");
exit();
}


// Mengambil pesan status dari URL
$status_message = '';
$status_type = '';
if (isset($_GET['status'])) {
if ($_GET['status'] == 'success_return') {
 $status_message = 'Item berhasil dikembalikan. Status transaksi telah diperbarui.';
 $status_type = 'success';
} elseif ($_GET['status'] == 'error') {
 $status_message = 'Gagal mengembalikan item: ' . (isset($_GET['message']) ? htmlspecialchars($_GET['message']) : 'Terjadi kesalahan tidak diketahui.');
 $status_type = 'danger';
}
}

// 1. Mengambil data header transaksi dari tabel borrowed_transactions
// Memasukkan kolom photo_url dalam query
$sql_header = "SELECT bt.*, u.name AS creator_name 
 FROM borrowed_transactions bt 
 JOIN users u ON bt.user_id = u.id 
 WHERE bt.id = ? AND bt.is_deleted = 0";
$stmt_header = $conn->prepare($sql_header);
$stmt_header->bind_param("i", $transaction_id);
$stmt_header->execute();
$result_header = $stmt_header->get_result();
$transaction = $result_header->fetch_assoc();
$stmt_header->close();

// Jika transaksi tidak ditemukan, tampilkan pesan error
if (!$transaction) {
echo "<div class='alert alert-danger'>Transaksi tidak ditemukan atau sudah dihapus.</div>";
include '../../includes/footer.php';
exit();
}

// 2. Mengambil data detail item dari tabel borrowed_transactions_detail
$sql_details = "SELECT btd.*, p.product_code, p.product_name, p.has_serial 
  FROM borrowed_transactions_detail btd
  JOIN products p ON btd.product_id = p.id
  JOIN borrowed_transactions bt ON btd.transaction_id = bt.id
  WHERE btd.transaction_id = ?";
$stmt_details = $conn->prepare($sql_details);
$stmt_details->bind_param("i", $transaction_id);
$stmt_details->execute();
$result_details = $stmt_details->get_result();
$details = $result_details->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();


$total_items = 0;
// Inisialisasi variabel untuk tanggal kembali aktual (latest return date jika semua item sudah kembali)
$actual_return_date = null;

foreach ($details as $item) {
$total_items += $item['quantity'];
$return_date = $item['return_date'];

// Cek jika ada item yang belum kembali
if (empty($return_date) || $return_date == '0000-00-00') {
 $actual_return_date = null; // Set null, artinya transaksi belum kembali lengkap
 break;
}

// Cari tanggal kembali terakhir (jika semua item sudah kembali)
if ($actual_return_date === null || strtotime($return_date) > strtotime($actual_return_date)) {
 $actual_return_date = $return_date;
}
}

?>

<div class="content-header mb-4">
<h1 class="fw-bold"><i class="bi bi-file-earmark-text me-2"></i> Detail Transaksi Peminjaman</h1>
</div>

<div class="card shadow-sm mb-4">
<div class="card-header bg-white d-flex justify-content-between align-items-center">
 <h5 class="mb-0 fw-bold text-dark">Transaksi #<?= htmlspecialchars($transaction['transaction_number']) ?></h5>
 
 <div class="d-flex gap-2">
 
 <a href="borrow_pdf.php?id=<?= $transaction_id ?>" target="_blank" class="btn btn-info btn-sm rounded-pill px-3">
  <i class="bi bi-file-earmark-pdf me-2"></i>Preview SP
 </a>
 
 <?php 
 // Pastikan URL untuk download foto dibuat dengan benar
 $download_photo_path = !empty($transaction['photo_url']) ? '../../uploads/borrowed_photos/' . $transaction['photo_url'] : null;
 if (!empty($download_photo_path) && file_exists($download_photo_path)): 
 ?>
  <a href="<?= htmlspecialchars($download_photo_path) ?>" download="<?= htmlspecialchars($transaction['transaction_number']) ?>_bukti.jpg" class="btn btn-success btn-sm rounded-pill px-3">
  <i class="bi bi-download me-2"></i>Unduh Foto
  </a>
 <?php endif; ?>

 <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
  <i class="bi bi-arrow-left me-2"></i>Kembali
  
 </a>
 </div>
</div>
<div class="card-body">
 <?php if ($status_message): ?>
 <div class="alert alert-<?= $status_type ?> alert-dismissible fade show" role="alert">
  <?= $status_message ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
 </div>
 <?php endif; ?>
 <div class="row">
 <div class="col-md-6">
  <p><strong>No. Transaksi:</strong> <?= htmlspecialchars($transaction['transaction_number']) ?></p>
  <p><strong>Tanggal Peminjaman:</strong> <?= date('d-m-Y', strtotime($transaction['borrow_date'])) ?></p>
    <p><strong>Tanggal Kembali:</strong> 
   <?php 
   if ($actual_return_date) {
    echo date('d-m-Y', strtotime($actual_return_date));
   } else {
    echo '-'; // Tampilkan '-' jika belum kembali lengkap
   }
   ?>
  </p>

  <p><strong>Peminjam:</strong> <?= htmlspecialchars($transaction['borrower_name']) ?></p>
 </div>
 <div class="col-md-6">
  <p><strong>Dibuat Oleh:</strong> <?= htmlspecialchars($transaction['creator_name']) ?></p>
  <p><strong>Total Item:</strong> <?= htmlspecialchars($total_items) ?></p>
  <p><strong>Subjek:</strong> <?= htmlspecialchars($transaction['subject']) ?></p>
  <p><strong>Catatan:</strong> <?= htmlspecialchars($transaction['notes']) ?></p>
 </div>
 </div>
 
 <hr>

 <h5 class="fw-bold mt-4">Foto Bukti Transaksi</h5>
 <?php 
 // Pastikan URL foto dibentuk dengan benar untuk ditampilkan
 $photo_path = !empty($transaction['photo_url']) ? '../../uploads/borrowed_photos/' . $transaction['photo_url'] : null;
 ?>
 <?php if (!empty($photo_path) && file_exists($photo_path)): ?>
 <div class="card p-3 mb-4 bg-light text-center">
  <h6 class="fw-bold">Foto Bukti Peminjaman:</h6>
  <img src="<?= htmlspecialchars($photo_path) ?>" class="img-fluid rounded shadow-sm mx-auto d-block mt-2" alt="Foto Bukti Transaksi" style="max-width: 400px; max-height: 400px; object-fit: contain;">
 </div>
 <?php else: ?>
 <div class="alert alert-info text-center" role="alert">
  Tidak ada foto bukti transaksi yang tersedia.
 </div>
 <?php endif; ?>
</div>
</div>

<div class="card shadow-sm">
<div class="card-header bg-white">
 <h5 class="mb-0 fw-bold text-dark">Daftar Produk yang Dipinjam</h5>
</div>
<div class="card-body">
 <div class="table-responsive">
 <table class="table table-bordered table-hover">
  <thead>
  <tr class="table-secondary">
   <th>No</th>
   <th>Kode Produk</th>
   <th>Nama Produk</th>
   <th>Kuantitas</th>
   <th>Serial Number</th>
   <th>Tgl. Kembali</th>
   <th>Status</th>
   <th>Aksi</th>
  </tr>
  </thead>
  <tbody>
  <?php if (count($details) > 0): ?>
   <?php foreach ($details as $i => $item): ?>
   <tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($item['product_code']) ?></td>
    <td><?= htmlspecialchars($item['product_name']) ?></td>
    <td><?= htmlspecialchars($item['quantity']) ?></td>
    <td><?= empty($item['serial_number']) ? '-' : htmlspecialchars($item['serial_number']) ?></td>
    
    <td>
    <?php 
     // Logika untuk menampilkan Tgl. Kembali (kosong jika belum kembali)
     $return_date = $item['return_date'];
     if (!empty($return_date) && $return_date !== '0000-00-00') {
     echo date('d-m-Y', strtotime($return_date));
     } else {
     echo ''; // Kosong jika belum kembali
     }
    ?>
    </td>
    
    <td>
    <?php if (empty($item['return_date']) || $item['return_date'] == '0000-00-00'): ?>
     <span class="badge bg-warning text-dark">Belum Kembali</span>
    <?php else: ?>
     <span class="badge bg-success">Sudah Kembali</span>
    <?php endif; ?>
    </td>
    
    <td>
    <?php if (empty($item['return_date']) || $item['return_date'] == '0000-00-00'): ?>
     <a href="return.php?detail_id=<?= $item['id'] ?>&serial_number=<?= htmlspecialchars($item['serial_number']) ?>&transaction_id=<?= $transaction_id ?>" class="btn btn-success btn-sm" onclick="return confirm('Apakah Anda yakin ingin mengembalikan item ini?')" title="Kembalikan"><i class="bi bi-check-circle-fill"></i> Kembalikan</a>
    <?php else: ?>
     <span class="text-success"><i class="bi bi-check-lg"></i> Dikembalikan</span>
    <?php endif; ?>
    </td>
   </tr>
   <?php endforeach; ?>
  <?php else: ?>
   <tr>
   <td colspan="8" class="text-center">Tidak ada item dalam transaksi ini.</td>
   </tr>
  <?php endif; ?>
  </tbody>
 </table>
 </div>
</div>
</div>

<?php include '../../includes/footer.php'; ?>