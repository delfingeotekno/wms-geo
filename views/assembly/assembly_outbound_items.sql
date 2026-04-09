-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 06, 2026 at 03:44 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wms_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `assembly_outbound_items`
--

CREATE TABLE `assembly_outbound_items` (
  `id` int(11) NOT NULL,
  `outbound_id` int(11) DEFAULT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `qty_out` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assembly_outbound_items`
--

INSERT INTO `assembly_outbound_items` (`id`, `outbound_id`, `product_id`, `qty_out`) VALUES
(1, 1, 150, 2),
(2, 1, 148, 2),
(3, 1, 223, 2),
(13, 2, 150, 1),
(14, 2, 148, 1),
(15, 2, 223, 1),
(16, 2, 146, 1),
(17, 2, 121, 1),
(18, 2, 129, 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assembly_outbound_items`
--
ALTER TABLE `assembly_outbound_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `outbound_id` (`outbound_id`),
  ADD KEY `product_id` (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assembly_outbound_items`
--
ALTER TABLE `assembly_outbound_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assembly_outbound_items`
--
ALTER TABLE `assembly_outbound_items`
  ADD CONSTRAINT `assembly_outbound_items_ibfk_1` FOREIGN KEY (`outbound_id`) REFERENCES `assembly_outbound` (`id`),
  ADD CONSTRAINT `assembly_outbound_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
