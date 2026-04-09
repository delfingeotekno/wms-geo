<?php
include '../../includes/db_connect.php';

$wh_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;

if ($wh_id <= 0) {
    echo "-";
    exit();
}

$code = 'GUD';
$wh_stmt = $conn->prepare("SELECT code FROM warehouses WHERE id = ?");
$wh_stmt->bind_param("i", $wh_id);
$wh_stmt->execute();
$res = $wh_stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $code = $row['code'];
}
$wh_stmt->close();

$roman_months = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
$m_roman = $roman_months[date('n')];
$year = date('Y');

$count_res = $conn->query("SELECT COUNT(*) FROM stock_requests WHERE YEAR(created_at) = $year");
$count = $count_res->fetch_row()[0];
$next_num = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

echo "FRS/$next_num/$code/$m_roman/$year";
?>
