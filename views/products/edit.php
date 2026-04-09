<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan hanya admin dan staff yang bisa mengakses halaman ini
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
  header("Location: ../../index.php");
  exit();
}

$user_warehouse_id = $_SESSION['warehouse_id'];

// Tentukan warehouse target untuk stok awal berdasarkan parameter tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (isset($_POST['active_tab']) ? $_POST['active_tab'] : 'all');
if ($active_tab !== 'all' && is_numeric($active_tab)) {
    $target_warehouse_id = (int)$active_tab;
} else {
    $target_warehouse_id = $user_warehouse_id; // Default fallback
}

// Ambil daftar gudang untuk opsi kepemilikan produk
$warehouses_query = $conn->query("SELECT id, name FROM warehouses ORDER BY name");
$warehouses_list = $warehouses_query->fetch_all(MYSQLI_ASSOC);

$status_message = '';
$status_type = '';

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($product_id == 0) {
  header("Location: index.php");
  exit();
}

// Ambil data brands dan types untuk dropdown (filtered by tenant)
$brands_query = $conn->query("SELECT id, brand_name, brand_code FROM brands ORDER BY brand_name");
$brands = $brands_query->fetch_all(MYSQLI_ASSOC);

$product_types_query = $conn->query("SELECT id, type_name FROM product_types ORDER BY type_name");
$product_types = $product_types_query->fetch_all(MYSQLI_ASSOC);

// Ambil data produk
$initial_product_query = $conn->prepare("
    SELECT product.*, brands.brand_code, COALESCE(ws.stock, 0) as stock_in_tab 
    FROM products product 
    JOIN brands ON product.brand_id = brands.id 
    LEFT JOIN warehouse_stocks ws ON product.id = ws.product_id AND ws.warehouse_id = ?
    WHERE product.id = ? AND product.is_deleted = 0
");
$initial_product_query->bind_param("ii", $target_warehouse_id, $product_id);
$initial_product_query->execute();
$initial_product_result = $initial_product_query->get_result();
$product = $initial_product_result->fetch_assoc();
$initial_product_query->close();

if (!$product) {
  $_SESSION['error_message'] = "Produk tidak ditemukan.";
  header("Location: index.php");
  exit();
}

// Fetch existing serial numbers ONLY for the targeted warehouse tab
$existing_serials = [];
if ($product['has_serial']) {
  $serials_query = $conn->prepare("SELECT serial_number FROM serial_numbers WHERE product_id = ? AND warehouse_id = ? AND is_deleted = 0 ORDER BY serial_number ASC");
  $serials_query->bind_param("ii", $product_id, $target_warehouse_id);
  $serials_query->execute();
  $serials_result = $serials_query->get_result();
  while ($row = $serials_result->fetch_assoc()) {
    $existing_serials[] = $row['serial_number'];
  }
  $serials_query->close();
}

// Cek jika form disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $current_product_code = $product['product_code'];
  $brand_id_post = (int)$_POST['brand_id'];
  $product_name = trim($_POST['product_name']);
  $product_type_id = (int)$_POST['product_type_id'];
  $unit_post = trim($_POST['unit']);
  $minimum_stock_level = (int)$_POST['minimum_stock_level'];
  $location = trim($_POST['location']);
  $has_serial_post = isset($_POST['has_serial']) ? 1 : 0;
  $note = trim($_POST['note']);
  $product_warehouse_id = empty($_POST['product_warehouse_id']) ? null : (int)$_POST['product_warehouse_id'];
  $updated_at = date('Y-m-d H:i:s');
  $current_image_filename = $product['product_image'];

  $conn->begin_transaction();

  try {
    // --- LOGIKA FOTO (Kamera/Upload) ---
    $product_image_filename = $current_image_filename;
    $upload_dir = '../../assets/uploads/';
    
    if (!empty($_POST['product_image_base64'])) {
      if ($current_image_filename && file_exists($upload_dir . $current_image_filename)) unlink($upload_dir . $current_image_filename);
      $base64_image = $_POST['product_image_base64'];
      $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64_image));
      $new_file_name = uniqid('product_camera_', true) . '.png';
      file_put_contents($upload_dir . $new_file_name, $data);
      $product_image_filename = $new_file_name;
    } elseif (isset($_FILES['product_image_file']) && $_FILES['product_image_file']['error'] == UPLOAD_ERR_OK) {
      $file_ext = strtolower(pathinfo($_FILES['product_image_file']['name'], PATHINFO_EXTENSION));
      if ($current_image_filename && file_exists($upload_dir . $current_image_filename)) unlink($upload_dir . $current_image_filename);
      $new_file_name = uniqid('product_upload_', true) . '.' . $file_ext;
      move_uploaded_file($_FILES['product_image_file']['tmp_name'], $upload_dir . $new_file_name);
      $product_image_filename = $new_file_name;
    } elseif (isset($_POST['delete_current_image']) && $_POST['delete_current_image'] == '1') {
      if ($current_image_filename && file_exists($upload_dir . $current_image_filename)) unlink($upload_dir . $current_image_filename);
      $product_image_filename = null;
    }

    // --- LOGIKA PERUBAHAN BRAND CODE ---
    if ($brand_id_post != $product['brand_id']) {
      $brand_code_new = ''; 
      foreach ($brands as $brand) { if ($brand['id'] == $brand_id_post) { $brand_code_new = $brand['brand_code']; break; } }
      $last_product_query = $conn->prepare("SELECT product_code FROM products WHERE product_code LIKE ? ORDER BY product_code DESC LIMIT 1");
      $like_pattern = $brand_code_new . '-%';
      $last_product_query->bind_param("s", $like_pattern);
      $last_product_query->execute();
      $last_result = $last_product_query->get_result();
      $new_seq = 1;
      if ($last_result->num_rows > 0) {
        $last_code = $last_result->fetch_assoc()['product_code'];
        $new_seq = ((int)substr($last_code, strrpos($last_code, '-') + 1)) + 1;
      }
      $current_product_code = $brand_code_new . '-' . str_pad($new_seq, 3, '0', STR_PAD_LEFT);
    }

    $stock_to_update = 0;
    // --- PERUBAHAN UTAMA: PENANGANAN SERIAL NUMBER ARRAY ---
    if ($has_serial_post) {
      // Ambil array dari input serial_numbers[]
      $posted_serials_raw = isset($_POST['serial_numbers']) ? $_POST['serial_numbers'] : [];
      
      // Bersihkan spasi dan hapus elemen kosong
      $posted_serials = array_filter(array_map('trim', $posted_serials_raw));
      $posted_serials = array_unique($posted_serials);

      // Cek Duplikat di Database (Produk Lain)
      if (!empty($posted_serials)) {
        $placeholders = implode(',', array_fill(0, count($posted_serials), '?'));
        $check_sql = "SELECT serial_number FROM serial_numbers WHERE serial_number IN ($placeholders) AND product_id != ? AND is_deleted = 0";
        $check_stmt = $conn->prepare($check_sql);
        $types = str_repeat('s', count($posted_serials)) . 'i';
        $params = array_merge($posted_serials, [$product_id]);
        $check_stmt->bind_param($types, ...$params);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
          throw new Exception("Satu atau lebih Serial Number sudah terdaftar di produk lain.");
        }
      }

      $serials_to_add = array_diff($posted_serials, $existing_serials);
      $serials_to_remove = array_diff($existing_serials, $posted_serials);

      // Ambil stok lama untuk gudang ini (untuk hitung selisih jika non-serial)
      $old_stock = (int)$product['stock_in_tab'];

      // Tambah Serial Baru
      if (!empty($serials_to_add)) {
        $add_stmt = $conn->prepare("INSERT INTO serial_numbers (product_id, warehouse_id, serial_number, status) VALUES (?, ?, ?, 'Tersedia')");
        $adj_stmt = $conn->prepare("INSERT INTO stock_adjustments (product_id, warehouse_id, type, quantity, reason, user_id) VALUES (?, ?, 'Masuk', 1, ?, ?)");
        foreach ($serials_to_add as $sn) {
          $add_stmt->bind_param("iis", $product_id, $target_warehouse_id, $sn);
          $add_stmt->execute();
          
          $reason = "Tambah S/N Baru: " . $sn;
          $adj_stmt->bind_param("iisi", $product_id, $target_warehouse_id, $reason, $_SESSION['user_id']);
          $adj_stmt->execute();
        }
        $adj_stmt->close();
      }

      // Hapus Serial (Soft Delete)
      if (!empty($serials_to_remove)) {
        $placeholders_rem = implode(',', array_fill(0, count($serials_to_remove), '?'));
        $rem_stmt = $conn->prepare("UPDATE serial_numbers SET is_deleted = 1 WHERE product_id = ? AND serial_number IN ($placeholders_rem)");
        $types_rem = 'i' . str_repeat('s', count($serials_to_remove));
        $params_rem = array_merge([$product_id], $serials_to_remove);
        $rem_stmt->bind_param($types_rem, ...$params_rem);
        $rem_stmt->execute();

        // Catat pengeluaran stok di histori penyesuaian
        $adj_rem_stmt = $conn->prepare("INSERT INTO stock_adjustments (product_id, warehouse_id, type, quantity, reason, user_id) VALUES (?, ?, 'Keluar', 1, ?, ?)");
        foreach ($serials_to_remove as $sn) {
            $reason = "Hapus S/N: " . $sn;
            $adj_rem_stmt->bind_param("iisi", $product_id, $target_warehouse_id, $reason, $_SESSION['user_id']);
            $adj_rem_stmt->execute();
        }
        $adj_rem_stmt->close();
      }
      
      // Hitung Stok Berdasarkan Serial Aktif (hanya di gudang target)
      $count_q = $conn->prepare("SELECT COUNT(*) AS active_count FROM serial_numbers WHERE product_id = ? AND warehouse_id = ? AND is_deleted = 0");
      $count_q->bind_param("ii", $product_id, $target_warehouse_id);
      $count_q->execute();
      $stock_to_update = $count_q->get_result()->fetch_assoc()['active_count'];

    } else {
      $stock_to_update = (int)$_POST['stock'];
      $old_stock = (int)$product['stock_in_tab'];

      // Hitung selisih untuk histori penyesuaian
      if ($stock_to_update != $old_stock) {
          $diff = $stock_to_update - $old_stock;
          $adj_type = ($diff > 0) ? 'Masuk' : 'Keluar';
          $adj_qty = abs($diff);
          $adj_reason = "Penyesuaian Stok Manual (Edit Produk)";
          
          $adj_stmt = $conn->prepare("INSERT INTO stock_adjustments (product_id, warehouse_id, type, quantity, reason, user_id) VALUES (?, ?, ?, ?, ?, ?)");
          $adj_stmt->bind_param("iisisi", $product_id, $target_warehouse_id, $adj_type, $adj_qty, $adj_reason, $_SESSION['user_id']);
          $adj_stmt->execute();
          $adj_stmt->close();
      }

      // Jika berubah dari Serial ke Non-Serial (Kuantitas), hapus SEMUA serial aktif untuk produk ini di seluruh gudang
      // Karena has_serial adalah status global produk.
      $conn->query("UPDATE serial_numbers SET is_deleted = 1 WHERE product_id = $product_id");

      // Update Warehouse Stocks
      $stock_sql = "INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock = ?";
      $stock_stmt = $conn->prepare($stock_sql);
      $stock_stmt->bind_param("iiii", $product_id, $target_warehouse_id, $stock_to_update, $stock_to_update);
      $stock_stmt->execute();
      $stock_stmt->close();
    }

    // UPDATE PRODUK (Stok global jangan diupdate manual karena sudah otomatis di-sync via DB Trigger)
    $sql_update = "UPDATE products SET product_code = ?, brand_id = ?, product_name = ?, product_type_id = ?, unit = ?, minimum_stock_level = ?, location = ?, has_serial = ?, product_image = ?, note = ?, warehouse_id = ?, updated_at = ? WHERE id = ?";
    $stmt = $conn->prepare($sql_update);
    $stmt->bind_param('sisisisissisi', $current_product_code, $brand_id_post, $product_name, $product_type_id, $unit_post, $minimum_stock_level, $location, $has_serial_post, $product_image_filename, $note, $product_warehouse_id, $updated_at, $product_id);
    
    if ($stmt->execute()) {
      $conn->commit();
      $_SESSION['success_message'] = "Produk berhasil diperbarui!";
      header("Location: index.php?tab=" . htmlspecialchars($active_tab));
      exit();
    }

  } catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = $e->getMessage();
    header("Location: edit.php?id=" . $product_id); 
    exit();
  }
}

// Data untuk Display
if (isset($_SESSION['success_message'])) { $status_message = $_SESSION['success_message']; $status_type = 'success'; unset($_SESSION['success_message']); }
elseif (isset($_SESSION['error_message'])) { $status_message = $_SESSION['error_message']; $status_type = 'danger'; unset($_SESSION['error_message']); }

$product_query_display = $conn->prepare("SELECT product.*, brands.brand_code, COALESCE(ws.stock, 0) as stock_in_tab 
                                         FROM products product 
                                         JOIN brands ON product.brand_id = brands.id 
                                         LEFT JOIN warehouse_stocks ws ON product.id = ws.product_id AND ws.warehouse_id = ?
                                         WHERE product.id = ?");
$product_query_display->bind_param("ii", $target_warehouse_id, $product_id);
$product_query_display->execute();
$product = $product_query_display->get_result()->fetch_assoc();

// Gunakan stock terfilter sebagai nilai default form 
$product['stock'] = $product['stock_in_tab'];

$existing_serials_for_display = [];
$serials_q = $conn->prepare("SELECT serial_number FROM serial_numbers WHERE product_id = ? AND warehouse_id = ? AND is_deleted = 0 ORDER BY serial_number ASC");
$serials_q->bind_param("ii", $product_id, $target_warehouse_id);
$serials_q->execute();
$res_s = $serials_q->get_result();
while ($row = $res_s->fetch_assoc()) { $existing_serials_for_display[] = $row['serial_number']; }
?>

<div class="container-fluid py-4">
  <div class="content-header mb-4">
    <h1 class="fw-bold">Ubah Produk</h1>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <h5 class="mb-0 fw-bold text-dark">Formulir Ubah Produk (Gudang Tab: <?= htmlspecialchars($active_tab) ?>)</h5>
      <a href="index.php?tab=<?= htmlspecialchars($active_tab) ?>" class="btn btn-secondary btn-sm rounded-pill px-3">
        <i class="bi bi-arrow-left me-2"></i>Kembali
      </a>
    </div>
    <div class="card-body">
      <?php if ($status_message): ?>
       <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

      <form action="edit.php?id=<?= $product['id'] ?>&tab=<?= htmlspecialchars($active_tab) ?>" method="POST" enctype="multipart/form-data">
        <div class="row">
          <div class="col-md-6 mb-3">
            <label for="product_code_display" class="form-label">Kode Produk</label>
            <input type="hidden" name="active_tab" value="<?= htmlspecialchars($active_tab) ?>">
            <input type="text" class="form-control bg-light" id="product_code_display" value="<?= htmlspecialchars($product['product_code']) ?>" required readonly>
            <input type="hidden" name="product_code" value="<?= htmlspecialchars($product['product_code']) ?>">
          </div>
          <div class="col-md-6 mb-3">
            <label for="product_name" class="form-label">Nama Produk</label>
            <input type="text" class="form-control" id="product_name" name="product_name" value="<?= htmlspecialchars($product['product_name']) ?>" required>
          </div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label for="brand_id" class="form-label">Merek</label>
            <select class="form-select" id="brand_id" name="brand_id" required>
              <option value="">Pilih Merek</option>
              <?php foreach ($brands as $brand): ?>
                <option value="<?= $brand['id'] ?>" <?= ($product['brand_id'] == $brand['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($brand['brand_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label for="product_type_id" class="form-label">Tipe Produk</label>
            <select class="form-select" id="product_type_id" name="product_type_id" required>
              <option value="">Pilih Tipe Produk</option>
              <?php foreach ($product_types as $type): ?>
                <option value="<?= $type['id'] ?>" <?= ($product['product_type_id'] == $type['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($type['type_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="row">
          <div class="col-md-12 mb-3">
            <label for="product_warehouse_id" class="form-label fw-bold"><i class="bi bi-box-seam me-1"></i>Tipe Akses / Kepemilikan Produk</label>
            <select class="form-select border-primary" id="product_warehouse_id" name="product_warehouse_id">
              <option value="" <?= is_null($product['warehouse_id']) ? 'selected' : '' ?>>🌐 Global (Tersedia Semuanya / Lintas Gudang)</option>
              <optgroup label="Spesifik Gudang (Eksklusif)">
                  <?php foreach ($warehouses_list as $wh): ?>
                      <option value="<?= $wh['id'] ?>" <?= ($product['warehouse_id'] == $wh['id']) ? 'selected' : '' ?>>
                          🏢 Eksklusif Produk untuk <?= htmlspecialchars($wh['name']) ?>
                      </option>
                  <?php endforeach; ?>
              </optgroup>
            </select>
            <div class="form-text">Pilih 'Global' jika barang ini ada dan dapat diakses di beberapa gudang. Pilih 'Eksklusif' jika barang ini mutlak hanya boleh dikelola oleh 1 gudang saja.</div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6 mb-3">
            <label for="unit" class="form-label">Unit</label>
            <input type="text" class="form-control" id="unit" name="unit" value="<?= htmlspecialchars($product['unit']) ?>" required>
          </div>
          <div class="col-md-6 mb-3">
            <label for="location" class="form-label">Lokasi</label>
            <input type="text" class="form-control" id="location" name="location" value="<?= htmlspecialchars($product['location']) ?>" required>
          </div>
        </div>
                
        <div class="row">
          <div class="col-12 mb-3">
            <label for="note" class="form-label">Catatan (Opsional)</label>
            <textarea class="form-control" id="note" name="note" rows="3"><?= htmlspecialchars($product['note']) ?></textarea>
          </div>
        </div>
                
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" value="1" id="has_serial_checkbox" name="has_serial" <?= $product['has_serial'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="has_serial_checkbox">
            Produk ini memiliki Serial Number
          </label>
        </div>

        <div id="stock_input_group" class="mb-3" style="<?= $product['has_serial'] ? 'display: none;' : 'display: block;' ?>">
          <label for="stock" class="form-label">Stok</label>
          <input type="number" class="form-control" id="stock" name="stock" value="<?= htmlspecialchars($product['stock']) ?>" min="0" <?= $product['has_serial'] ? '' : 'required' ?>>
        </div>

        <div id="serial_numbers_input_group" class="mb-3" style="<?= $product['has_serial'] ? 'display: block;' : 'display: none;' ?>">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label mb-0">Serial Numbers</label>
            <div class="gap-2 d-flex">
              <button type="button" class="btn btn-outline-primary btn-sm" id="addSerialBtn"><i class="bi bi-plus-circle me-1"></i> Tambah Baris</button>
              <button type="button" class="btn btn-primary btn-sm" id="startBarcodeScannerButton"><i class="bi bi-upc-scan me-1"></i> Scan Barcode</button>
            </div>
          </div>
          <div id="serial_container">
            <?php 
            // Inisialisasi baris dari data yang sudah ada di database
            if (!empty($existing_serials_for_display)) {
              foreach ($existing_serials_for_display as $index => $sn) {
                ?>
                <div class="input-group mb-2 serial-row">
                  <span class="input-group-text"><?= $index + 1 ?></span>
                  <input type="text" name="serial_numbers[]" class="form-control" placeholder="Masukkan S/N" value="<?= htmlspecialchars($sn) ?>" required>
                  <button type="button" class="btn btn-outline-danger remove-serial-btn"><i class="bi bi-trash"></i></button>
                </div>
                <?php
              }
            }
            ?>
          </div>
        </div>
        
        <div class="mb-3">
          <label for="minimum_stock_level" class="form-label">Stok Minimum</label>
          <input type="number" class="form-control" id="minimum_stock_level" name="minimum_stock_level" value="<?= htmlspecialchars($product['minimum_stock_level']) ?>" required min="0">
        </div>
        
        <div class="mb-3">
          <label for="product_image_file" class="form-label">Foto Produk (Pilih file atau gunakan kamera)</label>
          <input type="file" class="form-control mb-2" id="product_image_file" name="product_image_file">
          
          <?php if ($product['product_image']): ?>
            <div class="mb-2" id="currentImageContainer">
              <img src="<?= htmlspecialchars($upload_dir . $product['product_image']) ?>" alt="Foto Produk" class="img-fluid rounded border p-1" style="max-height: 200px;">
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" value="1" id="delete_current_image" name="delete_current_image">
                <label class="form-check-label" for="delete_current_image">
                  Hapus foto yang sudah ada
                </label>
              </div>
            </div>
          <?php else: ?>
            <p class="text-muted" id="noImageMessage">Tidak ada foto terunggah.</p>
          <?php endif; ?>

          <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-secondary btn-sm" id="startCameraButton"><i class="bi bi-camera me-1"></i> Buka Kamera</button>
            <button type="button" class="btn btn-primary btn-sm" id="switchCameraButton" style="display: none;"><i class="bi bi-arrow-repeat me-1"></i> Ganti Kamera</button>
            <button type="button" class="btn btn-info btn-sm text-white" id="takePictureButton" style="display: none;"><i class="bi bi-camera-fill me-1"></i> Ambil Foto</button>
            <button type="button" class="btn btn-danger btn-sm" id="stopCameraButton" style="display: none;"><i class="bi bi-x-circle me-1"></i> Tutup Kamera</button>
          </div>
          
          <video id="cameraFeed" class="mt-3 rounded shadow" autoplay style="width: 100%; max-width: 400px; display: none;"></video>
          <canvas id="photoCanvas" style="display: none;"></canvas>
          <img id="capturedImagePreview" src="" alt="Foto yang Diambil" class="img-fluid rounded border mt-3" style="width: 100%; max-width: 400px; display: none;">
          <input type="hidden" name="product_image_base64" id="productImageBase64">
        </div>

        <button type="submit" class="btn btn-warning rounded-pill px-4">
          <i class="bi bi-arrow-clockwise me-2"></i>Perbarui Produk
        </button>
      </form>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>

<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="barcodeScannerModalLabel">Pemindai Barcode</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <video id="barcodeVideoFeed" class="w-100 rounded" autoplay playsinline style="width: 100%; height: auto;"></video>
        <p id="barcodeResultDisplay" class="mt-2 text-muted">Arahkan kamera ke barcode.</p>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="messageModal" tabindex="-1" aria-labelledby="messageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="messageModalLabel">Pesan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <p id="modalMessageContent"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const hasSerialCheckbox = document.getElementById('has_serial_checkbox');
    const stockInputGroup = document.getElementById('stock_input_group');
    const stockInput = document.getElementById('stock');
    const serialNumbersInputGroup = document.getElementById('serial_numbers_input_group');
    
    // Elemen Baru untuk Kolom Serial
    const serialContainer = document.getElementById('serial_container');
    const addSerialBtn = document.getElementById('addSerialBtn');
    
    // Elemen untuk pemindaian barcode
    const startBarcodeScannerButton = document.getElementById('startBarcodeScannerButton');
    const barcodeScannerModalElement = document.getElementById('barcodeScannerModal');
    const barcodeScannerModal = new bootstrap.Modal(barcodeScannerModalElement);
    const barcodeVideoFeed = document.getElementById('barcodeVideoFeed');
    const barcodeResultDisplay = document.getElementById('barcodeResultDisplay');
    let scannerStream = null;
    let scanningInterval = null;

    // Elemen untuk kamera foto
    const startCameraButton = document.getElementById('startCameraButton');
    const switchCameraButton = document.getElementById('switchCameraButton');
    const takePictureButton = document.getElementById('takePictureButton');
    const stopCameraButton = document.getElementById('stopCameraButton');
    const cameraFeed = document.getElementById('cameraFeed');
    const photoCanvas = document.getElementById('photoCanvas');
    const capturedImagePreview = document.getElementById('capturedImagePreview');
    const productImageBase64 = document.getElementById('productImageBase64');
    const productImageFileInput = document.getElementById('product_image_file');
    const currentImageContainer = document.getElementById('currentImageContainer');
    const noImageMessage = document.getElementById('noImageMessage');
    const deleteCurrentImageCheckbox = document.getElementById('delete_current_image');

    let stream;
    let currentFacingMode = 'environment';

    // --- LOGIKA BARCODE SCANNER HARDWARE ---
let barcodeBuffer = "";
let lastKeyTime = Date.now();

document.addEventListener('keydown', function(e) {
    // Hanya jalan jika fitur Serial Number aktif
    if (!hasSerialCheckbox.checked) return;

    // Abaikan jika fokus sedang berada di input teks lain (agar tidak bentrok)
    // Kecuali jika fokus berada di dalam salah satu input serial_numbers[]
    const activeElem = document.activeElement;
    const isInputSerial = activeElem.name === "serial_numbers[]";
    const isOtherInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(activeElem.tagName) && !isInputSerial;

    if (isOtherInput) return;

    const currentTime = Date.now();
    
    // Scanner hardware biasanya mengirim karakter dengan jeda < 50ms
    // Jika jeda antar karakter lebih dari 100ms, kita anggap itu ketikan manusia (reset buffer)
    if (currentTime - lastKeyTime > 100) {
        barcodeBuffer = "";
    }

    lastKeyTime = currentTime;

    if (e.key === 'Enter') {
        if (barcodeBuffer.length > 0) {
            e.preventDefault(); // Mencegah form tersubmit secara tidak sengaja
            handleBarcodeResult(barcodeBuffer);
            barcodeBuffer = "";
        }
    } else if (e.key.length === 1) {
        barcodeBuffer += e.key;
    }
});

// Fungsi sentral untuk memproses hasil scan (baik dari Kamera maupun Hardware)
function handleBarcodeResult(code) {
    const inputs = serialContainer.querySelectorAll('input[name="serial_numbers[]"]');
    let filled = false;

    // 1. Cari baris yang sudah ada tapi masih kosong
    for (let input of inputs) {
        if (input.value.trim() === '') {
            input.value = code;
            filled = true;
            break;
        }
    }

    // 2. Jika semua baris sudah terisi, buat baris baru otomatis
    if (!filled) {
        addNewSerialRow(code);
    }

    // 3. Otomatis tambah baris kosong di bawahnya agar siap untuk scan berikutnya
    // (Opsional, agar user bisa langsung scan lagi tanpa henti)
    setTimeout(() => {
        const currentInputs = serialContainer.querySelectorAll('input[name="serial_numbers[]"]');
        const lastInput = currentInputs[currentInputs.length - 1];
        if (lastInput.value.trim() !== '') {
            addNewSerialRow();
        }
        
        // Fokuskan ke input terakhir yang masih kosong
        const finalInputs = serialContainer.querySelectorAll('input[name="serial_numbers[]"]');
        finalInputs[finalInputs.length - 1].focus();
    }, 100);
}

    function showModalMessage(message) {
        document.getElementById('modalMessageContent').textContent = message;
        const messageModal = new bootstrap.Modal(document.getElementById('messageModal'));
        messageModal.show();
    }

    // --- FUNGSI: Tambah Baris Kolom Baru ---
    function addNewSerialRow(value = '') {
        const rowCount = serialContainer.querySelectorAll('.serial-row').length + 1;
        const newRow = document.createElement('div');
        newRow.className = 'input-group mb-2 serial-row';
        newRow.innerHTML = `
            <span class="input-group-text">${rowCount}</span>
            <input type="text" name="serial_numbers[]" class="form-control" placeholder="Masukkan S/N" value="${value}" required>
            <button type="button" class="btn btn-outline-danger remove-serial-btn"><i class="bi bi-trash"></i></button>
        `;
        serialContainer.appendChild(newRow);
        reorderLabels();
    }

    // --- FUNGSI: Urutkan Ulang Nomor Indikator ---
    function reorderLabels() {
        const rows = serialContainer.querySelectorAll('.serial-row');
        rows.forEach((row, index) => {
            row.querySelector('.input-group-text').textContent = index + 1;
        });
    }

    if (addSerialBtn) {
        addSerialBtn.addEventListener('click', () => addNewSerialRow());
    }

    serialContainer.addEventListener('click', (e) => {
        if (e.target.closest('.remove-serial-btn')) {
            const rows = serialContainer.querySelectorAll('.serial-row');
            if (rows.length > 1) {
                e.target.closest('.serial-row').remove();
                reorderLabels();
            } else {
                e.target.closest('.serial-row').querySelector('input').value = '';
            }
        }
    });

    function toggleSerialInput() {
        const serialInputs = serialContainer.querySelectorAll('input[name="serial_numbers[]"]');
        if (hasSerialCheckbox.checked) {
            stockInputGroup.style.display = 'none';
            stockInput.removeAttribute('required');
            stockInput.value = '0';
            serialNumbersInputGroup.style.display = 'block';
            serialInputs.forEach(input => input.setAttribute('required', 'required'));
            if (serialContainer.children.length === 0) addNewSerialRow();
        } else {
            stockInputGroup.style.display = 'block';
            stockInput.setAttribute('required', 'required');
            serialNumbersInputGroup.style.display = 'none';
            serialInputs.forEach(input => input.removeAttribute('required'));
        }
    }

    hasSerialCheckbox.addEventListener('change', toggleSerialInput);
    toggleSerialInput();

    // --- LOGIKA KAMERA FOTO PRODUK (TETAP SAMA) ---
    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            cameraFeed.srcObject = null;
        }
        cameraFeed.style.display = 'none';
        takePictureButton.style.display = 'none';
        switchCameraButton.style.display = 'none';
        stopCameraButton.style.display = 'none';
        startCameraButton.style.display = 'inline-block';
        productImageFileInput.disabled = false;
        if (currentImageContainer) currentImageContainer.style.display = 'block';
        else if (noImageMessage) noImageMessage.style.display = 'block';
        capturedImagePreview.style.display = 'none';
    }
    
    async function startCamera(facingMode) {
        if (stream) stopCamera();
        try {
            const constraints = { video: { facingMode: facingMode } };
            stream = await navigator.mediaDevices.getUserMedia(constraints);
            cameraFeed.srcObject = stream;
            cameraFeed.style.display = 'block';
            takePictureButton.style.display = 'inline-block';
            switchCameraButton.style.display = 'inline-block';
            stopCameraButton.style.display = 'inline-block';
            startCameraButton.style.display = 'none';
            productImageFileInput.disabled = true;
            if (currentImageContainer) currentImageContainer.style.display = 'none';
            if (noImageMessage) noImageMessage.style.display = 'none';
            currentFacingMode = facingMode;
        } catch (err) {
            showModalMessage('Tidak dapat mengakses kamera.');
            stopCamera();
        }
    }

    startCameraButton.addEventListener('click', () => startCamera(currentFacingMode));
    switchCameraButton.addEventListener('click', () => {
        currentFacingMode = currentFacingMode === 'environment' ? 'user' : 'environment';
        startCamera(currentFacingMode);
    });
    takePictureButton.addEventListener('click', () => {
        if (stream) {
            photoCanvas.width = cameraFeed.videoWidth;
            photoCanvas.height = cameraFeed.videoHeight;
            photoCanvas.getContext('2d').drawImage(cameraFeed, 0, 0);
            const imageDataUrl = photoCanvas.toDataURL('image/png');
            capturedImagePreview.src = imageDataUrl;
            capturedImagePreview.style.display = 'block';
            productImageBase64.value = imageDataUrl;
        }
    });
    stopCameraButton.addEventListener('click', stopCamera);

    // --- LOGIKA SCAN BARCODE ---
    startBarcodeScannerButton.addEventListener('click', () => {
        if (hasSerialCheckbox.checked) {
            barcodeResultDisplay.textContent = 'Arahkan kamera ke barcode.';
            barcodeScannerModal.show();
        } else {
            showModalMessage('Fitur scan barcode hanya untuk produk dengan Serial Number.');
        }
    });

    barcodeScannerModalElement.addEventListener('shown.bs.modal', async function () {
        try {
            scannerStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
            barcodeVideoFeed.srcObject = scannerStream;
            await barcodeVideoFeed.play();

            const barcodeDetector = new BarcodeDetector({ 
                formats: ['code_128', 'ean_13', 'ean_8', 'code_39', 'upc_a', 'upc_e', 'qr_code'] 
            });
            
            scanningInterval = setInterval(async () => {
                try {
                    const barcodes = await barcodeDetector.detect(barcodeVideoFeed);
                    if (barcodes.length > 0) {
                        const code = barcodes[0].rawValue;
                        clearInterval(scanningInterval);
                        barcodeScannerModal.hide();

                        const inputs = serialContainer.querySelectorAll('input[name="serial_numbers[]"]');
                        let filled = false;
                        for (let input of inputs) {
                            if (input.value.trim() === '') {
                                input.value = code;
                                filled = true;
                                break;
                            }
                        }
                        if (!filled) addNewSerialRow(code);
                    }
                } catch (err) { console.error(err); }
            }, 500);
        } catch (err) {
            showModalMessage('Kamera barcode tidak dapat diakses.');
            barcodeScannerModal.hide();
        }
    });

    barcodeScannerModalElement.addEventListener('hidden.bs.modal', function () {
        if (scanningInterval) clearInterval(scanningInterval);
        if (scannerStream) scannerStream.getTracks().forEach(track => track.stop());
        barcodeVideoFeed.srcObject = null;
    });
});
</script>
