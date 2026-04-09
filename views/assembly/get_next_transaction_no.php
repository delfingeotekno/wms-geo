<?php
session_start();
include '../../includes/db_connect.php';

header('Content-Type: application/json');

$warehouse_code = isset($_GET['warehouse_code']) ? $_GET['warehouse_code'] : '';
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if (empty($warehouse_code)) {
    echo json_encode(['success' => false, 'message' => 'Warehouse code required']);
    exit;
}

// Roman numeral months
$roman = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
$romanMonth = $roman[$month - 1];

// Format code for the transaction number (e.g., GTG-JKT -> GEO-JKT based on existing pattern)
// Looking at existing: 001/BAST/GEO-JKT/III/2026, the code part uses GEO- prefix
// Let's derive it from the actual warehouse code
$codeParts = str_replace('-', '/', $warehouse_code);

// Build search pattern to count existing transactions for this month/year/warehouse
// Pattern: XXX/BAST/GEO/JKT/MONTH/YEAR
$search_pattern = "%/BAST/$codeParts/$romanMonth/$year";

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM assembly_outbound WHERE transaction_no LIKE ?");
$stmt->bind_param("s", $search_pattern);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$next_seq = ($result['cnt'] ?? 0) + 1;

$transaction_no = str_pad($next_seq, 3, '0', STR_PAD_LEFT) . "/BAST/$codeParts/$romanMonth/$year";

echo json_encode([
    'success' => true,
    'transaction_no' => $transaction_no,
    'next_seq' => $next_seq
]);
