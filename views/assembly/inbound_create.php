<?php include '../../includes/header.php'; 

// Blokir role production (read-only)
if ($_SESSION['role'] === 'production') {
    header("Location: index.php");
    exit();
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-9 mx-auto">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Manajemen Perakitan</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Penerimaan Hasil</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1 class="h3 mb-0 text-gray-800 fw-bold">Penerimaan Hasil Rakitan</h1>
                    <p class="text-muted small">Selesaikan proses perakitan dan pindahkan ke stok barang jadi.</p>
                </div>
                <a href="index.php" class="btn btn-outline-secondary btn-sm shadow-sm">
                    <i class="bi bi-arrow-left me-1"></i> Kembali
                </a>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-9">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-success py-3">
                    <h6 class="m-0 font-weight-bold text-white"><i class="bi bi-box-seam me-2"></i>Konfirmasi Penyelesaian Rakitan</h6>
                </div>
                <div class="card-body p-4">
                    <form action="inbound_process.php" method="POST" id="formInbound">
                        <div class="row mt-2">
                            <div class="col-md-12">
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-secondary">Referensi Transaksi Keluar (BAST)</label>
                                    <select name="outbound_id" id="outbound_id" class="form-select select2" required>
                                        <option value="">-- Pilih BAST yang Sedang Dirakit --</option>
                                        <?php
                                        // Ambil transaksi yang belum selesai (status != Completed)
                                        $sql_ref = "SELECT id, transaction_no, project_name 
                                                    FROM assembly_outbound 
                                                    WHERE status != 'Completed'
                                                    ORDER BY created_at DESC";
                                        $res_ref = $conn->query($sql_ref);
                                        while($r = $res_ref->fetch_assoc()): ?>
                                            <option value="<?= $r['id'] ?>"><?= $r['transaction_no'] ?> - <?= htmlspecialchars($r['project_name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div id="results_container" style="display:none;">
                                    <label class="form-label fw-bold small text-uppercase text-secondary">Rincian Hasil Rakitan</label>
                                    <div class="table-responsive mb-4">
                                        <table class="table table-bordered table-hover align-middle">
                                            <thead class="table-light small">
                                                <tr>
                                                    <th>Nama Rakitan / Produk Jadi</th>
                                                    <th width="120" class="text-center">Total Order</th>
                                                    <th width="120" class="text-center">Sdh Diterima</th>
                                                    <th width="150" class="text-center text-success fw-bold">Masuk Sekarang</th>
                                                </tr>
                                            </thead>
                                            <tbody id="results_tbody" class="small">
                                                <!-- Dynamic Content -->
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-4">
                                                <label for="warehouse_id" class="form-label fw-bold small text-uppercase text-secondary">Gudang Penyimpanan Barang Jadi</label>
                                                <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                                                    <option value="">-- Pilih Gudang Tujuan --</option>
                                                    <?php
                                                    $warehouses = $conn->query("SELECT id, name AS warehouse_name FROM warehouses WHERE is_active = 1");
                                                    while($w = $warehouses->fetch_assoc()) {
                                                        echo "<option value='{$w['id']}'>{$w['warehouse_name']}</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-4">
                                                <label class="form-label fw-bold small text-uppercase text-secondary">Catatan</label>
                                                <textarea name="note" class="form-control" placeholder="Contoh: Batch ini sudah QC" rows="1"></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="alert alert-info border-0 small d-flex align-items-center mb-4">
                                        <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                                        <div>
                                            Sistem akan otomatis menambah stok produk jadi sesuai resep masing-masing template yang Anda terima, 
                                            dan mengembalikan komponen <strong>Sifat Kembali</strong> ke gudang secara proporsional.
                                        </div>
                                    </div>

                                    <div class="text-end">
                                        <button type="submit" class="btn btn-success btn-lg px-5 shadow-sm fw-bold" id="btnSubmit">
                                            <i class="bi bi-check2-circle me-2"></i> Konfirmasi Penerimaan
                                        </button>
                                    </div>
                                </div>

                                <div id="empty_hint" class="text-center py-5 text-muted">
                                    <i class="bi bi-box-arrow-in-down mb-3" style="font-size: 3rem; opacity: 0.3; display: block;"></i>
                                    Silakan pilih nomor BAST untuk memproses penerimaan barang jadi.
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('.select2').each(function() {
        $(this).select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $(this).parent()
        });
    });

    // Otomatis load rincian rakitan saat BAST dipilih
    $('#outbound_id').on('change', function() {
        let outboundId = $(this).val();
        if(!outboundId) {
            $('#results_container').hide();
            $('#empty_hint').show();
            return;
        }

        $.getJSON(`get_outbound_results.php?outbound_id=${outboundId}`, function(data) {
            let html = '';
            if(data.length === 0) {
                html = '<tr><td colspan="4" class="text-center py-3">Tidak ada rincian data rakitan untuk BAST ini.</td></tr>';
            } else {
                data.forEach(item => {
                    let isFull = (item.remaining <= 0);
                    html += `
                        <tr class="${isFull ? 'table-light text-muted' : ''}">
                            <td>
                                <div class="fw-bold">${item.assembly_name}</div>
                                <div class="text-muted" style="font-size: 0.75rem;">Produk: ${item.product_name}</div>
                                <input type="hidden" name="result_id[]" value="${item.id}">
                                <input type="hidden" name="assembly_id[]" value="${item.assembly_id}">
                            </td>
                            <td class="text-center">${item.qty_ordered}</td>
                            <td class="text-center">${item.qty_received}</td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="qty_receive[]" class="form-control text-center fw-bold" 
                                           value="${item.remaining}" min="0" max="${item.remaining}" 
                                           ${isFull ? 'readonly' : ''}>
                                    <span class="input-group-text bg-white">/ ${item.remaining}</span>
                                </div>
                            </td>
                        </tr>`;
                });
            }
            $('#results_tbody').html(html);
            $('#empty_hint').hide();
            $('#results_container').fadeIn();
        });
    });

    $('#formInbound').on('submit', function(e) {
        let hasValue = false;
        $('input[name="qty_receive[]"]').each(function() {
            if(parseInt($(this).val()) > 0) hasValue = true;
        });

        if(!hasValue) {
            alert("Harap isi jumlah unit yang akan diterima minimal pada satu template!");
            e.preventDefault();
            return false;
        }

        let btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true);
        btn.html('<span class="spinner-border spinner-border-sm me-2"></span> Menyimpan...');
    });
});
</script>

<style>
    .breadcrumb-item + .breadcrumb-item::before { content: "›"; }
    .card { border-radius: 12px; }
    .card-header { border-radius: 12px 12px 0 0 !important; }
    .form-control, .input-group-text { border-radius: 8px; }
    .btn-lg { border-radius: 10px; transition: all 0.3s; }
    .btn-lg:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(25, 135, 84, 0.3); }
    .bg-light { background-color: #f8f9fc !important; border: 1px solid #e3e6f0; }
    .spin-slow { animation: spin 3s linear infinite; }
    @keyframes spin { 100% { transform: rotate(360deg); } }
</style>

<?php include '../../includes/footer.php'; ?>