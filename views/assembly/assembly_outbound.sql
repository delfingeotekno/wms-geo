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
-- Table structure for table `assembly_outbound`
--

CREATE TABLE `assembly_outbound` (
  `id` int(11) NOT NULL,
  `transaction_no` varchar(50) NOT NULL,
  `assembly_id` int(11) DEFAULT NULL,
  `total_units` int(11) NOT NULL,
  `project_name` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assembly_outbound`
--

INSERT INTO `assembly_outbound` (`id`, `transaction_no`, `assembly_id`, `total_units`, `project_name`, `user_id`, `created_at`) VALUES
(1, '001/BAST/GEO-JKT/I/2026', 1, 2, 'TRACKING RPM', 4, '2026-01-05 07:12:08'),
(2, '002/BAST/GEO-JKT/I/2026', 1, 1, 'TRACKING RPM BATERAI BACKUP', 4, '2026-01-05 07:43:34');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assembly_outbound`
--
ALTER TABLE `assembly_outbound`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_no` (`transaction_no`),
  ADD KEY `assembly_id` (`assembly_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assembly_outbound`
--
ALTER TABLE `assembly_outbound`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assembly_outbound`
--
ALTER TABLE `assembly_outbound`
  ADD CONSTRAINT `assembly_outbound_ibfk_1` FOREIGN KEY (`assembly_id`) REFERENCES `assemblies` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
