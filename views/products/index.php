<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan hanya admin dan staff yang bisa mengakses halaman ini
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff', 'marketing', 'procurement', 'production'])) {
 header("Location: ../../index.php");
 exit();
}

$user_role = $_SESSION['role']; // Ambil peran pengguna
$warehouse_id = $_SESSION['warehouse_id']; // Ambil warehouse_id pengguna

$status_message = '';
$status_type = '';

// Ambil pesan status dari URL jika ada
if (isset($_GET['status'])) {
 if ($_GET['status'] == 'success_add') {
  $status_message = 'Produk berhasil ditambahkan!';
  $status_type = 'success';
 } elseif ($_GET['status'] == 'success_edit') {
  $status_message = 'Produk berhasil diperbarui!';
  $status_type = 'success';
 } elseif ($_GET['status'] == 'success_delete') {
  $status_message = 'Produk berhasil dihapus!';
  $status_type = 'success';
 } elseif ($_GET['status'] == 'error_delete') {
  $status_message = 'Gagal menghapus produk. Terjadi kesalahan atau produk masih terkait dengan data lain.';
  $status_type = 'danger';
 } elseif ($_GET['status'] == 'success_restore') {
  $status_message = 'Produk berhasil dipulihkan!';
  $status_type = 'success';
 } elseif ($_GET['status'] == 'error_restore') {
  $status_message = 'Gagal memulihkan produk. Terjadi kesalahan.';
  $status_type = 'danger';
 }
}

// Ambil pesan status dari Session (dan hapus agar tidak muncul terus)
if (isset($_SESSION['success_message'])) {
    $status_message = $_SESSION['success_message'];
    $status_type = 'success';
    unset($_SESSION['success_message']);
} elseif (isset($_SESSION['error_message'])) {
    $status_message = $_SESSION['error_message'];
    $status_type = 'danger';
    unset($_SESSION['error_message']);
}

// Inisialisasi variabel untuk filter
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$brand_filter = isset($_GET['brand_id']) ? (int)$_GET['brand_id'] : 0;
$type_filter = isset($_GET['product_type_id']) ? (int)$_GET['product_type_id'] : 0;
$stock_status_filter = isset($_GET['stock_status']) ? $_GET['stock_status'] : '';
$show_deleted = isset($_GET['show_deleted']) ? (int)$_GET['show_deleted'] : 0;

// Ambil daftar merek dan tipe produk untuk filter dropdown (filtered by tenant)
$brands_list_query = $conn->query("SELECT id, brand_name FROM brands ORDER BY brand_name");
$brands_list = $brands_list_query->fetch_all(MYSQLI_ASSOC);

$product_types_list_query = $conn->query("SELECT id, type_name FROM product_types ORDER BY type_name");
$product_types_list = $product_types_list_query->fetch_all(MYSQLI_ASSOC);

// =========================================================================================================
// PERUBAHAN UTAMA DI SINI: MENGGUNAKAN SUBQUERY UNTUK MENGHITUNG effective_stock HANYA SEKALI
// =========================================================================================================

// Ambil daftar gudang untuk tab filter
$warehouses_query = $conn->query("SELECT id, name FROM warehouses ORDER BY name ASC");
$warehouses_list = $warehouses_query->fetch_all(MYSQLI_ASSOC);

// Tentukan tab aktif
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (string)$_SESSION['warehouse_id'];

// Logika filter warehouse untuk subquery
$dynamic_warehouse_columns = "";

if ($active_tab === 'all') {
    $warehouse_filter_sql = "";
    $product_scope_sql = "";
    $params = [];
    $param_types = '';
    
    // Bangun subquery dinamis untuk setiap gudang
    $dyn_cols = [];
    foreach ($warehouses_list as $wh) {
        $wh_id = (int)$wh['id'];
        $dyn_cols[] = "
        CASE 
            WHEN p.has_serial = 1 THEN (
                SELECT COUNT(*) FROM serial_numbers 
                WHERE product_id = p.id AND warehouse_id = $wh_id 
                AND (status = 'Tersedia' OR status = 'Tersedia (Bekas)') AND is_deleted = 0
            ) 
            ELSE (
                COALESCE((SELECT SUM(stock) FROM warehouse_stocks WHERE product_id = p.id AND warehouse_id = $wh_id), 0)
            )
        END AS wh_stock_$wh_id";
    }
    if (!empty($dyn_cols)) {
        $dynamic_warehouse_columns = implode(", ", $dyn_cols);
    } else {
        $dynamic_warehouse_columns = "'' AS dummy_column"; // Fallback jika tidak ada gudang
    }
    
} else {
    $filter_warehouse_id = (int)$active_tab;
    $warehouse_filter_sql = "AND warehouse_id = ?";
    $product_scope_sql = "AND (p.warehouse_id IS NULL OR p.warehouse_id = ?)";
    // Ketika BUKAN 'all', ada 2 placeholder '?' di $warehouse_filter_sql, dan 1 di $product_scope_sql
    $params = [$filter_warehouse_id, $filter_warehouse_id, $filter_warehouse_id];
    $param_types = 'iii';
    $dynamic_warehouse_columns = "'' AS dummy_column"; // Tidak butuh breakdown per gudang
}

// Persiapkan query SQL dasar
$sql = "
SELECT 
  SubQuery.*
FROM (
  SELECT 
    p.*, 
    b.brand_name, 
    pt.type_name, 
    CASE 
     WHEN p.has_serial = 1 THEN (
        -- 💥 PERBAIKAN KRITIS: Menambahkan filter is_deleted = 0 untuk SN yang tersedia
        SELECT COUNT(*) 
        FROM serial_numbers 
        WHERE product_id = p.id 
        $warehouse_filter_sql
        AND (status = 'Tersedia' OR status = 'Tersedia (Bekas)')
        AND is_deleted = 0 -- HANYA HITUNG SERIAL YANG BELUM DIHAPUS
     )
     ELSE (
        -- SUM stock from warehouse_stocks based on active tab
        COALESCE((
            SELECT SUM(stock) 
            FROM warehouse_stocks 
            WHERE product_id = p.id 
            $warehouse_filter_sql
        ), 0)
     )
    END AS effective_stock,
    $dynamic_warehouse_columns
  FROM products p
  LEFT JOIN brands b ON p.brand_id = b.id
  LEFT JOIN product_types pt ON p.product_type_id = pt.id
  WHERE 1=1 $product_scope_sql
) AS SubQuery
WHERE 1=1";
// CATATAN: Karena 'note' diasumsikan ada di kolom p.*, maka sudah termasuk.

// Tambahkan kondisi filter jika ada
if ($search_term) {
 // Perubahan di sini: Merujuk pada SubQuery
 $sql .= " AND (SubQuery.product_code LIKE ? OR SubQuery.product_name LIKE ?)";
 $search_like = "%" . $search_term . "%";
 $params[] = $search_like;
 $params[] = $search_like;
 $param_types .= 'ss';
}
if ($brand_filter > 0) {
 // Perubahan di sini: Merujuk pada SubQuery
 $sql .= " AND SubQuery.brand_id = ?";
 $params[] = $brand_filter;
 $param_types .= 'i';
}
if ($type_filter > 0) {
 // Perubahan di sini: Merujuk pada SubQuery
 $sql .= " AND SubQuery.product_type_id = ?";
 $params[] = $type_filter;
 $param_types .= 'i';
}

// Tambahkan filter status stok dengan logika yang disederhanakan, menggunakan effective_stock dari SubQuery
if ($stock_status_filter) {
 if ($stock_status_filter == 'danger') {
  // Menggunakan effective_stock yang sudah dihitung
  $sql .= " AND SubQuery.effective_stock <= SubQuery.minimum_stock_level";
 } elseif ($stock_status_filter == 'warning') {
  // Menggunakan effective_stock yang sudah dihitung
  $sql .= " AND SubQuery.effective_stock > SubQuery.minimum_stock_level AND SubQuery.effective_stock <= (SubQuery.minimum_stock_level + 5)";
 } elseif ($stock_status_filter == 'safe') {
  // Menggunakan effective_stock yang sudah dihitung
  $sql .= " AND SubQuery.effective_stock > (SubQuery.minimum_stock_level + 5)";
 }
}

// Logika Filter is_deleted
if ($show_deleted == 0) {
 $sql .= " AND SubQuery.is_deleted = 0"; // <-- Diubah ke SubQuery
} elseif ($show_deleted == 1) {
 $sql .= " AND SubQuery.is_deleted = 1"; // <-- Diubah ke SubQuery
}
// Tidak perlu else untuk "Semua", karena 1=1 sudah menangani itu.

$sql .= " ORDER BY SubQuery.updated_at DESC"; // <-- Diubah ke SubQuery

// Siapkan dan jalankan statement
$stmt = $conn->prepare($sql);
if ($param_types) {
 $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>

<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="content-header mb-4">
<h1 class="fw-bold">Manajemen Produk</h1>
</div>

<ul class="nav nav-tabs border-0">
  <li class="nav-item">
    <a class="nav-link <?= ($active_tab === 'all') ? 'active bg-white text-dark fw-bold border-bottom-0' : 'text-muted' ?>" 
       href="?tab=all<?= $search_term ? '&search='.urlencode($search_term) : '' ?><?= $brand_filter ? '&brand_id='.$brand_filter : '' ?><?= $type_filter ? '&product_type_id='.$type_filter : '' ?><?= $stock_status_filter ? '&stock_status='.urlencode($stock_status_filter) : '' ?><?= $show_deleted ? '&show_deleted='.$show_deleted : '' ?>">
       Semua Lokasi Gudang
    </a>
  </li>
  <?php foreach ($warehouses_list as $wh): ?>
  <li class="nav-item">
    <a class="nav-link <?= ($active_tab == $wh['id']) ? 'active bg-white text-dark fw-bold border-bottom-0' : 'text-muted' ?>" 
       href="?tab=<?= $wh['id'] ?><?= $search_term ? '&search='.urlencode($search_term) : '' ?><?= $brand_filter ? '&brand_id='.$brand_filter : '' ?><?= $type_filter ? '&product_type_id='.$type_filter : '' ?><?= $stock_status_filter ? '&stock_status='.urlencode($stock_status_filter) : '' ?><?= $show_deleted ? '&show_deleted='.$show_deleted : '' ?>">
       <?= htmlspecialchars($wh['name']) ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="card shadow-sm mb-4" style="border-top-left-radius: 0;">
<div class="card-header bg-white d-flex justify-content-between align-items-center">
  <h5 class="mb-0 fw-bold text-dark">Daftar Produk</h5>
  <div class="d-flex align-items-center">
    
    <a href="generate_product_pdf.php?tab=<?= htmlspecialchars($active_tab) ?>&search=<?= urlencode($search_term) ?>&brand_id=<?= $brand_filter ?>&product_type_id=<?= $type_filter ?>&stock_status=<?= urlencode($stock_status_filter) ?>&show_deleted=<?= $show_deleted ?>" 
       target="_blank" 
       class="btn btn-danger btn-sm rounded-pill px-3 me-2" 
       id="downloadPdfBtn">
      <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
    </a>
    
    <a href="export_excel.php?tab=<?= htmlspecialchars($active_tab) ?>&search=<?= urlencode($search_term) ?>&brand_id=<?= $brand_filter ?>&product_type_id=<?= $type_filter ?>&stock_status=<?= urlencode($stock_status_filter) ?>&show_deleted=<?= $show_deleted ?>" 
       class="btn btn-success btn-sm rounded-pill px-3 me-2">
      <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
    </a>

    <?php if ($user_role != 'marketing' && $user_role != 'production'): ?>
      <a href="create.php?tab=<?= htmlspecialchars($active_tab) ?>" class="btn btn-primary btn-sm rounded-pill px-3">
        <i class="bi bi-plus me-2"></i>Tambah Produk
      </a>
    <?php endif; ?>
  </div>
</div>
<div class="card-body">
 <?php if ($status_message): ?>
 <script>
 document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
      title: '<?= $status_type == "success" ? "Berhasil!" : "Terjadi Kesalahan!" ?>',
      text: '<?= addslashes($status_message) ?>',
      icon: '<?= $status_type == "success" ? "success" : "error" ?>',
      confirmButtonColor: '#3085d6',
      confirmButtonText: 'Tutup',
      timer: 3500,
      timerProgressBar: true
    });
 });
 </script>
 <?php endif; ?>

 <form action="" method="GET" class="mb-4">
  <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
 <div class="row g-2 align-items-end">
  <div class="col-md-4">
  <label for="search" class="form-label">Cari Produk</label>
  <div class="input-group">
   <input type="text" class="form-control" name="search" placeholder="Cari Kode atau Nama..." value="<?= htmlspecialchars($search_term) ?>">
  </div>
  </div>
  <div class="col-md-3">
  <label for="brand_id" class="form-label">Merek</label>
  <select class="form-select" name="brand_id">
   <option value="">Semua Merek</option>
   <?php foreach ($brands_list as $brand): ?>
   <option value="<?= $brand['id'] ?>" <?= ($brand['id'] == $brand_filter) ? 'selected' : '' ?>>
    <?= htmlspecialchars($brand['brand_name']) ?>
   </option>
   <?php endforeach; ?>
  </select>
  </div>
  <div class="col-md-3">
  <label for="product_type_id" class="form-label">Tipe Produk</label>
  <select class="form-select" name="product_type_id">
   <option value="">Semua Tipe</option>
   <?php foreach ($product_types_list as $type): ?>
   <option value="<?= $type['id'] ?>" <?= ($type['id'] == $type_filter) ? 'selected' : '' ?>>
    <?= htmlspecialchars($type['type_name']) ?>
   </option>
   <?php endforeach; ?>
  </select>
  </div>
  <div class="col-md-2 d-flex gap-2">
  <button class="btn btn-primary" type="submit">Filter</button>
  <a href="index.php" class="btn btn-outline-danger">Reset</a>
  </div>
 </div>
 <div class="row g-2 mt-2">
  <div class="col-md-3">
  <label for="stock_status" class="form-label">Status Stok</label>
  <select class="form-select" name="stock_status">
   <option value="">Semua Status</option>
   <option value="danger" <?= ($stock_status_filter == 'danger') ? 'selected' : '' ?>>Danger</option>
   <option value="warning" <?= ($stock_status_filter == 'warning') ? 'selected' : '' ?>>Warning</option>
   <option value="safe" <?= ($stock_status_filter == 'safe') ? 'selected' : '' ?>>Safe</option>
  </select>
  </div>
  <div class="col-md-3">
  <label for="show_deleted" class="form-label">Tampilkan Produk</label>
  <select class="form-select" name="show_deleted">
   <option value="0" <?= ($show_deleted == 0) ? 'selected' : '' ?>>Produk Aktif</option>
   <option value="1" <?= ($show_deleted == 1) ? 'selected' : '' ?>>Produk Dihapus</option>
   <option value="2" <?= ($show_deleted == 2) ? 'selected' : '' ?>>Semua Produk</option>
  </select>
  </div>
 </div>
 </form>

  <div class="table-responsive">
 <table class="table table-hover table-striped">
  <thead>
  <tr>
   <th>No.</th>
   <th>Kode Produk</th>
   <th>Nama Produk</th>
   <th>Merek</th>
   <th>Tipe</th>
   <th>Lokasi</th>
   <th>Stok Total</th>
   <?php if ($active_tab === 'all'): ?>
     <?php foreach ($warehouses_list as $wh): ?>
       <th>Stok <?= htmlspecialchars($wh['name']) ?></th>
     <?php endforeach; ?>
   <?php endif; ?>
   <th>Unit</th>
   <th>Catatan</th> 
   <th>Kategori</th>
   <th class="text-center">Status</th>
   <?php if ($user_role != 'marketing' && $user_role != 'production'): ?>
   <th class="text-center">Aksi</th>
   <?php endif; ?>
  </tr>
  </thead>
  <tbody>
  <?php if (empty($products)): ?>
   <tr>
   <?php 
     $colspan = ($user_role != 'marketing' && $user_role != 'production') ? 13 : 12;
     if ($active_tab === 'all') {
         $colspan += count($warehouses_list);
     }
   ?>
   <td colspan="<?= $colspan ?>" class="text-center">Produk tidak ditemukan.</td>
   </tr>
  <?php else: ?>
   <?php $i = 1; ?>
   <?php foreach ($products as $product): ?>
   <tr>
    <td><?= $i++ ?></td>
    <td><?= htmlspecialchars($product['product_code']) ?></td>
    <td><?= htmlspecialchars($product['product_name']) ?></td>
    <td><?= htmlspecialchars($product['brand_name']) ?></td>
    <td><?= htmlspecialchars($product['type_name']) ?></td>
    <td><?= htmlspecialchars($product['location']) ?></td>
    <td class="fw-bold"><?= htmlspecialchars($product['effective_stock']) ?></td> 
    <?php if ($active_tab === 'all'): ?>
      <?php foreach ($warehouses_list as $wh): ?>
        <td><?= htmlspecialchars($product['wh_stock_' . $wh['id']]) ?></td>
      <?php endforeach; ?>
    <?php endif; ?>
    <td><?= htmlspecialchars($product['unit']) ?> </td>
    <td><?= htmlspecialchars(substr($product['note'], 0, 50)) . (strlen($product['note']) > 50 ? '...' : '') ?></td> 
    <td>
    <?php if ($product['has_serial']): ?>
     <span class="badge bg-primary">Serial Number</span>
    <?php else: ?>
     <span class="badge bg-secondary">Kuantitas</span>
    <?php endif; ?>
    </td>
    <td class="text-center">
    <?php
    $status_text = '';
    $status_class = '';
    $effective_stock = $product['effective_stock'];
    
    if ($product['is_deleted'] == 1) {
     $status_text = 'Dihapus';
     $status_class = 'bg-dark';
    } else {
     $min_stock = $product['minimum_stock_level'];
     if ($effective_stock <= $min_stock) {
     $status_text = 'Danger';
     $status_class = 'bg-danger';
     } elseif ($effective_stock > $min_stock && $effective_stock <= ($min_stock + 5)) {
     $status_text = 'Warning';
     $status_class = 'bg-warning text-dark';
     } elseif ($effective_stock > ($min_stock + 5)) {
     $status_text = 'Safe';
     $status_class = 'bg-success';
     }
    }
    ?>
    <span class="badge rounded-pill <?= $status_class ?>"><?= $status_text ?></span>
    </td>
    
    <?php if ($user_role != 'marketing' && $user_role != 'production'): ?>
    <td class="text-center">
    <div class="d-flex justify-content-center p-1 rounded">
     <?php if ($product['is_deleted'] == 0): ?>
     <button class="btn btn-sm btn-info text-white me-1 detail-btn"
      data-bs-toggle="modal"
      data-bs-target="#productDetailModal"
      data-id="<?= $product['id'] ?>"
      data-product_code="<?= htmlspecialchars($product['product_code']) ?>"
      data-product_name="<?= htmlspecialchars($product['product_name']) ?>"
      data-brand_name="<?= htmlspecialchars($product['brand_name']) ?>"
      data-type_name="<?= htmlspecialchars($product['type_name']) ?>"
      data-stock="<?= htmlspecialchars($product['effective_stock']) ?>"
      data-unit="<?= htmlspecialchars($product['unit']) ?>" 
      data-minimum_stock_level="<?= htmlspecialchars($product['minimum_stock_level']) ?>"
      data-location="<?= htmlspecialchars($product['location']) ?>"
      data-has_serial="<?= $product['has_serial'] ? 'Ya' : 'Tidak' ?>"
      data-note="<?= htmlspecialchars($product['note']) ?>" 
      data-product_image="<?= htmlspecialchars($product['product_image'] ? '../../assets/uploads/' . $product['product_image'] : '../../assets/img/placeholder.png') ?>">
      <i class="bi bi-info-circle"></i>
     </button>
     
     <?php if ($user_role != 'procurement'): ?>
     <a href="../../product_history.php?product_id=<?= $product['id'] ?>" class="btn btn-sm btn-secondary text-white me-1" title="Lihat Histori">
      <i class="bi bi-clock-history"></i>
     </a>

     <a href="edit.php?id=<?= $product['id'] ?>&tab=<?= htmlspecialchars($active_tab) ?>" class="btn btn-sm btn-warning me-1">
      <i class="bi bi-pencil"></i>
     </a>
     
     <button type="button" class="btn btn-sm btn-danger me-1" data-bs-toggle="modal" data-bs-target="#deleteModal" data-id="<?= $product['id'] ?>" title="Hapus">
      <i class="bi bi-trash"></i>
     </button>
     <?php endif; ?>
     
     <?php if ($product['has_serial']): ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#serialNumberModal" data-product-id="<?= $product['id'] ?>" data-product-name="<?= htmlspecialchars($product['product_name']) ?>" title="List Serial Number">
      <i class="bi bi-list-ol"></i>
      </button>
     <?php endif; ?>
     <?php else: ?>
     <?php if ($user_role != 'procurement'): ?>
     <button type="button" class="btn btn-sm btn-success restore-btn" data-bs-toggle="modal" data-bs-target="#restoreModal" data-id="<?= $product['id'] ?>" title="Pulihkan">
      <i class="bi bi-arrow-counterclockwise"></i> Pulihkan
     </button>
     <?php endif; ?>
     <?php endif; ?>
    </div>
    </td>
    <?php endif; ?>
   </tr>
   <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
 </table>
 </div>
</div>
</div>

<div class="modal fade" id="productDetailModal" tabindex="-1" aria-labelledby="productDetailModalLabel" aria-hidden="true">
<div class="modal-dialog modal-lg">
 <div class="modal-content">
 <div class="modal-header bg-dark text-white">
  <h5 class="modal-title" id="productDetailModalLabel">Detail Produk</h5>
  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
 </div>
 <div class="modal-body">
  <div class="row">
  <div class="col-md-5 text-center mb-3 mb-md-0">
   <img id="modalProductImage" src="" alt="Foto Produk" class="img-fluid rounded border p-1" style="max-height: 250px;">
  </div>
    <div class="col-md-7">
        <h4 class="fw-bold" id="modalProductName"></h4>
        <hr>
        <ul class="list-unstyled">
            <li><strong>Kode Produk:</strong> <span id="modalProductCode"></span></li>
            <li><strong>Merek:</strong> <span id="modalBrandName"></span></li>
            <li><strong>Tipe:</strong> <span id="modalTypeName"></span></li>
            <li><strong>Lokasi:</strong> <span id="modalLocation"></span></li>
            <li><strong>Stok:</strong> <span id="modalStock"></span></li>
            <li><strong>Unit:</strong> <span id="modalUnit"></span></li> 
            <li><strong>Stok Minimum:</strong> <span id="modalMinimumStockLevel"></span></li>
            <li><strong>Memiliki Serial Number:</strong> <span id="modalHasSerial"></span></li>
            
            <div id="nonSerialPrintSection" class="mt-3 mb-3 p-2 bg-light border rounded text-center" style="display: none;">
                <small class="d-block mb-2 text-muted">Cetak label barcode menggunakan Kode Produk?</small>
                <button type="button" class="btn btn-warning btn-sm rounded-pill px-3 fw-bold" id="printNonSerialLabelBtn">
                    <i class="bi bi-printer me-1"></i> Print Label (Sesuai Stok)
                </button>
            </div>

            <li><strong>Catatan:</strong> <p id="modalNote" class="mt-2"></p></li>
        </ul>
    </div>
  </div>
  <div class="text-center mt-3">
  <small class="text-muted">Stok produk serial dihitung berdasarkan jumlah Serial Number yang Tersedia dan belum dihapus.</small>
  </div>
 </div>
 <div class="modal-footer">
  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
 </div>
 </div>
</div>
</div>

<?php if ($user_role != 'marketing'): ?>
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
<div class="modal-dialog">
 <div class="modal-content">
 <div class="modal-header">
  <h5 class="modal-title" id="deleteModalLabel">Konfirmasi Hapus Produk</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
 </div>
 <div class="modal-body">
  Apakah Anda yakin ingin menghapus produk ini?
 </div>
 <div class="modal-footer">
  <form id="deleteForm" method="POST" action="delete.php">
  <input type="hidden" name="id" id="product_id_to_delete">
  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
  <button type="submit" class="btn btn-danger">Hapus</button>
  </form>
 </div>
 </div>
</div>
</div>

<div class="modal fade" id="serialNumberModal" tabindex="-1" aria-labelledby="serialNumberModalLabel" aria-hidden="true" data-bs-focus="false">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header">
 <h5 class="modal-title" id="serialNumberModalLabel">Detail Stok Serial Number Produk</h5>
 <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
 <h6 id="productNameSerialModal" class="fw-bold mb-3"></h6>
 
<div class="d-flex justify-content-between align-items-center mb-3">
    <input type="text" id="serialSearchInput" class="form-control me-2" placeholder="Cari Serial Number atau Status..." aria-label="Cari Serial Number">
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-warning btn-sm rounded-pill px-3 fw-bold" id="printSerialLabelBtn">
            <i class="bi bi-printer me-1"></i> Print Label
        </button>
        
        <button type="button" class="btn btn-danger btn-sm rounded-pill px-3" id="downloadSerialPdfBtn">
            <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF Serial
        </button>
    </div>
</div>
 <div class="table-responsive">
 <table class="table table-striped table-bordered" id="serialNumberTable">
  <thead>
   <tr>
    <th>No</th>
    <th>Serial Number</th>
    <th>Status</th>
    <th>Lokasi Gudang</th>
    <th>No. Dokumen Terakhir</th>
    <th>Terakhir Update</th>
    <th>Aksi</th>
   </tr>
  </thead>
 <tbody>
 </tbody>
 </table>
 </div>
</div>
</div>
</div>
</div>

<div class="modal fade" id="restoreModal" tabindex="-1" aria-labelledby="restoreModalLabel" aria-hidden="true">
<div class="modal-dialog">
 <div class="modal-content">
 <div class="modal-header">
  <h5 class="modal-title" id="restoreModalLabel">Konfirmasi Pulihkan Produk</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
 </div>
 <div class="modal-body">
  Apakah Anda yakin ingin memulihkan produk ini?
 </div>
 <div class="modal-footer">
  <form id="restoreForm" method="POST" action="restore.php">
  <input type="hidden" name="id" id="product_id_to_restore">
  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
  <button type="submit" class="btn btn-success">Pulihkan</button>
  </form>
  
 </div>
 </div>
</div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

 // ==========================================================
 // GLOBAL
 // ==========================================================
 let currentProductId = null;
 let allProductSerials = [];

 // ==========================================================
 // MODAL DELETE & RESTORE
 // ==========================================================
 const deleteModal = document.getElementById('deleteModal');
 if (deleteModal) {
  deleteModal.addEventListener('show.bs.modal', function (event) {
   const button = event.relatedTarget;
   const productId = button.getAttribute('data-id');
   document.getElementById('product_id_to_delete').value = productId;
  });
 }

 const restoreModal = document.getElementById('restoreModal');
 if (restoreModal) {
  restoreModal.addEventListener('show.bs.modal', function (event) {
   const button = event.relatedTarget;
   const productId = button.getAttribute('data-id');
   document.getElementById('product_id_to_restore').value = productId;
  });
 }

// ==========================================================
 // MODAL DETAIL PRODUK
 // ==========================================================
 const productDetailModal = document.getElementById('productDetailModal');
 if (productDetailModal) {
  productDetailModal.addEventListener('show.bs.modal', function (event) {
   const button = event.relatedTarget; // Tombol yang memicu modal

   // 1. Ambil data dari dataset
   const productId = button.dataset.id;
   const productCode = button.dataset.product_code;
   const productName = button.dataset.product_name;
   const stock = button.dataset.stock;
   const hasSerial = button.dataset.has_serial; // Nilai 1 atau 0

   // 2. Isi konten modal
   document.getElementById('modalProductName').textContent = productName;
   document.getElementById('modalProductCode').textContent = productCode;
   document.getElementById('modalBrandName').textContent = button.dataset.brand_name;
   document.getElementById('modalTypeName').textContent = button.dataset.type_name;
   document.getElementById('modalStock').textContent = stock;
   document.getElementById('modalUnit').textContent = button.dataset.unit;
   document.getElementById('modalMinimumStockLevel').textContent = button.dataset.minimum_stock_level;
   document.getElementById('modalLocation').textContent = button.dataset.location;
   document.getElementById('modalHasSerial').textContent = (hasSerial == 1 || hasSerial == 'Ya') ? 'Ya' : 'Tidak';
   document.getElementById('modalNote').textContent = button.dataset.note;
   document.getElementById('modalProductImage').src = button.dataset.product_image;

   // 3. Logika Tombol Print Label Non-Serial
   const printSection = document.getElementById('nonSerialPrintSection');
   const printBtn = document.getElementById('printNonSerialLabelBtn');

   // Tombol hanya muncul jika hasSerial adalah 0 (Bukan produk Serial)
   if (hasSerial == 0 || hasSerial == 'Tidak') {
    printSection.style.display = 'block';
    
    // Set event klik untuk membuka halaman print
    printBtn.onclick = function() {
        const qtyToPrint = parseInt(stock);
        if (qtyToPrint <= 0) {
            alert("Stok produk ini kosong. Tidak ada label yang bisa dicetak.");
            return;
        }
        
        // Buka file cetak dengan parameter yang sesuai
        const url = `print_label_non_serial.php?id=${productId}&code=${encodeURIComponent(productCode)}&qty=${qtyToPrint}`;
        window.open(url, '_blank');
    };
   } else {
    printSection.style.display = 'none';
   }
  });
 }

 // ==========================================================
 // MODAL SERIAL NUMBER
 // ==========================================================
 const serialNumberModal = document.getElementById('serialNumberModal');
 const serialSearchInput = document.getElementById('serialSearchInput');

function renderSerialTable(serials, tbody, keyword = '') {
    tbody.innerHTML = '';
    const search = keyword.toLowerCase().trim();

    const filtered = serials.filter(s => {
        if (!search) return true;
        return s.serial_number.toLowerCase().includes(search)
            || s.status.toLowerCase().includes(search)
            || (s.transaction_number && s.transaction_number.toLowerCase().includes(search));
    });

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm" role="status"></div> Mengambil data serial...</td></tr>';
        return;
    }

    filtered.forEach((serial, index) => {
        let badgeClass = 'bg-secondary';
        if (serial.status === 'Tersedia' || serial.status === 'Tersedia (Bekas)') {
            badgeClass = 'bg-success';
        } else if (serial.status === 'Dipinjam') {
            badgeClass = 'bg-warning text-dark';
        } else if (serial.status === 'Keluar') {
            badgeClass = 'bg-danger';
        }

        let transactionCol = '-';
        if (serial.transaction_number) {
            transactionCol = `<a href="../${serial.transaction_type}/detail.php?id=${serial.transaction_id}" 
                                target="_blank" class="badge bg-primary text-decoration-none">
                                <i class="bi bi-box-arrow-up-right me-1"></i>${serial.transaction_number}</a>`;
        }

        // TOMBOL AKSI: Menggunakan serial.id yang baru kita tambahkan di PHP
        let actionBtns = '';
        if (serial.status === 'Tersedia' || serial.status === 'Tersedia (Bekas)') {
            actionBtns = `
                <button class="btn btn-sm btn-outline-warning edit-serial-btn me-1" 
                        data-id="${serial.id}" data-serial="${serial.serial_number}">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger delete-serial-btn" 
                        data-id="${serial.id}">
                    <i class="bi bi-trash"></i>
                </button>`;
        }

        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="text-center">${index + 1}</td>
                <td class="serial-text">${serial.serial_number}</td>
                <td><span class="badge ${badgeClass}">${serial.status}</span></td>
                <td>${serial.warehouse_name || '-'}</td>
                <td>${transactionCol}</td>
                <td class="small">${serial.created_at}</td> 
                <td class="text-center">${actionBtns}</td> 
            </tr>
        `);
    });
}

 if (serialNumberModal) {
  serialNumberModal.addEventListener('show.bs.modal', function (event) {

   const button = event.relatedTarget;
   const productId = button.getAttribute('data-product-id');
   const productName = button.getAttribute('data-product-name');

   const title = serialNumberModal.querySelector('#productNameSerialModal');
   const tbody = serialNumberModal.querySelector('#serialNumberTable tbody');

   currentProductId = productId;
   allProductSerials = [];
   if (serialSearchInput) serialSearchInput.value = '';

   title.textContent = `Daftar Serial Number Produk: ${productName}`;
   tbody.innerHTML =
    '<tr><td colspan="7" class="text-center py-4">Memuat data serial number...</td></tr>';

   fetch('get_serials.php?product_id=' + productId + '&tab=<?= urlencode($active_tab) ?>')
    .then(res => res.json())
    .then(data => {
     if (data.serials && data.serials.length) {
      allProductSerials = data.serials;
      renderSerialTable(allProductSerials, tbody);
     } else {
      tbody.innerHTML =
       '<tr><td colspan="7" class="text-center py-4">Tidak ada serial number.</td></tr>';
     }
    })
    .catch(() => {
     tbody.innerHTML =
      '<tr><td colspan="5" class="text-center py-4 text-danger">Gagal memuat data.</td></tr>';
    });
  });
 }

 // ==========================================================
 // SEARCH SERIAL
 // ==========================================================
 if (serialSearchInput) {
  serialSearchInput.addEventListener('keyup', function () {
   const tbody = serialNumberModal.querySelector('#serialNumberTable tbody');
   renderSerialTable(allProductSerials, tbody, this.value);
  });
 }

 // ==========================================================
 // DOWNLOAD PDF SERIAL
 // ==========================================================
 const downloadBtn = document.getElementById('downloadSerialPdfBtn');
 if (downloadBtn) {
  downloadBtn.addEventListener('click', function () {
   if (!currentProductId) return;

   const keyword = serialSearchInput.value;
   let url = 'download_serial_pdf.php?product_id=' + currentProductId + '&tab=<?= urlencode($active_tab) ?>';

   if (keyword) {
    url += '&search=' + encodeURIComponent(keyword);
   }

   window.open(url, '_blank');
  });
 }

const printLabelBtn = document.getElementById('printSerialLabelBtn');
if (printLabelBtn) {
    printLabelBtn.addEventListener('click', function () {
        // Cek apakah ID produk tersedia
        if (!currentProductId) return;

        // Ambil nilai pencarian jika ada
        const keyword = document.getElementById('serialSearchInput').value;
        
        // Buat URL menuju print_label.php
        let url = 'print_label.php?product_id=' + currentProductId + '&tab=<?= urlencode($active_tab) ?>';

        // Tambahkan parameter search jika user sedang memfilter tabel
        if (keyword) {
            url += '&search=' + encodeURIComponent(keyword);
        }

        // Buka di tab baru untuk proses cetak
        window.open(url, '_blank');
    });
}

 // Tambahkan event listener untuk tombol delete serial
$(document).on('click', '.delete-serial-btn', function() {
    const serialId = $(this).data('id');
    const row = $(this).closest('tr');
    const productId = currentProductId; 
    const serialNum = row.find('.serial-text').text();

    Swal.fire({
        title: 'Hapus Permanen?',
        text: `Serial ${serialNum} akan dihapus selamanya dari database dan stok akan berkurang.`,
        icon: 'danger', // Menggunakan icon danger agar lebih kontras
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Hapus Permanen!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Menghapus...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: 'delete_serial.php',
                type: 'POST',
                data: { id: serialId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Terhapus!', 'Data telah dibuang secara permanen.', 'success');
                        
                        // Animasi hapus baris di modal
                        row.fadeOut(400, function() { 
                            $(this).remove(); 
                            
                            // Update angka stok di tabel utama (produk)
                            let productRow = $(`button[data-id="${productId}"]`).closest('tr');
                            if (productRow.length > 0) {
                                let stockCell = productRow.find('td').eq(6); 
                                let currentStock = parseInt(stockCell.text());
                                if (!isNaN(currentStock) && currentStock > 0) {
                                    stockCell.text(currentStock - 1);
                                    stockCell.addClass('text-danger fw-bold').fadeOut(100).fadeIn(100);
                                    setTimeout(() => { stockCell.removeClass('text-danger fw-bold'); }, 2000);
                                }
                            }
                        });
                    } else {
                        Swal.fire('Gagal!', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error!', 'Terjadi kesalahan sistem.', 'error');
                }
            });
        }
    });
});

// LOGIKA EDIT SERIAL NUMBER
$(document).on('click', '.edit-serial-btn', function() {
    const id = $(this).data('id');
    const currentSerial = $(this).data('serial');
    const row = $(this).closest('tr');

    Swal.fire({
        title: 'Edit Serial Number',
        input: 'text',
        inputLabel: 'Masukkan Nomor Serial Baru',
        inputValue: currentSerial,
        showCancelButton: true,
        confirmButtonText: 'Simpan',
        cancelButtonText: 'Batal',
        inputValidator: (value) => {
            if (!value) return 'Nomor serial tidak boleh kosong!';
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const newSerial = result.value;

            $.ajax({
                url: 'update_serial.php',
                type: 'POST',
                data: { id: id, new_serial: newSerial },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Berhasil!', 'Nomor serial telah diperbarui.', 'success');
                        
                        // Update tampilan di modal secara real-time
                        row.find('.serial-text').text(newSerial);
                        // Update data-attribute pada tombol agar jika diklik lagi datanya sudah baru
                        row.find('.edit-serial-btn').data('serial', newSerial);
                    } else {
                        Swal.fire('Gagal!', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error!', 'Terjadi kesalahan pada server.', 'error');
                }
            });
        }
    });
});

});
</script>

<?php include '../../includes/footer.php'; ?>