<?php
include '../../includes/db_connect.php';

$action = $_GET['action'] ?? '';

// --- PROSES TAMBAH VENDOR ---
if ($action == 'add') {
    $name    = $_POST['vendor_name'];
    $sales   = $_POST['sales_name'];
    $phone   = $_POST['phone'];
    $address = $_POST['address'];
    $web     = $_POST['website_link'];
    $tax     = $_POST['tax_status'];
    $top     = $_POST['payment_term'];
    $curr    = $_POST['currency'];

    $stmt = $conn->prepare("INSERT INTO vendors (vendor_name, sales_name, phone, address, website_link, tax_status, payment_term, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $name, $sales, $phone, $address, $web, $tax, $top, $curr);
    
    if ($stmt->execute()) {
        header("Location: index.php?msg=added");
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
}

// --- PROSES EDIT VENDOR ---
elseif ($action == 'edit') {
    $id      = $_POST['id'];
    $name    = $_POST['vendor_name'];
    $sales   = $_POST['sales_name'];
    $phone   = $_POST['phone'];
    $address = $_POST['address'];
    $web     = $_POST['website_link'];
    $tax     = $_POST['tax_status'];
    $top     = $_POST['payment_term'];
    $curr    = $_POST['currency'];

    // Urutan kolom di SET: name(1), sales(2), phone(3), address(4), web(5), tax(6), top(7), curr(8) | WHERE id(9)
    $stmt = $conn->prepare("UPDATE vendors SET vendor_name=?, sales_name=?, phone=?, address=?, website_link=?, tax_status=?, payment_term=?, currency=? WHERE id=?");
    
    // bind_param harus sama urutannya dengan tanda tanya di atas
    $stmt->bind_param("ssssssssi", $name, $sales, $phone, $address, $web, $tax, $top, $curr, $id);
    
    if ($stmt->execute()) {
        header("Location: index.php?msg=updated");
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
}

// --- PROSES HAPUS VENDOR ---
elseif ($action == 'delete') {
    $id = $_GET['id'] ?? '';

    if (!empty($id)) {
        // Cek dulu apakah vendor ini sudah punya transaksi PO agar tidak error relasi
        $check_po = $conn->query("SELECT id FROM purchase_orders WHERE vendor_id = " . intval($id) . " LIMIT 1");
        
        if ($check_po->num_rows > 0) {
            header("Location: index.php?msg=error_used");
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM vendors WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            header("Location: index.php?msg=deleted");
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    }
}

$conn->close();
?>