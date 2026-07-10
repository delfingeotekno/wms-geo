<?php
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

$user_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? 'create';

// --- 1. Ambil & Validasi Data Umum ---
$transfer_id = (int)($_POST['transfer_id'] ?? 0);
$packing_slip_number = trim($_POST['packing_slip_number'] ?? '');
$packing_slip_date = $_POST['packing_slip_date'] ?? date('Y-m-d');
$recipient = trim($_POST['recipient'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$location = trim($_POST['location'] ?? '');
$koli_count = (int)($_POST['koli_count'] ?? 0);
$notes = $_POST['notes'] ?? '';
$packing_slip_id = (int)($_POST['packing_slip_id'] ?? 0);

if (!$transfer_id || empty($packing_slip_number) || $koli_count < 1) {
    $_SESSION['error_message'] = "Data utama (ID Transfer, No. PS, atau Jumlah Koli) tidak lengkap atau tidak valid.";
    header("Location: create_packing_slip.php?id=" . $transfer_id);
    exit();
}

$conn->begin_transaction();
try {
    if ($action === 'create') {
        // --- 2A. Aksi CREATE ---
        
        $stmt = $conn->prepare("INSERT INTO transfer_packing_slips (packing_slip_number, transfer_id, packing_slip_date, recipient, subject, location, koli_count, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sissssisi", 
            $packing_slip_number, 
            $transfer_id, 
            $packing_slip_date, 
            $recipient, 
            $subject, 
            $location, 
            $koli_count, 
            $notes, 
            $user_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute CREATE gagal: " . $stmt->error);
        }
        
        $packing_slip_id = $conn->insert_id;
        $stmt->close();
        
        $_SESSION['success_message'] = "Packing Slip TAG berhasil dibuat!";
        
    } elseif ($action === 'update' && $packing_slip_id > 0) {
        // --- 2B. Aksi UPDATE ---
        
        $stmt = $conn->prepare("UPDATE transfer_packing_slips SET packing_slip_date=?, recipient=?, subject=?, location=?, koli_count=?, notes=?, updated_at=NOW(), updated_by=? WHERE id=?");
        
        $stmt->bind_param("ssssisii", 
            $packing_slip_date, 
            $recipient, 
            $subject, 
            $location, 
            $koli_count, 
            $notes, 
            $user_id, 
            $packing_slip_id 
        );

        if (!$stmt->execute()) {
             throw new Exception("Execute UPDATE gagal: " . $stmt->error); 
        }
        $stmt->close();
        
        // Hapus detail lama
        $stmt_delete_details = $conn->prepare("DELETE FROM transfer_packing_slip_details WHERE packing_slip_id = ?");
        $stmt_delete_details->bind_param("i", $packing_slip_id);
        $stmt_delete_details->execute();
        $stmt_delete_details->close();

        $_SESSION['success_message'] = "Packing Slip TAG berhasil diperbarui!";
        
    } else {
        throw new Exception("Aksi tidak valid atau ID Packing Slip tidak ada untuk update.");
    }

    // --- 3. Memasukkan/Membuat Ulang Detail Produk ---
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        $stmt_detail = $conn->prepare("INSERT INTO transfer_packing_slip_details (packing_slip_id, product_id, quantity, serial_numbers) VALUES (?, ?, ?, ?)");
        
        foreach ($_POST['items'] as $item) {
            
            $product_id = (int)($item['product_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);
            $serial_numbers = trim($item['serial_numbers'] ?? ''); 

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
    $_SESSION['error_message'] = 'Terjadi kesalahan saat memproses Packing Slip TAG: ' . htmlspecialchars($e->getMessage());
    header("Location: create_packing_slip.php?id=" . $transfer_id);
    exit();
}
?>
