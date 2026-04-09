<?php
include '../../includes/db_connect.php';
include '../../includes/header.php';

if (!in_array($_SESSION['role'], ['admin', 'staff', 'procurement'])) {
    header("Location: ../../index.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: index.php");
    exit();
}

// Fetch Request Header
$sql_req = "SELECT * FROM stock_requests WHERE id = ?";
$stmt = $conn->prepare($sql_req);
$stmt->bind_param("i", $id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    echo "<div class='alert alert-danger'>Data tidak ditemukan.</div>";
    include '../../includes/footer.php';
    exit();
}

if ($request['status'] !== 'Pending') {
    echo "<div class='alert alert-warning'>Hanya request dengan status 'Pending' yang dapat diedit.</div>";
    include '../../includes/footer.php';
    exit();
}

$warehouse_id = $request['warehouse_id'];

// Fetch Details for JS
$sql_det = "SELECT 
                srd.*,
                p.product_name AS catalog_name,
                p.product_code
            FROM stock_request_details srd
            LEFT JOIN products p ON srd.product_id = p.id
            WHERE srd.stock_request_id = ?";
$stmt_det = $conn->prepare($sql_det);
$stmt_det->bind_param("i", $id);
$stmt_det->execute();
$details_query = $stmt_det->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_det->close();

// Map details into JS keys
$items_mapped = [];
foreach ($details_query as $row) {
    $items_mapped[] = [
        'product_id' => $row['product_id'],
        'product_name' => $row['product_id'] ? $row['catalog_name'] : $row['item_name_manual'],
        'current_stock' => (int)$row['current_stock'],
        'requested_qty' => (int)$row['requested_qty'],
        'specification' => $row['specification'],
        'unit' => $row['unit'],
        'item_notes' => $row['item_notes']
    ];
}
$items_json_init = json_encode($items_mapped);

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $eta_supply = $_POST['eta_supply'];
    $general_notes = trim($_POST['general_notes']);
    $subject = trim($_POST['subject'] ?? '');
    $items = json_decode($_POST['items_json'] ?? '[]', true);
    $action_status = 'Pending';

    if (empty($eta_supply) || empty($items) || empty($subject)) {
        $message = 'Mohon isi subjek, estimasi tanggal supply dan tambahkan item.';
        $message_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            $update_req = "UPDATE stock_requests SET subject = ?, eta_supply = ?, general_notes = ?, status = ? WHERE id = ?";
            $stmt_req = $conn->prepare($update_req);
            $stmt_req->bind_param("ssssi", $subject, $eta_supply, $general_notes, $action_status, $id);
            $stmt_req->execute();
            $stmt_req->close();

            // Clear existing details
            $conn->query("DELETE FROM stock_request_details WHERE stock_request_id = $id");

            // Re-insert Details
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

                $stmt_det->bind_param("iisssiis", $id, $p_id, $item_name_manual, $spec, $unit, $current_stock, $req_qty, $item_notes);
                $stmt_det->execute();
            }
            $stmt_det->close();

            $conn->commit();
            header("Location: index.php?status=success_update");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}
?>

<div class="content-header mb-4 d-flex justify-content-between align-items-center">
    <h1 class="fw-bold">Edit Stock Request</h1>
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

<form id="requestForm" method="POST">
    <div class="row g-3">
        <!-- Panel Kiri: Form Header -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text me-2"></i>Informasi Request</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Nomor Request</label>
                        <input type="text" class="form-control form-control-sm bg-light" value="<?= htmlspecialchars($request['request_number']) ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Subjek <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="subject" value="<?= htmlspecialchars($request['subject'] ?? '') ?>" placeholder="Contoh: Kebutuhan ATK Kantor" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">ETA Supply (Kapan Dibutuhkan) <span class="text-danger">*</span></label>
                        <input type="date" class="form-control form-control-sm" name="eta_supply" value="<?= $request['eta_supply'] ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Catatan Umum</label>
                        <textarea class="form-control form-control-sm" name="general_notes" rows="3" placeholder="Alasan request..."><?= htmlspecialchars($request['general_notes']) ?></textarea>
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
                            <tbody class="border-top-0"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden Input for JSON -->
    <input type="hidden" name="items_json" id="items_json" value="[]">
    <input type="hidden" name="form_action" id="form_action" value="save_draft">

    <!-- Submit Action -->
    <div class="d-flex justify-content-end gap-2 mt-4">
        <a href="index.php" class="btn btn-sm btn-secondary px-4 rounded-pill">Batal</a>
        <button type="submit" class="btn btn-sm btn-success px-4 rounded-pill fw-bold" onclick="document.getElementById('form_action').value='submit_request'">Simpan Perubahan</button>
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
    
    // LOAD PREVIOUS ITEMS FROM PHP
    let requestItems = <?= $items_json_init ?>;
    renderTable();

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
                fetch(`../borrow/search_products.php?q=${encodeURIComponent(term)}&warehouse_id=<?= $warehouse_id ?>`)
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
