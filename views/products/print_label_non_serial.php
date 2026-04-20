<?php
include '../../includes/db_connect.php';

// 1. Ambil Parameter
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$code = isset($_GET['code']) ? $_GET['code'] : '';
$qty = isset($_GET['qty']) ? (int)$_GET['qty'] : 0;

if ($id === 0 || $qty <= 0) {
    echo "<script>alert('Data tidak valid atau stok kosong.'); window.close();</script>";
    exit;
}

// 2. Ambil Nama Produk
session_start();
$stmt = $conn->prepare("SELECT product_name FROM products WHERE id = ? AND is_deleted = 0");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$product = $result->fetch_assoc();

if (!$product) {
    echo "Produk tidak ditemukan.";
    exit;
}

$product_name = $product['product_name'];

// 3. Pecah jumlah cetak menjadi array per 21 label (Grid 3x7)
$total_labels = range(1, $qty);
$pages = array_chunk($total_labels, 21);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Print Label Non-Serial - <?= htmlspecialchars($code) ?></title>
    <style>
        /* Pengaturan Dasar */
        body { 
            margin: 0; 
            padding: 0; 
            background-color: #f0f0f0; 
            font-family: 'Arial', sans-serif;
        }

        /* Spesifikasi Kertas A4 */
        .label-page { 
            width: 210mm; 
            height: 297mm; 
            padding: 10mm 4mm; 
            margin: 0 auto; 
            background: white;
            box-sizing: border-box;
            page-break-after: always;
        }

        /* Grid 3 Kolom x 7 Baris (Total 21) - Optimal untuk A4 */
        .label-grid { 
            display: grid; 
            grid-template-columns: repeat(3, 64mm); 
            grid-template-rows: repeat(7, 34mm); 
            column-gap: 2mm; 
            row-gap: 2mm;
            justify-content: center;
        }

        /* Box Stiker Individual */
        .label-box {
            width: 64mm; 
            height: 34mm; 
            border: 0.1mm dashed #ccc; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            overflow: hidden; 
            padding: 3mm;
            box-sizing: border-box;
            text-align: center;
        }

        /* Teks Nama Produk - Dikecilkan */
        .product-text { 
            font-size: 8pt; 
            font-weight: 600;
            color: #000; 
            line-height: 1.1;
            width: 100%;
            word-wrap: break-word;
            margin-bottom: 2mm;
            max-height: 8mm;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        /* Ukuran Barcode - Optimal untuk scannability */
        .barcode-img { 
            width: 100%; 
            height: 15mm; 
            object-fit: contain;
            image-rendering: -webkit-optimize-contrast;
            image-rendering: pixelated;
            margin-bottom: 1mm;
        }

        /* Teks Kode Produk - Dikecilkan */
        .code-text { 
            font-size: 9pt; 
            font-weight: bold; 
            letter-spacing: 0.5px;
            margin: 0;
            line-height: 1;
        }

        /* CSS khusus saat Print */
        @media print {
            body { background: none; }
            .label-page { margin: 0; border: none; box-shadow: none; }
            .label-box { border: none; } 
            @page { 
                size: A4; 
                margin: 0; 
            }
        }
    </style>
</head>
<body onload="window.print()">

    <?php foreach ($pages as $items): ?>
        <div class="label-page">
            <div class="label-grid">
                <?php foreach ($items as $index): ?>
                    <div class="label-box">
                        <div class="product-text">
                            <?= htmlspecialchars($product_name) ?>
                        </div>

                        <img class="barcode-img" 
                             src="https://bwipjs-api.metafloor.com/?bcid=code128&text=<?= urlencode($code) ?>&scale=4&height=40&rotate=N&includetext=false" 
                             alt="Barcode">

                        <div class="code-text">
                            <?= htmlspecialchars($code) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

</body>
</html>