<?php
// Pastikan path ke db_connect benar
include '../../includes/db_connect.php';
session_start();

// Guard Role - Hanya admin dan staff yang boleh menghapus serial secara manual
if(!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk menghapus data ini.']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID Serial tidak valid atau tidak terkirim.']);
        exit;
    }

    // no tenant_id

    // 1. Ambil data asli dari DB dengan filter tenant
    // Join dengan products untuk memastikan tenant, atau cek tenant_id di serial_numbers jika sudah ada
    // Using serial_numbers.tenant_id as added in V3
    $sql_check = "SELECT sn.status, sn.serial_number FROM serial_numbers sn WHERE sn.id = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $id);
    $stmt_check->execute();
    $result = $stmt_check->get_result()->fetch_assoc();

    if (!$result) {
        echo json_encode(['success' => false, 'message' => "Data dengan ID $id tidak ditemukan di database."]);
        exit;
    }

    // 2. Normalisasi status (hilangkan spasi dan ubah ke huruf kecil)
    $status_db = strtolower(trim($result['status']));
    $serial_val = $result['serial_number'];

    // 3. Logika Pengecekan yang lebih luas
    // Kita cek apakah mengandung kata 'tersedia'
    if (strpos($status_db, 'tersedia') !== false) {
        
        $sql_delete = "DELETE FROM serial_numbers WHERE id = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $id);
        
        // Ambil data untuk log penyesuaian
        $res_info = $conn->query("SELECT product_id, warehouse_id FROM serial_numbers WHERE id = $id");
        $info = $res_info->fetch_assoc();

        if ($stmt_delete->execute()) {
            if ($info) {
                $pid = $info['product_id'];
                $wid = $info['warehouse_id'];
                $uid = $_SESSION['user_id'];
                $conn->query("INSERT INTO stock_adjustments (product_id, warehouse_id, type, quantity, reason, user_id) VALUES ($pid, $wid, 'Keluar', 1, 'Hapus Serial Number (Manual)', $uid)");
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus: ' . $conn->error]);
        }
    } else {
        // Jika gagal, kirim pesan detail apa yang terbaca di DB
        echo json_encode([
            'success' => false, 
            'message' => "Gagal! Status SN $serial_val di database adalah '$status_db', bukan 'Tersedia'."
        ]);
    }
    exit;
}