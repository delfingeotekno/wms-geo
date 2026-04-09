<?php
include '../../includes/db_connect.php';
session_start();

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header("Location: index.php");
    exit();
}

// Cek status sebelum hapus (hanya Draft yang boleh dihapus)
$check = $conn->prepare("SELECT status FROM stock_requests WHERE id = ?");
$check->bind_param("i", $id);
$check->execute();
$res = $check->get_result()->fetch_assoc();
$check->close();

if (!$res) {
    header("Location: index.php?status=not_found");
    exit();
}

if ($res['status'] !== 'Pending') {
    header("Location: index.php?status=invalid_status");
    exit();
}

$stmt = $conn->prepare("DELETE FROM stock_requests WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    header("Location: index.php?status=success_delete");
} else {
    header("Location: index.php?status=error_delete");
}
$stmt->close();
exit();
?>
