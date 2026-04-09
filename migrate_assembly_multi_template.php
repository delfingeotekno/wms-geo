<?php
require_once 'includes/db_connect.php';

echo "--- Migrasi Database Rakitan ---\n";

// 1. Buat tabel assembly_outbound_results
$sql_create = "CREATE TABLE IF NOT EXISTS assembly_outbound_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    outbound_id INT NOT NULL,
    assembly_id INT NOT NULL,
    qty INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (outbound_id) REFERENCES assembly_outbound(id) ON DELETE CASCADE,
    FOREIGN KEY (assembly_id) REFERENCES assemblies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if ($conn->query($sql_create)) {
    echo "[OK] Tabel assembly_outbound_results berhasil dibuat atau sudah ada.\n";
} else {
    die("[ERR] Gagal membuat tabel: " . $conn->error . "\n");
}

// 2. Backfill data dari assembly_outbound ke assembly_outbound_results
// Hanya pindahkan data yang belum ada di tabel hasil
$sql_backfill = "INSERT INTO assembly_outbound_results (outbound_id, assembly_id, qty)
                SELECT id, assembly_id, total_units 
                FROM assembly_outbound 
                WHERE assembly_id IS NOT NULL 
                AND id NOT IN (SELECT outbound_id FROM assembly_outbound_results)";

if ($conn->query($sql_backfill)) {
    echo "[OK] Backfill data selesai. " . $conn->affected_rows . " baris dipindahkan.\n";
} else {
    echo "[ERR] Gagal sinkronisasi data lama: " . $conn->error . "\n";
}

echo "--- Migrasi Selesai ---\n";
?>
