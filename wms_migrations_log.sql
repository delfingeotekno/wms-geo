-- ====================================================================
-- WMS GEOTRACK - DATABASE MIGRATION LOG
-- File ini bertugas untuk mencatat setiap pembuatan atau perubahan 
-- kolom tabel (ALTER/CREATE) ke depannya.
-- 
-- CARA PENGGUNAAN DI SERVER PRODUKSI:
-- Setiap kali Anda mau update sistem di server, lihat apakah ada
-- catatan SQL baru dengan tanggal terbaru di file ini. Jika ada, 
-- salin dan jalankan (Go) ke dalam PhpMyAdmin Server Anda.
-- ====================================================================

-- --------------------------------------------------------------------
-- [TANGGAL: 09 April 2026]
-- FITUR: Multi-Template Assembly (Perakitan Ganda)
-- TUJUAN: Menghubungkan ID assembly_outbound dengan rakitan yang banyak
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assembly_outbound_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `outbound_id` int(11) NOT NULL,
  `assembly_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `received_qty` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `outbound_id` (`outbound_id`),
  KEY `assembly_id` (`assembly_id`),
  CONSTRAINT `assembly_outbound_results_ibfk_1` FOREIGN KEY (`outbound_id`) REFERENCES `assembly_outbound` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assembly_outbound_results_ibfk_2` FOREIGN KEY (`assembly_id`) REFERENCES `assemblies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- MIGRATION TERBARU BERIKUTNYA DITULIS DI BAWAH INI:

-- --------------------------------------------------------------------
-- [TANGGAL: 10 April 2026]
-- FITUR: Analisis ROP & Inventory (Reorder Point & Safety Stock)
-- TUJUAN: Menambahkan rentang lead time harian ke master data produk
-- --------------------------------------------------------------------
ALTER TABLE `products` 
ADD COLUMN `lead_time_avg` INT NOT NULL DEFAULT 7 AFTER `location`,
ADD COLUMN `lead_time_max` INT NOT NULL DEFAULT 14 AFTER `lead_time_avg`;

-- [TANDA BATAS]
