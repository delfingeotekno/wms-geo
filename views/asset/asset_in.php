<?php
// FILE: views/asset/asset_in.php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location: ../../index.php");
    exit();
}

$message = $_GET['message'] ?? '';
$message_type = $_GET['type'] ?? '';

// Ambil daftar gudang aktif untuk opsi
$warehouses_query = mysqli_query($conn, "SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY name ASC");
$warehouses = mysqli_fetch_all($warehouses_query, MYSQLI_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    body { background-color: #f4f6f9; }
    .card { border: none; border-radius: 12px; }
    #searchResultsContainer {
        max-height: 250px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 0 0 8px 8px;
        background: white;
        z-index: 1050;
    }
    .table-sm-custom th, .table-sm-custom td {
        font-size: 0.88rem;
        padding: 0.6rem;
    }
    .condition-select {
        font-size: 0.85rem;
        padding: 0.25rem 0.5rem;
    }
    .location-input {
        font-size: 0.85rem;
        padding: 0.25rem 0.5rem;
    }
</style>

<div class="main-content">
    <div class="container-fluid py-4">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="fw-bold h3 mb-1"><i class="bi bi-tools text-primary me-2"></i>Pemasukan Barang Aset / Bekas</h1>
                <p class="text-muted small mb-0">Tambahkan barang-barang bekas/aset ke inventarisasi gudang dengan mencatat kondisi spesifik unit.</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show shadow-sm border-0" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <!-- Form Grid Utama -->
            <div class="col-lg-9">
                <form id="assetBatchForm" method="POST" action="save_asset_in_batch.php">
                    
                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>1. Informasi Dasar & Pilih Produk</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-2">
                                    <label for="asset_date" class="form-label small fw-bold text-muted">Tanggal</label>
                                    <input type="date" class="form-control form-control-sm" id="asset_date" name="asset_date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label for="warehouse_id" class="form-label small fw-bold text-muted">Gudang Penyimpanan</label>
                                    <select class="form-select form-select-sm" id="warehouse_id" name="warehouse_id" required>
                                        <?php foreach ($warehouses as $wh): ?>
                                            <option value="<?= $wh['id'] ?>" <?= (isset($_SESSION['warehouse_id']) && $_SESSION['warehouse_id'] == $wh['id']) ? 'selected' : '' ?>><?= htmlspecialchars($wh['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-7 position-relative">
                                    <label for="productInput" class="form-label small fw-bold text-muted">Cari Produk (Wajib Serial Number untuk Aset)</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                                        <input type="text" class="form-control" id="productInput" placeholder="Ketik kode atau nama produk berserial..." autocomplete="off">
                                    </div>
                                    <div id="searchResultsContainer" class="list-group position-absolute w-100 shadow-lg" style="display: none;"></div>
                                </div>
                            </div>

                            <!-- INPUT MANUAL SN -->
                            <div class="row g-3 mt-1 align-items-end" id="manualSnSection" style="display: none;">
                                <div class="col-md-12">
                                    <hr class="my-2">
                                    <label for="manualSnInput" class="form-label small fw-bold text-info"><i class="bi bi-keyboard me-1"></i> Input Serial Number Manual</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control border-info" id="manualSnInput" placeholder="Ketik Serial Number di sini lalu tekan Enter..." autocomplete="off">
                                        <button type="button" class="btn btn-info text-white fw-bold px-3" id="addManualSnBtn">
                                            <i class="bi bi-plus-circle me-1"></i>Tambah
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-list-columns-reverse me-2 text-primary"></i>2. Daftar Item Aset yang Di-Scan</h6>
                            <div>
                                <span class="badge bg-light text-dark border"><i class="bi bi-upc-scan me-1 text-primary"></i>Gunakan Scanner Hardware</span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            
                            <!-- Area Notification Alert inside Card -->
                            <div class="p-3" id="assetAlertPlaceholder">
                                <div class="alert alert-secondary mb-0 py-2 small"><i class="bi bi-info-circle me-1"></i> Silakan cari dan pilih produk di atas terlebih dahulu, lalu mulai **Scan Serial Number** aset Anda.</div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm table-hover table-bordered table-sm-custom mb-0" id="assetItemTable" style="display: none;">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center" style="width: 50px;">No.</th>
                                            <th>Nama Produk</th>
                                            <th style="width:180px;">Serial Number</th>
                                            <th style="width:160px;">Kondisi Aset</th>
                                            <th>Lokasi Simpan</th>
                                            <th class="text-center" style="width: 60px;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Javascript appended rows here -->
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                    <input type="hidden" name="items_json" id="items_json">
                    
                    <div class="text-end">
                        <button type="submit" class="btn btn-success btn-lg px-5 rounded-pill shadow-sm fw-bold" id="submitBatchBtn" style="display: none;">
                            <i class="bi bi-save-fill me-2"></i> Simpan Batch Aset
                        </button>
                    </div>

                </form>
            </div>

            <!-- Sidebar Info -->
            <div class="col-lg-3">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-shield-check me-2 text-warning"></i>Status Aset</h6>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">Serial Number yang Anda masukkan akan tercatat sebagai status <strong class="text-dark">"Tersedia (Bekas)"</strong> dan akan menaikkan unit fisik di sistem Pergudangan.</p>
                        
                        <button type="button" class="btn btn-outline-info btn-sm w-100 mb-2 rounded-pill fw-bold" id="showAssetListBtn" style="display: none;">
                            <i class="bi bi-eye me-1"></i> Lihat List SN Aset
                        </button>
                        
                        <div id="assetListContainer" style="display: none;">
                            <hr class="my-2">
                            <h6 class="small fw-bold text-primary mb-2">Daftar SN Aset Tersedia:</h6>
                            <ul class="list-group list-group-flush border rounded-3 p-1 shadow-sm" id="currentAssetSNList" style="max-height: 250px; overflow-y: auto;">
                                <li class="list-group-item text-center text-muted small">Memuat...</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const productInput = document.getElementById('productInput');
    const searchResultsContainer = document.getElementById('searchResultsContainer');
    const assetItemTable = document.getElementById('assetItemTable');
    const assetTableBody = assetItemTable.querySelector('tbody');
    const assetAlertPlaceholder = document.getElementById('assetAlertPlaceholder');
    const submitBatchBtn = document.getElementById('submitBatchBtn');
    const itemsJsonInput = document.getElementById('items_json');
    const showAssetListBtn = document.getElementById('showAssetListBtn');
    const assetListContainer = document.getElementById('assetListContainer');
    const currentAssetSNList = document.getElementById('currentAssetSNList');
    const manualSnSection = document.getElementById('manualSnSection');
    const manualSnInput = document.getElementById('manualSnInput');
    const addManualSnBtn = document.getElementById('addManualSnBtn');

    let selectedProduct = null;
    let assetItems = []; // Array of {product_id, product_name, serial_number, condition, location}

    // Barcode Buffer untuk Hardware Scanner
    let barcodeBuffer = '';
    let lastKeyTime = Date.now();

    // 1. SMART SCANNER LISTENER
    document.addEventListener('keydown', function(e) {
        const active = document.activeElement;
        // Jangan tangkap jika kursor sedang di dalam input notes atau input pencarian
        if ([productInput].includes(active) || active.tagName === 'TEXTAREA') return;

        const now = Date.now();
        if (now - lastKeyTime > 100) barcodeBuffer = ''; // interval 100ms
        lastKeyTime = now;

        if (e.key === 'Enter') {
            if (barcodeBuffer.length >= 3) {
                e.preventDefault();
                processScannedSN(barcodeBuffer.trim());
                barcodeBuffer = '';
            }
        } else if (e.key.length === 1) {
            barcodeBuffer += e.key;
        }
    });

    function processScannedSN(sn) {
        if (!selectedProduct) {
            alert("⚠️ Temukan dan PILIH PRODUK terlebih dahulu sebelum melakukan scanning Serial Number!");
            return;
        }

        // Duplikasi Internal Check
        const isDuplicate = assetItems.some(item => item.serial_number === sn && item.product_id === selectedProduct.id);
        if (isDuplicate) {
            alert(`⚠️ S/N ${sn} sudah ada di dalam daftar di bawah.`);
            return;
        }

        // Tambah ke Array
        assetItems.push({
            product_id: selectedProduct.id,
            product_code: selectedProduct.product_code,
            product_name: selectedProduct.product_name,
            serial_number: sn,
            condition: 'Baik',
            location: 'Gudang Aset'
        });

        renderTable();
        playBeep();
    }

    function playBeep() {
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(1000, audioCtx.currentTime); 
            oscillator.connect(audioCtx.destination);
            oscillator.start();
            oscillator.stop(audioCtx.currentTime + 0.1); 
        } catch (e) {}
    }

    // 2. SEARCH PRODUK
    let debounceTimer;
    productInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const term = this.value.trim();
        if (term.length < 2) { searchResultsContainer.style.display = 'none'; return; }
        debounceTimer = setTimeout(() => {
            const whId = document.getElementById('warehouse_id').value;
            fetch(`search_products_asset.php?term=${encodeURIComponent(term)}&warehouse_id=${whId}`)
                .then(res => res.json())
                .then(data => displaySearchResults(data.results || data))
                .catch(err => console.error(err));
        }, 300);
    });

    function displaySearchResults(results) {
        searchResultsContainer.innerHTML = '';
        if (!results.length) { searchResultsContainer.style.display = 'none'; return; }

        results.forEach(product => {
            if (parseInt(product.has_serial) !== 1) return; // Hanya berserial

            const item = document.createElement('a');
            item.className = 'list-group-item list-group-item-action py-2';
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div><strong>${product.product_code}</strong> - <span class="small">${product.product_name}</span></div>
                    <span class="badge bg-secondary">Aset: ${product.stock || 0}</span>
                </div>`;
            item.addEventListener('click', function(e) {
                e.preventDefault();
                selectedProduct = product;
                productInput.value = `${product.product_code} - ${product.product_name}`;
                searchResultsContainer.style.display = 'none';
                
                // Update UI Sidebar/Guidance
                assetAlertPlaceholder.innerHTML = `<div class="alert alert-success py-2 mb-0 small"><i class="bi bi-shield-check me-1"></i> Produk terpilih: **${product.product_name}**. Silakan scan Serial Number unit atau masukkan secara manual di bawah.</div>`;
                showAssetListBtn.style.display = 'block';
                assetListContainer.style.display = 'none';
                manualSnSection.style.display = 'flex'; // Tampilkan manual input
                setTimeout(() => manualSnInput.focus(), 300); // Auto fokus
            });
            searchResultsContainer.appendChild(item);
        });
        searchResultsContainer.style.display = 'block';
    }

    // 3. RENDER TABEL GRID
    function renderTable() {
        assetTableBody.innerHTML = '';
        
        if (assetItems.length === 0) {
            assetItemTable.style.display = 'none';
            submitBatchBtn.style.display = 'none';
            return;
        }

        assetItemTable.style.display = 'table';
        submitBatchBtn.style.display = 'inline-block';

        assetItems.forEach((item, index) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-center text-muted align-middle">${index + 1}</td>
                <td class="align-middle">${item.product_name}</td>
                <td class="align-middle fw-bold text-success">${item.serial_number}</td>
                <td class="align-middle">
                    <select class="form-select form-select-sm condition-select" onchange="updateItem(${index}, 'condition', this.value)">
                        <option value="Baik" ${item.condition === 'Baik' ? 'selected' : ''}>Baik</option>
                        <option value="Rusak Ringan" ${item.condition === 'Rusak Ringan' ? 'selected' : ''}>Rusak Ringan</option>
                        <option value="Rusak Berat" ${item.condition === 'Rusak Berat' ? 'selected' : ''}>Rusak Berat</option>
                        <option value="Perlu Perbaikan" ${item.condition === 'Perlu Perbaikan' ? 'selected' : ''}>Perlu Perbaikan</option>
                    </select>
                </td>
                <td class="align-middle">
                    <input type="text" class="form-control form-control-sm location-input" value="${item.location}" onblur="updateItem(${index}, 'location', this.value)">
                </td>
                <td class="text-center align-middle">
                    <button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="removeItem(${index})"><i class="bi bi-trash"></i></button>
                </td>
            `;
            assetTableBody.appendChild(tr);
        });

        // Sync items_json
        itemsJsonInput.value = JSON.stringify(assetItems);
    }

    // Hubungkan Global Functions
    window.updateItem = function(index, key, value) {
        assetItems[index][key] = value;
        itemsJsonInput.value = JSON.stringify(assetItems);
    }

    window.removeItem = function(index) {
        assetItems.splice(index, 1);
        renderTable();
    }

    // 4. SIDEBAR VIEW SN LIST
    showAssetListBtn.addEventListener('click', function() {
        if (!selectedProduct) return;

        if (assetListContainer.style.display === 'none') {
            currentAssetSNList.innerHTML = '<li class="list-group-item text-center text-muted small"><div class="spinner-border spinner-border-sm me-2"></div>Memuat SN...</li>';
            assetListContainer.style.display = 'block';

            const whId = document.getElementById('warehouse_id').value;
            fetch(`get_asset_sn_list.php?product_id=${selectedProduct.id}&warehouse_id=${whId}`)
                .then(res => res.json())
                .then(data => {
                    currentAssetSNList.innerHTML = '';
                    if (data.serial_numbers && data.serial_numbers.length > 0) {
                        data.serial_numbers.forEach((sn, idx) => {
                            const li = document.createElement('li');
                            li.className = 'list-group-item py-1 border-0 small d-flex justify-content-between align-items-center';
                            li.innerHTML = `<span>${idx + 1}. <strong class="text-secondary">${sn.serial_number.replace('S/N: ', '')}</strong></span>`;
                            currentAssetSNList.appendChild(li);
                        });
                    } else {
                        currentAssetSNList.innerHTML = '<li class="list-group-item text-center text-muted small">Belum ada SN Aset.</li>';
                    }
                })
                .catch(err => {
                    currentAssetSNList.innerHTML = '<li class="list-group-item text-center text-danger small">Gagal memuat.</li>';
                });
        } else {
            assetListContainer.style.display = 'none';
        }
    });

    document.getElementById('assetBatchForm').addEventListener('submit', function() {
        submitBatchBtn.disabled = true;
        submitBatchBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...';
    });

    // 5. INPUT MANUAL SN HANDLER
    addManualSnBtn.addEventListener('click', function() {
        const val = manualSnInput.value.trim();
        if (val) {
            processScannedSN(val);
            manualSnInput.value = '';
            manualSnInput.focus();
        }
    });

    manualSnInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addManualSnBtn.click();
        }
    });
});
</script>