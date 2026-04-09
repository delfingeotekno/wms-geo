<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$warehouse_id = $_SESSION['warehouse_id'] ?? 0;
$warehouses_query = $conn->query("SELECT id, name FROM warehouses ORDER BY name ASC");
$warehouses = $warehouses_query->fetch_all(MYSQLI_ASSOC);

function generateRequestNumber($conn, $wh_id) {
    $code = 'GUD';
    $wh_stmt = $conn->prepare("SELECT code FROM warehouses WHERE id = ?");
    $wh_stmt->bind_param("i", $wh_id);
    $wh_stmt->execute();
    $res = $wh_stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $code = $row['code'];
    }
    $wh_stmt->close();

    $roman_months = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    $m_roman = $roman_months[date('n')];
    $year = date('Y');

    $count_res = $conn->query("SELECT COUNT(*) FROM stock_requests WHERE YEAR(created_at) = $year");
    $count = $count_res->fetch_row()[0];
    
    $next_num = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    return "FRS/$next_num/$code/$m_roman/$year";
}

$request_number = generateRequestNumber($conn, $warehouse_id);
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $eta_supply = $_POST['eta_supply'];
    $general_notes = trim($_POST['general_notes']);
    $subject = trim($_POST['subject'] ?? '');
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $items = json_decode($_POST['items_json'] ?? '[]', true);

    if (empty($eta_supply) || empty($items) || empty($subject) || $warehouse_id <= 0) {
        $message = 'Mohon isi subjek, gudang, dan estimasi tanggal supply.';
        $message_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            $final_req_number = generateRequestNumber($conn, $warehouse_id);
            $insert_req = "INSERT INTO stock_requests (request_number, subject, warehouse_id, user_id, eta_supply, status, general_notes) VALUES (?, ?, ?, ?, ?, 'Pending', ?)";
            $stmt_req = $conn->prepare($insert_req);
            $user_id = $_SESSION['user_id'];
            $stmt_req->bind_param("sssiss", $final_req_number, $subject, $warehouse_id, $user_id, $eta_supply, $general_notes);
            $stmt_req->execute();
            $request_id = $conn->insert_id;
            $stmt_req->close();

            $insert_detail = "INSERT INTO stock_request_details (stock_request_id, product_id, item_name_manual, specification, unit, current_stock, requested_qty, item_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_det = $conn->prepare($insert_detail);

            foreach ($items as $item) {
                $p_id = !empty($item['product_id']) ? (int)$item['product_id'] : NULL;
                $item_name_manual = $p_id ? NULL : trim($item['product_name']);
                $spec = trim($item['specification'] ?? '');
                $unit = trim($item['unit'] ?? '');
                $current_stock = (int)($item['current_stock'] ?? 0);
                $req_qty = (int)$item['requested_qty'];
                $item_notes = trim($item['item_notes'] ?? '');

                $stmt_det->bind_param("iisssiis", $request_id, $p_id, $item_name_manual, $spec, $unit, $current_stock, $req_qty, $item_notes);
                $stmt_det->execute();
            }
            $stmt_det->close();

            $conn->commit();
            header("Location: index.php?status=success_request");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}
?>

<div class="content-header mb-4">
    <h1 class="fw-bold">Buat Stock Request</h1>
    <a href="index.php" class="btn btn-secondary btn-sm rounded-pill px-3">
        <i class="bi bi-arrow-left me-1"></i> Kembali
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<form id="requestForm" method="POST" action="create.php">
    <div class="row g-3">
        <!-- Panel Kiri: Form Header -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text me-2"></i>Informasi Request</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Gudang <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="warehouse_id" id="warehouse_select" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>" <?= $wh['id'] == $warehouse_id ? 'selected' : '' ?>><?= htmlspecialchars($wh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Nomor Request</label>
                        <input type="text" id="request_number_input" class="form-control form-control-sm bg-light" value="<?= htmlspecialchars($request_number) ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Subjek <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="subject" placeholder="Contoh: Kebutuhan ATK Kantor" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">ETA Supply (Kapan Dibutuhkan) <span class="text-danger">*</span></label>
                        <input type="date" class="form-control form-control-sm" name="eta_supply" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Catatan Umum</label>
                        <textarea class="form-control form-control-sm" name="general_notes" rows="3" placeholder="Alasan request..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel Kanan: Tabel Item -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-header bg-light py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-cart-plus me-2"></i>Daftar Item Permintaan</h6>
                </div>
                <div class="card-body">
                    
                    <!-- Search / Scanner -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <div class="position-relative">
                                <input type="text" id="product_input" class="form-control form-control-sm" placeholder="Cari Produk atau Ketik Barang Baru...">
                                <div id="product_search_results" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index: 1000; max-height: 200px; overflow-y: auto;"></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-sm btn-outline-primary w-100 fw-bold" id="manual_add_btn">
                                <i class="bi bi-plus-circle me-1"></i> Tambah Manual
                            </button>
                        </div>
                    </div>

                    <!-- Table Grid -->
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle" id="items-table">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th>Item Name</th>
                                    <th>Spec</th>
                                    <th width="80" class="text-center">Stock</th>
                                    <th width="90" class="text-center">Req Qty</th>
                                    <th width="70">Unit</th>
                                    <th>Notes</th>
                                    <th width="50" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="border-top-0">
                                <tr><td colspan="7" class="text-center text-muted py-3">Belum ada item ditambahkan.</td></tr>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Hidden Input for JSON -->
    <input type="hidden" name="items_json" id="items_json" value="[]">

    <!-- Submit Action -->
    <div class="d-flex justify-content-end gap-2 mt-4">
        <a href="index.php" class="btn btn-sm btn-secondary px-4 rounded-pill">Batal</a>
        <button type="submit" class="btn btn-sm btn-success px-4 rounded-pill fw-bold" id="submitBtn">Simpan Request</button>
    </div>
</form>

<!-- Modal Input Manual Item (Untuk item baru / free text) -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h6 class="modal-title fw-bold" id="itemModalTitle">Tambah Item</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="modalItemForm">
                    <input type="hidden" id="modal_product_id" value="">
                    <input type="hidden" id="modal_current_stock" value="0">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Nama Item <span class="text-danger">*</span></label>
                        <input type="text" id="modal_product_name" class="form-control form-control-sm" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-bold">Spec</label>
                            <input type="text" id="modal_spec" class="form-control form-control-sm" placeholder="Part No / Brand">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label small fw-bold">Unit</label>
                            <input type="text" id="modal_unit" class="form-control form-control-sm" value="Pcs" placeholder="Pcs/Mtr">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label small fw-bold">Req Qty <span class="text-danger">*</span></label>
                            <input type="number" id="modal_requested_qty" class="form-control form-control-sm text-center" min="1" value="1" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Notes</label>
                        <textarea id="modal_notes" class="form-control form-control-sm" rows="2" placeholder="Catatan detail..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-sm btn-primary fw-bold" id="confirmItemBtn">Tambahkan</button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const productInput = document.getElementById('product_input');
    const searchResults = document.getElementById('product_search_results');
    const itemsTableBody = document.querySelector('#items-table tbody');
    const itemsJsonInput = document.getElementById('items_json');

    const itemModalEl = document.getElementById('itemModal');
    const itemModal = new bootstrap.Modal(itemModalEl);
    const warehouseSelect = document.getElementById('warehouse_select');
    const requestNumberInput = document.getElementById('request_number_input');

    if (warehouseSelect) {
        warehouseSelect.addEventListener('change', function() {
            let wh_id = this.value;
            if (!wh_id) {
                requestNumberInput.value = '-';
                return;
            }
            fetch(`get_request_number.php?warehouse_id=${wh_id}`)
                .then(res => res.text())
                .then(data => {
                    requestNumberInput.value = data;
                });
        });
    }

    let requestItems = [];
    let debounceTimer;

    if (productInput) {
        productInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const term = this.value.trim();
            if (term.length < 2) {
                searchResults.classList.add('d-none');
                return;
            }

            debounceTimer = setTimeout(() => {
                let wh_id = warehouseSelect ? warehouseSelect.value : <?= $warehouse_id ?>;
                if (!wh_id) {
                    alert("Silakan pilih Gudang terlebih dahulu.");
                    searchResults.classList.add('d-none');
                    return;
                }
                fetch(`../borrow/search_products.php?q=${encodeURIComponent(term)}&warehouse_id=${wh_id}`)
                    .then(res => res.json())
                    .then(data => {
                        searchResults.innerHTML = '';
                        if (data.results && data.results.length > 0) {
                            data.results.forEach(p => {
                                const a = document.createElement('a');
                                a.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center small';
                                a.innerHTML = `<div><strong>${p.product_code}</strong> - ${p.product_name}</div><span class="badge bg-secondary">Stok: ${p.stock}</span>`;
                                a.addEventListener('click', () => {
                                    openModalForProduct(p);
                                    searchResults.classList.add('d-none');
                                    productInput.value = '';
                                });
                                searchResults.appendChild(a);
                            });
                        } else {
                            searchResults.innerHTML = `<div class="list-group-item text-muted small">Barang tidak ditemukan. <a href="#" id="add_manual_sub" class="fw-bold">Tambah manual</a></div>`;
                            document.getElementById('add_manual_sub').addEventListener('click', (e) => {
                                e.preventDefault();
                                openModalForManual(term);
                                searchResults.classList.add('d-none');
                                productInput.value = '';
                            });
                        }
                        searchResults.classList.remove('d-none');
                    });
            }, 300);
        });

        document.addEventListener('click', function(e) {
            if (e.target !== productInput && !searchResults.contains(e.target)) {
                searchResults.classList.add('d-none');
            }
        });
    }

    // 2. OPEN MODAL TRIGGERS
    document.getElementById('manual_add_btn').addEventListener('click', () => openModalForManual(''));

    function openModalForProduct(product) {
        document.getElementById('itemModalTitle').textContent = "Tambah Item (Katalog)";
        document.getElementById('modal_product_id').value = product.id;
        document.getElementById('modal_current_stock').value = product.stock;
        document.getElementById('modal_product_name').value = product.product_name;
        document.getElementById('modal_product_name').readOnly = true;
        document.getElementById('modal_requested_qty').value = 1;
        document.getElementById('modal_spec').value = product.product_code; 
        document.getElementById('modal_notes').value = '';
        itemModal.show();
    }

    function openModalForManual(name) {
        document.getElementById('itemModalTitle').textContent = "Tambah Item Manual";
        document.getElementById('modal_product_id').value = '';
        document.getElementById('modal_current_stock').value = 0;
        document.getElementById('modal_product_name').value = name;
        document.getElementById('modal_product_name').readOnly = false;
        document.getElementById('modal_requested_qty').value = 1;
        document.getElementById('modal_spec').value = '';
        document.getElementById('modal_notes').value = '';
        itemModal.show();
    }

    // 3. CONFIRM ITEM BTN
    document.getElementById('confirmItemBtn').addEventListener('click', function() {
        const name = document.getElementById('modal_product_name').value.trim();
        const qty = parseInt(document.getElementById('modal_requested_qty').value);
        if (!name || isNaN(qty) || qty <= 0) {
            alert("Nama item dan Qty wajib diisi dengan benar.");
            return;
        }

        const item = {
            product_id: document.getElementById('modal_product_id').value,
            product_name: name,
            current_stock: parseInt(document.getElementById('modal_current_stock').value),
            requested_qty: qty,
            specification: document.getElementById('modal_spec').value.trim(),
            unit: document.getElementById('modal_unit').value.trim(),
            item_notes: document.getElementById('modal_notes').value.trim()
        };

        requestItems.push(item);
        renderTable();
        itemModal.hide();
    });

    // 4. RENDER TABLE
    function renderTable() {
        if (requestItems.length === 0) {
            itemsTableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Belum ada item ditambahkan.</td></tr>';
            itemsJsonInput.value = '[]';
            return;
        }

        itemsTableBody.innerHTML = '';
        requestItems.forEach((item, i) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><div class="fw-bold">${item.product_name}</div><small class="text-muted">${item.product_id ? 'Katalog' : 'Manual'}</small></td>
                <td>${item.specification || '-'}</td>
                <td class="text-center">${item.current_stock}</td>
                <td class="text-center">
                    <input type="number" class="form-control form-control-sm text-center mx-auto" style="width: 75px;" value="${item.requested_qty}" min="1" oninput="updateItemQtyInline(${i}, this.value)">
                </td>
                <td>${item.unit || '-'}</td>
                <td><small>${item.item_notes || '-'}</small></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-sm rounded-circle" onclick="removeItem(${i})"><i class="bi bi-trash"></i></button>
                </td>
            `;
            itemsTableBody.appendChild(row);
        });

        itemsJsonInput.value = JSON.stringify(requestItems);
    }

    window.updateItemQtyInline = function(index, value) {
        let qty = parseInt(value);
        if (isNaN(qty) || qty <= 0) qty = 1;
        requestItems[index].requested_qty = qty;
        itemsJsonInput.value = JSON.stringify(requestItems);
    };

    window.removeItem = function(index) {
        requestItems.splice(index, 1);
        renderTable();
    };
});
</script>
