<?php
include 'includes/db_connect.php';
include 'includes/header.php';

// Pastikan pengguna sudah login dan memiliki peran yang diizinkan
if (!isset($_SESSION['user_id'])) {
  header("Location: /wms-geo/login.php");
  exit();
}
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
  header("Location: /wms-geo/index.php");
  exit();
}

// Tentukan tab aktif untuk filter gudang
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : (string)$_SESSION['warehouse_id'];

// Mengambil nilai filter dari URL lainnya
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

// Ambil daftar gudang
$warehouses_query = $conn->query("SELECT id, name FROM warehouses ORDER BY name ASC");
$warehouses_list = $warehouses_query->fetch_all(MYSQLI_ASSOC);

// Mengambil daftar produk untuk filter dropdown
$products_query = $conn->query("SELECT id, product_name, product_code FROM products WHERE is_deleted = 0 ORDER BY product_name");
$products_list = $products_query->fetch_all(MYSQLI_ASSOC);

// Membangun kueri SQL UNION dengan filter
$sql_parts = [];
$union_params = [];
$union_types = "";

// Fungsi untuk membangun klausa WHERE dan parameter
function buildFilterClause($tableAlias, $startDate, $endDate, $productId, $activeTab) {
  $where_clauses = ["$tableAlias.is_deleted = 0"];
  
  if ($activeTab !== 'all') {
      $where_clauses[] = "$tableAlias.warehouse_id = " . (int)$activeTab;
  }
  
  $params = [];
  $types = "";

  if (!empty($startDate)) {
    $where_clauses[] = "DATE($tableAlias.created_at) >= ?";
    $params[] = $startDate;
    $types .= "s";
  }
  if (!empty($endDate)) {
    $where_clauses[] = "DATE($tableAlias.created_at) <= ?";
    $params[] = $endDate;
    $types .= "s";
  }
  if ($productId > 0) {
    if ($tableAlias == 'bt') { 
        $where_clauses[] = "bi.product_id = ?";
    } else if ($tableAlias == 'it' || $tableAlias == 'ot') {
        $where_clauses[] = "p.id = ?";
    } else {
        $where_clauses[] = "p.id = ?";
    }
    $params[] = $productId;
    $types .= "i";
  }

  return [
    'where' => implode(' AND ', $where_clauses),
    'params' => $params,
    'types' => $types
  ];
}

// Kueri untuk Barang Masuk (inbound_transactions.supplier/sender)
if (empty($type_filter) || $type_filter == 'inbound') {
  $filters = buildFilterClause('it', $start_date, $end_date, $product_id, $active_tab);
  $sql_parts[] = "
    (SELECT 'inbound' COLLATE utf8mb4_unicode_ci AS type, it.id, it.created_at AS date, p.product_name COLLATE utf8mb4_unicode_ci AS product_name, SUM(itd.quantity) AS quantity, it.transaction_number COLLATE utf8mb4_unicode_ci AS transaction_number, it.notes COLLATE utf8mb4_unicode_ci AS notes, 
      it.sender COLLATE utf8mb4_unicode_ci AS party_name 
    FROM inbound_transaction_details itd
    JOIN inbound_transactions it ON it.id = itd.transaction_id
    JOIN products p ON p.id = itd.product_id
    WHERE " . $filters['where'] . "
    GROUP BY it.id, p.id)";
  
  $union_params = array_merge($union_params, $filters['params']);
  $union_types .= $filters['types'];
}

// Kueri untuk Barang Keluar (outbound_transactions.recipient)
if (empty($type_filter) || $type_filter == 'outbound') {
  $filters = buildFilterClause('ot', $start_date, $end_date, $product_id, $active_tab);
  $sql_parts[] = "
    (SELECT 'outbound' COLLATE utf8mb4_unicode_ci AS type, ot.id, ot.created_at AS date, p.product_name COLLATE utf8mb4_unicode_ci AS product_name, SUM(otd.quantity) AS quantity, ot.transaction_number COLLATE utf8mb4_unicode_ci AS transaction_number, ot.notes COLLATE utf8mb4_unicode_ci AS notes, 
      ot.recipient COLLATE utf8mb4_unicode_ci AS party_name 
    FROM outbound_transaction_details otd
    JOIN outbound_transactions ot ON ot.id = otd.transaction_id
    JOIN products p ON p.id = otd.product_id
    WHERE " . $filters['where'] . "
    GROUP BY ot.id, p.id)";
  
  $union_params = array_merge($union_params, $filters['params']);
  $union_types .= $filters['types'];
}

// Kueri untuk Peminjaman (borrowed_transactions.borrower_name)
if (empty($type_filter) || $type_filter == 'borrow') {
  $filters = buildFilterClause('bt', $start_date, $end_date, $product_id, $active_tab);
  $sql_parts[] = "
    (SELECT 'borrow' COLLATE utf8mb4_unicode_ci AS type, bt.id, bt.created_at AS date, p.product_name COLLATE utf8mb4_unicode_ci AS product_name, SUM(bi.quantity) AS quantity, bt.transaction_number COLLATE utf8mb4_unicode_ci AS transaction_number, bt.notes COLLATE utf8mb4_unicode_ci AS notes, 
      bt.borrower_name COLLATE utf8mb4_unicode_ci AS party_name 
    FROM borrowed_transactions_detail bi
    JOIN borrowed_transactions bt ON bt.id = bi.transaction_id
    JOIN products p ON p.id = bi.product_id
    WHERE " . $filters['where'] . "
    GROUP BY bt.id, p.id)";
  
  $union_params = array_merge($union_params, $filters['params']);
  $union_types .= $filters['types'];
}

// Kueri untuk Mutasi Keluar (transfers.source_warehouse_id)
if (empty($type_filter) || $type_filter == 'transfer') {
  $where_clauses = ["1=1"];
  $params = [];
  $types = "";
  if ($active_tab !== 'all') { $where_clauses[] = "tf.source_warehouse_id = " . (int)$active_tab; }
  if (!empty($start_date)) { $where_clauses[] = "DATE(tf.created_at) >= ?"; $params[] = $start_date; $types .= "s"; }
  if (!empty($end_date)) { $where_clauses[] = "DATE(tf.created_at) <= ?"; $params[] = $end_date; $types .= "s"; }
  if ($product_id > 0) { $where_clauses[] = "p.id = ?"; $params[] = $product_id; $types .= "i"; }

  $sql_parts[] = "
    (SELECT 'transfer_out' COLLATE utf8mb4_unicode_ci AS type, tf.id, tf.created_at AS date, p.product_name COLLATE utf8mb4_unicode_ci AS product_name, SUM(ti.quantity) AS quantity, tf.transfer_no COLLATE utf8mb4_unicode_ci AS transaction_number, tf.notes COLLATE utf8mb4_unicode_ci AS notes, 
      (SELECT name FROM warehouses WHERE id = tf.destination_warehouse_id) COLLATE utf8mb4_unicode_ci AS party_name 
    FROM transfer_items ti
    JOIN transfers tf ON tf.id = ti.transfer_id
    JOIN products p ON p.id = ti.product_id
    WHERE " . implode(' AND ', $where_clauses) . "
    GROUP BY tf.id, p.id)";
  $union_params = array_merge($union_params, $params);
  $union_types .= $types;
}

// Kueri untuk Mutasi Masuk (transfers.destination_warehouse_id)
if (empty($type_filter) || $type_filter == 'transfer') {
  $where_clauses = ["tf.status = 'Completed'"];
  $params = [];
  $types = "";
  if ($active_tab !== 'all') { $where_clauses[] = "tf.destination_warehouse_id = " . (int)$active_tab; }
  if (!empty($start_date)) { $where_clauses[] = "DATE(tf.received_at) >= ?"; $params[] = $start_date; $types .= "s"; }
  if (!empty($end_date)) { $where_clauses[] = "DATE(tf.received_at) <= ?"; $params[] = $end_date; $types .= "s"; }
  if ($product_id > 0) { $where_clauses[] = "p.id = ?"; $params[] = $product_id; $types .= "i"; }

  $sql_parts[] = "
    (SELECT 'transfer_in' COLLATE utf8mb4_unicode_ci AS type, tf.id, tf.received_at AS date, p.product_name COLLATE utf8mb4_unicode_ci AS product_name, SUM(ti.quantity) AS quantity, tf.transfer_no COLLATE utf8mb4_unicode_ci AS transaction_number, tf.notes COLLATE utf8mb4_unicode_ci AS notes, 
      (SELECT name FROM warehouses WHERE id = tf.source_warehouse_id) COLLATE utf8mb4_unicode_ci AS party_name 
    FROM transfer_items ti
    JOIN transfers tf ON tf.id = ti.transfer_id
    JOIN products p ON p.id = ti.product_id
    WHERE " . implode(' AND ', $where_clauses) . "
    GROUP BY tf.id, p.id)";
  $union_params = array_merge($union_params, $params);
  $union_types .= $types;
}

if (empty($sql_parts)) {
  $activities = [];
} else {
  $final_sql = implode(' UNION ALL ', $sql_parts) . " ORDER BY date DESC LIMIT 200";
  
  $stmt = $conn->prepare($final_sql);
  if ($stmt === false) {
    error_log("Failed to prepare statement: " . $conn->error);
    $activities = [];
  } else {
    if (!empty($union_params)) {
      // Menggunakan operator splatter (...) untuk bind_param
      $stmt->bind_param($union_types, ...$union_params);
    }
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  }
}
?>

<div class="content-header mb-4">
  <h1 class="fw-bold">Semua Aktivitas Transaksi</h1>
</div>

<ul class="nav nav-tabs border-0">
  <li class="nav-item">
    <a class="nav-link <?= ($active_tab === 'all') ? 'active bg-white text-dark fw-bold border-bottom-0' : 'text-muted' ?>" 
       href="?tab=all<?= $type_filter ? '&type='.urlencode($type_filter) : '' ?><?= $product_id ? '&product_id='.$product_id : '' ?><?= $start_date ? '&start_date='.urlencode($start_date) : '' ?><?= $end_date ? '&end_date='.urlencode($end_date) : '' ?>">
       Semua Lokasi Gudang
    </a>
  </li>
  <?php foreach ($warehouses_list as $wh): ?>
  <li class="nav-item">
    <a class="nav-link <?= ($active_tab == $wh['id']) ? 'active bg-white text-dark fw-bold border-bottom-0' : 'text-muted' ?>" 
       href="?tab=<?= $wh['id'] ?><?= $type_filter ? '&type='.urlencode($type_filter) : '' ?><?= $product_id ? '&product_id='.$product_id : '' ?><?= $start_date ? '&start_date='.urlencode($start_date) : '' ?><?= $end_date ? '&end_date='.urlencode($end_date) : '' ?>">
       <?= htmlspecialchars($wh['name']) ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="card shadow-sm mb-4" style="border-top-left-radius: 0;">
  <div class="card-header bg-white border-bottom py-3">
    <h5 class="mb-0 fw-bold text-dark">Filter Aktivitas</h5>
  </div>
  <div class="card-body">
    <form action="" method="GET" class="row g-3">
      <div class="col-md-4">
        <label for="type_filter" class="form-label">Jenis Transaksi</label>
        <select id="type_filter" name="type" class="form-select">
          <option value="">Semua</option>
          <option value="inbound" <?= $type_filter == 'inbound' ? 'selected' : '' ?>>Barang Masuk</option>
          <option value="outbound" <?= $type_filter == 'outbound' ? 'selected' : '' ?>>Barang Keluar</option>
          <option value="borrow" <?= $type_filter == 'borrow' ? 'selected' : '' ?>>Peminjaman</option>
          <option value="transfer" <?= $type_filter == 'transfer' ? 'selected' : '' ?>>Mutasi Antar Gudang</option>
        </select>
      </div>
      <div class="col-md-4">
        <label for="product_filter" class="form-label">Nama Produk</label>
        <select id="product_filter" name="product_id" class="form-select">
          <option value="0">Semua Produk</option>
          <?php foreach ($products_list as $product): ?>
            <option value="<?= $product['id'] ?>" <?= $product_id == $product['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($product['product_name']) ?> (<?= htmlspecialchars($product['product_code']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label for="date_range" class="form-label">Rentang Tanggal</label>
        <div class="input-group">
          <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
          <span class="input-group-text">sampai</span>
          <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
        </div>
      </div>
      <div class="col-12 d-flex justify-content-end">
        <a href="generate_activities_pdf.php?tab=<?= htmlspecialchars($active_tab) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&product_id=<?= $product_id ?>&type=<?= urlencode($type_filter) ?>" target="_blank" class="btn btn-danger rounded-pill me-3"><i class="bi bi-file-earmark-pdf me-2"></i>Download PDF</a>
        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
        <button type="submit" class="btn btn-primary rounded-pill me-2"><i class="bi bi-funnel me-2"></i>Terapkan Filter</button>
        <a href="all_activities.php?tab=<?= htmlspecialchars($active_tab) ?>" class="btn btn-secondary rounded-pill"><i class="bi bi-x-circle me-2"></i>Reset Filter</a>
      </div>
    </form>
  </div>
</div>


<div class="card shadow-sm">
  <div class="card-header bg-white border-bottom py-3">
    <h5 class="mb-0 fw-bold text-dark">Hasil Pencarian</h5>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-hover" id="activitiesTable">
        <thead>
          <tr class="table-secondary">
            <th>No</th>
            <th>Tanggal</th>
            <th>Jenis Transaksi</th>
            <th>Nomor Transaksi</th>
            <th>PIHAK TERKAIT</th>
            <th>Produk</th>
            <th>Kuantitas</th>
            <th>Catatan</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($activities)): ?>
            <?php foreach ($activities as $i => $activity): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><?= date('d-m-Y H:i', strtotime($activity['date'])) ?></td>
                <td>
                  <?php
                    $badge_class = '';
                    $type_text = '';
                    $detail_page = '';
                    switch ($activity['type']) {
                      case 'inbound':
                        $badge_class = 'bg-success';
                        $type_text = 'Masuk';
                        $detail_page = ' /wms-geo/views/inbound/detail.php';
                        break;
                      case 'outbound':
                        $badge_class = 'bg-danger';
                        $type_text = 'Keluar';
                        $detail_page = ' /wms-geo/views/outbound/detail.php';
                        break;
                      case 'borrow':
                        $badge_class = 'bg-info text-dark';
                        $type_text = 'Peminjaman';
                        $detail_page = ' /wms-geo/views/borrow/detail.php';
                        break;
                      case 'transfer_out':
                        $badge_class = 'bg-warning text-dark';
                        $type_text = 'Mutasi Keluar';
                        $detail_page = ' /wms-geo/views/transfer/detail.php';
                        break;
                      case 'transfer_in':
                        $badge_class = 'bg-success text-white';
                        $type_text = 'Mutasi Masuk';
                        $detail_page = ' /wms-geo/views/transfer/detail.php';
                        break;
                    }
                  ?>
                  <span class="badge <?= $badge_class ?>"><?= $type_text ?></span>
                </td>
                <td>
                  <?php if ($detail_page): ?>
                    <a href="<?= $detail_page ?>?id=<?= $activity['id'] ?>" class="link-primary fw-bold text-decoration-none">
                      <?= htmlspecialchars($activity['transaction_number']) ?>
                    </a>
                  <?php else: ?>
                    <?= htmlspecialchars($activity['transaction_number']) ?>
                  <?php endif; ?>
                </td>
                <td>
                  <span>
                    <?= htmlspecialchars($activity['party_name']) ?: '-' ?>
                  </span>
                </td>
                <td><?= htmlspecialchars($activity['product_name']) ?></td>
                <td><?= htmlspecialchars($activity['quantity']) ?></td>
                <td><?= htmlspecialchars($activity['notes']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="8" class="text-center">Tidak ada aktivitas yang ditemukan.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
<script>
$(document).ready(function() {
  $('#activitiesTable').DataTable({
    // Konfigurasi untuk penyesuaian DataTables
    "pageLength": 10, 
    "lengthMenu": [
      [10, 25, 50, 75, 100, -1],
      [10, 25, 50, 75, 100, "Semua"]
    ], 
    "language": {
      "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json"
    },
    "columnDefs": [{
      "targets": 0, 
      "orderable": false 
    }],
    "initComplete": function(settings, json) {
      // Hapus pesan "Showing 0 to 0 of 0 entries" ketika tidak ada data
      if (json.recordsTotal === 0) {
        $('#activitiesTable_info').hide();
      }
    }
  });
});
</script>