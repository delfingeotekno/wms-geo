<?php 
include '../../includes/db_connect.php';
include '../../includes/header.php'; 

// Blokir role production (read-only)
if ($_SESSION['role'] === 'production') {
    header("Location: master_index.php");
    exit();
}

$id = $_GET['id'] ?? 0;

// Ambil data utama rakitan
$stmt = $conn->prepare("SELECT * FROM assemblies WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$assembly = $stmt->get_result()->fetch_assoc();

if (!$assembly) {
    echo "<script>alert('Data tidak ditemukan!'); window.location='master_index.php';</script>";
    exit;
}
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="master_index.php">Master Rakitan</a></li>
            <li class="breadcrumb-item active">Edit Rakitan</li>
        </ol>
    </nav>

    <form action="master_update.php" method="POST">
        <input type="hidden" name="assembly_id" value="<?= $assembly['id'] ?>">
        
        <div class="row">
            <div class="col-md-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Informasi Rakitan</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Nama Rakitan</label>
                            <input type="text" name="assembly_name" class="form-control" value="<?= htmlspecialchars($assembly['assembly_name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($assembly['description']) ?></textarea>
                        </div>
                        <div class="mb-0">
                            <label class="form-label text-success fw-bold"><i class="bi bi-box-seam"></i> Produk Hasil Akhir (Target Jadi)</label>
                            <select name="finished_product_id" class="form-select select2-products" required>
                                <option value="">-- Pilih Produk Jadi --</option>
                                <?php
                                $res_prod = $conn->query("SELECT id, product_code, product_name FROM products WHERE is_deleted = 0 ORDER BY product_name ASC");
                                while($p = $res_prod->fetch_assoc()) {
                                    $sel = ($p['id'] == $assembly['finished_product_id']) ? 'selected' : '';
                                    echo "<option value='{$p['id']}' $sel>[{$p['product_code']}] {$p['product_name']}</option>";
                                }
                                ?>
                            </select>
                            <small class="text-muted" style="font-size: 0.70rem;">Stok produk inilah yang akan dipantau di halaman depan perakitan.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Daftar Komponen (Resep)</h6>
                        <button type="button" class="btn btn-success btn-sm" onclick="addRow()">
                            <i class="bi bi-plus"></i> Tambah Komponen
                        </button>
                    </div>
                    <div class="card-body">
                        <table class="table" id="recipeTable">
                            <thead>
                                <tr>
                                    <th>Produk / Komponen</th>
                                    <th width="150">Jumlah Standar</th>
                                    <th width="150">Sifat Barang</th>
                                    <th width="50"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $details = $conn->query("SELECT * FROM assembly_details WHERE assembly_id = $id");
                                $rowCount = 0;
                                while($det = $details->fetch_assoc()):
                                    $rowCount++;
                                ?>
                                <tr id="row_<?= $rowCount ?>">
                                    <td>
                                        <select name="product_id[]" class="form-select select2-products" required>
                                            <?php
                                            $products = $conn->query("SELECT id, product_code, product_name FROM products WHERE is_deleted = 0");
                                            while($p = $products->fetch_assoc()) {
                                                $selected = ($p['id'] == $det['product_id']) ? 'selected' : '';
                                                echo "<option value='{$p['id']}' $selected>[{$p['product_code']}] {$p['product_name']}</option>";
                                            }
                                            ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="default_qty[]" class="form-control" value="<?= $det['default_quantity'] ?>" min="1" required>
                                    </td>
                                    <td>
                                        <select name="is_returnable[]" class="form-select" required>
                                            <option value="0" <?= ($det['is_returnable'] ?? 0) == 0 ? 'selected' : '' ?>>Habis Pakai</option>
                                            <option value="1" <?= ($det['is_returnable'] ?? 0) == 1 ? 'selected' : '' ?>>Kembali ke Stok</option>
                                        </select>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(<?= $rowCount ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary float-end">Update Master Rakitan</button>
                            <a href="master_index.php" class="btn btn-secondary float-end me-2">Batal</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let rowCount = <?= $rowCount ?>;

function addRow() {
    rowCount++;
    let html = `
    <tr id="row_${rowCount}">
        <td>
            <select name="product_id[]" class="form-select select2-products" required>
                <option value="">-- Pilih Produk --</option>
                <?php
                $products = $conn->query("SELECT id, product_code, product_name FROM products WHERE is_deleted = 0");
                while($p = $products->fetch_assoc()) {
                    echo "<option value='{$p['id']}'>[{$p['product_code']}] {$p['product_name']}</option>";
                }
                ?>
            </select>
        </td>
        <td><input type="number" name="default_qty[]" class="form-control" value="1" min="1" required></td>
        <td>
            <select name="is_returnable[]" class="form-select" required>
                <option value="0">Habis Pakai</option>
                <option value="1">Kembali ke Stok</option>
            </select>
        </td>
        <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(${rowCount})"><i class="bi bi-trash"></i></button></td>
    </tr>`;
    $('#recipeTable tbody').append(html);
    $('.select2-products').select2({ theme: 'bootstrap-5' });
}

function removeRow(id) {
    $(`#row_${id}`).remove();
}

$(document).ready(function() {
    $('.select2-products').select2({ theme: 'bootstrap-5' });
});
</script>