<?php 
include '../../includes/db_connect.php'; 
include '../../includes/header.php'; 

// Blokir role production (read-only) dari halaman create
if ($_SESSION['role'] === 'production') {
    header("Location: index.php");
    exit();
}
// Transaction number via AJAX
?>
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Buat Keluar Barang / Rakitan</h1>
        <a href="index.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
    </div>

    <form action="process_assembly.php" method="POST" id="assemblyForm">
        <div class="row">
            <div class="col-md-4">
                <div class="card shadow mb-4">
                    <div class="card-header bg-light fw-bold">Informasi Utama</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">No. Transaksi</label>
                            <input type="text" id="transaction_number_display" name="transaction_no" class="form-control fw-bold text-primary" value="Pilih Gudang Asal..." readonly>
                        </div>
                        <div class="mb-3">
                            <input type="text" name="project_name" class="form-control" placeholder="Contoh: Project RPM Jakarta" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Gudang Asal Komponen</label>
                            <select name="warehouse_id" id="warehouse_id" class="form-select" required onchange="resetAndGenerate()">
                                <option value="">-- Pilih Lokasi Gudang --</option>
                                <?php
                                $warehouses = $conn->query("SELECT id, name AS warehouse_name, code AS warehouse_code FROM warehouses WHERE is_active = 1");
                                while($w = $warehouses->fetch_assoc()) {
                                    echo "<option value='{$w['id']}' data-code='{$w['warehouse_code']}'>{$w['warehouse_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label text-primary fw-bold"><i class="bi bi-box-seam"></i> Pilih Template Rakitan (BOM)</label>
                            <div class="input-group">
                                <select id="assembly_id" class="form-select select2">
                                    <option value="">-- Pilih Rakitan (BOM) --</option>
                                    <?php
                                    $res = $conn->query("SELECT * FROM assemblies ORDER BY assembly_name ASC");
                                    while($row = $res->fetch_assoc()) echo "<option value='{$row['id']}'>{$row['assembly_name']}</option>";
                                    ?>
                                </select>
                                <button type="button" class="btn btn-primary" onclick="addAssemblyTemplate()">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>

                        <div id="produced_assemblies_container" class="mt-3" style="display:none;">
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
                    <div class="card-header bg-primary text-white fw-bold">Tambah Produk Manual</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Cari Produk</label>
                            <select id="manual_product_select" class="form-select select2">
                                <option value="">-- Cari Nama/Kode Barang --</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm w-100" onclick="addManualRow()">
                            <i class="bi bi-plus-circle"></i> Tambahkan ke Daftar
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-light d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Daftar Barang Keluar</h6>
                        <span class="badge bg-info" id="itemCount">0 Item</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle" id="mainTable">
                                <thead class="table-light small">
                                    <tr>
                                        <th>Nama Komponen</th>
                                        <th width="100">Stok</th>
                                        <th width="120">Keluar</th>
                                        <th width="50"></th>
                                    </tr>
                                </thead>
                                <tbody id="componentList">
                                    </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-success w-100 mt-3" id="btnSubmit" disabled>
                            <i class="bi bi-check-circle me-2"></i> Konfirmasi Pengeluaran Barang
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- MODAL PILIH SERIAL NUMBER -->
<div class="modal fade" id="serialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-primary">Pilih Serial Number</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="serialModalProductName" class="fw-bold mb-3"></p>
                <div class="input-group mb-3">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="serialSearchInput" class="form-control" placeholder="Cari S/N...">
                </div>
                <div id="serialCheckboxesContainer" class="list-group" style="max-height: 300px; overflow-y: auto;">
                    <div class="text-center py-4 text-muted" id="serialLoadingSpinner">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>Mencari Serial...
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light d-flex justify-content-between">
                <div>
                    <span class="badge bg-primary rounded-pill mb-1">Dipilih: <span id="selectedSerialCount">0</span> / <span id="requiredSerialCount">0</span></span>
                </div>
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-success fw-bold" id="confirmSerialBtn" disabled>
                        <i class="bi bi-check2-circle me-1"></i>Konfirmasi
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let productSerials = {}; // Track selected serials per row: {rowId: [sn1Check, sn2Check]}
let currentRowId = null;
let snModal = null;

function resetAndGenerate() {
    $('#componentList').empty();
    productSerials = {};
    generateTransactionNumber();
    updateSubmitButton();
}

// Generate nomor transaksi
function generateTransactionNumber() {
    let warehouseSelect = document.getElementById('warehouse_id');
    let selectedOption = warehouseSelect.options[warehouseSelect.selectedIndex];
    let warehouseCode = selectedOption.getAttribute('data-code');
    let display = document.getElementById('transaction_number_display');

    if (!warehouseCode) {
        display.value = 'Pilih Gudang Asal...';
        return;
    }

    const romanMonths = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
    const now = new Date();
    const romanMonth = romanMonths[now.getMonth()];
    const year = now.getFullYear();

    $.getJSON(`get_next_transaction_no.php?warehouse_code=${encodeURIComponent(warehouseCode)}&month=${now.getMonth()+1}&year=${year}`, function(data) {
        if (data.success) {
            display.value = data.transaction_no;
        } else {
            let seq = String(data.next_seq || 1).padStart(3, '0');
            let codeParts = warehouseCode.replace(/-/g, '/');
            display.value = `${seq}/BAST/${codeParts}/${romanMonth}/${year}`;
        }
    }).fail(function() {
        let codeParts = warehouseCode.replace(/-/g, '/');
        display.value = `001/BAST/${codeParts}/${romanMonth}/${year}`;
    });
}

// NEW: Add a complete assembly template group
function addAssemblyTemplate() {
    let id = $('#assembly_id').val();
    let name = $('#assembly_id option:selected').text();
    let warehouse_id = $('#warehouse_id').val();
    
    if(!id) return;
    if(!warehouse_id) {
        alert("Pilih lokasi gudang terlebih dahulu.");
        return;
    }

    // Check if template already in the produced list
    if ($(`#produced_row_${id}`).length > 0) {
        alert("Template ini sudah ada dalam daftar.");
        return;
    }

    // Add to produced list
    let html = `
        <tr id="produced_row_${id}">
            <td class="align-middle">${name}</td>
            <td>
                <input type="number" name="produced_assembly_id[]" value="${id}" hidden>
                <input type="number" name="produced_total_units[]" class="form-control form-control-sm assembly-multiplier" 
                value="1" min="1" oninput="updateComponentQty(${id}, this.value)">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-link text-danger p-0" onclick="removeAssemblyTemplate(${id})">
                    <i class="bi bi-dash-circle-fill"></i>
                </button>
            </td>
        </tr>`;
    
    $('#producedAssemblyList').append(html);
    $('#produced_assemblies_container').show();

    // Load components
    $.getJSON(`get_assembly_details.php?id=${id}&warehouse_id=${warehouse_id}`, function(data) {
        data.forEach(item => {
            addOrUpdateRow(item.product_id, item.product_name, item.stock, item.default_quantity, true, item.is_returnable, item.has_serial, id);
        });
        updateSubmitButton();
    });

    // Reset select
    $('#assembly_id').val('').trigger('change');
}

// Tambah Manual
function addManualRow() {
    let select = $('#manual_product_select');
    let data = select.select2('data');
    if(!data || data.length === 0 || !data[0].id) return;
    
    let item = data[0];
    addOrUpdateRow(item.id, item.product_name, item.stock, 1, false, 0, item.has_serial);
    select.val(null).trigger('change');
}

// Fungsi inti menambah baris ke tabel
function addOrUpdateRow(id, name, stock, qty, isTemplate, isReturnable = 0, hasSerial = 0, assemblyId = "") {
    let rowId = `row_${id}_${Date.now()}`;
    let existingRow = $(`.row-item[data-id="${id}"]`);
    
    if(existingRow.length > 0 && !hasSerial) {
        if(!isTemplate) {
            let currentQtyInput = existingRow.find('.actual-qty');
            currentQtyInput.val(parseInt(currentQtyInput.val()) + 1);
        }
        return;
    }

    let badgeClass = (parseInt(stock) < parseInt(qty)) ? 'bg-danger' : 'bg-success';
    let retHtml = (isReturnable == 1) ? '<span class="badge bg-warning text-dark ms-2">Kembali ke Stok</span>' : '<span class="badge bg-secondary ms-2">Habis Pakai</span>';
    
    let serialBtnHtml = '';
    if (hasSerial == 1) {
        serialBtnHtml = `
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-outline-info" onclick="openSerialModal(${id}, '${name.replace(/'/g, "\\'")}', this)">
                    <i class="bi bi-key-fill"></i> <span class="btn-text">Pilih S/N</span>
                </button>
                <div class="selected-serials-tags mt-1 small text-muted">Belum ada S/N dipilih</div>
                <input type="hidden" name="serial_ids[${rowId}]" class="serial-ids-input" value="">
            </div>`;
    }

    let rowHtml = `
        <tr class="row-item" data-id="${id}" data-rowid="${rowId}" data-template="${isTemplate}" data-serial="${hasSerial}" data-assembly="${assemblyId}">
            <td>
                <strong>${name}</strong>
                <input type="hidden" name="row_id[]" value="${rowId}">
                <input type="hidden" name="product_id[]" value="${id}">
                <input type="hidden" name="is_returnable[]" value="${isReturnable}">
                ${isTemplate ? '<br><small class="text-primary font-italic">Bagian dari Rakitan</small>' : ''}
                ${retHtml}
                ${serialBtnHtml}
            </td>
            <td><span class="badge ${badgeClass}">${stock}</span></td>
            <td>
                <input type="number" name="out_qty[]" class="form-control actual-qty" value="${qty}" min="1" 
                data-std="${qty}" oninput="handleQtyChange(this)" ${hasSerial == 1 ? 'readonly' : ''}>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-link text-danger p-0" onclick="removeRow(this)">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </td>
        </tr>`;
    $('#componentList').append(rowHtml);
    updateSubmitButton();
}

function handleQtyChange(input) {
    let row = $(input).closest('tr');
    if (row.data('serial') == 1) {
        // Jika berserial, reset pilihan SN jika qty berubah (tapi di sini readonly)
    }
    updateSubmitButton();
}

function removeRow(btn) {
    let row = $(btn).closest('tr');
    let rowId = row.data('rowid');
    delete productSerials[rowId];
    row.remove();
    updateSubmitButton();
}

function removeAssemblyTemplate(id) {
    if(!confirm("Hapus rakitan ini dan semua komponen terkaitnya?")) return;
    
    // Remove from produced list
    $(`#produced_row_${id}`).remove();
    if ($('#producedAssemblyList tr').length === 0) $('#produced_assemblies_container').hide();

    // Remove associated components
    $(`.row-item[data-template="true"][data-assembly="${id}"]`).each(function() {
        let rowId = $(this).data('rowid');
        delete productSerials[rowId];
        $(this).remove();
    });
    
    updateSubmitButton();
}

function updateComponentQty(assemblyId, multiplier) {
    let m = multiplier || 1;
    $(`.row-item[data-template="true"][data-assembly="${assemblyId}"]`).each(function() {
        let std = $(this).find('.actual-qty').data('std');
        let newQty = std * m;
        $(this).find('.actual-qty').val(newQty);
        
        // Jika berserial, reset
        if ($(this).data('serial') == 1) {
            $(this).find('.selected-serials-tags').html('<span class="text-warning">Jumlah berubah, pilih S/N kembali!</span>');
            $(this).find('.serial-ids-input').val('');
            let rowId = $(this).data('rowid');
            delete productSerials[rowId];
        }
    });
    updateSubmitButton();
}

// SERIAL MODAL LOGIC
function openSerialModal(productId, productName, btn) {
    let row = $(btn).closest('tr');
    currentRowId = row.data('rowid');
    let qty = parseInt(row.find('.actual-qty').val());
    let warehouseId = $('#warehouse_id').val();

    $('#serialModalProductName').text(productName);
    $('#requiredSerialCount').text(qty);
    $('#selectedSerialCount').text(productSerials[currentRowId] ? productSerials[currentRowId].length : 0);
    $('#serialCheckboxesContainer').html('<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div> Mencari...</div>');
    
    if (!snModal) {
        snModal = new bootstrap.Modal(document.getElementById('serialModal'));
    }
    snModal.show();

    $.getJSON(`../outbound/get_available_serials.php?product_id=${productId}&warehouse_id=${warehouseId}`, function(data) {
        renderSerialCheckboxes(data);
    });
}

function renderSerialCheckboxes(data) {
    let container = $('#serialCheckboxesContainer');
    container.empty();
    
    if (data.length === 0) {
        container.html('<div class="alert alert-warning">Tidak ada nomor seri tersedia di gudang ini.</div>');
        return;
    }

    let selected = productSerials[currentRowId] || [];

    data.forEach(sn => {
        let isChecked = selected.includes(sn.serial_number) ? 'checked' : '';
        let item = `
            <label class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <input class="form-check-input me-2 serial-checkbox" type="checkbox" value="${sn.serial_number}" ${isChecked}>
                    <span class="sn-text">${sn.serial_number}</span>
                </div>
                <span class="badge bg-light text-dark small">${sn.status}</span>
            </label>`;
        container.append(item);
    });

    updateModalCount();
}

function updateModalCount() {
    let count = $('.serial-checkbox:checked').length;
    let required = parseInt($('#requiredSerialCount').text());
    $('#selectedSerialCount').text(count);
    $('#confirmSerialBtn').prop('disabled', count !== required);
}

$(document).on('change', '.serial-checkbox', function() {
    updateModalCount();
});

$('#serialSearchInput').on('input', function() {
    let val = $(this).val().toLowerCase();
    $('.list-group-item').each(function() {
        let sn = $(this).find('.sn-text').text().toLowerCase();
        if (sn.includes(val)) {
            $(this).removeClass('d-none').addClass('d-flex');
        } else {
            $(this).removeClass('d-flex').addClass('d-none');
        }
    });
});

$('#confirmSerialBtn').click(function() {
    let selected = [];
    $('.serial-checkbox:checked').each(function() {
        selected.push($(this).val());
    });
    
    productSerials[currentRowId] = selected;
    
    let row = $(`.row-item[data-rowid="${currentRowId}"]`);
    row.find('.serial-ids-input').val(selected.join(','));
    row.find('.selected-serials-tags').html(selected.map(s => `<span class="badge bg-info me-1">${s}</span>`).join(''));
    row.find('.btn-text').text('Ubah S/N');
    
    if (snModal) snModal.hide();
    updateSubmitButton();
});

function updateSubmitButton() {
    let count = $('#componentList tr').length;
    $('#itemCount').text(count + ' Item');
    
    let allSerialsPicked = true;
    $('.row-item[data-serial="1"]').each(function() {
        if (!$(this).find('.serial-ids-input').val()) {
            allSerialsPicked = false;
        }
    });

    $('#btnSubmit').prop('disabled', count === 0 || !allSerialsPicked);
}

$(document).ready(function() {
    $('#manual_product_select').select2({
        ajax: {
            url: 'search_products.php',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    q: params.term,
                    warehouse_id: $('#warehouse_id').val()
                };
            },
            processResults: function (data) {
                return {
                    results: data.results
                };
            }
        },
        minimumInputLength: 2,
        placeholder: '-- Cari Nama/Kode Barang --'
    });
});
</script>

<?php include '../../includes/footer.php'; ?>