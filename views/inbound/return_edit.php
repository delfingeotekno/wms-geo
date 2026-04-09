<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    exit();
}

$return_id = $_GET['id'] ?? null;
if (!$return_id) { header("Location: index.php"); exit; }

// 1. Ambil Data Header
// Menyesuaikan: document_number di tabel inbound adalah referensi ke transaction_number di tabel outbound
$sql = "SELECT 
            t.*, 
            t.document_number AS outbound_number, 
            ot.recipient AS sender_name 
        FROM inbound_transactions t
        LEFT JOIN outbound_transactions ot ON t.document_number = ot.transaction_number
        WHERE t.id = ? AND t.transaction_type = 'RET' AND t.is_deleted = 0";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $return_id);
$stmt->execute();
$return_data = $stmt->get_result()->fetch_assoc();

if (!$return_data) { die("<div class='alert alert-danger m-3'>Data return tidak ditemukan.</div>"); }

// Path foto lama
$old_photo_path = "../../uploads/inbound_photos/" . $return_data['proof_photo_path'];
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

<div class="content-header mb-4">
    <h1 class="fw-bold">Edit Transaksi Barang Masuk (Return)</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">ID Return: <?= htmlspecialchars($return_data['transaction_number']) ?></h5>
        <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-arrow-left me-2"></i>Kembali
        </a>
    </div>
    <div class="card-body">
        <form id="return-edit-form" action="return_update.php" method="POST">
            <input type="hidden" name="return_id" value="<?= $return_id ?>">
            <input type="hidden" name="items_json" id="items_json">

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Nomor Transaksi Keluar (DN/DO)</label>
                    <input type="text" class="form-control bg-light" id="outbound_display" value="<?= htmlspecialchars($return_data['outbound_number']) ?>" readonly>
                    <input type="hidden" name="outbound_number" id="outbound_number" value="<?= htmlspecialchars($return_data['outbound_number']) ?>">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="transaction_date" class="form-label fw-bold">Tanggal Transaksi Return</label>
                    <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?= $return_data['transaction_date'] ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="sender" class="form-label">Nama Pengirim</label>
                    <input type="text" class="form-control bg-light" id="sender" value="<?= htmlspecialchars($return_data['sender']) ?>" readonly>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Referensi</label>
                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($return_data['reference']) ?>" readonly>
                </div>
            </div>
            
            <hr>
            
            <h5 class="fw-bold mt-4">Foto Bukti Transaksi</h5>
            <div class="card p-3 mb-3 bg-light text-center">
                <div id="photo-preview-container" class="position-relative mb-3" style="max-width: 400px; margin: auto; display: <?= $return_data['proof_photo_path'] ? 'block' : 'none' ?>;">
                    <img id="photo-preview" src="<?= $old_photo_path ?>" class="img-fluid rounded border" alt="Foto Bukti">
                    <button type="button" class="btn btn-sm btn-danger rounded-circle position-absolute top-0 end-0 m-2" id="delete-photo-btn" style="width: 30px; height: 30px; padding: 0;">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-center">
                    <button type="button" class="btn btn-dark rounded-pill px-4" id="take-photo-btn">
                        <i class="bi bi-camera-fill me-2"></i>Kamera
                    </button>
                    <label class="btn btn-primary rounded-pill px-4 mb-0" for="upload-photo-input">
                        <i class="bi bi-upload me-2"></i>Unggah Baru
                    </label>
                    <input type="file" id="upload-photo-input" accept="image/*" class="d-none">
                </div>
                <input type="hidden" name="proof_photo_data" id="proof_photo_data" value="<?= htmlspecialchars($return_data['proof_photo_path']) ?>">
            </div>

            <h5 class="fw-bold mt-4">Detail Item</h5>
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="items-table">
                    <thead class="table-secondary">
                        <tr>
                            <th>Kode Produk</th>
                            <th>Nama Produk</th>
                            <th>SN/Detail</th>
                            <th class="text-center">Max Keluar</th>
                            <th style="width: 150px;" class="text-center">Qty Return</th>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                        </tbody>
                </table>
            </div>

            <div class="mt-4">
                <label for="notes" class="form-label">Catatan Return</label>
                <textarea class="form-control" id="notes" name="notes" rows="3"><?= htmlspecialchars($return_data['notes']) ?></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary rounded-pill px-5 mt-4 shadow-sm" id="submit-btn">
                <i class="bi bi-save me-2"></i>Simpan Perubahan
            </button>
        </form>
    </div>
</div>

<div class="modal fade" id="cameraModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ambil Foto Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <video id="camera-video" class="img-fluid rounded border" style="width: 100%;"></video>
                <canvas id="camera-canvas" style="display: none;"></canvas>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="capture-photo-btn">Ambil Gambar</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const returnId = <?= json_encode($return_id) ?>;
    let ITEMS_STATE = [];

    // 1. Fungsi memuat item detail
    function loadItems() {
        const outboundNum = $('#outbound_number').val();

        if (!outboundNum) {
            $('#items-body').html('<tr><td colspan="5" class="text-center text-warning">Nomor DN/DO tidak valid.</td></tr>');
            return;
        }

        fetch(`get_return_edit_details.php?return_id=${returnId}&outbound_number=${encodeURIComponent(outboundNum)}`)
        .then(res => res.json())
        .then(data => {
            const container = $('#items-body');
            container.empty();
            ITEMS_STATE = [];

            if (data.status === 'success' && data.details && data.details.length > 0) {
                data.details.forEach((item, index) => {
                    const isSerial = parseInt(item.has_serial) === 1;
                    const currentReturnQty = parseFloat(item.current_return_qty) || 0;
                    
                    ITEMS_STATE.push({
                        product_id: item.product_id,
                        quantity: currentReturnQty,
                        serial_number: item.serial_number
                    });

                    let inputHtml = isSerial 
                        ? `<div class="form-check form-switch d-flex justify-content-center">
                            <input class="form-check-input item-switch" type="checkbox" data-index="${index}" ${currentReturnQty > 0 ? 'checked' : ''}>
                           </div>`
                        : `<input type="number" class="form-control form-control-sm qty-input text-center" data-index="${index}" value="${currentReturnQty}" min="0" max="${item.max_outbound_qty}">`;

                    container.append(`
                        <tr>
                            <td><strong>${item.product_code}</strong></td>
                            <td>${item.product_name}</td>
                            <td>${item.serial_number || '-'}</td>
                            <td class="text-center">${item.max_outbound_qty}</td>
                            <td>${inputHtml}</td>
                        </tr>
                    `);
                });
            } else {
                container.append('<tr><td colspan="5" class="text-center">Tidak ada item ditemukan.</td></tr>');
            }
            updateJson();
        })
        .catch(err => {
            console.error("Error loading items:", err);
            $('#items-body').html('<tr><td colspan="5" class="text-center text-danger">Gagal memuat data item.</td></tr>');
        });
    }

    // 2. Listener Input Perubahan Qty
    $(document).on('change', '.item-switch', function() {
        const idx = $(this).data('index');
        ITEMS_STATE[idx].quantity = this.checked ? 1 : 0;
        updateJson();
    });

    $(document).on('input', '.qty-input', function() {
        const idx = $(this).data('index');
        let val = parseFloat($(this).val()) || 0;
        const max = parseFloat($(this).attr('max'));
        
        if (val > max) {
            val = max;
            $(this).val(max);
        }
        
        ITEMS_STATE[idx].quantity = val;
        updateJson();
    });

    // 3. Update JSON & Validasi Tombol Submit
    function updateJson() {
        const filtered = ITEMS_STATE.filter(it => it.quantity > 0);
        $('#items_json').val(JSON.stringify(filtered));
        
        const hasItems = filtered.length > 0;
        const hasPhoto = $('#proof_photo_data').val() !== "";
        $('#submit-btn').prop('disabled', !hasItems || !hasPhoto);
    }

    // 4. Handler Foto
    $('#delete-photo-btn').on('click', function() {
        $('#photo-preview').attr('src', '');
        $('#proof_photo_data').val('');
        $('#photo-preview-container').hide();
        updateJson();
    });

    $('#upload-photo-input').on('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                $('#photo-preview').attr('src', event.target.result);
                $('#proof_photo_data').val(event.target.result);
                $('#photo-preview-container').show();
                updateJson();
            };
            reader.readAsDataURL(file);
        }
    });

    // Jalankan fungsi load data item saat pertama kali dimuat
    loadItems();
});
</script>

<?php include '../../includes/footer.php'; ?>