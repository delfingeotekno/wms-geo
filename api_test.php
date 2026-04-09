<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pencarian Produk via API</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f0f2f5; font-family: 'Inter', sans-serif; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .search-box { 
            background: white; 
            padding: 30px; 
            border-radius: 20px;
            margin-bottom: 30px;
        }
        #jsonOutput { 
            background-color: #1e1e1e; 
            color: #d4d4d4; 
            padding: 15px; 
            border-radius: 8px; 
            max-height: 400px; 
            overflow-y: auto; 
            font-family: 'Consolas', monospace;
            font-size: 12px;
        }
        .table-custom thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #edf2f7;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            color: #718096;
        }
        .warehouse-tag {
            font-size: 10px;
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 10px;
            margin-right: 5px;
            display: inline-block;
        }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="d-flex align-items-center mb-4">
                <a href="index.php" class="btn btn-light rounded-circle shadow-sm me-3">
                    <i class="bi bi-house-door-fill"></i>
                </a>
                <h2 class="mb-0 fw-bold">WMS Product API Explorer</h2>
            </div>

            <!-- Search Section -->
            <div class="search-box shadow-sm">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label small fw-bold text-muted">Cari Nama atau Kode Barang</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Contoh: Garmin, Fenix, atau Kode Produk...">
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-bold text-muted">X-API-KEY</label>
                        <input type="text" id="apiKey" class="form-control" value="WMS-GEO-SECRET-2026">
                    </div>
                </div>
                <div class="mt-4">
                    <button id="searchBtn" class="btn btn-primary px-5 py-2 fw-bold rounded-pill">
                        <i class="bi bi-lightning-charge-fill me-2"></i> Cari Sekarang
                    </button>
                    <button id="viewJsonBtn" class="btn btn-outline-secondary px-4 py-2 rounded-pill ms-2">
                        <i class="bi bi-code-slash me-2"></i> Lihat JSON
                    </button>
                </div>
            </div>

            <!-- Content Area -->
            <div id="resultsArea" style="display: none;">
                <div class="card mb-4">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold text-dark">Hasil Pencarian</h5>
                        <div id="statusIndicator"></div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 table-custom">
                                <thead>
                                    <tr>
                                        <th class="ps-4">Produk</th>
                                        <th>Brand</th>
                                        <th>Gudang & Stok</th>
                                        <th class="text-end pe-4">Total Stok</th>
                                    </tr>
                                </thead>
                                <tbody id="resultsBody">
                                    <!-- Results injected here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Raw JSON View (Hidden by Default) -->
                <div id="jsonView" style="display: none;">
                    <div class="card bg-dark">
                        <div class="card-header border-0 bg-transparent text-white pt-3 ps-4">
                            <h6 class="mb-0"><i class="bi bi-braces me-2"></i>Raw JSON Response</h6>
                        </div>
                        <div class="card-body">
                            <div id="jsonOutput"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div id="emptyState" class="text-center py-5">
                <i class="bi bi-search display-1 text-muted opacity-25"></i>
                <p class="mt-3 text-muted">Masukkan kata kunci untuk mencari persediaan produk melalui API.</p>
            </div>
            
            <!-- Loading State -->
            <div id="loadingState" class="text-center py-5" style="display: none;">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-3 text-muted">Mengambil data dari API...</p>
            </div>
        </div>
    </div>
</div>

<script>
    const searchBtn = document.getElementById('searchBtn');
    const viewJsonBtn = document.getElementById('viewJsonBtn');
    const searchInput = document.getElementById('searchInput');
    const apiKeyInput = document.getElementById('apiKey');
    
    const resultsArea = document.getElementById('resultsArea');
    const resultsBody = document.getElementById('resultsBody');
    const jsonOutput = document.getElementById('jsonOutput');
    const jsonView = document.getElementById('jsonView');
    const emptyState = document.getElementById('emptyState');
    const loadingState = document.getElementById('loadingState');
    const statusDiv = document.getElementById('statusIndicator');

    let currentData = null;

    searchBtn.addEventListener('click', performSearch);
    searchInput.addEventListener('keypress', (e) => { if(e.key === 'Enter') performSearch(); });

    viewJsonBtn.addEventListener('click', () => {
        jsonView.style.display = jsonView.style.display === 'none' ? 'block' : 'none';
        viewJsonBtn.innerHTML = jsonView.style.display === 'none' 
            ? '<i class="bi bi-code-slash me-2"></i> Lihat JSON' 
            : '<i class="bi bi-eye-slash me-2"></i> Sembunyikan JSON';
    });

    function performSearch() {
        const query = searchInput.value.trim();
        const apiKey = apiKeyInput.value.trim();
        const url = `api/get_products.php?search=${encodeURIComponent(query)}`;

        // UI Reset
        emptyState.style.display = 'none';
        resultsArea.style.display = 'none';
        loadingState.style.display = 'block';
        searchBtn.disabled = true;

        fetch(url, {
            method: 'GET',
            headers: {
                'X-API-KEY': apiKey,
                'Content-Type': 'application/json'
            }
        })
        .then(response => {
            statusDiv.innerHTML = `<span class="badge ${response.ok ? 'bg-success' : 'bg-danger'} rounded-pill">${response.status} ${response.statusText}</span>`;
            return response.json();
        })
        .then(data => {
            currentData = data;
            renderResults(data);
            jsonOutput.innerHTML = JSON.stringify(data, null, 4);
        })
        .catch(error => {
            console.error('Fetch error:', error);
            resultsBody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-danger">Gagal menghubungi API: ${error.message}</td></tr>`;
            resultsArea.style.display = 'block';
        })
        .finally(() => {
            loadingState.style.display = 'none';
            searchBtn.disabled = false;
        });
    }

    function renderResults(data) {
        resultsBody.innerHTML = '';
        
        if (!data || data.length === 0) {
            resultsBody.innerHTML = '<tr><td colspan="4" class="text-center py-5 text-muted">Produk tidak ditemukan.</td></tr>';
        } else {
            data.forEach(item => {
                let warehouseHtml = item.warehouse_breakdown.map(w => 
                    `<span class="warehouse-tag"><b>${w.warehouse_name}</b>: ${w.stock}</span>`
                ).join('') || '<i class="text-muted small">Tanpa stok di gudang manapun</i>';

                resultsBody.innerHTML += `
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-dark">${item.product_name}</div>
                            <div class="text-muted small">${item.product_code}</div>
                        </td>
                        <td><span class="badge bg-light text-dark border">${item.brand_name}</span></td>
                        <td>${warehouseHtml}</td>
                        <td class="text-end pe-4 fw-bold text-primary fs-5">${item.total_stock}</td>
                    </tr>
                `;
            });
        }
        
        resultsArea.style.display = 'block';
    }
</script>

</body>
</html>
