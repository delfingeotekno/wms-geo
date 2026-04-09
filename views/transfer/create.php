<?php
$page_title = "Buat Surat Jalan Transfer";
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff'])) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Akses ditolak.</div></div>";
    include '../../includes/footer.php';
    exit;
}

// Ambil daftar gudang
$sql_w = "SELECT * FROM warehouses ORDER BY name ASC";
$res_w = $conn->query($sql_w);
$warehouses = [];
while ($w = $res_w->fetch_assoc()) {
    $warehouses[] = $w;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Transfer Gudang</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Buat Transfer Baru</li>
                </ol>
            </nav>
            <h2 class="h4 fw-bold mb-0">Surat Jalan Transfer</h2>
        </div>
        <a href="index.php" class="btn btn-light shadow-sm border"><i class="bi bi-arrow-left me-2"></i> Kembali</a>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <form id="transferForm">
                        <div class="row g-4 mb-4 pb-4 border-bottom">
                            <div class="col-md-3">
                                <label class="form-label fw-bold text-muted small text-uppercase">Nomor Mutasi</label>
                                <input type="text" class="form-control bg-light fw-bold text-secondary text-center" id="transfer_no" value="[OTOMATIS]" readonly>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-bold text-muted small text-uppercase">Gudang Asal (Source)</label>
                                <select class="form-select select2" id="source_warehouse_id" required>
                                    <option value="">-- Pilih Asal --</option>
                                    <?php foreach ($warehouses as $w): ?>
                                        <option value="<?= $w['id'] ?>" <?= ($warehouse_id == $w['id']) ? 'selected' : '' ?>><?= $w['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold text-muted small text-uppercase">Gudang Tujuan (Dest)</label>
                                <select class="form-select select2" id="dest_warehouse_id" required>
                                    <option value="">-- Pilih Tujuan --</option>
                                    <?php foreach ($warehouses as $w): ?>
                                        <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-bold text-muted small text-uppercase">Kurir / Ekspedisi</label>
                                <input type="text" class="form-control" id="courier_info" placeholder="Cth: JNE - 123456789">
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
                                            <th width="40%">Scan Serial Number (Opsional)</th>
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
                            <textarea class="form-control" id="notes" rows="2" placeholder="Catatan internal pengiriman surat jalan..."></textarea>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-5">
                            <button type="button" id="btnProses" class="btn btn-success btn-lg px-5 shadow-sm fw-bold">
                                <i class="bi bi-send-check me-2"></i> Proses Pengiriman Mutasi
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal SN Selector -->
<div class="modal fade" id="snModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title fs-5 fw-bold"><i class="bi bi-list-check me-2"></i> Pilih Serial Number Stok</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light-subtle">
        <div id="snListContainer" class="d-flex flex-column gap-2">
           <!-- JS Checkboxes Render -->
        </div>
      </div>
      <div class="modal-footer bg-light p-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary btn-sm fw-bold" id="btnSaveModalSn" data-bs-dismiss="modal">Terapkan Pilihan</button>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({ theme: 'bootstrap-5' });

    let sourceSelect = $('#source_warehouse_id');
    let destSelect = $('#dest_warehouse_id');

    // Validasi agar tujuan tidak sama dengan asal
    destSelect.on('change', function() {
        if ($(this).val() === sourceSelect.val() && $(this).val() !== '') {
            alert('Gudang Tujuan tidak boleh sama dengan Gudang Asal!');
            $(this).val('').trigger('change');
        }
    });

    sourceSelect.on('change', function() {
        if ($(this).val() === destSelect.val() && $(this).val() !== '') {
            destSelect.val('').trigger('change');
        }
        // Jika gudang asal berubah, kosongkan tabel karena stok berbeda
        $('#itemsTable tbody').empty();
        addRow();
    });

    // Tambah baris
    function addRow() {
        let sw_id = sourceSelect.val();
        if (!sw_id) {
            alert('Pilih Gudang Asal terlebih dahulu.');
            return;
        }

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
                <textarea class="form-control sn-input" rows="2" placeholder="Sistem mendeteksi status SN otomatis..."></textarea>
                <div class="d-flex justify-content-between mt-1 align-items-center">
                    <small class="text-muted sn-counter">0 SN terdeteksi</small>
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
        
        // Inisialisasi Select2 AJAX
        select.select2({
            theme: 'bootstrap-5',
            ajax: {
                url: '/wms-geo/views/outbound/search_products.php', // Reuse outbound search yang butuh parameter warehouse_id
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        q: params.term,
                        warehouse_id: sourceSelect.val() // Kirim warehouse_id
                    };
                },
                processResults: function (data) {
                    return {
                        results: data.map(function(item) {
                            return {
                                id: item.id,
                                text: item.product_name + ' (' + item.product_code + ') - Stok: ' + item.stock,
                                stock: item.stock,
                                has_serial: item.has_serial
                            };
                        })
                    };
                },
                cache: true
            },
            placeholder: 'Ketik nama/kode produk...',
            minimumInputLength: 1
        });

        // Event saat produk dipilih
        select.on('select2:select', function(e) {
            let data = e.params.data;
            row.find('.product-select').attr('data-product-id', data.id);
            row.attr('data-has-serial', data.has_serial);
            row.find('.qty-input').attr('max', data.stock).val(0);
            row.find('.max-stock-info span').text(data.stock);
            row.find('.stock-warning').hide();

            // Intelligence SN Toggle
            if (data.has_serial == 1) {
                row.find('.sn-input').val('').prop('readonly', false).attr('placeholder', 'Scan SN atau klik Pilih dari Stok...');
                row.find('.sn-counter').text('0 SN');
                row.find('.btn-sn-list').show();
                row.find('.qty-input').val(0).prop('readonly', true).attr('title', 'Jumlah otomatis mengikuti jumlah Serial Number');
            } else {
                row.find('.sn-input').val('-').prop('readonly', true).attr('placeholder', 'Barang non-serial');
                row.find('.sn-counter').text('Non-Serial');
                row.find('.btn-sn-list').hide();
                row.find('.qty-input').val(1).prop('readonly', false).removeAttr('title');
            }
        });

        // Event validasi Qty
        row.find('.qty-input').on('keyup change', function() {
            let val = parseInt($(this).val()) || 0;
            let max = parseInt($(this).attr('max')) || 0;
            if (val > max) {
                $(this).addClass('is-invalid');
                row.find('.stock-warning').show();
            } else {
                $(this).removeClass('is-invalid');
                row.find('.stock-warning').hide();
            }
        });

        // Event hitung SN
        row.find('.sn-input').on('input', function() {
            let lines = $(this).val().split(/[\n,]+/).map(s => s.trim()).filter(s => s !== '' && s !== '-');
            row.find('.sn-counter').text(lines.length + ' SN');
            
            // Auto Update Qty jika produk adalah barang serial (btn-sn-list terlihat)
            if (row.find('.btn-sn-list').is(':visible')) {
                row.find('.qty-input').val(lines.length).trigger('change');
            }
        });
    }

    $('#addItemBtn').click(function() {
        addRow();
    });

    $(document).on('click', '.btn-remove', function() {
        $(this).closest('tr').remove();
    });

    // SN Modal Logic
    let activeSnInput = null;
    $(document).on('click', '.btn-sn-list', function() {
        let row = $(this).closest('tr');
        activeSnInput = row.find('.sn-input');
        let product_id = row.find('.product-select').attr('data-product-id');
        let warehouse_id = $('#source_warehouse_id').val();
        
        $('#snListContainer').html('<div class="text-center p-4"><span class="spinner-border text-primary"></span><div class="mt-2 small text-muted">Akses Stok SN...</div></div>');
        
        $.getJSON(`/wms-geo/views/outbound/get_available_serials.php?product_id=${product_id}&warehouse_id=${warehouse_id}`, function(data) {
            if (data.length === 0) {
                $('#snListContainer').html('<div class="alert alert-warning border-0 shadow-sm mx-2">Data Serial Number kosong di gudang asal ini.</div>');
                return;
            }
            
            let html = '';
            let currentSns = activeSnInput.val().split(/[\n,]+/).map(s => s.trim());
            data.forEach(sn => {
                let isChecked = currentSns.includes(sn.serial_number) ? 'checked' : '';
                html += `
                <div class="form-check shadow-sm border border-light rounded px-3 py-2 bg-white d-flex align-items-center">
                    <input class="form-check-input sn-cbx fs-5 mt-0 me-3" type="checkbox" value="${sn.serial_number}" id="sn_${sn.serial_number}" ${isChecked}>
                    <label class="form-check-label font-monospace w-100 fw-bold" for="sn_${sn.serial_number}">
                        <i class="bi bi-upc-scan me-2 text-primary"></i>${sn.serial_number}
                    </label>
                </div>`;
            });
            $('#snListContainer').html(html);
        });
    });

    $('#btnSaveModalSn').click(function() {
        if (activeSnInput) {
            let selectedSns = [];
            $('.sn-cbx:checked').each(function() {
                selectedSns.push($(this).val());
            });
            // Gunakan join dengan newline fisik
            activeSnInput.val(selectedSns.join('\n')); 
            activeSnInput.trigger('input'); // re-count and auto-update qty
        }
    });

    // Proses Submit
    $('#btnProses').click(function() {
        if (!sourceSelect.val() || !destSelect.val()) {
            alert('Lengkapi pilihan Gudang Asal dan Tujuan!');
            return;
        }

        let items = [];
        let hasError = false;

        $('#itemsTable tbody tr').each(function() {
            let p_id = $(this).find('.product-select').val();
            let qty = parseInt($(this).find('.qty-input').val()) || 0;
            let max = parseInt($(this).find('.qty-input').attr('max')) || 0;
            let has_serial = $(this).attr('data-has-serial') == "1";
            let sns = $(this).find('.sn-input').val().split(/[\n,]+/).map(s => s.trim()).filter(s => s !== '' && s !== '-');

            if (!p_id || qty <= 0) {
                alert('Terdapat baris produk yang belum lengkap atau qty 0.');
                hasError = true;
                return false;
            }

            if (qty > max) {
                alert('Gagal! Ada item yang qty mutasinya melebihi sisa stok di Gudang Asal.');
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
                sns = []; // Kosongkan arrray untuk mencegah bypass backend
            }

            items.push({
                product_id: p_id,
                quantity: qty,
                serials: sns
            });
        });

        if (hasError) return;
        if (items.length === 0) {
            alert('Tambahkan minimal 1 barang untuk dimutasi!');
            return;
        }

        let payload = {
            transfer_no: $('#transfer_no').val(),
            source_warehouse_id: sourceSelect.val(),
            dest_warehouse_id: destSelect.val(),
            courier_info: $('#courier_info').val(),
            notes: $('#notes').val(),
            items: items
        };

        let btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span> Memproses...');

        $.ajax({
            url: 'save_transfer.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            success: function(res) {
                if (res.success) {
                    window.location.href = 'index.php?status=success';
                } else {
                    alert('Gagal: ' + res.message);
                    btn.prop('disabled', false).html('<i class="bi bi-send-check me-2"></i> Proses Pengiriman Mutasi');
                }
            },
            error: function() {
                alert('Terjadi kesalahan server.');
                btn.prop('disabled', false).html('<i class="bi bi-send-check me-2"></i> Proses Pengiriman Mutasi');
            }
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
