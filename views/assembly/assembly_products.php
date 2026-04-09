<?php include '../../includes/header.php'; ?>

<div class="container-fluid py-4">
    <div class="d-md-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800 fw-bold">Katalog Produk Rakitan</h1>
            <p class="text-muted small mb-0">Daftar produk jadi hasil produksi internal (Finish Goods).</p>
        </div>
        <a href="inbound_create.php" class="btn btn-success shadow-sm btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Tambah Hasil Produksi
        </a>
    </div>

    <div class="row">
        <?php
        // Query untuk mengambil produk yang ditandai sebagai produk rakitan
        $sql = "SELECT p.id, p.product_name, p.stock, p.unit,
                (SELECT SUM(ao.total_units) FROM assembly_outbound ao WHERE ao.status = 'Pending' AND ao.assembly_id IN (SELECT id FROM assemblies WHERE assembly_name = p.product_name)) as wip_stock
                FROM products p 
                WHERE p.is_assembly_product = 1";
        $res = $conn->query($sql);

        if ($res->num_rows > 0):
            while($row = $res->fetch_assoc()): 
                $ready = $row['stock'] ?? 0;
                $wip = $row['wip_stock'] ?? 0;
                $total = $ready + $wip;
        ?>
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="card-body p-0">
                    <div class="p-3 border-bottom bg-light">
                        <h5 class="fw-bold mb-0 text-dark"><?= $row['product_name'] ?></h5>
                        <span class="badge bg-primary-subtle text-primary mt-2 small">ID: PROD-<?= $row['id'] ?></span>
                    </div>
                    <div class="p-3">
                        <div class="row text-center">
                            <div class="col-4 border-end">
                                <div class="text-muted small mb-1">Ready</div>
                                <div class="h5 mb-0 fw-bold text-info"><?= $ready ?></div>
                            </div>
                            <div class="col-4 border-end">
                                <div class="text-muted small mb-1">WIP</div>
                                <div class="h5 mb-0 fw-bold text-warning"><?= $wip ?></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small mb-1">Potensi</div>
                                <div class="h5 mb-0 fw-bold text-success"><?= $total ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-white border-0 p-3">
                        <div class="progress mb-2" style="height: 6px;">
                            <?php 
                                $percent = ($total > 0) ? ($ready / $total) * 100 : 0;
                            ?>
                            <div class="progress-bar bg-info" role="progressbar" style="width: <?= $percent ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Rasio Ketersediaan</span>
                            <span class="fw-bold small"><?= round($percent) ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; 
        else: ?>
            <div class="col-12 text-center py-5">
                <p class="text-muted small">Belum ada produk yang ditandai sebagai Produk Rakitan.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .bg-primary-subtle { background-color: #e7f5ff !important; }
    .card { transition: transform 0.2s; }
    .card:hover { transform: translateY(-5px); }
</style>

<?php include '../../includes/footer.php'; ?>