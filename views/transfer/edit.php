<?php
$page_title = "Edit Surat Jalan Transfer";
include '../../includes/header.php';

if (!isset($_GET['id'])) {
    echo "<script>window.location='index.php';</script>";
    exit;
}

$transfer_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// Get Header
$sql = "SELECT * FROM transfers WHERE id = $transfer_id";
$res = $conn->query($sql);
if ($res->num_rows === 0) {
    die("Data tidak ditemukan.");
}
$trf = $res->fetch_assoc();

if ($trf['status'] !== 'In Transit') {
    die("Hanya transaksi 'In Transit' yang dapat diubah.");
}

// Ambil daftar gudang
$sql_w = "SELECT * FROM warehouses ORDER BY name ASC";
$res_w = $conn->query($sql_w);
$warehouses = [];
while ($w = $res_w->fetch_assoc()) {
    $warehouses[] = $w;
}

// Get Items
$sql_items = "SELECT ti.*, p.product_name, p.product_code, p.has_serial, 
              (SELECT stock FROM warehouse_stocks WHERE product_id = ti.product_id AND warehouse_id = " . $trf['source_warehouse_id'] . ") as current_stock
              FROM transfer_items ti
              JOIN products p ON ti.product_id = p.id
              WHERE ti.transfer_id = $transfer_id";
$res_items = $conn->query($sql_items);
$items_json = [];
while ($itm = $res_items->fetch_assoc()) {
    // Get Serials
    $ti_id = $itm['id'];
    $sql_sn = "SELECT serial_number FROM transfer_serials WHERE transfer_item_id = $ti_id";
    $res_sn = $conn->query($sql_sn);
    $sns = [];
    while ($sn = $res_sn->fetch_assoc()) {
        $sns[] = $sn['serial_number'];
    }
    $itm['serials'] = $sns;
    $items_json[] = $itm;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Transfer Gudang</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit Transfer</li>
                </ol>
            </nav>
            <h2 class="h4 fw-bold mb-0">Edit Surat Jalan: <?= $trf['transfer_no'] ?></h2>
        </div>
        <a href="index.php" class="btn btn-light shadow-sm border"><i class="bi bi-arrow-left me-2"></i> Kembali</a>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <form id="transferForm">
                        <input type="hidden" id="edit_id" value="<?= $transfer_id ?>">
                        <div class="row g-4 mb-4 pb-4 border-bottom">
                            <div class="col-md-3">
                                <label class="form-label fw-bold text-muted small text-uppercase">Nomor Mutasi</label>
                                <input type="text" class="form-control bg-light fw-bold text-primary" id="transfer_no" value="<?= $trf['transfer_no'] ?>" readonly>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold text-muted small text-uppercase">Gudang Asal (Source)</label>
                                <select class="form-select select2" id="source_warehouse_id" required>
                                    <?php foreach ($warehouses as $w): ?>
                                        <option value="<?= $w['id'] ?>" <?= ($trf['source_warehouse_id'] == $w['id']) ? 'selected' : '' ?>><?= $w['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold text-muted small text-uppercase">Gudang Tujuan (Dest)</label>
                                <select class="form-select select2" id="dest_warehouse_id" required>
                                    <?php foreach ($warehouses as $w): ?>
                                        <option value="<?= $w['id'] ?>" <?= ($trf['destination_warehouse_id'] == $w['id']) ? 'selected' : '' ?>><?= $w['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold text-muted small text-uppercase">Kurir / Ekspedisi</label>
                                <input type="text" class="form-control" id="courier_info" value="<?= htmlspecialchars($trf['courier_info'] ?: '') ?>">
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold mb-0">Daftar Barang Mutasi</h5>
                                <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" id="addItemBtn">
                                    <i class="bi bi-plus-lg me-1"></i> Tambah Baris
                                </button>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle" id="itemsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="40%">Pilih Produk (Tersedia)</th>
                                            <th width="15%">Jml Transfer</th>
                                            <th width="40%">Scan Serial Number</th>
                                            <th width="5%" class="text-center">Hapus</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Baris akan ditambahkan via JS -->
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-muted small text-uppercase">Catatan Tambahan</label>
                            <textarea class="form-control" id="notes" rows="2"><?= htmlspecialchars($trf['notes'] ?: '') ?></textarea>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-5">
                            <button type="button" id="btnProses" class="btn btn-warning btn-lg px-5 shadow-sm fw-bold">
                                <i class="bi bi-save me-2"></i> Simpan Perubahan Mutasi
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal SN Selector (Same as create) -->
<div class="modal fade" id="snModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title fs-5 fw-bold"><i class="bi bi-list-check me-2"></i> Pilih Serial Number Stok</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light-subtle">
        <div id="snListContainer" class="d-flex flex-column gap-2"></div>
      </div>
      <div class="modal-footer bg-light p-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary btn-sm fw-bold" id="btnSaveModalSn" data-bs-dismiss="modal">Terapkan Pilihan</button>
      </div>
    </div>
  </div>
</div>

<script>
const existingItems = <?= json_encode($items_json) ?>;

$(document).ready(function() {
    $('.select2').select2({ theme: 'bootstrap-5' });

    let sourceSelect = $('#source_warehouse_id');
    let destSelect = $('#dest_warehouse_id');

    // Tambah baris
    function addRow(data = null) {
        let tr = `
        <tr>
            <td>
                <select class="form-select product-select" style="width:100%" required>
                    <option value="">Mencari produk...</option>
                </select>
                <small class="text-danger stock-warning" style="display:none;">Stok tidak cukup</small>
            </td>
            <td>
                <input type="number" class="form-control qty-input" min="1" required placeholder="0">
                <small class="text-muted max-stock-info">Maks: <span>0</span></small>
            </td>
            <td>
                <textarea class="form-control sn-input" rows="2"></textarea>
                <div class="d-flex justify-content-between mt-1 align-items-center">
                    <small class="text-muted sn-counter">0 SN</small>
                    <button type="button" class="btn btn-sm btn-outline-primary btn-sn-list" style="display:none;" data-bs-toggle="modal" data-bs-target="#snModal">
                        <i class="bi bi-search me-1"></i> Pilih dari Stok
                    </button>
                </div>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger btn-remove"><i class="bi bi-trash"></i></button>
            </td>
        </tr>`;
        
        $('#itemsTable tbody').append(tr);
        let row = $('#itemsTable tbody tr:last');
        let select = row.find('.product-select');
        
        select.select2({
            theme: 'bootstrap-5',
            ajax: {
                url: '/wms-geo/views/outbound/search_products.php',
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return { q: params.term, warehouse_id: sourceSelect.val() };
                },
                processResults: function (data) {
                    return {
                        results: data.map(function(item) {
                            return { id: item.id, text: item.product_name + ' (' + item.product_code + ') - Stok: ' + item.stock, stock: item.stock, has_serial: item.has_serial };
                        })
                    };
                },
                cache: true
            },
            placeholder: 'Ketik nama/kode produk...',
            minimumInputLength: 1
        });

        select.on('select2:select', function(e) {
            let p = e.params.data;
            row.find('.product-select').attr('data-product-id', p.id);
            row.attr('data-has-serial', p.has_serial);
            row.find('.qty-input').attr('max', p.stock).val(0);
            row.find('.max-stock-info span').text(p.stock);
            
            if (p.has_serial == 1) {
                row.find('.sn-input').val('').prop('readonly', false);
                row.find('.btn-sn-list').show();
                row.find('.qty-input').val(0).prop('readonly', true);
            } else {
                row.find('.sn-input').val('-').prop('readonly', true);
                row.find('.btn-sn-list').hide();
                row.find('.qty-input').val(1).prop('readonly', false);
            }
        });

        row.find('.sn-input').on('input', function() {
            let lines = $(this).val().split(/[\n,]+/).map(s => s.trim()).filter(s => s !== '' && s !== '-');
            row.find('.sn-counter').text(lines.length + ' SN');
            if (row.find('.btn-sn-list').is(':visible')) {
                row.find('.qty-input').val(lines.length).trigger('change');
            }
        });

        // Jika ada data awal, populate
        if (data) {
            let option = new Option(data.product_name + ' (' + data.product_code + ')', data.product_id, true, true);
            select.append(option).trigger('change');
            row.find('.product-select').attr('data-product-id', data.product_id);
            row.attr('data-has-serial', data.has_serial);
            row.find('.qty-input').attr('max', parseInt(data.current_stock) + parseInt(data.quantity)).val(data.quantity);
            row.find('.max-stock-info span').text(parseInt(data.current_stock) + parseInt(data.quantity));
            
            if (data.has_serial == 1) {
                row.find('.sn-input').val(data.serials.join('\n')).prop('readonly', false);
                row.find('.btn-sn-list').show();
                row.find('.qty-input').prop('readonly', true);
                row.find('.sn-counter').text(data.serials.length + ' SN');
            } else {
                row.find('.sn-input').val('-').prop('readonly', true);
                row.find('.btn-sn-list').hide();
            }
        }
    }

    // Load existing items
    existingItems.forEach(item => addRow(item));

    $('#addItemBtn').click(function() { addRow(); });
    $(document).on('click', '.btn-remove', function() { $(this).closest('tr').remove(); });

    // SN Modal (Same logic)
    let activeSnInput = null;
    $(document).on('click', '.btn-sn-list', function() {
        let row = $(this).closest('tr');
        activeSnInput = row.find('.sn-input');
        let product_id = row.find('.product-select').attr('data-product-id');
        let warehouse_id = $('#source_warehouse_id').val();
        $('#snListContainer').html('<div class="text-center p-4"><span class="spinner-border text-primary"></span></div>');
        $.getJSON(`/wms-geo/views/outbound/get_available_serials.php?product_id=${product_id}&warehouse_id=${warehouse_id}`, function(data) {
            let html = '';
            let currentSns = activeSnInput.val().split(/[\n,]+/).map(s => s.trim());
            data.forEach(sn => {
                let isChecked = currentSns.includes(sn.serial_number) ? 'checked' : '';
                html += `<div class="form-check px-3 py-2 border rounded bg-white mt-1"><input class="form-check-input sn-cbx" type="checkbox" value="${sn.serial_number}" id="sn_${sn.serial_number}" ${isChecked}><label class="form-check-label w-100" for="sn_${sn.serial_number}">${sn.serial_number}</label></div>`;
            });
            $('#snListContainer').html(html || 'Stok SN habis.');
        });
    });

    $('#btnSaveModalSn').click(function() {
        if (activeSnInput) {
            let selectedSns = [];
            $('.sn-cbx:checked').each(function() { selectedSns.push($(this).val()); });
            activeSnInput.val(selectedSns.join('\n')).trigger('input');
        }
    });

    $('#btnProses').click(function() {
        let items = [];
        let hasError = false;
        $('#itemsTable tbody tr').each(function() {
            let p_id = $(this).find('.product-select').val();
            let qty = parseInt($(this).find('.qty-input').val()) || 0;
            let has_serial = $(this).attr('data-has-serial') == "1";
            let sns = $(this).find('.sn-input').val().split(/[\n,]+/).map(s => s.trim()).filter(s => s !== '' && s !== '-');
            
            if (!p_id || qty <= 0) { 
                hasError = true; 
                return false; 
            }

            if (has_serial) {
                if (sns.length !== qty) {
                    alert('Gagal! Jumlah Serial Number yang di-scan tidak sesuai dengan jumlah mutasi pada item tersebut.');
                    hasError = true;
                    return false;
                }
            } else {
                sns = [];
            }

            items.push({ product_id: p_id, quantity: qty, serials: sns });
        });

        if (hasError || items.length === 0) { alert('Data tidak valid.'); return; }

        let payload = {
            edit_id: $('#edit_id').val(),
            source_warehouse_id: sourceSelect.val(),
            dest_warehouse_id: destSelect.val(),
            courier_info: $('#courier_info').val(),
            notes: $('#notes').val(),
            items: items
        };

        $(this).prop('disabled', true).text('Menyimpan...');

        $.ajax({
            url: 'save_transfer.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function(res) {
                if (res.success) window.location.href = 'index.php?status=updated';
                else { alert('Gagal: ' + res.message); $('#btnProses').prop('disabled', false).text('Simpan Perubahan'); }
            }
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
