session_start();
include '../../includes/db_connect.php'; 

// no tenant_id
$warehouse_id = $_SESSION['warehouse_id'];

// Cek sesi
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: asset_in.php");
    exit();
}

$product_id = intval($_POST['product_id']);
$serial_number = trim($_POST['serial_number']);
$condition = trim($_POST['condition']);
$location = trim($_POST['location']);
$asset_date = trim($_POST['asset_date']); // Tanggal pemasukan

if (empty($product_id) || empty($serial_number) || empty($condition)) {
    $message = "Semua field Serial Number, Kondisi, dan Produk harus diisi.";
    $message_type = 'danger';
    header("Location: asset_in.php?message=" . urlencode($message) . "&type={$message_type}");
    exit();
}

// Pastikan lokasi tidak kosong, defaultnya 'Gudang Aset' sudah diset di frontend
if (empty($location)) {
    $location = 'Gudang Aset';
}

$conn->begin_transaction();

try {
    $asset_status = 'Tersedia (Bekas)';
    $is_deleted = 0;
    
    // 1. Cek apakah Serial Number sudah ada di tabel serial_numbers
    // Gunakan cek yang lebih ketat: SN harus unik per produk (biasanya) - filtered by tenant and warehouse
    $check_sql = "SELECT id, status FROM serial_numbers WHERE serial_number = ? AND product_id = ? AND warehouse_id = ?";
    $stmt_check = $conn->prepare($check_sql);
    $stmt_check->bind_param("sii", $serial_number, $product_id, $warehouse_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $existing_sn = $result_check->fetch_assoc();
    $stmt_check->close();

    if ($existing_sn) {
        // SN SUDAH ADA (Mungkin dari 'Keluar' atau 'Tersedia (Baru)')
        
        if ($existing_sn['status'] == $asset_status) {
             throw new Exception("Serial Number **{$serial_number}** sudah berstatus 'Tersedia (Bekas)' di dalam sistem.");
        }

        // UPDATE status SN menjadi 'Tersedia (Bekas)'
        $update_sql = "UPDATE serial_numbers SET status = ?, updated_at = NOW() WHERE id = ?";
        $stmt_update = $conn->prepare($update_sql);
        $stmt_update->bind_param("si", $asset_status, $existing_sn['id']);
        if (!$stmt_update->execute()) {
             throw new Exception("Gagal mengupdate status SN: " . $stmt_update->error);
        }
        $action = "diperbarui statusnya menjadi 'Tersedia (Bekas)'";
        $stmt_update->close();

    } else {
        // INSERT SN baru dengan status 'Tersedia (Bekas)'
        $insert_sql = "INSERT INTO serial_numbers (warehouse_id, product_id, serial_number, status, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
        $stmt_insert = $conn->prepare($insert_sql);
        $stmt_insert->bind_param("iissi", $warehouse_id, $product_id, $serial_number, $asset_status, $is_deleted);
        if (!$stmt_insert->execute()) {
             throw new Exception("Gagal menyimpan SN baru: " . $stmt_insert->error);
        }
        $action = "ditambahkan sebagai aset baru dengan status 'Tersedia (Bekas)'";
        $stmt_insert->close();
    }

    // --- NEW: Sync warehouse_stocks ---
    // Every time an asset is added/restored to 'Tersedia (Bekas)', we must increment the physical stock in that warehouse.
    $sync_stock_sql = "INSERT INTO warehouse_stocks (warehouse_id, product_id, stock) 
                       VALUES (?, ?, 1) 
                       ON DUPLICATE KEY UPDATE stock = stock + 1, updated_at = NOW()";
    $stmt_sync = $conn->prepare($sync_stock_sql);
    $stmt_sync->bind_param("ii", $warehouse_id, $product_id);
    if (!$stmt_sync->execute()) {
        throw new Exception("Gagal sinkronisasi stok produk: " . $stmt_sync->error);
    }
    $stmt_sync->close();
    
    // 2. INSERT LOG KE TABEL asset_log
    $log_sql = "
        INSERT INTO asset_log (
            warehouse_id,
            serial_number, 
            product_id, 
            condition_asset, 
            location_asset, 
            transaction_date, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ";
    
    $stmt_log = $conn->prepare($log_sql);
    
    // Bind parameters: (tenant_id, warehouse_id, serial_number, product_id, condition, location, asset_date)
    // Tipe data: integer, integer, string, integer, string, string, string (tanggal) -> "iisisss"
    $stmt_log->bind_param(
        "isisss", 
        $warehouse_id,
        $serial_number, 
        $product_id, 
        $condition, 
        $location, 
        $asset_date
    );
    
    if (!$stmt_log->execute()) {
         throw new Exception("Gagal mencatat kondisi aset ke log: " . $stmt_log->error);
    }
    $stmt_log->close();

    $conn->commit();
    
    // Redirect dengan pesan sukses
    $message = "Serial Number **{$serial_number}** berhasil {$action}. Kondisi: {$condition}, Lokasi: {$location}.";
    $message_type = 'success';
    header("Location: asset_in.php?message=" . urlencode($message) . "&type={$message_type}");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    
    // Redirect dengan pesan error
    $message = 'Error: ' . $e->getMessage();
    $message_type = 'danger';
    header("Location: asset_in.php?message=" . urlencode($message) . "&type={$message_type}");
    exit();
}

$conn->close();
?>