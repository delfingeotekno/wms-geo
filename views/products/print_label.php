<?php
include '../../includes/db_connect.php';
session_start();
// Hapus baris pengecekan tenant_id

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (string)$_SESSION['warehouse_id'];

// Logika filter warehouse
if ($active_tab === 'all') {
    $warehouse_filter_sql = "";
} else {
    $filter_warehouse_id = (int)$active_tab;
    $warehouse_filter_sql = " AND sn.warehouse_id = " . $filter_warehouse_id; 
}

if ($product_id === 0) {
    echo "Produk tidak valid.";
    exit;
}

// 2. Bangun Query
$query = "SELECT sn.serial_number, p.product_name 
          FROM serial_numbers sn
          JOIN products p ON sn.product_id = p.id
          WHERE sn.product_id = ? 
          AND sn.status IN ('Tersedia', 'Tersedia (Bekas)')
          AND sn.is_deleted = 0 
          AND p.is_deleted = 0" . $warehouse_filter_sql;

if (!empty($search)) {
    $query .= " AND (sn.serial_number LIKE ?)";
}

$stmt = $conn->prepare($query);

if (!empty($search)) {
    $search_param = "%$search%";
    $stmt->bind_param("is", $product_id, $search_param);
} else {
    $stmt->bind_param("i", $product_id);
}

$stmt->execute();
$result = $stmt->get_result();
$all_data = $result->fetch_all(MYSQLI_ASSOC); // Ambil semua data ke array

if (count($all_data) === 0) {
    echo "<script>alert('Tidak ada Serial Number tersedia.'); window.close();</script>";
    exit;
}

// Pecah data menjadi kelompok isi 40 per halaman
$pages = array_chunk($all_data, 40); 
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Print Label TJ 108 (Max 40 per Page)</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f0f0f0; }
        
        /* Pengaturan Halaman */
        .label-page { 
            width: 210mm; 
            height: 297mm; /* Paksa tinggi A4 */
            padding: 12mm 5mm; /* Margin atas disesuaikan agar pas dengan kertas TJ */
            margin: 10mm auto; 
            background: white;
            box-sizing: border-box;
            page-break-after: always; /* Potong halaman saat print */
        }

        /* Grid untuk TJ 108 (5 Kolom x 8 Baris = 40) */
        .label-grid { 
            display: grid; 
            grid-template-columns: repeat(5, 38mm); 
            grid-template-rows: repeat(8, 18mm); /* Batasi 8 baris */
            column-gap: 3mm;
            row-gap: 2mm;
            justify-content: center;
        }

        .label-box {
            width: 38mm; 
            height: 18mm; 
            border: 0.1mm dashed #eee; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            overflow: hidden; 
            padding: 1mm;
            box-sizing: border-box;
        }

        .product-text { 
            font-size: 5pt; 
            color: #333; 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
            width: 100%;
            text-align: center;
        }

        .barcode-img { 
            max-width: 95%; 
            height: 8mm; 
            object-fit: contain;
        }

        .sn-text { 
            font-size: 7pt; 
            font-weight: bold; 
            margin-top: 1px;
        }

        @media print {
            body { background: none; }
            .label-page { margin: 0; border: none; box-shadow: none; }
            .label-box { border: none; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body onload="window.print()">

    <?php foreach ($pages as $page_items): ?>
        <div class="label-page">
            <div class="label-grid">
                <?php foreach ($page_items as $row): ?>
                    <div class="label-box">
                        <div class="product-text"><?= htmlspecialchars($row['product_name']) ?></div>
                        <img class="barcode-img" 
                             src="https://bwipjs-api.metafloor.com/?bcid=code128&text=<?= urlencode($row['serial_number']) ?>&scale=2&height=30&rotate=N&includetext=false" 
                             alt="Barcode">
                        <div class="sn-text"><?= htmlspecialchars($row['serial_number']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

</body>
</html>