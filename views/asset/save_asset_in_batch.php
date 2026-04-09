<?php
// FILE: save_asset_in_batch.php
session_start();
include '../../includes/db_connect.php'; 

$warehouse_id = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : ($_SESSION['warehouse_id'] ?? 0);

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: asset_in.php");
    exit();
}

$items_json = $_POST['items_json'] ?? '';
$asset_date = $_POST['asset_date'] ?? date('Y-m-d');

if (empty($items_json)) {
    header("Location: asset_in.php?message=" . urlencode("Tidak ada item untuk disimpan.") . "&type=danger");
    exit();
}

$items = json_decode($items_json, true);

if (!is_array($items) || empty($items)) {
    header("Location: asset_in.php?message=" . urlencode("Format data item tidak valid.") . "&type=danger");
    exit();
}

$conn->begin_transaction();

try {
    $asset_status = 'Tersedia (Bekas)';
    $is_deleted = 0;
    
    $success_count = 0;

    foreach ($items as $item) {
        $product_id = intval($item['product_id']);
        $serial_number = trim($item['serial_number']);
        $condition = trim($item['condition']);
        $location = trim($item['location']);

        if (empty($product_id) || empty($serial_number) || empty($condition)) {
            continue; // Skip faulty item entries if any
        }

        if (empty($location)) {
            $location = 'Gudang Aset';
        }

        // 1. Cek apakah Serial Number sudah ada di tabel serial_numbers
        $check_sql = "SELECT id, status FROM serial_numbers WHERE serial_number = ? AND product_id = ? AND warehouse_id = ?";
        $stmt_check = $conn->prepare($check_sql);
        $stmt_check->bind_param("sii", $serial_number, $product_id, $warehouse_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $existing_sn = $result_check->fetch_assoc();
        $stmt_check->close();

        if ($existing_sn) {
            if ($existing_sn['status'] == $asset_status) {
                // Skip if already in this state inside loop to avoid raising hard transaction rollback for partial batch
                continue; 
            }

            // UPDATE status SN menjadi 'Tersedia (Bekas)'
            $update_sql = "UPDATE serial_numbers SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update = $conn->prepare($update_sql);
            $stmt_update->bind_param("si", $asset_status, $existing_sn['id']);
            if (!$stmt_update->execute()) {
                 throw new Exception("Gagal mengupdate status SN $serial_number: " . $stmt_update->error);
            }
            $stmt_update->close();
        } else {
            // INSERT SN baru dengan status 'Tersedia (Bekas)'
            $insert_sql = "INSERT INTO serial_numbers (warehouse_id, product_id, serial_number, status, is_deleted, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt_insert = $conn->prepare($insert_sql);
            $stmt_insert->bind_param("iissi", $warehouse_id, $product_id, $serial_number, $asset_status, $is_deleted);
            if (!$stmt_insert->execute()) {
                 throw new Exception("Gagal menyimpan SN baru $serial_number: " . $stmt_insert->error);
            }
            $stmt_insert->close();
        }

        // 2. Sync warehouse_stocks
        $sync_sql = "INSERT INTO warehouse_stocks (warehouse_id, product_id, stock) 
                     VALUES (?, ?, 1) 
                     ON DUPLICATE KEY UPDATE stock = stock + 1";
        $stmt_sync = $conn->prepare($sync_sql);
        $stmt_sync->bind_param("ii", $warehouse_id, $product_id);
        if (!$stmt_sync->execute()) {
             throw new Exception("Gagal sinkronisasi stok produk ID $product_id: " . $stmt_sync->error);
        }
        $stmt_sync->close();

        // 3. Log ke asset_log
        $log_sql = "
            INSERT INTO asset_log (warehouse_id, serial_number, product_id, condition_asset, location_asset, transaction_date, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt_log = $conn->prepare($log_sql);
        $stmt_log->bind_param("isisss", $warehouse_id, $serial_number, $product_id, $condition, $location, $asset_date);
        if (!$stmt_log->execute()) {
             throw new Exception("Gagal mencatat log aset $serial_number: " . $stmt_log->error);
        }
        $stmt_log->close();

        $success_count++;
    }

    if ($success_count === 0) {
        throw new Exception("Tidak ada item baru yang berhasil diproses/ditambahkan.");
    }

    $conn->commit();
    header("Location: asset_in.php?message=" . urlencode("$success_count item aset berhasil diproses bersama penyetelan stok.") . "&type=success");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    header("Location: asset_in.php?message=" . urlencode("Error batch: " . $e->getMessage()) . "&type=danger");
    exit();
}
?>
