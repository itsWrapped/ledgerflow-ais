-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 24, 2026 at 01:22 PM
-- Server version: 10.11.16-MariaDB
-- PHP Version: 8.3.36

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ledgerflow_local`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(150) NOT NULL,
  `account_type` enum('asset','liability','equity','income','expense') NOT NULL,
  `normal_balance` enum('debit','credit') NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`id`, `account_code`, `account_name`, `account_type`, `normal_balance`, `is_active`, `created_at`) VALUES
(1, '101', 'Cash', 'asset', 'debit', 1, '2026-11-19 13:01:49'),
(2, '102', 'Accounts Receivable', 'asset', 'debit', 1, '2026-11-19 13:01:49'),
(3, '103', 'Supplies', 'asset', 'debit', 1, '2026-11-19 13:01:49'),
(4, '104', 'Prepaid Rent', 'asset', 'debit', 1, '2026-11-19 13:01:49'),
(5, '105', 'Equipment', 'asset', 'debit', 1, '2026-11-19 13:01:49'),
(6, '106', 'Accumulated Depreciation', 'asset', 'credit', 1, '2026-11-19 13:01:49'),
(7, '201', 'Accounts Payable', 'liability', 'credit', 1, '2026-11-19 13:01:49'),
(8, '202', 'Salaries Payable', 'liability', 'credit', 1, '2026-11-19 13:01:49'),
(9, '203', 'Unearned Revenue', 'liability', 'credit', 1, '2026-11-19 13:01:49'),
(10, '204', 'Loans Payable', 'liability', 'credit', 1, '2026-11-19 13:01:49'),
(11, '301', 'Owner Capital', 'equity', 'credit', 1, '2026-11-19 13:01:49'),
(12, '302', 'Owner Drawings', 'equity', 'debit', 1, '2026-11-19 13:01:49'),
(13, '401', 'Service Revenue', 'income', 'credit', 1, '2026-11-19 13:01:49'),
(14, '402', 'Other Income', 'income', 'credit', 1, '2026-11-19 13:01:49'),
(15, '501', 'Salaries Expense', 'expense', 'debit', 1, '2026-11-19 13:01:49'),
(16, '502', 'Rent Expense', 'expense', 'debit', 1, '2026-11-19 13:01:49'),
(17, '503', 'Utilities Expense', 'expense', 'debit', 1, '2026-11-19 13:01:49'),
(18, '504', 'Supplies Expense', 'expense', 'debit', 1, '2026-11-19 13:01:49'),
(19, '505', 'Depreciation Expense', 'expense', 'debit', 1, '2026-11-19 13:01:49'),
(20, '506', 'Miscellaneous Expense', 'expense', 'debit', 1, '2026-11-19 13:01:49');

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `voucher_number` varchar(30) DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `payee` varchar(150) DEFAULT NULL,
  `description` mediumtext NOT NULL,
  `status` enum('draft','posted','void') DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `posted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `journal_entries`
--

INSERT INTO `journal_entries` (`id`, `voucher_number`, `transaction_date`, `payee`, `description`, `status`, `created_by`, `posted_by`, `created_at`, `posted_at`) VALUES
(1, '11-001-2026', '2026-11-19', NULL, 'A business receives ?10,000 cash for services rendered.', 'posted', 1, 1, '2026-11-19 13:33:45', '2026-11-19 13:33:45'),
(2, '11-002-2026', '2026-11-19', NULL, 'the business receives cash from services rendered', 'posted', 1, 1, '2026-11-19 13:39:46', '2026-11-19 13:39:46'),
(3, '11-003-2026', '2026-11-19', NULL, 'the business receives ?8,000 for services rendered', 'posted', 1, 1, '2026-11-19 13:42:35', '2026-11-19 13:42:35'),
(4, '11-004-2026', '2026-11-20', NULL, 'business receives a lot of ₱₱₱', 'posted', 1, 1, '2026-11-19 21:25:15', '2026-11-19 21:25:15'),
(5, '11-005-2026', '2026-11-20', 'Test Supplier', 'someone paid the business I guess?', 'posted', 1, 1, '2026-11-20 00:26:47', '2026-11-20 00:26:47'),
(6, '11-006-2026', '2026-11-20', 'Someone', 'secret', 'posted', 1, 1, '2026-11-20 00:37:22', '2026-11-20 00:37:22'),
(7, '11-007-2026', '2026-11-20', 'Someone', 'someone gave us a lot of ₱₱₱', 'posted', 1, 1, '2026-11-20 00:39:17', '2026-11-20 00:39:17'),
(8, '11-008-2026', '2026-11-20', 'Ernest Telano', 'he paid his debt', 'posted', 1, 1, '2026-11-20 01:00:06', '2026-11-20 01:00:06'),
(9, '12-001-2025', '2025-12-01', 'Ernest Telano', 'he paid something with cash', 'posted', 1, 1, '2026-11-20 03:55:32', '2026-11-20 03:55:32');

-- --------------------------------------------------------

--
-- Table structure for table `journal_entry_lines`
--

CREATE TABLE `journal_entry_lines` (
  `id` int(11) NOT NULL,
  `journal_entry_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `line_description` varchar(255) DEFAULT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `journal_entry_lines`
--

INSERT INTO `journal_entry_lines` (`id`, `journal_entry_id`, `account_id`, `line_description`, `debit`, `credit`) VALUES
(1, 1, 1, NULL, 10000.00, 0.00),
(2, 1, 13, NULL, 0.00, 10000.00),
(3, 2, 1, NULL, 5000.00, 0.00),
(4, 2, 13, NULL, 0.00, 5000.00),
(5, 3, 1, NULL, 8000.00, 0.00),
(6, 3, 13, NULL, 0.00, 8000.00),
(7, 4, 1, NULL, 5555.00, 0.00),
(8, 4, 13, NULL, 0.00, 5555.00),
(9, 5, 1, NULL, 1000.00, 0.00),
(10, 5, 13, NULL, 0.00, 1000.00),
(11, 6, 1, NULL, 5000.00, 0.00),
(12, 6, 11, NULL, 0.00, 5000.00),
(13, 7, 1, NULL, 10000.00, 0.00),
(14, 7, 11, NULL, 0.00, 10000.00),
(15, 8, 1, NULL, 8000.00, 0.00),
(16, 8, 2, NULL, 0.00, 8000.00),
(17, 9, 1, NULL, 12000.00, 0.00),
(18, 9, 13, NULL, 0.00, 12000.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','finance_officer') NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `password_hash`, `role`, `is_active`, `created_at`) VALUES
(1, 'Rafael Puspus', 'Paeng', '$2y$10$ewMFN4P86uI6ROCB7D2h5Ou0lkYkcWS3DXk/SBLBY48ZLMuEuSmi2', 'admin', 1, '2026-11-19 13:17:01'),
(2, 'Ernest Telano', 'Ernest', '$2y$10$0vxHAimwjGJl4fZ7y5Qeb.ZcP0XoxSUG86CHTXNRC2d3naOhSYjSa', 'finance_officer', 1, '2026-11-20 03:58:35');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_code` (`account_code`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `voucher_number` (`voucher_number`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `posted_by` (`posted_by`),
  ADD KEY `idx_journal_date` (`transaction_date`);

--
-- Indexes for table `journal_entry_lines`
--
ALTER TABLE `journal_entry_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `journal_entry_id` (`journal_entry_id`),
  ADD KEY `idx_journal_account` (`account_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `journal_entry_lines`
--
ALTER TABLE `journal_entry_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
