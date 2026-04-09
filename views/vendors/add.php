<?php 
include '../../includes/header.php'; 

// Proteksi Halaman: Hanya Admin dan Staff yang boleh tambah vendor
if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    echo "<script>alert('Akses ditolak!'); window.location.href='/wms-geo/index.php';</script>";
    exit();
}
?>

<div class="container-fluid">
    <div class="mb-4">
        <h2 class="h3 mb-0 text-gray-800">Tambah Vendor</h2>
    </div>

    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">Formulir Vendor Baru</h5>
            <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
        </div>
        
        <div class="card-body">
            <form action="process.php?action=add" method="POST">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nama Vendor</label>
                        <input type="text" name="vendor_name" class="form-control" placeholder="Contoh: PT. Geotrack" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nama Sales</label>
                        <input type="text" name="sales_name" class="form-control" placeholder="Nama PIC Sales">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">No. HP Vendor</label>
                        <input type="text" name="phone" class="form-control" placeholder="0812xxxx">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Link Website</label>
                        <input type="text" name="website_link" class="form-control" placeholder="https://www.vendor.com">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Alamat</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="Alamat lengkap kantor/gudang vendor"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Status Pajak</label>
                        <select name="tax_status" class="form-select">
                            <option value="NON_PPN">NON PPN</option>
                            <option value="PPN">PPN (11%)</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">TOP (Term of Payment)</label>
                        <select name="payment_term" class="form-select">
                            <option value="Cash" selected>Cash</option>
                            <option value="Tempo 7 Hari">Tempo 7 Hari</option>
                            <option value="Tempo 14 Hari">Tempo 14 Hari</option>
                            <option value="Tempo 30 Hari">Tempo 30 Hari</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Mata Uang</label>
                        <select name="currency" class="form-select">
                            <option value="IDR" selected>IDR - Rupiah Indonesia</option>
                            <option value="USD">USD - US Dollar</option>
                            <option value="SGD">SGD - Singapore Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="JPY">JPY - Japanese Yen</option>
                            <option value="CNY">CNY - Chinese Yuan</option>
                        </select>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="text-end">
                    <button type="submit" class="btn btn-primary px-4 rounded-pill">
                        <i class="bi bi-save me-2"></i>Simpan Vendor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>