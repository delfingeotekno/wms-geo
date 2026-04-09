<?php
// process_packing_slip.php

// Mulai sesi jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../../includes/db_connect.php';

// Cek otentikasi
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id']; // Gunakan variabel yang jelas
$action = $_GET['action'] ?? 'create';

// --- 1. Ambil & Validasi Data Umum ---
$transaction_id = (int)($_POST['transaction_id'] ?? 0);
$packing_slip_number = trim($_POST['packing_slip_number'] ?? null);
$packing_slip_date = $_POST['packing_slip_date'] ?? date('Y-m-d');
$recipient = trim($_POST['recipient'] ?? null);
$subject = trim($_POST['subject'] ?? null);
$location = trim($_POST['location'] ?? null);
$koli_count = (int)($_POST['koli_count'] ?? 0);
$notes = $_POST['notes'] ?? null;
$packing_slip_id = (int)($_POST['packing_slip_id'] ?? 0);

if (!$transaction_id || empty($packing_slip_number) || $koli_count < 1) {
    $_SESSION['error_message'] = "Data utama (ID Transaksi, No. PS, atau Jumlah Koli) tidak lengkap atau tidak valid.";
    header("Location: create_packing_slip.php?transaction_id=" . $transaction_id);
    exit();
}

$conn->begin_transaction();
try {
    if ($action === 'create') {
        // --- 2A. Aksi CREATE (INSERT Baru) ---
        
        $stmt_ref = $conn->prepare("SELECT reference FROM outbound_transactions WHERE id = ?");
        $stmt_ref->bind_param("i", $transaction_id);
        $stmt_ref->execute();
        $result_ref = $stmt_ref->get_result();
        $outbound_ref = $result_ref->fetch_assoc()['reference'] ?? null;
        $stmt_ref->close();

        $stmt = $conn->prepare("INSERT INTO packing_slips (packing_slip_number, transaction_id, packing_slip_date, recipient, subject, location, reference, koli_count, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisssssisi", 
            $packing_slip_number, 
            $transaction_id, 
            $packing_slip_date, 
            $recipient, 
            $subject, 
            $location, 
            $outbound_ref, 
            $koli_count, 
            $notes, 
            $user_id // Menggunakan $user_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute CREATE gagal: " . $stmt->error);
        }
        
        $packing_slip_id = $conn->insert_id;
        $stmt->close();
        
        $_SESSION['success_message'] = "Packing Slip berhasil dibuat!";
        
    } elseif ($action === 'update' && $packing_slip_id > 0) {
        // --- 2B. Aksi UPDATE (Edit Data) ---
        
        // Update data header packing_slips
        $stmt = $conn->prepare("UPDATE packing_slips SET packing_slip_date=?, recipient=?, subject=?, location=?, koli_count=?, notes=?, updated_at=NOW(), updated_by=? WHERE id=?");
        
        // HARUS "sssssiii" jika notes adalah string
        $stmt->bind_param("sssssiii", 
            $packing_slip_date, // s
            $recipient, // s
            $subject, // s
            $location, // s
            $koli_count, // i
            $notes, // s
            $user_id, // i (updated_by)
            $packing_slip_id // i (WHERE id)
        );

        if (!$stmt->execute()) {
             // Debugging penting!
             throw new Exception("Execute UPDATE gagal: " . $stmt->error); 
        }
        $stmt->close();
        
        // Hapus detail lama
        $stmt_delete_details = $conn->prepare("DELETE FROM packing_slip_details WHERE packing_slip_id = ?");
        $stmt_delete_details->bind_param("i", $packing_slip_id);
        $stmt_delete_details->execute();
        $stmt_delete_details->close();

        $_SESSION['success_message'] = "Packing Slip berhasil diperbarui!";
        
    } else {
        throw new Exception("Aksi tidak valid atau ID Packing Slip tidak ada untuk update.");
    }

    // --- 3. Memasukkan/Membuat Ulang Detail Produk ---
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        $stmt_detail = $conn->prepare("INSERT INTO packing_slip_details (packing_slip_id, product_id, quantity, serial_numbers) VALUES (?, ?, ?, ?)");
        
        foreach ($_POST['items'] as $item) {
            
            $product_id = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            $serial_numbers = trim($item['serial_numbers'] ?? null); 

            if ($product_id > 0) {
                // Tipe data: integer, integer, integer, string (i i i s)
                $stmt_detail->bind_param("iiis", 
                    $packing_slip_id, 
                    $product_id, 
                    $quantity, 
                    $serial_numbers
                );
                $stmt_detail->execute();
            }
        }
        $stmt_detail->close();
    }

    $conn->commit();
    
    // --- 4. Redirect ke PDF Review ---
    header("Location: generate_packing_slip_pdf.php?id=" . $packing_slip_id);
    exit();

} catch (Exception $e) {
    $conn->rollback();
    // Tampilkan pesan error dan redirect kembali
    $_SESSION['error_message'] = 'Terjadi kesalahan saat memproses Packing Slip: ' . htmlspecialchars($e->getMessage());
    header("Location: create_packing_slip.php?transaction_id=" . $transaction_id);
    exit();
}
?>