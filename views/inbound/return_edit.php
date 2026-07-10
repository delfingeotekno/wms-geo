<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

// Pastikan hanya user yang berhak yang bisa mengakses
if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$return_id = $_GET['id'] ?? null;
if (!$return_id) { 
    header("Location: index.php"); 
    exit; 
}

// 1. Ambil Data Header Return
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

if (!$return_data) { 
    die("<div class='alert alert-danger m-3'>Data return tidak ditemukan.</div>"); 
}

// Path foto bukti transaksi lama
$old_photo_path = "../../uploads/inbound_photos/" . $return_data['proof_photo_path'];
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Formulir Barang Masuk (Return) - Edit</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-dark">Edit Data Transaksi Return</h5>
        <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-arrow-left me-2"></i>Kembali ke Daftar Inbound
        </a>
    </div>
    <div class="card-body">
        <form id="return-form" action="return_update.php" method="POST">
            <input type="hidden" id="return_id" name="return_id" value="<?= $return_id ?>">
            <input type="hidden" name="items_json" id="items_json">

            <div class="row">
                <div class="col-md-12 mb-3">
                    <label for="warehouse_id" class="form-label">Gudang Tujuan Return <span class="text-danger">*</span></label>
                    <select class="form-select bg-light" id="warehouse_id" name="warehouse_id" disabled required>
                        <option value="">Pilih Gudang</option>
                        <?php
                        $wh_options = $conn->query("SELECT id, name AS warehouse_name FROM warehouses WHERE is_active = 1");
                        while($w = $wh_options->fetch_assoc()) {
                            $selected = ($w['id'] == $return_data['warehouse_id']) ? 'selected' : '';
                            echo "<option value='{$w['id']}' {$selected}>{$w['warehouse_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3 position-relative">
                    <label for="outbound_search" class="form-label">Nomor Transaksi Barang Keluar (DN/DO)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="outbound_search" class="form-control bg-light" value="<?= htmlspecialchars($return_data['outbound_number']) ?>" readonly required>
                    </div>
                    <input type="hidden" id="outbound_number" name="outbound_number" value="<?= htmlspecialchars($return_data['outbound_number']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="transaction_date" class="form-label">Tanggal Transaksi Return</label>
                    <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?= htmlspecialchars($return_data['transaction_date']) ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="sender" class="form-label">Nama Pengirim (Receiver Outbound)</label>
                    <input type="text" class="form-control bg-light" id="sender" name="sender" value="<?= htmlspecialchars($return_data['sender']) ?>" readonly required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="transaction_number_display" class="form-label">Nomor Transaksi Return (RET)</label>
                    <input type="text" class="form-control bg-light" id="transaction_number_display" value="<?= htmlspecialchars($return_data['transaction_number']) ?>" readonly>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="reference" class="form-label">Referensi</label>
                <input type="text" class="form-control bg-light" id="reference" name="reference" value="<?= htmlspecialchars($return_data['reference']) ?>" readonly>
            </div>
            
            <hr>
            
            <h5 class="fw-bold mt-4">Foto Bukti Transaksi <span class="text-danger">*</span></h5>
            <div class="card p-3 mb-3 bg-light text-center">
                <div id="photo-preview-container" class="position-relative mb-3" style="max-width: 400px; margin: auto; display: <?= $return_data['proof_photo_path'] ? 'block' : 'none' ?>;">
                    <img id="photo-preview" src="<?= $old_photo_path ?>" class="img-fluid rounded border" alt="Pratinjau Foto">
                    <button type="button" class="btn btn-sm btn-danger rounded-circle position-absolute top-0 end-0 m-2" id="delete-photo-btn" style="width: 30px; height: 30px; font-size: 1rem; padding: 0;">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-center">
                    <button type="button" class="btn btn-dark rounded-pill px-4" id="take-photo-btn">
                        <i class="bi bi-camera-fill me-2"></i>Ambil Foto
                    </button>
                    <label class="btn btn-primary rounded-pill px-4" for="upload-photo-input">
                        <i class="bi bi-upload me-2"></i>Unggah Gambar
                    </label>
                    <input type="file" id="upload-photo-input" name="upload-photo" accept="image/*" class="d-none">
                </div>
                <input type="hidden" name="proof_photo_data" id="proof_photo_data" value="<?= htmlspecialchars($return_data['proof_photo_path']) ?>" required>
            </div>

            <h5 class="fw-bold mt-4">Detail Item yang Dikembalikan</h5>
            <p class="text-muted" id="item-info-message">Memuat daftar item transaksi...</p>

            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="items-table">
                    <thead>
                        <tr class="table-secondary">
                            <th>Kode Produk</th>
                            <th>Nama Produk</th>
                            <th>SN/Detail</th>
                            <th>Qty Keluar</th>
                            <th>Qty Return</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <label for="notes" class="form-label">Catatan Return</label>
                <textarea class="form-control" id="notes" name="notes" rows="3"><?= htmlspecialchars($return_data['notes']) ?></textarea>
            </div>
            
            <button type="submit" class="btn btn-success rounded-pill px-4 mt-3" id="submit-return-btn" disabled>
                <i class="bi bi-arrow-return-left me-2"></i>Simpan Perubahan
            </button>
        </form>
    </div>
</div>

<div class="modal fade" id="cameraModal" tabindex="-1" aria-labelledby="cameraModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cameraModalLabel">Ambil Foto Bukti</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div id="camera-select-buttons" class="mb-3 d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-sm btn-primary" id="frontCamBtn"><i class="bi bi-person-circle"></i> Kamera Depan</button>
                    <button type="button" class="btn btn-sm btn-info" id="backCamBtn"><i class="bi bi-camera-fill"></i> Kamera Belakang</button>
                </div>
                
                <video id="camera-video" class="img-fluid rounded border" style="width: 100%; max-width: 400px; display: none;"></video>
                <div id="camera-message" class="text-muted p-3">Pilih kamera untuk memulai.</div>
                <canvas id="camera-canvas" style="display: none;"></canvas>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="capture-photo-btn" disabled>
                    <i class="bi bi-camera me-2"></i>Ambil Foto
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS & SweetAlert -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // --- Variabel Global untuk Foto/Kamera ---
    let currentStream = null;
    let preferredFacingMode = 'environment';
    const cameraVideo = document.getElementById('camera-video');
    const cameraCanvas = document.getElementById('camera-canvas');
    const capturePhotoBtn = document.getElementById('capture-photo-btn');
    const takePhotoBtn = document.getElementById('take-photo-btn');
    const uploadPhotoInput = document.getElementById('upload-photo-input');
    const photoPreview = document.getElementById('photo-preview');
    const photoPreviewContainer = document.getElementById('photo-preview-container');
    const deletePhotoBtn = document.getElementById('delete-photo-btn');
    const proofPhotoDataInput = document.getElementById('proof_photo_data');
    const cameraModal = new bootstrap.Modal(document.getElementById('cameraModal'));

    // --- Variabel Global Form Return ---
    const returnId = <?= json_encode($return_id) ?>;
    const outboundHiddenInput = document.getElementById('outbound_number');
    const itemsTableBody = document.querySelector('#items-table tbody');
    const itemsJsonInput = document.getElementById('items_json');
    const submitBtn = document.getElementById('submit-return-btn');
    const itemInfoMessage = document.getElementById('item-info-message');

    // Array global untuk menyimpan state item yang akan di-return
    const ITEMS_RETURN = []; 
    
    // Fungsi untuk menghentikan aliran kamera
    function stopCamera() {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
        }
        cameraVideo.style.display = 'none';
        cameraVideo.srcObject = null;
        capturePhotoBtn.disabled = true;
        document.getElementById('camera-message').style.display = 'block';
        document.getElementById('camera-message').textContent = 'Pilih kamera untuk memulai.';
    }

    // Fungsi untuk memulai kamera
    function startCamera(facingMode) {
        stopCamera();
        const constraints = {
            video: {
                facingMode: facingMode
            }
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(stream => {
                currentStream = stream;
                cameraVideo.srcObject = stream;
                cameraVideo.style.display = 'block';
                document.getElementById('camera-message').style.display = 'none';
                
                cameraVideo.onloadedmetadata = () => {
                    cameraVideo.play();
                    capturePhotoBtn.disabled = false;
                };
            })
            .catch(err => {
                console.error("Error mengakses kamera:", err);
                document.getElementById('camera-message').textContent = 'Akses kamera ditolak atau tidak tersedia.';
            });
    }

    // Event Listener untuk tombol Ambil Foto
    takePhotoBtn.addEventListener('click', function() {
        startCamera(preferredFacingMode); 
        cameraModal.show();
    });

    // Event Listener untuk tombol Kamera Depan
    document.getElementById('frontCamBtn').addEventListener('click', () => {
        preferredFacingMode = 'user';
        startCamera('user');
    });

    // Event Listener untuk tombol Kamera Belakang
    document.getElementById('backCamBtn').addEventListener('click', () => {
        preferredFacingMode = 'environment';
        startCamera('environment');
    });

    // Event saat modal kamera ditutup
    document.getElementById('cameraModal').addEventListener('hidden.bs.modal', stopCamera);

    // Event Listener untuk Capture Photo
    capturePhotoBtn.addEventListener('click', function() {
        cameraCanvas.width = cameraVideo.videoWidth;
        cameraCanvas.height = cameraVideo.videoHeight;
        const context = cameraCanvas.getContext('2d');
        
        context.drawImage(cameraVideo, 0, 0, cameraCanvas.width, cameraCanvas.height);

        const imageDataURL = cameraCanvas.toDataURL('image/jpeg', 0.8);

        photoPreview.src = imageDataURL;
        proofPhotoDataInput.value = imageDataURL;
        photoPreviewContainer.style.display = 'block';
        
        stopCamera();
        cameraModal.hide();
        updateItemsJson();
    });

    // Event Listener untuk Upload Foto
    uploadPhotoInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                photoPreview.src = e.target.result;
                proofPhotoDataInput.value = e.target.result;
                photoPreviewContainer.style.display = 'block';
                updateItemsJson();
            };
            reader.readAsDataURL(file);
        }
    });

    // Event Listener untuk Hapus Foto
    deletePhotoBtn.addEventListener('click', function() {
        photoPreview.src = '';
        proofPhotoDataInput.value = '';
        photoPreviewContainer.style.display = 'none';
        uploadPhotoInput.value = ''; // Reset input file
        updateItemsJson();
    });

    // --- LOGIKA FORM RETURN (Pengolahan Item) ---
    
    /**
     * Mengambil data transaksi keluar menggunakan AJAX/Fetch API.
     */
    function fetchTransactionData(outboundNumber) {
        if (!outboundNumber) {
            return;
        }

        itemInfoMessage.innerHTML = '<span class="text-info"><i class="bi bi-hourglass-split"></i> Memuat detail item transaksi...</span>';
        
        fetch(`get_return_edit_details.php?return_id=${returnId}&outbound_number=${encodeURIComponent(outboundNumber)}`) 
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.statusText);
            }
            return response.json();
        })
        .then(data => {
            if (data.status === 'success') {
                itemInfoMessage.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Daftar item berhasil dimuat. Silakan perbarui kuantitas pengembalian barang.</span>';
                renderItemsTable(data.details);
            } else {
                alert('Gagal memuat data detail return: ' + data.message);
                itemInfoMessage.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + data.message + '</span>';
            }
        })
        .catch(error => {
            console.error('Fetch atau Parsing Error:', error);
            alert('Terjadi kesalahan fatal saat memuat data: ' + error.message);
            itemInfoMessage.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Kesalahan Sistem.</span>';
        });
    }

    /**
     * Merender data item dari API ke dalam tabel dan mengisi array ITEMS_RETURN.
     */
    function renderItemsTable(details) {
        itemsTableBody.innerHTML = ''; 
        ITEMS_RETURN.length = 0; // Reset array global

        details.forEach((item, index) => {
            const isSerial = item.has_serial == 1;
            const row = itemsTableBody.insertRow();

            // Kolom 1: Kode Produk
            row.insertCell().innerHTML = `<span class="fw-bold">${item.product_code}</span>`;

            // Kolom 2: Nama Produk
            row.insertCell().textContent = item.product_name || '-';

            // Kolom 3: SN/Detail
            const snCell = row.insertCell();
            const serialValue = isSerial ? (item.serial_number || '-') : '-';
            snCell.textContent = serialValue;

            // Kolom 4: Qty Keluar
            row.insertCell().textContent = item.max_outbound_qty;

            // Kolom 5: Qty Return
            const returnQtyCell = row.insertCell();
            let returnQtyInputHTML = '';

            // Inisialisasi item di array ITEMS_RETURN
            const currentReturnQty = parseFloat(item.current_return_qty) || 0;
            const isChecked = currentReturnQty > 0;

            ITEMS_RETURN.push({
                product_id: item.product_id,
                quantity: currentReturnQty, 
                serial_number: isSerial ? item.serial_number : null,
                is_return: isChecked
            });

            if (isSerial) {
                // Jika memiliki SN, gunakan switch
                returnQtyInputHTML = `
                    <div class="form-check form-switch d-flex justify-content-center">
                        <input class="form-check-input item-switch" type="checkbox" id="return-switch-${index}" data-index="${index}" ${isChecked ? 'checked' : ''}>
                        <label class="form-check-label ms-2" for="return-switch-${index}">Return</label>
                    </div>
                `;
            } else {
                // Jika tidak memiliki SN, gunakan input untuk Qty Return
                returnQtyInputHTML = `
                    <input type="number" class="form-control form-control-sm return-qty-input" 
                           data-index="${index}" value="${currentReturnQty}" min="0" max="${item.max_outbound_qty}" 
                           required style="width: 80px; text-align: center;">
                `;
            }
            returnQtyCell.innerHTML = returnQtyInputHTML;

            // Kolom 6: Aksi (kosong)
            row.insertCell().textContent = '';
        });

        setupItemListeners();
        updateItemsJson();
    }

    /**
     * Menyiapkan listener untuk input/switch Qty Return.
     */
    function setupItemListeners() {
        // Listener untuk switch Serial Number
        document.querySelectorAll('.item-switch').forEach(switchEl => {
            switchEl.addEventListener('change', function() {
                const index = parseInt(this.dataset.index);
                const isChecked = this.checked;
                
                if (index < ITEMS_RETURN.length) {
                    ITEMS_RETURN[index].is_return = isChecked;
                    ITEMS_RETURN[index].quantity = isChecked ? 1 : 0; 
                }
                updateItemsJson();
            });
        });

        // Listener untuk input Quantity (Non-Serial)
        document.querySelectorAll('.return-qty-input').forEach(inputEl => {
            inputEl.addEventListener('input', function() {
                const index = parseInt(this.dataset.index);
                let qty = parseInt(this.value);
                const maxQty = parseInt(this.getAttribute('max'));

                // Sanitasi input
                if (isNaN(qty) || qty < 0) {
                    qty = 0;
                } else if (qty > maxQty) {
                    qty = maxQty;
                    this.value = maxQty; 
                } else if (this.value === "") { 
                    return; 
                }
                
                if (index < ITEMS_RETURN.length) {
                    ITEMS_RETURN[index].quantity = qty;
                    ITEMS_RETURN[index].is_return = qty > 0; 
                }
                updateItemsJson();
            });
        });
    }

    /**
     * Mengupdate hidden field JSON sebelum submit.
     */
    function updateItemsJson() {
        // Filter hanya item yang ditandai untuk return (Qty > 0)
        const itemsToSubmit = ITEMS_RETURN.filter(item => item.quantity > 0);
        itemsJsonInput.value = JSON.stringify(itemsToSubmit);
        
        // Non-aktifkan tombol submit jika tidak ada item yang di-return atau foto kosong
        submitBtn.disabled = itemsToSubmit.length === 0 || !proofPhotoDataInput.value || !outboundHiddenInput.value;
    }

    // --- Inisialisasi & Event Utama ---
    document.addEventListener('DOMContentLoaded', function() {
        // Ambil data transaksi secara langsung saat pertama kali dimuat
        const initialOutboundNumber = outboundHiddenInput.value;
        fetchTransactionData(initialOutboundNumber);

        // --- Validasi Final saat form disubmit ---
        document.getElementById('return-form').addEventListener('submit', function(e) {
            updateItemsJson(); // Pastikan JSON terakhir terupdate
            
            // Validasi 1: Foto Bukti
            if (!proofPhotoDataInput.value) {
                e.preventDefault();
                alert('Mohon ambil atau unggah foto bukti transaksi.');
                return false;
            }

            // Validasi 2: Minimal 1 item di-return
            const items = JSON.parse(itemsJsonInput.value || '[]');
            if (items.length === 0) {
                e.preventDefault();
                alert('Pilih setidaknya satu item untuk di-return dengan kuantitas lebih dari nol.');
                return false;
            }
            
            return true;
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>