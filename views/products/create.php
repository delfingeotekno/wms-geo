<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan hanya admin dan staff yang bisa mengakses halaman ini
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
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

$status_message = '';
$status_type = '';

// Ambil daftar merek dan tipe produk untuk form dropdown
$brands_query = $conn->query("SELECT id, brand_name, brand_code FROM brands ORDER BY brand_name");
$brands = $brands_query->fetch_all(MYSQLI_ASSOC);

$product_types_query = $conn->query("SELECT id, type_name FROM product_types ORDER BY type_name");
$product_types = $product_types_query->fetch_all(MYSQLI_ASSOC);

// Ambil daftar gudang untuk opsi kepemilikan produk
$warehouses_query = $conn->query("SELECT id, name FROM warehouses ORDER BY name");
$warehouses_list = $warehouses_query->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $brand_id = (int)$_POST['brand_id'];
  $product_name = trim($_POST['product_name']);
  $product_type_id = (int)$_POST['product_type_id'];
  $unit = trim($_POST['unit']);
  $location = trim($_POST['location']);
  $minimum_stock_level = (int)$_POST['minimum_stock_level'];
  $note = trim($_POST['note']); // <<< PERUBAHAN 1: Ambil nilai catatan
  $has_serial = isset($_POST['has_serial']) ? 1 : 0;
  $product_warehouse_id = !empty($_POST['product_warehouse_id']) ? (int)$_POST['product_warehouse_id'] : null;
  
  // Inisialisasi stock, akan dihitung ulang jika has_serial = 1
  $stock = 0; 
  $serial_array = []; // Inisialisasi array untuk serial numbers

  // Mulai transaksi database
  $conn->begin_transaction();

  try {
    // --- LOGIKA UNTUK UPLOAD FOTO ATAU AMBIL DARI CAMERA ---
    $product_image = null; 
    $upload_dir = '../../assets/uploads/'; 
   
    // Pastikan direktori ada
    if (!is_dir($upload_dir)) {
      mkdir($upload_dir, 0777, true);
    }
   
    // Prioritaskan gambar dari kamera jika ada data base64
    if (!empty($_POST['product_image_base64'])) {
      $base64_image = $_POST['product_image_base64'];
      $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64_image));
      $new_file_name = uniqid('product_camera_', true) . '.png';
      $upload_path = $upload_dir . $new_file_name;

      if (file_put_contents($upload_path, $data)) {
        $product_image = $new_file_name;
      } else {
        throw new Exception("Gagal menyimpan foto dari kamera.");
      }
    }
    // Jika tidak ada gambar dari kamera, cek upload file biasa
    elseif (isset($_FILES['product_image_file']) && $_FILES['product_image_file']['error'] == UPLOAD_ERR_OK) {
      $file_tmp_path = $_FILES['product_image_file']['tmp_name'];
      $file_name = $_FILES['product_image_file']['name'];
      $file_size = $_FILES['product_image_file']['size'];
      $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

      $allowed_ext = ['jpeg', 'jpg', 'png', 'gif'];
      if (!in_array($file_ext, $allowed_ext)) {
        throw new Exception("Ekstensi file tidak diizinkan. Hanya JPG, JPEG, PNG, dan GIF.");
      } elseif ($file_size > 5000000) { // Batas 5MB
        throw new Exception("Ukuran file terlalu besar, maksimal 5MB.");
      } else {
        $new_file_name = uniqid('product_upload_', true) . '.' . $file_ext;
        $upload_path = $upload_dir . $new_file_name;
       
        if (move_uploaded_file($file_tmp_path, $upload_path)) {
          $product_image = $new_file_name;
        } else {
          throw new Exception("Gagal mengunggah file. Coba lagi.");
        }
      }
    }
    // --- AKHIR LOGIKA UPLOAD FOTO ATAU AMBIL DARI CAMERA ---

    // Validasi input
    if (empty($product_name) || $brand_id == 0 || $product_type_id == 0 || empty($unit) || $minimum_stock_level === '' || empty($location)) {
      throw new Exception('Semua field wajib diisi (kecuali Catatan).');
    }

    // --- LOGIKA GENERATE KODE PRODUK ---
    $brand_code = '';
    foreach ($brands as $brand) {
      if ($brand['id'] == $brand_id) {
        $brand_code = $brand['brand_code'];
        break;
      }
    }
    $last_product_query = $conn->prepare("SELECT product_code FROM products WHERE product_code LIKE ? ORDER BY product_code DESC LIMIT 1");
    $like_pattern = $brand_code . '-%';
    $last_product_query->bind_param("s", $like_pattern);
    $last_product_query->execute();
    $last_product_result = $last_product_query->get_result();
    $new_sequence_number = 1;
    if ($last_product_result->num_rows > 0) {
      $last_product = $last_product_result->fetch_assoc();
      $last_code = $last_product['product_code'];
      $last_sequence = (int)substr($last_code, strrpos($last_code, '-') + 1);
      $new_sequence_number = $last_sequence + 1;
    }
    $product_code = $brand_code . '-' . str_pad($new_sequence_number, 3, '0', STR_PAD_LEFT);

    // --- PERUBAHAN UTAMA: TENTUKAN STOK BERDASARKAN has_serial (ARRAY) ---
    if ($has_serial) {
        $serial_array = [];
        
        // Cek apakah data dikirim sebagai array dari input dinamis
        if (isset($_POST['serial_numbers']) && is_array($_POST['serial_numbers'])) {
            // Bersihkan spasi tiap elemen dan hapus elemen yang kosong
            $serial_array = array_filter(array_map('trim', $_POST['serial_numbers']));
        }

        // Hitung stok berdasarkan jumlah serial yang masuk
        $stock = count($serial_array);

        // Jalankan validasi HANYA JIKA ada serial yang diinput
        if ($stock > 0) {
            // Validasi duplikat di input form internal
            if (count($serial_array) !== count(array_unique($serial_array))) {
                throw new Exception("Ada serial number duplikat dalam daftar yang Anda masukkan.");
            }

            // Cek duplikat serial number di database
            $placeholders = implode(',', array_fill(0, count($serial_array), '?'));
            $check_duplicate_sql = "SELECT serial_number FROM serial_numbers WHERE serial_number IN ($placeholders) AND is_deleted = 0";
            
            $check_stmt = $conn->prepare($check_duplicate_sql);
            $types = str_repeat('s', count($serial_array));
            $check_stmt->bind_param($types, ...$serial_array);
            $check_stmt->execute();
            $duplicate_result = $check_stmt->get_result();

            if ($duplicate_result->num_rows > 0) {
                $duplicate_serials_found = [];
                while ($row = $duplicate_result->fetch_assoc()) {
                    $duplicate_serials_found[] = $row['serial_number'];
                }
                throw new Exception("Serial number berikut sudah terdaftar di sistem: " . implode(', ', $duplicate_serials_found));
            }
            $check_stmt->close();
        }
        
    } else {
        // Logika untuk produk non-serial
        $stock = isset($_POST['stock']) ? intval($_POST['stock']) : 0;
        if ($stock < 0) {
            throw new Exception("Stok tidak boleh negatif.");
        }
    }

    // --- QUERY SQL UNTUK MENYIMPAN DATA PRODUK ---
    $sql = "INSERT INTO products (product_code, brand_id, product_name, product_type_id, unit, stock, minimum_stock_level, location, has_serial, product_image, note, warehouse_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
   
    $stmt = $conn->prepare($sql);
   
    if ($stmt) {
      $stmt->bind_param("sisisiisissi", $product_code, $brand_id, $product_name, $product_type_id, $unit, $stock, $minimum_stock_level, $location, $has_serial, $product_image, $note, $product_warehouse_id);
     
      if ($stmt->execute()) {
        $product_id = $conn->insert_id;
        $stmt->close();

        // --- SIMPAN STOCK DI WAREHOUSE (Warehouse Stocks) ---
        if (!$has_serial && $stock > 0) {
            $stock_sql = "INSERT INTO warehouse_stocks (product_id, warehouse_id, stock) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock = stock + ?";
            $stock_stmt = $conn->prepare($stock_sql);
            $stock_stmt->bind_param("iiii", $product_id, $target_warehouse_id, $stock, $stock);
            if (!$stock_stmt->execute()) {
                 throw new Exception("Gagal menyimpan stok ke warehouse: " . $stock_stmt->error);
            }
            $stock_stmt->close();
        }

        // --- SIMPAN SERIAL NUMBERS ---
        if ($has_serial && !empty($serial_array)) {
          $serial_sql = "INSERT INTO serial_numbers (product_id, warehouse_id, serial_number, status) VALUES (?, ?, ?, 'Tersedia')";
          $serial_stmt = $conn->prepare($serial_sql);
         
          foreach ($serial_array as $serial) {
            if (!empty($serial)) {
              $serial_stmt->bind_param("iis", $product_id, $target_warehouse_id, $serial);
              if (!$serial_stmt->execute()) {
                throw new Exception("Gagal menambahkan serial number '" . htmlspecialchars($serial) . "': " . $serial_stmt->error);
              }
            }
          }
          $serial_stmt->close();

          // Catat stok awal ke history penyesuaian (Produk Serial)
          $adj_stmt = $conn->prepare("INSERT INTO stock_adjustments (product_id, warehouse_id, type, quantity, reason, user_id) VALUES (?, ?, 'Masuk', ?, 'Stok Awal Produk Baru', ?)");
          $adj_stmt->bind_param("iiii", $product_id, $target_warehouse_id, $stock, $_SESSION['user_id']);
          $adj_stmt->execute();
          $adj_stmt->close();
        } else if (!$has_serial && $stock > 0) {
          // Stok awal untuk non-serial sudah disimpan di warehouse_stocks, sekarang catat lognya
          $adj_stmt = $conn->prepare("INSERT INTO stock_adjustments (product_id, warehouse_id, type, quantity, reason, user_id) VALUES (?, ?, 'Masuk', ?, 'Stok Awal Produk Baru', ?)");
          $adj_stmt->bind_param("iiii", $product_id, $target_warehouse_id, $stock, $_SESSION['user_id']);
          $adj_stmt->execute();
          $adj_stmt->close();
        }

        $conn->commit();
        $_SESSION['success_message'] = "Produk berhasil ditambahkan!";
        header("Location: index.php?tab=" . urlencode($active_tab) . "&status=success_add");
        exit();
      } else {
        throw new Exception("Gagal menambahkan produk: " . $stmt->error);
      }
    } else {
      throw new Exception("Error dalam menyiapkan statement SQL: " . $conn->error);
    }

  } catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = $e->getMessage();
    header("Location: create.php");
    exit();
  }
}

// Ambil pesan status
if (isset($_SESSION['success_message'])) {
    $status_message = $_SESSION['success_message'];
    $status_type = 'success';
    unset($_SESSION['success_message']);
} elseif (isset($_SESSION['error_message'])) {
    $status_message = $_SESSION['error_message'];
    $status_type = 'danger';
    unset($_SESSION['error_message']);
}
?>

<div class="content-header mb-4">
  <h1 class="fw-bold">Tambah Produk Baru</h1>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <h5 class="mb-0 fw-bold text-dark">Formulir Produk</h5>
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
   
    <form action="create.php" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="active_tab" value="<?= htmlspecialchars($active_tab) ?>">
      
      <div class="row">
        <div class="col-md-12 mb-3">
          <label for="product_warehouse_id" class="form-label">Tipe Akses / Kepemilikan Produk</label>
          <select class="form-select" id="product_warehouse_id" name="product_warehouse_id">
            <option value="">Global (Tersedia untuk Semua Gudang)</option>
            <?php foreach ($warehouses_list as $wh): ?>
              <option value="<?= $wh['id'] ?>" <?= ($active_tab == $wh['id'] || (isset($_POST['product_warehouse_id']) && $_POST['product_warehouse_id'] == $wh['id'])) ? 'selected' : '' ?>>
                Hanya untuk <?= htmlspecialchars($wh['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted">Jika Global, produk ini akan muncul di katalog semua gudang. Jika eksklusif, hanya gudang terpilih yang akan melihatnya.</small>
        </div>
      </div>

      <div class="row">
        <div class="col-md-6 mb-3">
          <label for="brand_id" class="form-label">Merek</label>
          <select class="form-select" id="brand_id" name="brand_id" required>
            <option value="">Pilih Merek</option>
            <?php foreach ($brands as $brand): ?>
              <option value="<?= $brand['id'] ?>" <?= (isset($_POST['brand_id']) && $_POST['brand_id'] == $brand['id']) ? 'selected' : '' ?>><?= htmlspecialchars($brand['brand_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3">
          <label for="product_name" class="form-label">Nama Produk</label>
          <input type="text" class="form-control" id="product_name" name="product_name" required value="<?= isset($_POST['product_name']) ? htmlspecialchars($_POST['product_name']) : '' ?>">
        </div>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label for="product_type_id" class="form-label">Tipe Produk</label>
          <select class="form-select" id="product_type_id" name="product_type_id" required>
            <option value="">Pilih Tipe Produk</option>
            <?php foreach ($product_types as $type): ?>
              <option value="<?= $type['id'] ?>" <?= (isset($_POST['product_type_id']) && $_POST['product_type_id'] == $type['id']) ? 'selected' : '' ?>><?= htmlspecialchars($type['type_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3">
          <label for="unit" class="form-label">Unit</label>
          <input type="text" class="form-control" id="unit" name="unit" required value="<?= isset($_POST['unit']) ? htmlspecialchars($_POST['unit']) : '' ?>">
        </div>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label for="location" class="form-label">Lokasi</label>
          <input type="text" class="form-control" id="location" name="location" required value="<?= isset($_POST['location']) ? htmlspecialchars($_POST['location']) : '' ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label for="minimum_stock_level" class="form-label">Stok Minimum</label>
          <input type="number" class="form-control" id="minimum_stock_level" name="minimum_stock_level" required min="0" value="<?= isset($_POST['minimum_stock_level']) ? htmlspecialchars($_POST['minimum_stock_level']) : '0' ?>">
        </div>
      </div>

            <div class="row">
        <div class="col-12 mb-3">
          <label for="note" class="form-label">Catatan (Opsional)</label>
          <textarea class="form-control" id="note" name="note" rows="3"><?= isset($_POST['note']) ? htmlspecialchars($_POST['note']) : '' ?></textarea>
        </div>
      </div>
                  <div class="mb-3">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="has_serial_checkbox" name="has_serial" value="1" <?= (isset($_POST['has_serial']) && $_POST['has_serial'] == 1) ? 'checked' : '' ?>>
          <label class="form-check-label" for="has_serial_checkbox">Produk ini memiliki Serial Number</label>
        </div>
      </div>
     
            <div id="stock_input_group" class="mb-3" style="<?= (isset($_POST['has_serial']) && $_POST['has_serial'] == 1) ? 'display: none;' : 'display: block;' ?>">
        <label for="stock" class="form-label">Stok Awal</label>
        <input type="number" class="form-control" id="stock" name="stock" min="0" <?= (isset($_POST['has_serial']) && $_POST['has_serial'] == 1) ? '' : 'required' ?> value="<?= isset($_POST['stock']) ? htmlspecialchars($_POST['stock']) : '0' ?>">
      </div>

      <div id="serial_numbers_input_group" class="mb-3" style="<?= (isset($_POST['has_serial']) && $_POST['has_serial'] == 1) ? 'display: block;' : 'none' ?>">
          <div class="d-flex justify-content-between align-items-center mb-2">
              <label class="form-label mb-0">Daftar Serial Number</label>
              <div class="btn-group">
                  <button type="button" class="btn btn-outline-primary btn-sm" id="addSerialBtn">
                      <i class="bi bi-plus-circle me-1"></i> Tambah Baris
                  </button>
                  <button type="button" class="btn btn-primary btn-sm" id="scanBarcodeBtn">
                      <i class="bi bi-qr-code-scan me-1"></i> Scan Barcode
                  </button>
              </div>
          </div>
          
          <div id="serial_container">
              <div class="input-group mb-2 serial-row">
                  <span class="input-group-text">1</span>
                  <input type="text" name="serial_numbers[]" class="form-control" placeholder="Masukkan S/N">
                  <button type="button" class="btn btn-outline-danger remove-serial-btn"><i class="bi bi-trash"></i></button>
              </div>
          </div>
          <small class="text-muted">*Pastikan setiap kolom terisi jika produk memiliki S/N.</small>
      </div>
     
      <div class="mb-3">
        <label for="product_image_file" class="form-label">Foto Produk (Pilih file atau gunakan kamera)</label>
        <input type="file" class="form-control mb-2" id="product_image_file" name="product_image_file">
       
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

      <button type="submit" class="btn btn-primary rounded-pill px-4">
        <i class="bi bi-save me-2"></i>Simpan Produk
      </button>
    </form>
  </div>
</div>

<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="barcodeScannerModalLabel">Scan Barcode</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p id="barcodeResultDisplay" class="text-center fw-bold"></p>
        <video id="barcodeVideoFeed" class="w-100 rounded" autoplay></video>
      </div>
    </div>
  </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const hasSerialCheckbox = document.getElementById('has_serial_checkbox');
  const stockInputGroup = document.getElementById('stock_input_group');
  const stockInput = document.getElementById('stock');
  const serialNumbersInputGroup = document.getElementById('serial_numbers_input_group');
  
  // Elemen Baru untuk Kolom Serial
  const serialContainer = document.getElementById('serial_container');
  const addSerialBtn = document.getElementById('addSerialBtn');

  const startCameraButton = document.getElementById('startCameraButton');
  const switchCameraButton = document.getElementById('switchCameraButton');
  const takePictureButton = document.getElementById('takePictureButton');
  const stopCameraButton = document.getElementById('stopCameraButton');
  const cameraFeed = document.getElementById('cameraFeed');
  const photoCanvas = document.getElementById('photoCanvas');
  const capturedImagePreview = document.getElementById('capturedImagePreview');
  const productImageBase64 = document.getElementById('productImageBase64');
  const productImageFileInput = document.getElementById('product_image_file');
  
  // Elemen untuk Barcode Scanner
  const scanBarcodeBtn = document.getElementById('scanBarcodeBtn');
  const barcodeScannerModalElement = document.getElementById('barcodeScannerModal');
  const barcodeVideoFeed = document.getElementById('barcodeVideoFeed');
  const barcodeResultDisplay = document.getElementById('barcodeResultDisplay');
  const barcodeScannerModal = new bootstrap.Modal(barcodeScannerModalElement);

  let stream;
  let currentFacingMode = 'environment'; 
  let scannerStream;
  let scanningInterval;

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

  // --- FUNGSI: Urutkan Ulang Nomor Indikator (1, 2, 3...) ---
  function reorderLabels() {
    const rows = serialContainer.querySelectorAll('.serial-row');
    rows.forEach((row, index) => {
      row.querySelector('.input-group-text').textContent = index + 1;
    });
  }

  // Event Tambah Baris Manual
  if (addSerialBtn) {
    addSerialBtn.addEventListener('click', () => addNewSerialRow());
  }

  // Event Hapus Baris
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

  // Toggle Input S/N vs Stok Awal
  function toggleSerialInput() {
    if (hasSerialCheckbox.checked) {
      stockInputGroup.style.display = 'none';
      stockInput.removeAttribute('required');
      stockInput.value = '0';
      serialNumbersInputGroup.style.display = 'block';
      if (serialContainer.children.length === 0) addNewSerialRow();
    } else {
      stockInputGroup.style.display = 'block';
      stockInput.setAttribute('required', 'required');
      serialNumbersInputGroup.style.display = 'none';
      serialContainer.innerHTML = '';
    }
  }

  hasSerialCheckbox.addEventListener('change', toggleSerialInput);
  toggleSerialInput(); // Jalankan saat load

  // --- LOGIKA SCAN BARCODE (DIPERBAIKI UNTUK SISTEM KOLOM) ---
  scanBarcodeBtn.addEventListener('click', async () => {
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
            
            // Berhenti scanning setelah terdeteksi satu
            clearInterval(scanningInterval);
            barcodeScannerModal.hide();

            // LOGIKA PENGISIAN KOLOM:
            const inputs = serialContainer.querySelectorAll('input[name="serial_numbers[]"]');
            let filled = false;

            // 1. Cari input yang sudah ada tapi masih kosong
            for (let input of inputs) {
              if (input.value.trim() === '') {
                input.value = code;
                filled = true;
                break;
              }
            }

            // 2. Jika semua kolom sudah berisi, buat kolom baru otomatis
            if (!filled) {
              addNewSerialRow(code);
            }
            
          }
        } catch (err) {
          console.error('Barcode detection error:', err);
        }
      }, 500);

    } catch (err) {
      console.error('Error accessing camera:', err);
      barcodeResultDisplay.textContent = 'Tidak dapat mengakses kamera. Pastikan izin diberikan.';
    }
  });

  barcodeScannerModalElement.addEventListener('hidden.bs.modal', function () {
    if (scanningInterval) clearInterval(scanningInterval);
    if (scannerStream) {
      scannerStream.getTracks().forEach(track => track.stop());
      scannerStream = null;
    }
    barcodeVideoFeed.srcObject = null;
  });

  // --- LOGIKA KAMERA FOTO PRODUK (TETAP SAMA) ---
  async function startCamera(facingMode) {
    if (stream) stream.getTracks().forEach(track => track.stop());
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
      capturedImagePreview.style.display = 'none';
      currentFacingMode = facingMode;
    } catch (err) {
      showModalMessage('Tidak dapat mengakses kamera.');
      stopCamera();
    }
  }

  function stopCamera() {
    if (stream) stream.getTracks().forEach(track => track.stop());
    cameraFeed.style.display = 'none';
    takePictureButton.style.display = 'none';
    switchCameraButton.style.display = 'none';
    stopCameraButton.style.display = 'none';
    startCameraButton.style.display = 'inline-block';
    productImageFileInput.disabled = false;
  }

  startCameraButton.addEventListener('click', () => startCamera(currentFacingMode));
  switchCameraButton.addEventListener('click', () => {
    startCamera(currentFacingMode === 'environment' ? 'user' : 'environment');
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

  function showModalMessage(message) {
    const modalEl = document.createElement('div');
    modalEl.innerHTML = `
      <div class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-body text-center"><p>${message}</p></div>
            <div class="modal-footer d-flex justify-content-center">
              <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modalEl);
    const modal = new bootstrap.Modal(modalEl.querySelector('.modal'));
    modal.show();
    modalEl.querySelector('.modal').addEventListener('hidden.bs.modal', () => modalEl.remove());
  }
});
</script>