<?php
include '../../includes/db_connect.php';

session_start();
$warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
// Mengambil term pencarian dari parameter GET
$term = $_GET['term'] ?? '';

// Query JOIN dengan filter untuk menampilkan PO yang masih memiliki item belum diterima
$sql = "SELECT 
            po.id, 
            po.po_number, 
            v.vendor_name AS vendor_name,
            po.pic_name
        FROM purchase_orders po
        LEFT JOIN vendors v ON po.vendor_id = v.id
        WHERE po.po_number LIKE ? 
        AND po.status = 'Completed' 
        AND EXISTS (
            SELECT 1 FROM po_items pi 
            WHERE pi.po_id = po.id AND pi.qty > pi.received_quantity
        )
        LIMIT 10";

/* Penjelasan Tambahan:
1. EXISTS(SELECT 1 FROM po_items ...): Memastikan hanya PO yang masih punya minimal 1 item dengan sisa stok belum diterima (quantity > received_quantity) yang dimunculkan.
2. Status = 'Completed': Artinya PO sudah di-approve dan siap diterima barangnya.
*/

$stmt = $conn->prepare($sql);
$search = "%$term%";
$stmt->bind_param("s", $search);
$stmt->execute();
$result = $stmt->get_result();

$pos = [];
while($row = $result->fetch_assoc()) { 
    // Jika vendor_name di tabel vendors kosong, gunakan pic_name sebagai fallback
    if (empty($row['vendor_name'])) {
        $row['vendor_name'] = $row['pic_name'] ?? 'Tidak ada nama';
    }
    $pos[] = $row; 
}

// Pastikan header adalah JSON agar bisa dibaca JavaScript dengan benar
header('Content-Type: application/json');
echo json_encode($pos);