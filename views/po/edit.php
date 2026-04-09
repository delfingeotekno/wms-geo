<?php 
include '../../includes/header.php'; 

// --- PROTEKSI HALAMAN ---
if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: /wms-geo/index.php");
    exit();
}

// 1. Ambil ID dari URL
$id = $_GET['id'] ?? '';

if (empty($id)) {
    header("Location: index.php");
    exit;
}

// 2. Query Data Header PO
$sql_po = "SELECT * FROM purchase_orders WHERE id = ?";
$stmt = $conn->prepare($sql_po);
$stmt->bind_param("i", $id);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();

if (!$po) {
    echo "Data PO tidak ditemukan.";
    exit;
}

// Hanya PO dengan status Pending yang bisa diedit
if ($po['status'] !== 'Pending') {
    echo "<div class='alert alert-danger m-3'>PO ini sudah tidak dapat diedit karena statusnya: " . $po['status'] . "</div>";
    exit;
}
?>

<style>
    input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }
    #poTable .form-control { padding: 0.5rem 0.75rem; border-radius: 8px; }
    .select2-container--bootstrap-5 .select2-selection { min-height: 42px; border-radius: 8px; }
</style>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

<div class="container-fluid py-4">
    <form action="process_po.php?action=update" method="POST" id="poForm">
        <input type="hidden" name="po_id" value="<?= $po['id'] ?>">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="h3 mb-0 text-gray-800 fw-bold">Edit Purchase Order</h2>
                <span class="badge bg-warning text-dark">Status: <?= $po['status'] ?></span>
            </div>
            <div class="text-end">
                <a href="index.php" class="btn btn-light rounded-pill px-4 me-2 border">Batal</a>
                <button type="submit" class="btn btn-success rounded-pill px-4 shadow-sm">
                    <i class="bi bi-check-all me-2"></i>Update Purchase Order
                </button>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-8">
                <div class="card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">No. PO</label>
                                <input type="text" name="po_number" class="form-control bg-light fw-bold" value="<?= $po['po_number'] ?>" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Tanggal PO</label>
                                <input type="date" name="po_date" class="form-control" value="<?= $po['po_date'] ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">PIC Marketing</label>
                                <input type="text" name="pic_name" class="form-control" value="<?= $po['pic_name'] ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Vendor</label>
                                <select name="vendor_id" id="vendor_id" class="form-select init-select2" required>
                                    <?php
                                    $v_sql = "SELECT id, vendor_name, tax_status, currency FROM vendors ORDER BY vendor_name ASC";
                                    $v_res = $conn->query($v_sql);
                                    while($v = $v_res->fetch_assoc()) {
                                        $selected = ($v['id'] == $po['vendor_id']) ? 'selected' : '';
                                        echo "<option value='{$v['id']}' data-tax='{$v['tax_status']}' data-curr='{$v['currency']}' $selected>{$v['vendor_name']} ({$v['currency']})</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Reference / No. PR</label>
                                <input type="text" name="reference" class="form-control" value="<?= $po['reference'] ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-primary">Daftar Item Barang</h6>
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" id="addRow">
                            <i class="bi bi-plus-lg me-1"></i> Tambah Item
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" id="poTable" style="min-width: 900px;">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40%;" class="ps-4">Produk</th>
                                        <th style="width: 10%;" class="text-center">Qty</th>
                                        <th style="width: 20%;">Harga Satuan</th>
                                        <th style="width: 15%;">Disc</th>
                                        <th style="width: 10%;" class="text-end pe-4">Total</th>
                                        <th style="width: 5%;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // 3. Ambil Data Item PO (po_items)
                                    $sql_items = "SELECT * FROM po_items WHERE po_id = ?";
                                    $stmt_i = $conn->prepare($sql_items);
                                    $stmt_i->bind_param("i", $id);
                                    $stmt_i->execute();
                                    $res_items = $stmt_i->get_result();

                                    while($item = $res_items->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td class="ps-3">
                                            <select name="product_id[]" class="form-select select-product" required>
                                                <?php
                                                $p_sql = "SELECT id, product_code, product_name FROM products WHERE is_deleted = 0";
                                                $p_res = $conn->query($p_sql);
                                                while($p = $p_res->fetch_assoc()) {
                                                    $p_selected = ($p['id'] == $item['product_id']) ? 'selected' : '';
                                                    echo "<option value='{$p['id']}' $p_selected>{$p['product_code']} - {$p['product_name']}</option>";
                                                }
                                                ?>
                                            </select>
                                        </td>
                                        <td><input type="number" name="qty[]" class="form-control qty text-center" value="<?= $item['qty'] ?>" min="1"></td>
                                        <td><input type="number" name="price[]" class="form-control price" value="<?= $item['price'] ?>" step="any"></td>
                                        <td><input type="number" name="discount[]" class="form-control discount" value="<?= $item['discount'] ?>" step="any"></td>
                                        <td class="row-total fw-bold text-end pe-4"><?= number_format($item['subtotal'], 0, ',', '.') ?></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-link text-danger removeRow p-0"><i class="bi bi-trash fs-5"></i></button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-4 sticky-top" style="top: 20px;">
                    <div class="card-body">
                        <h6 class="fw-bold mb-4">Ringkasan Biaya (<span id="currLabel">IDR</span>)</h6>
                        <div class="d-flex justify-content-between mb-2 text-muted">
                            <span>Subtotal</span>
                            <span id="subtotalText">0</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 text-muted">
                            <span>PPN (11%)</span>
                            <span id="ppnText">0</span>
                        </div>
                        <hr class="my-3">
                        <div class="d-flex justify-content-between mb-4">
                            <span class="h5 mb-0">Grand Total</span>
                            <span class="h5 mb-0 fw-bold text-primary" id="grandTotalText">0</span>
                        </div>
                        
                        <input type="hidden" name="total_before_tax" id="inputSubtotal">
                        <input type="hidden" name="ppn_amount" id="inputPPN">
                        <input type="hidden" name="grand_total" id="inputGrandTotal">

                        <div class="mb-0">
                            <label class="form-label fw-bold small text-uppercase text-muted">Catatan</label>
                            <textarea name="notes" class="form-control" rows="5"><?= $po['notes'] ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    function initSelect2(element) { $(element).select2({ theme: 'bootstrap-5', width: '100%' }); }
    initSelect2('.init-select2');
    initSelect2('.select-product');

    // Jalankan kalkulasi pertama kali saat halaman dimuat
    calculateTotal();

    $("#addRow").click(function() {
        $(".select-product").select2('destroy');
        let newRow = $("#poTable tbody tr:first").clone();
        newRow.find('input').val(0);
        newRow.find('.qty').val(1);
        newRow.find('.row-total').text(0);
        newRow.find('select').val('');
        $("#poTable tbody").append(newRow);
        initSelect2('.select-product');
    });

    $(document).on('click', '.removeRow', function() {
        if ($("#poTable tbody tr").length > 1) { $(this).closest('tr').remove(); calculateTotal(); }
    });

    $("#vendor_id").change(function() { calculateTotal(); });
    $(document).on('input', '.qty, .price, .discount', function() { calculateTotal(); });

    function calculateTotal() {
        let subtotal = 0;
        let taxStatus = $("#vendor_id").find(':selected').data('tax');
        let curr = $("#vendor_id").find(':selected').data('curr') || 'IDR';
        $("#currLabel").text(curr);

        $("#poTable tbody tr").each(function() {
            let qty = parseFloat($(this).find('.qty').val()) || 0;
            let price = parseFloat($(this).find('.price').val()) || 0;
            let disc = parseFloat($(this).find('.discount').val()) || 0;
            let rowTotal = (qty * price) - disc;
            $(this).find('.row-total').text(rowTotal.toLocaleString('id-ID'));
            subtotal += rowTotal;
        });

        let ppn = (taxStatus === 'PPN') ? Math.ceil(subtotal * 0.11) : 0;
        let grandTotal = subtotal + ppn;

        $("#subtotalText").text(subtotal.toLocaleString('id-ID'));
        $("#ppnText").text(ppn.toLocaleString('id-ID'));
        $("#grandTotalText").text(grandTotal.toLocaleString('id-ID'));
        $("#inputSubtotal").val(subtotal);
        $("#inputPPN").val(ppn);
        $("#inputGrandTotal").val(grandTotal);
    }
});
</script>

<?php include '../../includes/footer.php'; ?>