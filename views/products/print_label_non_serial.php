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

// 3. Pecah jumlah cetak menjadi array per 40 label (1 halaman TJ 108 = 40 stiker)
$total_labels = range(1, $qty);
$pages = array_chunk($total_labels, 40);
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
            padding: 12mm 4mm; /* Penyesuaian margin fisik kertas TJ */
            margin: 10mm auto; 
            background: white;
            box-sizing: border-box;
            page-break-after: always;
        }

        /* Grid 5 Kolom x 8 Baris (Total 40) */
        .label-grid { 
            display: grid; 
            grid-template-columns: repeat(5, 38mm); 
            grid-template-rows: repeat(8, 18mm); 
            column-gap: 3.5mm; /* Celah horizontal antar stiker */
            row-gap: 2.5mm;    /* Celah vertikal antar stiker */
            justify-content: center;
        }

        /* Box Stiker Individual */
        .label-box {
            width: 38mm; 
            height: 18mm; 
            border: 0.1mm dashed #eee; /* Garis bantu, hilang saat print */
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: space-between; 
            overflow: hidden; 
            padding: 1mm 1mm;
            box-sizing: border-box;
            text-align: center;
        }

        /* Teks Nama Produk */
        .product-text { 
            font-size: 5pt; 
            font-weight: 600;
            color: #000; 
            line-height: 1.1;
            width: 100%;
            word-wrap: break-word;
            margin: 0;
            max-height: 5.5mm;
            overflow: hidden;
        }

        /* Ukuran Barcode */
        .barcode-img { 
            width: 100%; 
            height: 9.5mm; 
            object-fit: contain;
            margin: 0;
        }

        /* Teks Kode Produk di Bawah Barcode */
        .code-text { 
            font-size: 6.5pt; 
            font-weight: bold; 
            letter-spacing: 0.5px;
            margin: 0;
            line-height: 1;
        }

        /* CSS khusus saat Print */
        @media print {
            body { background: none; }
            .label-page { margin: 0; border: none; box-shadow: none; }
            .label-box { border: none; } /* Sembunyikan garis bantu stiker */
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
                             src="https://bwipjs-api.metafloor.com/?bcid=code128&text=<?= urlencode($code) ?>&scale=2&height=35&rotate=N&includetext=false" 
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