<?php
include '../../includes/db_connect.php';
include '../../includes/phpqrcode/phpqrcode.php'; 
session_start();

// --- PROTEKSI HALAMAN ---
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    die("Akses ditolak.");
}

// --- PROTEKSI HALAMAN ---
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    die("Akses ditolak.");
}

$user_logged_in = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$id = $_GET['id'] ?? '';

if (empty($id)) {
    die("ID PO tidak ditemukan.");
}

$sql = "SELECT po.*, v.vendor_name, v.address, v.phone, v.currency, v.payment_term 
        FROM purchase_orders po 
        JOIN vendors v ON po.vendor_id = v.id 
        WHERE po.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();

if (!$po) die("Data tidak ditemukan.");

// PERBAIKAN: Gunakan kolom created_by dari database
// Jika kolom created_by kosong, fallback ke 'Admin'
$maker_name = !empty($po['created_by']) ? $po['created_by'] : 'Admin';

$tempDir = "temp_qr/";
if (!file_exists($tempDir)) {
    mkdir($tempDir);
}

// Update isi QR Code menggunakan nama pembuat dari DB
$isi_qr_maker = $po['po_number'] . " - " . $maker_name;
$isi_qr_approver = $po['po_number'] . " - Meri Mentari";if (!$po) die("Data tidak ditemukan.");

// PERBAIKAN: Gunakan kolom created_by dari database
// Jika kolom created_by kosong, fallback ke 'Admin'
$maker_name = !empty($po['created_by']) ? $po['created_by'] : 'Admin';

$tempDir = "temp_qr/";
if (!file_exists($tempDir)) {
    mkdir($tempDir);
}

// Update isi QR Code menggunakan nama pembuat dari DB
$isi_qr_maker = $po['po_number'] . " - " . $maker_name;
$isi_qr_approver = $po['po_number'] . " - Meri Mentari";

$file_maker = $tempDir . "qr_maker_" . $id . ".png";
$file_approver = $tempDir . "qr_app_" . $id . ".png";

QRcode::png($isi_qr_maker, $file_maker, QR_ECLEVEL_L, 4, 2);
QRcode::png($isi_qr_approver, $file_approver, QR_ECLEVEL_L, 4, 2);

$sql_disc = "SELECT SUM(pi.discount) as total_disc FROM po_items pi JOIN purchase_orders po ON pi.po_id = po.id WHERE pi.po_id = ?";
$stmt_d = $conn->prepare($sql_disc);
$stmt_d->bind_param("i", $id);
$stmt_d->execute();
$total_discount = $stmt_d->get_result()->fetch_assoc()['total_disc'] ?? 0;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Print PO - <?= $po['po_number'] ?></title>
<style>
    /* Konfigurasi Ukuran Kertas untuk Print */
    @page {
        size: A4;
        margin: 0;
    }

    body { 
        font-family: 'Helvetica', 'Arial', sans-serif; 
        font-size: 11px; 
        color: #333; 
        line-height: 1.4; 
        margin: 0; 
        padding: 0; 
        background-color: #f4f4f9; /* Warna latar belakang di luar kertas */
    }

    /* Pembungkus agar tampilan di layar seperti kertas A4 */
    .paper { 
        width: 210mm;
        /* Gunakan box-sizing agar padding tidak menambah lebar total */
        box-sizing: border-box; 
        padding: 15mm; /* Sesuaikan margin dalam kertas di sini */
        margin: 20px auto;
        background: white;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        /* Pastikan tidak ada konten yang keluar */
        overflow: hidden;
    }

    .header { 
        text-align: right; 
        border-bottom: 2px solid #000; 
        padding-bottom: 10px; 
        margin-bottom: 20px; 
    }

    .header h2 { margin: 0; font-size: 24px; text-transform: uppercase; color: #000; }
    
    .info-section { display: flex; justify-content: space-between; margin-bottom: 20px; text-align: left; }
    .info-box { width: 48%; }
    
    table { 
        width: 100%; 
        border-collapse: collapse; 
        table-layout: fixed; 
    }

    table th, table td { 
        border: 1px solid #000; 
        padding: 6px 8px; 
        word-wrap: break-word; 
    }

    table th { background-color: #f2f2f2 !important; font-size: 10px; }
    
    .text-end { text-align: right; }
    .text-center { text-align: center; }
    .fw-bold { font-weight: bold; }
    
    .signature-wrapper { 
    margin-top: 30px; 
    display: flex; 
    justify-content: space-between; 
    page-break-inside: avoid; /* Mencegah ttd terpisah halaman */
    clear: both;
    }

    .sig-box { width: 150px; text-align: center; }
    .qr-code img { width: 80px; height: 80px; margin: 5px 0; }

    .currency-box { display: flex; justify-content: space-between; }

    /* CSS khusus saat tombol Print ditekan */
    @media print {
        body { background: none; }
        .no-print { display: none !important; }
        .paper { 
            margin: 0 !important; 
            box-shadow: none !important; 
            width: 210mm !important; /* Pastikan tetap A4 */
            min-height: auto !important;
            padding: 15mm !important;
        }
    }
</style>
</head>
<body>

<div class="no-print" style="background: #e9ecef; padding: 15px; text-align: center; border-bottom: 1px solid #ddd;">
    <button onclick="window.print()" style="padding: 10px 25px; background: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
        CETAK KE PDF / PRINTER
    </button>
</div>

<div class="paper">
    <div class="header" style="margin-top: 50px;">
        <h2>Purchase Order</h2>
        <p style="margin: 5px 0 0 0;">No: <strong><?= $po['po_number'] ?></strong></p>
        <p style="margin: 2px 0 0 0;">Tanggal: <?= date('d M Y', strtotime($po['po_date'])) ?></p>
    </div>

    <div class="info-section">
        <div class="info-box">
            <strong style="text-decoration: underline;">VENDOR:</strong><br>
            <span style="font-size: 12px; font-weight: bold;"><?= $po['vendor_name'] ?></span><br>
            <?= nl2br($po['address']) ?><br>
            Telp: <?= $po['phone'] ?><br>
            TOP: <?= !empty($po['payment_term']) ? $po['payment_term'] : '-' ?>
        </div>
        <div class="info-box text-end">
            <strong style="text-decoration: underline;">SHIP TO:</strong><br>
            <strong>PT GEO TEKNO GLOBALINDO</strong><br>
            JL Boulevard Raya KGC/C18 , RT 000, RW 000, Kelapa Gading Timur, Kelapa Gading, Kota
ADM. Jakarta Utara, DKI Jakarta 14240<br>
            PIC: <?= $po['pic_name'] ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 40%;">Description</th>
                <th style="width: 8%;" class="text-center">Qty</th>
                <th style="width: 10%;" class="text-center">Unit</th>
                <th style="width: 17%;" class="text-end">Unit Price</th>
                <th style="width: 20%;" class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $sql_items = "SELECT pi.*, p.product_name, p.unit FROM po_items pi JOIN products p ON pi.product_id = p.id WHERE pi.po_id = ?";
            $stmt_i = $conn->prepare($sql_items);
            $stmt_i->bind_param("i", $id);
            $stmt_i->execute();
            $items = $stmt_i->get_result();
            $no = 1;
            while($item = $items->fetch_assoc()):
            ?>
            <tr>
                <td class="text-center"><?= $no++ ?></td>
                <td><?= $item['product_name'] ?></td>
                <td class="text-center"><?= number_format($item['qty']) ?></td>
                <td class="text-center"><?= strtoupper($item['unit']) ?></td>
                <td class="text-end">
                    <div class="currency-box">
                        <span><?= $po['currency'] ?></span>
                        <span><?= number_format($item['price'], 0, ',', '.') ?></span>
                    </div>
                </td>
                <td class="text-end">
                    <div class="currency-box">
                        <span><?= $po['currency'] ?></span>
                        <span><?= number_format($item['qty'] * $item['price'], 0, ',', '.') ?></span>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            
            <tr>
                <td colspan="4" rowspan="4" style="vertical-align: top;">
                    <span class="fw-bold" style="text-decoration: underline;">Notes:</span><br>
                    <div style="font-style: italic; margin-top: 5px;">
                        <?= !empty($po['notes']) ? nl2br(htmlspecialchars($po['notes'])) : '-' ?>
                    </div>
                </td>
                <td class="fw-bold">Sub Total</td>
                <td class="text-end fw-bold">
                    <div class="currency-box">
                        <span><?= $po['currency'] ?></span>
                        <span><?= number_format($po['total_before_tax'] + $total_discount, 0, ',', '.') ?></span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="fw-bold">Discount</td>
                <td class="text-end fw-bold text-danger">
                    <div class="currency-box">
                        <span><?= $po['currency'] ?></span>
                        <span><?= number_format($total_discount, 0, ',', '.') ?></span>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="fw-bold">VAT / PPN (11%)</td>
                <td class="text-end fw-bold">
                    <div class="currency-box">
                        <span><?= $po['currency'] ?></span>
                        <span><?= number_format($po['ppn_amount'], 0, ',', '.') ?></span>
                    </div>
                </td>
            </tr>
            <tr style="background-color: #f2f2f2 !important;">
                <td class="fw-bold" style="font-size: 12px;">GRAND TOTAL</td>
                <td class="text-end fw-bold" style="font-size: 12px;">
                    <div class="currency-box">
                        <span><?= $po['currency'] ?></span>
                        <span><?= number_format($po['grand_total'], 0, ',', '.') ?></span>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="signature-wrapper">
        <div class="sig-box">
            <p style="margin-bottom: 5px;">CREATED BY,</p>
            <div class="qr-code">
                <img src="<?= $file_maker ?>" alt="QR Maker">
            </div>
            <p><strong><?= htmlspecialchars($maker_name) ?></strong></p>
            <p style="font-size: 9px; color: #666; margin-top: -5px;">
                Date: <?= date('d/m/Y H:i', strtotime($po['created_at'])) ?>
            </p>
        </div>
        
        <div class="sig-box">
            <p style="margin-bottom: 5px;">APPROVED BY,</p>
            <div class="qr-code">
                <?php if (!empty($po['approved_at'])): ?>
                    <img src="<?= $file_approver ?>" alt="QR Approver">
                <?php else: ?>
                    <div style="height: 85px; width: 85px; margin: 5px auto; border: 1px dashed #ccc; display: flex; align-items: center; justify-content: center; color: #ccc;">
                        PENDING
                    </div>
                <?php endif; ?>
            </div>
            <p><strong>Meri Mentari</strong></p>
            <p style="font-size: 9px; color: #666; margin-top: -5px;">
                <?= !empty($po['approved_at']) ? 'Date: '.date('d/m/Y H:i', strtotime($po['approved_at'])) : '(Waiting for Approval)' ?>
            </p>
        </div>
    </div>
</div>

<script>
    // Opsional: Langsung panggil print saat window loading
    // window.onload = function() { window.print(); }
</script>

</body>
</html>