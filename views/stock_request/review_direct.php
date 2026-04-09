<?php
include '../../includes/db_connect.php';
session_start();

if ($_SESSION['role'] !== 'procurement' && $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($id <= 0 || !in_array($action, ['Approve', 'Reject', 'Reset_Pending'])) {
    header("Location: index.php?status=invalid_request");
    exit();
}

// Cek status sebelum update (hanya Pending yang bisa direview)
$check = $conn->prepare("SELECT status FROM stock_requests WHERE id = ?");
$check->bind_param("i", $id);
$check->execute();
$res = $check->get_result()->fetch_assoc();
$check->close();

if (!$res) {
    header("Location: index.php?status=not_found");
    exit();
}

if ($action === 'Reset_Pending') {
    if ($_SESSION['role'] !== 'admin' || $res['status'] !== 'Approved') {
        header("Location: index.php?status=invalid_status");
        exit();
    }
} else {
    if ($res['status'] !== 'Pending') {
        header("Location: index.php?status=invalid_status");
        exit();
    }
}

if ($action === 'Reset_Pending') {
    $new_status = 'Pending';
    $review_notes = "Status dikembalikan ke Pending oleh Admin.";
} else {
    $new_status = ($action === 'Approve') ? 'Approved' : 'Rejected';
    $review_notes = "Direct $action via List Action Button.";
}

$update_sql = "UPDATE stock_requests SET status = ?, general_notes = CONCAT(IFNULL(general_notes,''), '\n\nReview Notes: ', ?) WHERE id = ?";
$stmt_upd = $conn->prepare($update_sql);
$stmt_upd->bind_param("ssi", $new_status, $review_notes, $id);

if ($stmt_upd->execute()) {
    header("Location: index.php?status=success_review");
} else {
    header("Location: index.php?status=error_review");
}
$stmt_upd->close();
exit();
?>
