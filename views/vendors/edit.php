<?php 
include '../../includes/header.php'; 

/**
 * PROTEKSI HAK AKSES
 * Marketing tidak boleh mengakses halaman ini.
 * Hanya Admin dan Staff yang boleh.
 */
if ($_SESSION['role'] === 'marketing') {
    echo "<script>alert('Akses ditolak! Anda tidak memiliki izin untuk mengedit vendor.'); window.location.href='/wms-geo/index.php';</script>";
    exit();
}

// Ambil ID dari URL
$id = $_GET['id'] ?? '';
if (empty($id)) {
    header("Location: index.php");
    exit();
}

// Ambil data vendor berdasarkan ID
$sql = "SELECT * FROM vendors WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$vendor = $result->fetch_assoc();

if (!$vendor) {
    echo "<div class='container-fluid mt-4'><div class='alert alert-danger'>Data vendor tidak ditemukan.</div></div>";
    exit();
}
?>

<div class="container-fluid">
    <div class="mb-4">
        <h2 class="h3 mb-0 text-gray-800">Edit Vendor</h2>
    </div>

    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">Formulir Edit Vendor</h5>
            <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
        </div>
        
        <div class="card-body">
            <form action="process.php?action=edit" method="POST">
                <input type="hidden" name="id" value="<?= $vendor['id'] ?>">
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nama Vendor</label>
                        <input type="text" name="vendor_name" class="form-control" value="<?= htmlspecialchars($vendor['vendor_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nama Sales</label>
                        <input type="text" name="sales_name" class="form-control" value="<?= htmlspecialchars($vendor['sales_name']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">No. HP Vendor</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($vendor['phone']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Link Website</label>
                        <input type="text" name="website_link" class="form-control" value="<?= htmlspecialchars($vendor['website_link']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Alamat</label>
                        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($vendor['address']) ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Status Pajak</label>
                        <select name="tax_status" class="form-select">
                            <option value="NON_PPN" <?= $vendor['tax_status'] == 'NON_PPN' ? 'selected' : '' ?>>NON PPN</option>
                            <option value="PPN" <?= $vendor['tax_status'] == 'PPN' ? 'selected' : '' ?>>PPN (11%)</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">TOP (Term of Payment)</label>
                        <select name="payment_term" class="form-select">
                            <?php 
                            $options = ['Cash', 'Tempo 7 Hari', 'Tempo 14 Hari', 'Tempo 30 Hari'];
                            // Jika nilai di DB tidak ada di list atas, tambahkan ke list agar tetap muncul (selected)
                            if (!in_array($vendor['payment_term'], $options) && !empty($vendor['payment_term'])) {
                                array_push($options, $vendor['payment_term']);
                            }
                            
                            foreach ($options as $opt):
                                $selected = ($vendor['payment_term'] == $opt) ? 'selected' : '';
                                echo "<option value='$opt' $selected>$opt</option>";
                            endforeach;
                            ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Mata Uang</label>
                        <select name="currency" class="form-select">
                            <?php 
                            $currencies = ['IDR', 'USD', 'SGD', 'EUR', 'JPY', 'CNY'];
                            foreach ($currencies as $curr):
                                $selected = ($vendor['currency'] == $curr) ? 'selected' : '';
                                echo "<option value='$curr' $selected>$curr</option>";
                            endforeach;
                            ?>
                        </select>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="text-end">
                    <button type="submit" class="btn btn-primary px-4 rounded-pill">
                        <i class="bi bi-check-lg me-2"></i>Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>