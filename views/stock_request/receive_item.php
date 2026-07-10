<?php
include '../../includes/db_connect.php';
session_start();

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $detail_id = (int)$_POST['detail_id'];
    $input_qty = (int)$_POST['received_qty'];
    $request_id = (int)$_POST['request_id'];

    if ($detail_id <= 0 || $input_qty <= 0 || $request_id <= 0) {
        header("Location: detail.php?id=$request_id&status=invalid_input");
        exit();
    }

    $conn->begin_transaction();
    try {
        // 1. Get detail info
        $stmt = $conn->prepare("SELECT requested_qty, received_qty, stock_request_id FROM stock_request_details WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $detail_id);
        $stmt->execute();
        $detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$detail || $detail['stock_request_id'] != $request_id) {
            throw new Exception("Detail tidak ditemukan.");
        }

        // 2. Cek status header
        $stmt_hdr = $conn->prepare("SELECT status FROM stock_requests WHERE id = ? FOR UPDATE");
        $stmt_hdr->bind_param("i", $request_id);
        $stmt_hdr->execute();
        $header = $stmt_hdr->get_result()->fetch_assoc();
        $stmt_hdr->close();

        if (!in_array($header['status'], ['Approved', 'Partial'])) {
            throw new Exception("Status dokumen tidak mengizinkan penerimaan barang.");
        }

        // 3. Update Detail
        $new_received_qty = $detail['received_qty'] + $input_qty;
        if ($new_received_qty >= $detail['requested_qty']) {
            $new_received_qty = $detail['requested_qty'];
            $detail_status = 'Completed';
        } else {
            $detail_status = 'Pending';
        }

        $stmt_upd = $conn->prepare("UPDATE stock_request_details SET received_qty = ?, status = ? WHERE id = ?");
        $stmt_upd->bind_param("isi", $new_received_qty, $detail_status, $detail_id);
        $stmt_upd->execute();
        $stmt_upd->close();

        // 4. Update Header Status
        $stmt_check = $conn->prepare("SELECT COUNT(*) as total_items, SUM(IF(status='Completed', 1, 0)) as completed_items FROM stock_request_details WHERE stock_request_id = ?");
        $stmt_check->bind_param("i", $request_id);
        $stmt_check->execute();
        $check_res = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();

        $new_header_status = 'Partial';
        if ($check_res['total_items'] > 0 && $check_res['total_items'] == $check_res['completed_items']) {
            $new_header_status = 'Completed';
        }

        if ($header['status'] !== $new_header_status) {
            $stmt_hdr_upd = $conn->prepare("UPDATE stock_requests SET status = ? WHERE id = ?");
            $stmt_hdr_upd->bind_param("si", $new_header_status, $request_id);
            $stmt_hdr_upd->execute();
            $stmt_hdr_upd->close();
        }

        $conn->commit();
        header("Location: detail.php?id=$request_id&status=received_success");
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: detail.php?id=$request_id&status=received_error");
    }
} else {
    header("Location: index.php");
}
