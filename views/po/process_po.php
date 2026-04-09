<?php
session_start(); // Pastikan session dimulai di awal
include '../../includes/db_connect.php';

// Ambil identitas user dan parameter aksi
$user_name = $_SESSION['name'] ?? $_SESSION['username'] ?? 'System';
$user_role = $_SESSION['role'] ?? 'staff';
$action    = $_GET['action'] ?? '';

// --- 1. PROSES SIMPAN PO BARU ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    
    $po_number      = $_POST['po_number'];
    $vendor_id      = $_POST['vendor_id'];
    $po_date        = $_POST['po_date'];
    $pic_name       = $_POST['pic_name'];
    $reference      = $_POST['reference'];
    $subtotal_h     = $_POST['total_before_tax']; 
    $ppn            = $_POST['ppn_amount'];
    $grand_total    = $_POST['grand_total'];
    $notes          = $_POST['notes'];
    $status         = 'Pending';

    $conn->begin_transaction();

    try {
        $sql_po = "INSERT INTO purchase_orders (po_number, vendor_id, po_date, pic_name, reference, total_before_tax, ppn_amount, grand_total, status, created_by, notes, created_at) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql_po);
        $stmt->bind_param("sisssdddsss", $po_number, $vendor_id, $po_date, $pic_name, $reference, $subtotal_h, $ppn, $grand_total, $status, $user_name, $notes);
        $stmt->execute();
        
        $po_id = $conn->insert_id;

        $product_ids = $_POST['product_id'];
        $qtys        = $_POST['qty'];
        $prices      = $_POST['price'];
        $discounts   = $_POST['discount'];

        $sql_item = "INSERT INTO po_items (po_id, product_id, qty, price, discount, subtotal) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_item = $conn->prepare($sql_item);

        for ($i = 0; $i < count($product_ids); $i++) {
            $p_id   = $product_ids[$i];
            $q      = $qtys[$i];
            $p      = $prices[$i];
            $d      = $discounts[$i];
            $sub    = ($q * $p) - $d;

            $stmt_item->bind_param("iiiddd", $po_id, $p_id, $q, $p, $d, $sub);
            $stmt_item->execute();
        }

        $conn->commit();
        header("Location: index.php?msg=po_created");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        die("Gagal menyimpan PO: " . $e->getMessage());
    }
}

// --- 2. PROSES UPDATE (EDIT) PO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action == 'update') {
    $po_id       = $_POST['po_id']; 
    $vendor_id   = $_POST['vendor_id'];
    $po_date     = $_POST['po_date'];
    $pic_name    = $_POST['pic_name'];
    $reference   = $_POST['reference'];
    $subtotal_h  = $_POST['total_before_tax'];
    $ppn         = $_POST['ppn_amount'];
    $grand_total = $_POST['grand_total'];
    $notes       = $_POST['notes'];

    $conn->begin_transaction();

    try {
        $sql_update_po = "UPDATE purchase_orders SET 
                            vendor_id = ?, 
                            po_date = ?, 
                            pic_name = ?, 
                            reference = ?, 
                            total_before_tax = ?, 
                            ppn_amount = ?, 
                            grand_total = ?, 
                            notes = ? 
                          WHERE id = ?";
        
        $stmt = $conn->prepare($sql_update_po);
        $stmt->bind_param("isssdddsi", $vendor_id, $po_date, $pic_name, $reference, $subtotal_h, $ppn, $grand_total, $notes, $po_id);
        $stmt->execute();

        // Hapus item lama dan masukkan yang baru (sederhana) - security check via po_id
        $conn->query("DELETE FROM po_items WHERE po_id = $po_id AND EXISTS (SELECT 1 FROM purchase_orders WHERE id = $po_id)");

        $product_ids = $_POST['product_id'];
        $qtys        = $_POST['qty'];
        $prices      = $_POST['price'];
        $discounts   = $_POST['discount'];

        $sql_item = "INSERT INTO po_items (po_id, product_id, qty, price, discount, subtotal) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_item = $conn->prepare($sql_item);

        for ($i = 0; $i < count($product_ids); $i++) {
            $p_id   = $product_ids[$i];
            $q      = $qtys[$i];
            $p      = $prices[$i];
            $d      = $discounts[$i];
            $sub    = ($q * $p) - $d;

            $stmt_item->bind_param("iiiddd", $po_id, $p_id, $q, $p, $d, $sub);
            $stmt_item->execute();
        }

        $conn->commit();
        header("Location: index.php?msg=po_updated");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        die("Gagal memperbarui PO: " . $e->getMessage());
    }
}

// --- 3. PROSES PERSETUJUAN (APPROVE) PO ---
if ($action == 'approve') {
    $id = $_GET['id'] ?? '';
    
    if ($user_role !== 'admin') {
        die("Akses ditolak.");
    }

    if (!empty($id)) {
        $sql = "UPDATE purchase_orders SET status = 'Completed', approved_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            header("Location: index.php?msg=po_approved");
            exit();
        } else {
            die("Error: " . $conn->error);
        }
    }
}

// --- 4. PROSES PEMBATALAN (CANCEL) PO ---
if ($action == 'cancel') {
    $id = $_GET['id'] ?? '';
    
    if (!empty($id)) {
        $sql = "UPDATE purchase_orders SET status = 'Cancelled' WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            header("Location: index.php?msg=po_cancelled");
            exit();
        } else {
            die("Error: " . $conn->error);
        }
    }
}

// --- 5. PROSES BATAL APPROVE (UNAPPROVE) PO ---
if ($action == 'unapprove') {
    $id = $_GET['id'] ?? ''; // Ambil ID secara spesifik di sini
    
    // Pastikan hanya admin yang bisa melakukan ini
    if ($user_role !== 'admin') {
        header("Location: index.php?msg=error_auth");
        exit();
    }

    if (!empty($id)) {
        // Update status kembali ke Pending dan kosongkan data approval
        $sql = "UPDATE purchase_orders SET status = 'Pending', approved_at = NULL WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            header("Location: index.php?msg=po_unapproved");
            exit();
        } else {
            die("Error: " . $stmt->error);
        }
        $stmt->close();
    }
}

// Jika tidak ada aksi yang cocok, kembali ke index
$conn->close();
header("Location: index.php");
exit();
?>