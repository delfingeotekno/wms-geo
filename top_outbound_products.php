<?php
include 'includes/db_connect.php';
include 'includes/header.php';

// Pastikan pengguna sudah login dan memiliki peran yang diizinkan
if (!isset($_SESSION['user_id'])) {
    header("Location:  /wms-geo/login.php");
    exit();
}
if ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff') {
    header("Location:  /wms-geo/index.php");
    exit();
}

// Initialize filter variables
$filter_product_type_name = $_GET['product_type'] ?? '';
$filter_brand_name = $_GET['brand'] ?? '';
$filter_start_date = $_GET['start_date'] ?? '';
$filter_end_date = $_GET['end_date'] ?? '';

// Get distinct brands and product types for filter dropdowns
$brands_query = $conn->query("SELECT brand_name FROM brands ORDER BY brand_name");
$brands = $brands_query->fetch_all(MYSQLI_ASSOC);

$product_types_query = $conn->query("SELECT type_name FROM product_types ORDER BY id");
$product_types = $product_types_query->fetch_all(MYSQLI_ASSOC);

// Build SQL query with filters, using JOIN to get brand and product type names
$top_outbound_products_query = "
    SELECT p.product_name, SUM(otd.quantity) AS total_quantity_out
    FROM outbound_transaction_details otd
    JOIN outbound_transactions ot ON ot.id = otd.transaction_id
    JOIN products p ON p.id = otd.product_id
    JOIN brands b ON b.id = p.brand_id
    JOIN product_types pt ON pt.id = p.product_type_id
    WHERE ot.is_deleted = 0";

// Add WHERE conditions based on filters
$params = [];
$types = '';
if (!empty($filter_product_type_name)) {
    $top_outbound_products_query .= " AND pt.type_name = ?";
    $params[] = $filter_product_type_name;
    $types .= 's';
}
if (!empty($filter_brand_name)) {
    $top_outbound_products_query .= " AND b.brand_name = ?";
    $params[] = $filter_brand_name;
    $types .= 's';
}
if (!empty($filter_start_date)) {
    $top_outbound_products_query .= " AND DATE(ot.created_at) >= ?";
    $params[] = $filter_start_date;
    $types .= 's';
}
if (!empty($filter_end_date)) {
    $top_outbound_products_query .= " AND DATE(ot.created_at) <= ?";
    $params[] = $filter_end_date;
    $types .= 's';
}

$top_outbound_products_query .= "
    GROUP BY p.product_name
    ORDER BY total_quantity_out DESC
    LIMIT 10";

// Use prepared statement for security
$stmt = $conn->prepare($top_outbound_products_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$top_outbound_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Produk Keluar</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Chart.js and jQuery from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        /* Custom styles for Select2 to match Tailwind */
        .select2-container .select2-selection--single {
            height: 42px; /* Adjusted height to match form inputs */
            border-radius: 0.375rem;
            border-color: #d1d5db; /* gray-300 */
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 42px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        .select2-container--default .select2-search--dropdown .select2-search__field {
            border-radius: 0.375rem;
            border: 1px solid #d1d5db;
        }
        .select2-container--open .select2-dropdown--below {
            border-color: #d1d5db;
            border-top: none;
        }
        .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
            background-color: #2563eb; /* blue-600 */
            color: white;
        }
    </style>
</head>
<body class="p-8">
    <div class="container mx-auto">
        <h1 class="text-3xl font-bold mb-6 text-gray-800">Laporan Produk Paling Sering Keluar</h1>
        <div class="bg-white shadow-lg rounded-xl overflow-hidden mb-8 p-6">
            <div class="flex items-center mb-4">
                <i class="fa-solid fa-filter text-blue-500 mr-2"></i>
                <h2 class="text-lg font-bold text-gray-700">Filter Laporan</h2>
            </div>
            <form action="" method="GET">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                    <div>
                        <label for="product_type" class="block text-sm font-medium text-gray-700 mb-1">Tipe Produk</label>
                        <select class="form-select w-full border border-gray-300 rounded-md p-2 focus:ring focus:ring-blue-200 focus:border-blue-500 searchable-dropdown" id="product_type" name="product_type">
                            <option value="">Semua Tipe</option>
                            <?php foreach ($product_types as $type): ?>
                                <option value="<?= htmlspecialchars($type['type_name']) ?>" <?= ($filter_product_type_name == $type['type_name']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($type['type_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="brand" class="block text-sm font-medium text-gray-700 mb-1">Merek</label>
                        <select class="form-select w-full border border-gray-300 rounded-md p-2 focus:ring focus:ring-blue-200 focus:border-blue-500 searchable-dropdown" id="brand" name="brand">
                            <option value="">Semua Merek</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= htmlspecialchars($brand['brand_name']) ?>" <?= ($filter_brand_name == $brand['brand_name']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($brand['brand_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Dari Tanggal</label>
                        <input type="date" class="form-control w-full border border-gray-300 rounded-md p-2 focus:ring focus:ring-blue-200 focus:border-blue-500" id="start_date" name="start_date" value="<?= htmlspecialchars($filter_start_date) ?>">
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">Sampai Tanggal</label>
                        <input type="date" class="form-control w-full border border-gray-300 rounded-md p-2 focus:ring focus:ring-blue-200 focus:border-blue-500" id="end_date" name="end_date" value="<?= htmlspecialchars($filter_end_date) ?>">
                    </div>
                    <div class="col-span-full text-right">
                        <button type="submit" class="bg-blue-600 text-white font-semibold py-2 px-6 rounded-lg hover:bg-blue-700 transition duration-300 shadow-md">
                            <i class="fa-solid fa-magnifying-glass mr-2"></i>Terapkan Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="bg-white shadow-lg rounded-xl overflow-hidden p-6 h-[400px] flex flex-col justify-center items-center">
            <div class="flex items-center mb-4">
                <i class="fa-solid fa-chart-bar text-green-500 mr-2"></i>
                <h2 class="text-lg font-bold text-gray-700 mt-2">Grafik Produk Paling Sering Keluar</h2>
            </div>
            <?php if (empty($top_outbound_products)): ?>
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md text-center" role="alert">
                    <p class="font-bold">Belum ada data barang keluar</p>
                    <p class="text-sm">Data yang sesuai dengan filter yang dipilih tidak ditemukan.</p>
                </div>
            <?php else: ?>
                <canvas id="outboundBarChart" class="w-full"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Initialize Select2 on the dropdowns
        $(document).ready(function() {
            $('.searchable-dropdown').select2({
                placeholder: 'Cari dan pilih...',
                allowClear: true
            });
        });
    
        const topOutboundProducts = <?= json_encode($top_outbound_products) ?>;
        
        if (topOutboundProducts.length > 0) {
            const productLabels = topOutboundProducts.map(product => product.product_name);
            const productQuantities = topOutboundProducts.map(product => product.total_quantity_out);
            
            const ctx = document.getElementById('outboundBarChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: productLabels,
                    datasets: [{
                        label: 'Total Barang Keluar',
                        data: productQuantities,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Jumlah Unit Keluar',
                                color: '#4b5563',
                                font: {
                                    weight: 'bold'
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Nama Produk',
                                color: '#4b5563',
                                font: {
                                    weight: 'bold'
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
