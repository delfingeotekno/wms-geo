<?php 
include '../../includes/header.php'; 

// Blokir role production (read-only)
if ($_SESSION['role'] === 'production') {
    header("Location: index.php");
    exit();
}

// 1. Ambil ID dari URL
$id = $_GET['id'] ?? 0;

// 2a. Ambil Data Header Transaksi (RESTORED)
$header_query = "SELECT * FROM assembly_outbound WHERE id = ?";
$stmt_header = $conn->prepare($header_query);
$stmt_header->bind_param("i", $id);
$stmt_header->execute();
$header = $stmt_header->get_result()->fetch_assoc();

if (!$header) {
    echo "<div class='alert alert-danger'>Data transaksi tidak ditemukan!</div>";
    exit;
}
$warehouse_id = $header['warehouse_id'];

// 2b. Ambil Daftar Hasil Rakitan (Multi-Template)
$results_query = "SELECT aor.*, a.assembly_name FROM assembly_outbound_results aor 
                   JOIN assemblies a ON aor.assembly_id = a.id 
                   WHERE aor.outbound_id = ?";
$stmt_res = $conn->prepare($results_query);
$stmt_res->bind_param("i", $id);
$stmt_res->execute();
$res_results = $stmt_res->get_result();

$produced_json = [];
while($r = $res_results->fetch_assoc()) {
    $produced_json[] = [
        'id'   => $r['assembly_id'],
        'name' => $r['assembly_name'],
        'qty'  => (int)$r['qty']
    ];
}

// 3. Ambil Detail Item dan siapkan untuk JSON
// Kita GROUP BY agar produk yang sama namun dari template berbeda tetap digabung di satu baris (menghindari duplicate error)
$details_query = "SELECT aoi.product_id, p.product_name, p.has_serial, aoi.is_returnable,
                         SUM(aoi.qty_out) as total_qty,
                         GROUP_CONCAT(aoi.serial_number SEPARATOR ',') as serials,
                         ws.stock 
                  FROM assembly_outbound_items aoi 
                  JOIN products p ON aoi.product_id = p.id 
                  LEFT JOIN warehouse_stocks ws ON p.id = ws.product_id AND ws.warehouse_id = $warehouse_id
                  WHERE aoi.outbound_id = ?
                  GROUP BY aoi.product_id, aoi.is_returnable";
$stmt_detail = $conn->prepare($details_query);
$stmt_detail->bind_param("i", $id);
$stmt_detail->execute();
$res_details = $stmt_detail->get_result();

$items_json = [];
while($row = $res_details->fetch_assoc()) {
    $items_json[] = [
        'id'    => $row['product_id'],
        'name'  => $row['product_name'],
        'has_serial' => (int)$row['has_serial'],
        'current_stock' => (int)$row['stock'],
        // Stok tersedia adalah (stok gudang + stok yang sudah diambil transaksi ini)
        'max_limit' => (int)$row['stock'] + (int)$row['total_qty'], 
        'qty'   => (int)$row['total_qty'],
        'serials' => $row['serials'] ? explode(',', $row['serials']) : [],
        'is_returnable' => (int)($row['is_returnable'] ?? 0)
    ];
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Edit BAST #<?= $header['transaction_no'] ?></h1>
        <a href="index.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
    </div>

    <form action="edit_process.php" method="POST" id="formEdit">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="warehouse_id" id="warehouse_id" value="<?= $warehouse_id ?>">

        <div class="row">
            <div class="col-md-4">
                <div class="card shadow mb-4">
                    <div class="card-header bg-light fw-bold small">Informasi Utama</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold small">No. Transaksi</label>
                            <input type="text" class="form-control fw-bold bg-light" value="<?= $header['transaction_no'] ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Project / Penerima</label>
                            <input type="text" name="project_name" class="form-control" value="<?= htmlspecialchars($header['project_name']) ?>" required>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label text-primary fw-bold small"><i class="bi bi-box-seam"></i> Pilih Template Rakitan (BOM)</label>
                            <div class="input-group">
                                <select id="assembly_id" class="form-select select2">
                                    <option value="">-- Pilih Rakitan (BOM) --</option>
                                    <?php
                                    $res_bom = $conn->query("SELECT * FROM assemblies ORDER BY assembly_name ASC");
                                    while($row = $res_bom->fetch_assoc()) echo "<option value='{$row['id']}'>{$row['assembly_name']}</option>";
                                    ?>
                                </select>
                                <button type="button" class="btn btn-primary" onclick="addAssemblyTemplate()">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>

                        <div id="produced_assemblies_container" class="mt-3">
                            <label class="form-label fw-bold small text-muted">Daftar Rakitan yang Dihasilkan:</label>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr style="font-size: 11px;">
                                            <th>Nama Rakitan</th>
                                            <th width="70">Jumlah</th>
                                            <th width="30"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="producedAssemblyList" style="font-size: 12px;">
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow mb-4 border-left-primary">
                    <div class="card-header bg-primary text-white fw-bold small">Cari & Tambah Komponen</div>
                    <div class="card-body">
                        <select id="product_search" class="form-select select2">
                            <option value="">-- Ketik Nama Produk --</option>
                        </select>
                        <button type="button" class="btn btn-primary w-100 mt-2 btn-sm" onclick="addManualRow()">
                            <i class="bi bi-plus-lg"></i> Tambahkan
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-light fw-bold">Daftar Komponen Keluar</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="tableEdit">
                                <thead class="table-dark small">
                                    <tr>
                                        <th>Komponen</th>
                                        <th width="120">Tersedia</th>
                                        <th width="120">Keluar</th>
                                        <th width="150" class="text-center">Sifat Barang</th>
                                        <th width="50" class="text-center">#</th>
                                    </tr>
                                </thead>
                                <tbody id="componentList">
                                    </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-white">
                        <button type="submit" class="btn btn-warning w-100 fw-bold" id="btnSubmit">
                            <i class="bi bi-save me-2"></i> Simpan Perubahan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Modal Serial Number (REUSED) -->
    <div class="modal fade" id="serialModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content shadow">
                <div class="modal-header bg-info text-dark">
                    <h5 class="modal-title fw-bold"><i class="bi bi-key-fill"></i> Pilih Nomor Seri (S/N)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        Produk: <strong id="serialModalProductName">-</strong><br>
                        Dibutuhkan: <strong id="requiredSerialCount">0</strong> S/N | Terpilih: <strong id="selectedSerialCount">0</strong> S/N
                    </div>
                    <div class="row" id="serialCheckboxesContainer" style="max-height: 400px; overflow-y: auto;">
                        <!-- Checkboxes loaded via AJAX -->
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary fw-bold" id="btnConfirmSerial" disabled>Konfirmasi Pilihan</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
let productSerials = {}; // rowId: [sn1, sn2]
let snModal = null;
let currentRowId = null;

$(document).ready(function() {
    // 1. Init Select2
    $('#product_search').select2({
        theme: 'bootstrap-5',
        ajax: {
            url: 'search_products.php',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return { q: params.term, warehouse_id: $('#warehouse_id').val() };
            },
            processResults: function (data) { return { results: data.results }; }
        },
        minimumInputLength: 2,
        placeholder: '-- Ketik Nama Produk --'
    });

    // 2. Load Initial Data
    const initialProduced = <?= json_encode($produced_json) ?>;
    initialProduced.forEach(item => {
        addProducedRow(item.id, item.name, item.qty);
    });

    const initialItems = <?= json_encode($items_json) ?>;
    initialItems.forEach(item => {
        // id, name, currentStock, maxLimit, qty, isReturnable, hasSerial, assemblyId, serials
        loadExistingRow(item.id, item.name, item.current_stock, item.max_limit, item.qty, item.is_returnable, item.has_serial, item.serials);
    });

    updateSubmitButton();
});

function addAssemblyTemplate() {
    let id = $('#assembly_id').val();
    let name = $('#assembly_id option:selected').text();
    let warehouse_id = $('#warehouse_id').val();
    
    if(!id) return;
    if ($(`#produced_row_${id}`).length > 0) {
        alert("Template ini sudah ada!");
        return;
    }

    addProducedRow(id, name, 1);

    // Load components
    $.getJSON(`get_assembly_details.php?id=${id}&warehouse_id=${warehouse_id}`, function(data) {
        data.forEach(item => {
            addOrUpdateRow(item.product_id, item.product_name, item.stock, item.stock, item.default_quantity, item.is_returnable, item.has_serial, id);
        });
        updateSubmitButton();
    });

    $('#assembly_id').val(null).trigger('change');
}

function addProducedRow(id, name, qty) {
    let html = `
        <tr id="produced_row_${id}">
            <td class="align-middle small fw-bold">${name}</td>
            <td>
                <input type="hidden" name="produced_assembly_id[]" value="${id}">
                <input type="number" name="produced_total_units[]" class="form-control form-control-sm assembly-multiplier" 
                value="${qty}" min="1" oninput="updateComponentQty(${id}, this.value)">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-link text-danger p-0" onclick="removeAssemblyTemplate(${id})">
                    <i class="bi bi-dash-circle-fill"></i>
                </button>
            </td>
        </tr>`;
    $('#producedAssemblyList').append(html);
    $('#produced_assemblies_container').show();
}

function removeAssemblyTemplate(id) {
    if(!confirm("Hapus rakitan ini dan komponen terkaitnya?")) return;
    $(`#produced_row_${id}`).remove();
    if ($('#producedAssemblyList tr').length === 0) $('#produced_assemblies_container').hide();

    // Remove associated components (only those that were loaded from this template)
    $(`.row-item[data-assembly="${id}"]`).each(function() {
        let rowId = $(this).data('rowid');
        delete productSerials[rowId];
        $(this).remove();
    });
    updateSubmitButton();
}

function updateComponentQty(assemblyId, multiplier) {
    let m = multiplier || 1;
    $(`.row-item[data-assembly="${assemblyId}"]`).each(function() {
        let std = $(this).find('.out-qty-input').data('std') || 1;
        $(this).find('.out-qty-input').val(std * m);
        
        if ($(this).data('serial') == 1) {
            $(this).find('.selected-serials-tags').html('<span class="text-warning">Jumlah berubah, pilih S/N kembali!</span>');
            $(this).find('.serial-ids-input').val('');
            delete productSerials[$(this).data('rowid')];
        }
    });
    updateSubmitButton();
}

function addManualRow() {
    let select = $('#product_search');
    let data = select.select2('data');
    if(!data || data.length === 0 || !data[0].id) return;
    
    let item = data[0];
    addOrUpdateRow(item.id, item.product_name, item.stock, item.stock, 1, 0, item.has_serial, "");
    select.val(null).trigger('change');
}

// Separate function for initial load to avoid duplication logic interfering
function loadExistingRow(id, name, currentStock, maxLimit, qty, isReturnable, hasSerial, serials) {
    let rowId = `row_${id}_${Date.now()}`;
    appendRowHtml(id, name, currentStock, maxLimit, qty, isReturnable, hasSerial, "", rowId, true);
    
    if (hasSerial && serials.length > 0) {
        productSerials[rowId] = serials;
        let row = $(`.row-item[data-rowid="${rowId}"]`);
        row.find('.serial-ids-input').val(serials.join(','));
        row.find('.selected-serials-tags').html(serials.map(s => `<span class="badge bg-info me-1">${s}</span>`).join(''));
        row.find('.btn-text').text('Ubah S/N');
    }
}

function addOrUpdateRow(id, name, currentStock, maxLimit, qty, isReturnable, hasSerial, assemblyId) {
    // Duplication check (Improved for multi-template)
    let existingRow = $(`.row-item[data-id="${id}"]`);
    if(existingRow.length > 0 && !hasSerial) {
        // If not serial, we can merge qty
        let input = existingRow.find('.out-qty-input');
        input.val(parseInt(input.val()) + qty);
        return;
    }

    let rowId = `row_${id}_${Date.now()}`;
    appendRowHtml(id, name, currentStock, maxLimit, qty, isReturnable, hasSerial, assemblyId, rowId, false);
}

function appendRowHtml(id, name, currentStock, maxLimit, qty, isReturnable, hasSerial, assemblyId, rowId, isInitialLoad) {
    let ret0 = (isReturnable == 0) ? 'selected' : '';
    let ret1 = (isReturnable == 1) ? 'selected' : '';

    let serialHtml = '';
    if (hasSerial == 1) {
        serialHtml = `
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-outline-info" onclick="openSerialModal(${id}, '${name.replace(/'/g, "\\'")}', this)">
                    <i class="bi bi-key"></i> <span class="btn-text">Pilih S/N</span>
                </button>
                <div class="selected-serials-tags mt-1 small text-muted">Belum ada S/N dipilih</div>
                <input type="hidden" name="serial_ids[${rowId}]" class="serial-ids-input" value="">
            </div>`;
    }

    let html = `
        <tr class="row-item" data-id="${id}" data-rowid="${rowId}" data-serial="${hasSerial}" data-assembly="${assemblyId}">
            <td>
                <div class="fw-bold small">${name}</div>
                <input type="hidden" name="product_id[]" value="${id}">
                <input type="hidden" name="row_id[]" value="${rowId}">
                ${assemblyId ? '<small class="text-primary">Bagian dari Rakitan</small>' : ''}
                ${serialHtml}
            </td>
            <td><span class="badge bg-secondary font-monospace">Stok: ${currentStock}</span></td>
            <td>
                <input type="number" name="out_qty[]" class="form-control form-control-sm out-qty-input" 
                value="${qty}" min="1" data-stock="${maxLimit}" data-std="${qty/($(`#produced_row_${assemblyId} .assembly-multiplier`).val() || 1)}" 
                oninput="updateSubmitButton()" ${hasSerial ? 'readonly' : ''}>
            </td>
            <td>
                <select name="is_returnable[]" class="form-select form-select-sm">
                    <option value="0" ${ret0}>Habis Pakai</option>
                    <option value="1" ${ret1}>Kembali</option>
                </select>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-link text-danger p-0" onclick="removeRow(this)">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>`;

    $('#componentList').append(html);
    updateSubmitButton();
}

function removeRow(btn) {
    let row = $(btn).closest('tr');
    delete productSerials[row.data('rowid')];
    row.remove();
    updateSubmitButton();
}

function updateSubmitButton() {
    let hasItems = $('#componentList tr').length > 0;
    let allSerialsOk = true;
    $('.row-item[data-serial="1"]').each(function() {
        if (!$(this).find('.serial-ids-input').val()) allSerialsOk = false;
    });

    $('#btnSubmit').prop('disabled', !hasItems || !allSerialsOk);
}

// Reuse serial modal logic from create.php
function openSerialModal(productId, productName, btn) {
    let row = $(btn).closest('tr');
    currentRowId = row.data('rowid');
    let qty = parseInt(row.find('.out-qty-input').val());
    let warehouseId = $('#warehouse_id').val();

    if (!snModal) snModal = new bootstrap.Modal(document.getElementById('serialModal'));
    
    $('#serialModalProductName').text(productName);
    $('#requiredSerialCount').text(qty);
    $('#selectedSerialCount').text(productSerials[currentRowId] ? productSerials[currentRowId].length : 0);
    $('#serialCheckboxesContainer').html('<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div> Mencari...</div>');
    snModal.show();

    // Note: Use initial selected serials for this row as 'current'
    $.getJSON(`get_available_serials_edit.php?product_id=${productId}&warehouse_id=${warehouseId}&outbound_id=<?= $id ?>`, function(data) {
        renderSerialCheckboxes(data);
    });
}

function renderSerialCheckboxes(data) {
    let container = $('#serialCheckboxesContainer');
    container.empty();
    
    if (data.length === 0) {
        container.html('<div class="alert alert-warning small">Tidak ada nomor seri tersedia di gudang ini.</div>');
        return;
    }

    let currentSelected = productSerials[currentRowId] || [];
    
    data.forEach(sn => {
        let isChecked = currentSelected.includes(sn.serial_number) ? 'checked' : '';
        let html = `
            <div class="col-md-6 mb-2">
                <div class="form-check serial-item p-2 border rounded">
                    <input class="form-check-input ms-0 me-2 sn-checkbox" type="checkbox" value="${sn.serial_number}" id="sn_${sn.id}" ${isChecked}>
                    <label class="form-check-label d-block cursor-pointer" for="sn_${sn.id}">
                        ${sn.serial_number}
                    </label>
                </div>
            </div>`;
        container.append(html);
    });

    $('.sn-checkbox').on('change', updateModalCount);
    updateModalCount();
}

function updateModalCount() {
    let count = $('.sn-checkbox:checked').length;
    $('#selectedSerialCount').text(count);
    
    let required = parseInt($('#requiredSerialCount').text());
    if (count === required) {
        $('#btnConfirmSerial').prop('disabled', false).removeClass('btn-secondary').addClass('btn-primary');
    } else {
        $('#btnConfirmSerial').prop('disabled', true).removeClass('btn-primary').addClass('btn-secondary');
    }
}

$('#btnConfirmSerial').on('click', function() {
    let selected = [];
    $('.sn-checkbox:checked').each(function() {
        selected.push($(this).val());
    });

    productSerials[currentRowId] = selected;
    
    let row = $(`.row-item[data-rowid="${currentRowId}"]`);
    row.find('.serial-ids-input').val(selected.join(','));
    row.find('.selected-serials-tags').html(selected.map(s => `<span class="badge bg-info me-1">${s}</span>`).join(''));
    row.find('.btn-text').text('Ubah S/N');
    
    snModal.hide();
    updateSubmitButton();
});
</script>

<style>
    /* Agar tooltip tidak merusak layout tabel */
    .position-relative { position: relative; }
    .invalid-tooltip {
        position: absolute;
        top: 100%;
        left: 0;
        z-index: 5;
        display: none;
        width: 100%;
        padding: .25rem .5rem;
        font-size: .75rem;
        background-color: rgba(220, 53, 69, .9);
        color: #fff;
        border-radius: .25rem;
    }
    .is-invalid { border-color: #dc3545 !important; }
    .is-valid { border-color: #198754 !important; }
</style>

<?php include '../../includes/footer.php'; ?>