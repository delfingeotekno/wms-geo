<?php
ob_start();
session_start();
include 'db_connect.php';

// Ambil role dari session untuk mempermudah pengecekan
$role = $_SESSION['role'] ?? '';

// Cek jika pengguna belum login, arahkan ke halaman login
if (!isset($_SESSION['user_id'])) {
    header("Location: /wms-geo/login.php"); 
    exit();
}

// Dapatkan path halaman saat ini untuk menentukan menu aktif
$request_uri = $_SERVER['REQUEST_URI'];
$request_path = strtok($request_uri, '?');

if (substr($request_path, 0, 1) !== '/') {
    $request_path = '/' . $request_path;
}

// Tentukan menu aktif berdasarkan path URL saat ini
$dashboard_active = '';
$products_active = '';
$warehouse_active = '';
$inbound_active = '';
$outbound_active = '';
$borrow_active = '';
$users_active = '';
$management_active = '';
$activities_active = '';
$asset_active = '';
$vendor_active = '';
$po_active = '';
$assembly_active = '';
$stock_request_active = '';
$transfer_active = '';

if ($request_path === '/' || $request_path === '/wms-geo/index.php') {
    $dashboard_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/products') === 0) {
    $products_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/warehouse') === 0) {
    $warehouse_active = 'active';    
} elseif (strpos($request_path, '/wms-geo/views/vendors') === 0) { 
    $vendor_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/po') === 0) { 
    $po_active = 'active';  
} elseif (strpos($request_path, '/wms-geo/views/inbound') === 0) {
    $inbound_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/outbound') === 0) {
    $outbound_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/borrow') === 0) {
    $borrow_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/stock_request') === 0) {
    $stock_request_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/asset') === 0) { 
    $asset_active = 'active';
} elseif (strpos($request_path, '/wms-geo/all_activities.php') === 0) {
    $activities_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/users') === 0) {
    $users_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/management') === 0) {
    $management_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/assembly') === 0) {
    $assembly_active = 'active';
} elseif (strpos($request_path, '/wms-geo/views/transfer') === 0) {
    $transfer_active = 'active';
}
// Ambil warehouse_id dari session
$warehouse_id = $_SESSION['warehouse_id'] ?? 0;

// --- LOGIKA NOTIFIKASI STOCK DANGER (Isolate by Warehouse) ---
$danger_products = [];
$danger_count = 0;
$sql_danger_stock = "SELECT p.id, p.product_code, p.product_name, ps.stock as current_stock, p.minimum_stock_level, p.location
                     FROM products p
                     JOIN warehouse_stocks ps ON p.id = ps.product_id
                     WHERE ps.stock <= p.minimum_stock_level 
                     AND p.is_deleted = 0 
                     AND ps.warehouse_id = ?";
$stmt = $conn->prepare($sql_danger_stock);
$stmt->bind_param("i", $warehouse_id);
$stmt->execute();
$result_danger = $stmt->get_result();
$danger_products = $result_danger->fetch_all(MYSQLI_ASSOC);
$danger_count = count($danger_products);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WMS Geotrack</title>
    
    <link rel="icon" href="/assets/img/logo.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/wms-geo/assets/css/style.css"> 
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quagga/dist/quagga.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .sidebar-heading .logo-container { display: flex; justify-content: center; align-items: center; }
        .sidebar-heading img { max-width: 100%; height: auto; width: 300px; }
        #sidebar-wrapper { height: 100vh; overflow-y: auto; overflow-x: hidden; }
        #sidebar-wrapper::-webkit-scrollbar { width: 6px; }
        #sidebar-wrapper::-webkit-scrollbar-thumb { background-color: rgba(0,0,0,0.2); border-radius: 4px; }
    </style>
</head>
<body>

<div class="d-flex" id="wrapper">
    <div class="sidebar-wrapper" id="sidebar-wrapper">
        <div class="sidebar-heading">
            <div class="logo-container">
                <img src="/wms-geo/assets/img/logogeo.png" alt="Logo WMS">
            </div>
        </div>
        <div class="list-group list-group-flush mt-3">
            
            <a href="/wms-geo/index.php" class="list-group-item list-group-item-action rounded-pill <?= $dashboard_active ?>">
                <i class="bi bi-speedometer2 me-2"></i> Dashboard
            </a>
            <a href="/wms-geo/views/products/index.php" class="list-group-item list-group-item-action rounded-pill <?= $products_active ?>">
                <i class="bi bi-box-seam me-2"></i> Produk
            </a>
            <?php if ($role == 'admin' || $role == 'staff'): ?>
                <a href="/wms-geo/views/warehouse/index.php" class="list-group-item list-group-item-action rounded-pill <?= $warehouse_active ?>">
                    <i class="bi bi-building me-2"></i> Warehouse
                </a>
            <?php endif; ?>

            <?php if ($role == 'admin' || $role == 'staff' || $role == 'procurement'): ?>
                <a href="/wms-geo/views/vendors/index.php" class="list-group-item list-group-item-action rounded-pill <?= $vendor_active ?>">
                    <i class="bi bi-truck me-2"></i> Vendor
                </a>
                <a href="/wms-geo/views/po/index.php" class="list-group-item list-group-item-action rounded-pill <?= $po_active ?>">
                    <i class="bi bi-file-earmark-text me-2"></i> Purchase Order
                </a>
                <a href="/wms-geo/views/inbound/index.php" class="list-group-item list-group-item-action rounded-pill <?= $inbound_active ?>">
                    <i class="bi bi-box-arrow-in-down me-2"></i> Barang Masuk
                </a>
            <?php endif; ?>

            <?php if ($role == 'admin' || $role == 'staff'): ?>
                <a href="/wms-geo/views/outbound/index.php" class="list-group-item list-group-item-action rounded-pill <?= $outbound_active ?>">
                    <i class="bi bi-box-arrow-up-right me-2"></i> Barang Keluar
                </a>
            <?php endif; ?>

            <?php if ($role == 'admin' || $role == 'staff' || $role == 'production'): ?>
                <a href="/wms-geo/views/assembly/index.php" class="list-group-item list-group-item-action rounded-pill <?= $assembly_active ?>">
                    <i class="bi bi-cpu me-2"></i> Perakitan
                </a>
            <?php endif; ?>

            <?php if ($role == 'admin' || $role == 'staff'): ?>
                <a href="/wms-geo/views/transfer/index.php" class="list-group-item list-group-item-action rounded-pill <?= $transfer_active ?>">
                    <i class="bi bi-arrow-left-right me-2"></i> Transfer Gudang
                </a>
                <a href="/wms-geo/views/asset/asset_in.php" class="list-group-item list-group-item-action rounded-pill <?= $asset_active ?>">
                    <i class="bi bi-tools me-2"></i> Aset / Barang Lama
                </a>
                <a href="/wms-geo/views/borrow/index.php" class="list-group-item list-group-item-action rounded-pill <?= $borrow_active ?>">
                    <i class="bi bi-arrow-down-up me-2"></i> Peminjaman
                </a>
            <?php endif; ?>

            <?php if ($role == 'admin' || $role == 'staff' || $role == 'procurement'): ?>
                <a href="/wms-geo/views/stock_request/index.php" class="list-group-item list-group-item-action rounded-pill <?= $stock_request_active ?>">
                    <i class="bi bi-cart-plus me-2"></i> Stock Request
                </a>
            <?php endif; ?>

            <?php if ($role == 'admin' || $role == 'staff'): ?>
                <a href="/wms-geo/all_activities.php" class="list-group-item list-group-item-action rounded-pill <?= $activities_active ?>">
                    <i class="bi bi-clock-history me-2"></i> Aktivitas Transaksi
                </a>
            <?php endif; ?>

            <?php if ($role == 'admin' || $role == 'staff' || $role == 'procurement'): ?>
                <div class="dropdown">
                    <a href="#" class="list-group-item list-group-item-action rounded-pill dropdown-toggle <?= $management_active ?>" id="managementDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear me-2"></i> Management Product
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="managementDropdown">
                        <li><a class="dropdown-item" href="/wms-geo/views/management/brands.php">Merek</a></li>
                        <li><a class="dropdown-item" href="/wms-geo/views/management/product_types.php">Tipe Produk</a></li>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($role == 'admin'): ?>
                <a href="/wms-geo/views/users/index.php" class="list-group-item list-group-item-action rounded-pill <?= $users_active ?>">
                    <i class="bi bi-person-gear me-2"></i> Manajemen User
                </a>
            <?php endif; ?>
            
        </div>
    </div>
    
    <div id="page-content-wrapper">
        <nav class="navbar navbar-expand-lg rounded-4 shadow-sm py-3">
            <div class="container-fluid">
                <button class="btn btn-outline-secondary d-md-none" id="sidebarToggle">
                    <i class="bi bi-list"></i>
                </button>

                <form id="searchForm" class="d-flex w-50 ms-md-3 me-auto order-md-1 order-2 mt-2 mt-md-0" role="search" action="/wms-geo/views/products/index.php" method="GET">
                    <div class="input-group">
                        <input id="searchInput" class="form-control rounded-pill pe-5" type="search" name="search" placeholder="Cari Kode atau Nama Produk..." aria-label="Search">
                        <span class="input-group-text bg-transparent border-0 position-absolute end-0 top-50 translate-middle-y" style="z-index: 1;">
                            <i class="bi bi-search"></i>
                        </span>
                    </div>
                </form>

                <ul class="navbar-nav ms-auto mb-2 mb-lg-0 d-flex flex-row align-items-center order-md-2 order-1">
                    <li class="nav-item me-3 position-relative">
                        <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#dangerStockModal">
                            <i class="bi bi-bell-fill fs-5 text-dark"></i>
                            <?php if ($danger_count > 0): ?>
                                <span id="danger-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6em;">
                                    <?= $danger_count ?> <span class="visually-hidden">unread messages</span>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle fs-4 text-dark"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><span class="dropdown-item-text fw-bold"><?= htmlspecialchars($_SESSION['name'] ?? 'User') ?></span></li>
                            <li><span class="dropdown-item-text text-muted text-capitalize"><?= htmlspecialchars($role) ?></span></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/wms-geo/profile.php"><i class="bi bi-person-fill me-2"></i>Profil</a></li>
                            <li><a class="dropdown-item text-danger" href="/wms-geo/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="container-fluid py-4 px-4">

        <div class="modal fade" id="dangerStockModal" tabindex="-1" aria-labelledby="dangerStockModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="dangerStockModalLabel">Produk Stok Menipis</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($danger_count > 0): ?>
                            <p>Produk berikut ini memiliki stok di bawah atau sama dengan level minimum.</p>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm">
                                    <thead>
                                        <tr class="table-danger">
                                            <th>Kode Produk</th>
                                            <th>Nama Produk</th>
                                            <th>Lokasi</th>
                                            <th>Stok</th>
                                            <th>Stok Min.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($danger_products as $product): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($product['product_code']) ?></td>
                                                <td><?= htmlspecialchars($product['product_name']) ?></td>
                                                <td><?= htmlspecialchars($product['location']) ?></td>
                                                <td><?= htmlspecialchars($product['current_stock']) ?></td>
                                                <td><?= htmlspecialchars($product['minimum_stock_level']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success text-center" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i>Semua stok produk aman!
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>