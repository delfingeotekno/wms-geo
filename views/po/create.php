<?php 
include '../../includes/header.php'; 

// --- PROTEKSI HALAMAN ---
if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: /wms-geo/index.php");
    exit();
}

// --- LOGIKA GENERATE NO PO ---
function getRomawi($bln){
    $bulan = (int)$bln;
    $map = array('', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII');
    return $map[$bulan];
}

$bulan_romawi = getRomawi(date('m'));
$tahun = date('Y');

$sql_last = "SELECT po_number FROM purchase_orders 
             WHERE po_number LIKE '%/$tahun'
             ORDER BY id DESC LIMIT 1";
$res_last = $conn->query($sql_last);

if ($res_last && $res_last->num_rows > 0) {
    $row = $res_last->fetch_assoc();
    $parts = explode('/', $row['po_number']);
    $last_num = (int)$parts[2]; 
    $next_num = str_pad($last_num + 1, 3, '0', STR_PAD_LEFT);
} else {
    $next_num = "001";
}

$no_po = "PO/GEO-JKT/$next_num/$bulan_romawi/$tahun";
?>

<style>
    /* Menghilangkan spinner (panah atas-bawah) pada input number */
    input::-webkit-outer-spin-button,
    input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    input[type=number] {
        -moz-appearance: textfield;
    }

    /* Memastikan input memenuhi ruang dan memberikan padding yang nyaman */
    #poTable .form-control {
        padding: 0.5rem 0.75rem;
        border-radius: 8px;
    }

    /* Penyesuaian tinggi Select2 agar sejajar dengan input lain */
    .select2-container--bootstrap-5 .select2-selection {
        min-height: 42px;
        display: flex;
        align-items: center;
        border-radius: 8px;
    }

    /* Membuat header tabel lebih tegas */
    #poTable thead th {
        text-transform: uppercase;
        font-size: 0.8rem;
        letter-spacing: 0.5px;
        color: #6c757d;
        border-bottom: 2px solid #f8f9fa;
    }

    /* Hover effect pada baris tabel */
    #poTable tbody tr:hover {
        background-color: rgba(0,0,0,0.01);
    }
</style>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

<div class="container-fluid py-4">
    <form action="process_po.php" method="POST" id="poForm">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="h3 mb-0 text-gray-800 fw-bold">Buat Purchase Order Baru</h2>
            </div>
            <div class="text-end">
                <a href="index.php" class="btn btn-light rounded-pill px-4 me-2 border">Batal</a>
                <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">
                    <i class="bi bi-send me-2"></i>Simpan & Kirim PO
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
                                <input type="text" name="po_number" class="form-control bg-light" value="<?= $no_po ?>" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Tanggal PO</label>
                                <input type="date" name="po_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">PIC</label>
                                <input type="text" name="pic_name" class="form-control" placeholder="Nama Marketing" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Vendor / Supplier</label>
                                <select name="vendor_id" id="vendor_id" class="form-select init-select2" required>
                                    <option value="">-- Pilih Vendor --</option>
                                    <?php
                                    $v_sql = "SELECT id, vendor_name, tax_status, currency FROM vendors ORDER BY vendor_name ASC";
                                    $v_res = $conn->query($v_sql);
                                    while($v = $v_res->fetch_assoc()) {
                                        echo "<option value='{$v['id']}' data-tax='{$v['tax_status']}' data-curr='{$v['currency']}'>{$v['vendor_name']} ({$v['currency']})</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Reference / No. PR</label>
                                <input type="text" name="reference" class="form-control" placeholder="Contoh: PR/2025/001">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-box me-2"></i>Daftar Item Barang</h6>
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
                                        <th style="width: 15%;">Disc (Nominal)</th>
                                        <th style="width: 10%;" class="text-end pe-4">Total</th>
                                        <th style="width: 5%;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="ps-3">
                                            <select name="product_id[]" class="form-select select-product" required>
                                                <option value="">Cari Produk...</option>
                                                <?php
                                                $p_sql = "SELECT id, product_code, product_name FROM products WHERE is_deleted = 0";
                                                $p_res = $conn->query($p_sql);
                                                while($p = $p_res->fetch_assoc()) {
                                                    echo "<option value='{$p['id']}'>{$p['product_code']} - {$p['product_name']}</option>";
                                                }
                                                ?>
                                            </select>
                                        </td>
                                        <td><input type="number" name="qty[]" class="form-control qty text-center" value="1" min="1"></td>
                                        <td><input type="number" name="price[]" class="form-control price" value="0" step="any"></td>
                                        <td><input type="number" name="discount[]" class="form-control discount" value="0" step="any"></td>
                                        <td class="row-total fw-bold text-end pe-4">0</td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-link text-danger removeRow p-0">
                                                <i class="bi bi-trash fs-5"></i>
                                            </button>
                                        </td>
                                    </tr>
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
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Subtotal</span>
                            <span id="subtotalText" class="fw-semibold text-dark">0</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">PPN (11%)</span>
                            <span id="ppnText" class="fw-semibold text-dark">0</span>
                        </div>
                        <hr class="my-3">
                        <div class="d-flex justify-content-between mb-4">
                            <span class="h5 mb-0 text-dark">Grand Total</span>
                            <span class="h5 mb-0 fw-bold text-primary" id="grandTotalText">0</span>
                        </div>
                        
                        <input type="hidden" name="total_before_tax" id="inputSubtotal">
                        <input type="hidden" name="ppn_amount" id="inputPPN">
                        <input type="hidden" name="grand_total" id="inputGrandTotal">

                        <div class="mb-0">
                            <label class="form-label fw-bold small text-uppercase text-muted">Catatan Tambahan</label>
                            <textarea name="notes" class="form-control" rows="5" placeholder="Instruksi pengiriman atau termin pembayaran..."></textarea>
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
    function initSelect2(element) {
        $(element).select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    }

    initSelect2('.init-select2');
    initSelect2('.select-product');

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
        if ($("#poTable tbody tr").length > 1) {
            $(this).closest('tr').remove();
            calculateTotal();
        }
    });

    $("#vendor_id").change(function() {
        let curr = $(this).find(':selected').data('curr') || 'IDR';
        $("#currLabel").text(curr);
        calculateTotal();
    });

    $(document).on('input', '.qty, .price, .discount', function() {
        calculateTotal();
    });

    function calculateTotal() {
        let subtotal = 0;
        let taxStatus = $("#vendor_id").find(':selected').data('tax');

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