-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 24, 2025 at 05:09 AM
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
-- Database: `meditrack`
--

-- --------------------------------------------------------

--
-- Table structure for table `allocation_disbursals`
--

CREATE TABLE `allocation_disbursals` (
  `id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `bhw_id` int(11) NOT NULL,
  `disbursed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `allocation_disbursal_batches`
--

CREATE TABLE `allocation_disbursal_batches` (
  `id` int(11) NOT NULL,
  `disbursal_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `allocation_programs`
--

CREATE TABLE `allocation_programs` (
  `id` int(11) NOT NULL,
  `program_name` varchar(191) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `quantity_per_senior` int(11) NOT NULL,
  `frequency` enum('monthly','quarterly') NOT NULL,
  `scope_type` enum('barangay','purok') NOT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `purok_id` int(11) DEFAULT NULL,
  `claim_window_days` int(11) NOT NULL DEFAULT 14,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `barangays`
--

CREATE TABLE `barangays` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangays`
--

INSERT INTO `barangays` (`id`, `name`) VALUES
(1, 'Basdacu');

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `recipient` varchar(191) NOT NULL,
  `subject` varchar(191) NOT NULL,
  `body` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('sent','failed') NOT NULL,
  `error` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_logs`
--

INSERT INTO `email_logs` (`id`, `recipient`, `subject`, `body`, `sent_at`, `status`, `error`) VALUES
(1, 's2peed3@gmail.com', 'New medicine added', '<!doctype html>\r\n<html><head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"/><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />\r\n<title>New medicine available</title>\r\n<style>body{background-color:#f7f7fb;margin:0;font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;color:#111827} .container{max-width:640px;margin:0 auto;padding:24px} .card{background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 1px 3px rgba(16,24,40,.1);overflow:hidden} .header{background:linear-gradient(135deg,#2563eb 0%,#3b82f6 100%);padding:20px 24px;color:#fff} .brand{font-weight:700;font-size:18px} .title{font-size:20px;margin:0} .content{padding:24px} p{line-height:1.6;margin:0 0 12px} .lead{font-size:16px;color:#374151;margin-bottom:16px} .divider{height:1px;background:#e5e7eb;margin:16px 0} .btn a{display:inline-block;background:#2563eb;color:#fff !important;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600} .muted{color:#6b7280;font-size:12px;margin-top:12px} @media (prefers-color-scheme: dark){ body{background:#0b1220;color:#e5e7eb} .card{background:#111827;box-shadow:none} .header{background:linear-gradient(135deg,#1d4ed8 0%,#2563eb 100%)} .lead{color:#9ca3af} .divider{background:#1f2937} .muted{color:#9ca3af} }</style></head>\r\n<body>\r\n  <div class=\"container\">\r\n    <div class=\"card\">\r\n      <div class=\"header\"><div class=\"brand\">MediTrack</div><h1 class=\"title\">New medicine available</h1></div>\r\n      <div class=\"content\">\r\n        <p class=\"lead\">A new medicine has been added to the inventory.</p>\r\n        <div><p>Medicine: <b>asd</b></p><p>Please review batches and availability.</p></div>\r\n        <table role=\"presentation\" cellspacing=\"0\" cellpadding=\"0\"><tr><td class=\"btn\"><a href=\"/thesis/public/bhw/dashboard.php\">Open BHW Panel</a></td></tr></table>\r\n        <div class=\"divider\"></div>\r\n        <p class=\"muted\">This is an automated message from MediTrack. Please do not reply.</p>\r\n      </div>\r\n    </div>\r\n    <p class=\"muted\" style=\"text-align:center\">© 2025 MediTrack</p>\r\n  </div>\r\n</body></html>', '2025-09-23 03:34:38', 'failed', 'SMTP Error: Could not connect to SMTP host. Failed to connect to server'),
(2, 's2peed3@gmail.com', 'New medicine added', '<!doctype html>\r\n<html><head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"/><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />\r\n<title>New medicine available</title>\r\n<style>body{background-color:#f7f7fb;margin:0;font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;color:#111827} .container{max-width:640px;margin:0 auto;padding:24px} .card{background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 1px 3px rgba(16,24,40,.1);overflow:hidden} .header{background:linear-gradient(135deg,#2563eb 0%,#3b82f6 100%);padding:20px 24px;color:#fff} .brand{font-weight:700;font-size:18px} .title{font-size:20px;margin:0} .content{padding:24px} p{line-height:1.6;margin:0 0 12px} .lead{font-size:16px;color:#374151;margin-bottom:16px} .divider{height:1px;background:#e5e7eb;margin:16px 0} .btn a{display:inline-block;background:#2563eb;color:#fff !important;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600} .muted{color:#6b7280;font-size:12px;margin-top:12px} @media (prefers-color-scheme: dark){ body{background:#0b1220;color:#e5e7eb} .card{background:#111827;box-shadow:none} .header{background:linear-gradient(135deg,#1d4ed8 0%,#2563eb 100%)} .lead{color:#9ca3af} .divider{background:#1f2937} .muted{color:#9ca3af} }</style></head>\r\n<body>\r\n  <div class=\"container\">\r\n    <div class=\"card\">\r\n      <div class=\"header\"><div class=\"brand\">MediTrack</div><h1 class=\"title\">New medicine available</h1></div>\r\n      <div class=\"content\">\r\n        <p class=\"lead\">A new medicine has been added to the inventory.</p>\r\n        <div><p>Medicine: <b>hahahha</b></p><p>Please review batches and availability.</p></div>\r\n        <table role=\"presentation\" cellspacing=\"0\" cellpadding=\"0\"><tr><td class=\"btn\"><a href=\"/thesis/public/bhw/dashboard.php\">Open BHW Panel</a></td></tr></table>\r\n        <div class=\"divider\"></div>\r\n        <p class=\"muted\">This is an automated message from MediTrack. Please do not reply.</p>\r\n      </div>\r\n    </div>\r\n    <p class=\"muted\" style=\"text-align:center\">© 2025 MediTrack</p>\r\n  </div>\r\n</body></html>', '2025-09-23 03:54:09', 'failed', 'SMTP Error: Could not connect to SMTP host.'),
(3, 's2peed3@gmail.com', 'New medicine added', '<!doctype html>\r\n<html><head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"/><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />\r\n<title>New medicine available</title>\r\n<style>body{background-color:#f7f7fb;margin:0;font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;color:#111827} .container{max-width:640px;margin:0 auto;padding:24px} .card{background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 1px 3px rgba(16,24,40,.1);overflow:hidden} .header{background:linear-gradient(135deg,#2563eb 0%,#3b82f6 100%);padding:20px 24px;color:#fff} .brand{font-weight:700;font-size:18px} .title{font-size:20px;margin:0} .content{padding:24px} p{line-height:1.6;margin:0 0 12px} .lead{font-size:16px;color:#374151;margin-bottom:16px} .divider{height:1px;background:#e5e7eb;margin:16px 0} .btn a{display:inline-block;background:#2563eb;color:#fff !important;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600} .muted{color:#6b7280;font-size:12px;margin-top:12px} @media (prefers-color-scheme: dark){ body{background:#0b1220;color:#e5e7eb} .card{background:#111827;box-shadow:none} .header{background:linear-gradient(135deg,#1d4ed8 0%,#2563eb 100%)} .lead{color:#9ca3af} .divider{background:#1f2937} .muted{color:#9ca3af} }</style></head>\r\n<body>\r\n  <div class=\"container\">\r\n    <div class=\"card\">\r\n      <div class=\"header\"><div class=\"brand\">MediTrack</div><h1 class=\"title\">New medicine available</h1></div>\r\n      <div class=\"content\">\r\n        <p class=\"lead\">A new medicine has been added to the inventory.</p>\r\n        <div><p>Medicine: <b>bago</b></p><p>Please review batches and availability.</p></div>\r\n        <table role=\"presentation\" cellspacing=\"0\" cellpadding=\"0\"><tr><td class=\"btn\"><a href=\"/thesis/public/bhw/dashboard.php\">Open BHW Panel</a></td></tr></table>\r\n        <div class=\"divider\"></div>\r\n        <p class=\"muted\">This is an automated message from MediTrack. Please do not reply.</p>\r\n      </div>\r\n    </div>\r\n    <p class=\"muted\" style=\"text-align:center\">© 2025 MediTrack</p>\r\n  </div>\r\n</body></html>', '2025-09-23 04:12:19', 'sent', NULL),
(4, 's2peed3@gmail.com', 'New medicine added', '<!doctype html>\r\n<html><head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"/><meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />\r\n<title>New medicine available</title>\r\n<style>body{background-color:#f7f7fb;margin:0;font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;color:#111827} .container{max-width:640px;margin:0 auto;padding:24px} .card{background:#ffffff;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 1px 3px rgba(16,24,40,.1);overflow:hidden} .header{background:linear-gradient(135deg,#2563eb 0%,#3b82f6 100%);padding:20px 24px;color:#fff} .brand{font-weight:700;font-size:18px} .title{font-size:20px;margin:0} .content{padding:24px} p{line-height:1.6;margin:0 0 12px} .lead{font-size:16px;color:#374151;margin-bottom:16px} .divider{height:1px;background:#e5e7eb;margin:16px 0} .btn a{display:inline-block;background:#2563eb;color:#fff !important;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600} .muted{color:#6b7280;font-size:12px;margin-top:12px} @media (prefers-color-scheme: dark){ body{background:#0b1220;color:#e5e7eb} .card{background:#111827;box-shadow:none} .header{background:linear-gradient(135deg,#1d4ed8 0%,#2563eb 100%)} .lead{color:#9ca3af} .divider{background:#1f2937} .muted{color:#9ca3af} }</style></head>\r\n<body>\r\n  <div class=\"container\">\r\n    <div class=\"card\">\r\n      <div class=\"header\"><div class=\"brand\">MediTrack</div><h1 class=\"title\">New medicine available</h1></div>\r\n      <div class=\"content\">\r\n        <p class=\"lead\">A new medicine has been added to the inventory.</p>\r\n        <div><p>Medicine: <b>bai na  bai</b></p><p>Please review batches and availability.</p></div>\r\n        <table role=\"presentation\" cellspacing=\"0\" cellpadding=\"0\"><tr><td class=\"btn\"><a href=\"/thesis/public/bhw/dashboard.php\">Open BHW Panel</a></td></tr></table>\r\n        <div class=\"divider\"></div>\r\n        <p class=\"muted\">This is an automated message from MediTrack. Please do not reply.</p>\r\n      </div>\r\n    </div>\r\n    <p class=\"muted\" style=\"text-align:center\">© 2025 MediTrack</p>\r\n  </div>\r\n</body></html>', '2025-09-23 06:14:17', 'sent', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `email_notifications`
--

CREATE TABLE `email_notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `family_members`
--

CREATE TABLE `family_members` (
  `id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `relationship` varchar(100) NOT NULL,
  `age` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medicines`
--

CREATE TABLE `medicines` (
  `id` int(11) NOT NULL,
  `name` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medicines`
--

INSERT INTO `medicines` (`id`, `name`, `description`, `image_path`, `is_active`, `created_at`) VALUES
(1, 'biot', 'asd', NULL, 1, '2025-09-23 02:22:35'),
(2, 'asdas', 'asd', NULL, 1, '2025-09-23 02:25:23'),
(3, 'biogesic', 'asdas', 'uploads/medicines/med_1758594421_2344edfb.png', 1, '2025-09-23 02:27:01'),
(5, 'asd', 'asd', 'uploads/medicines/med_1758598478_f2eeb828.png', 1, '2025-09-23 03:34:38'),
(6, 'hahahha', 'aasdas', 'uploads/medicines/med_1758599649_86bb2279.png', 1, '2025-09-23 03:54:09'),
(7, 'bago', 'daan', 'uploads/medicines/med_1758600735_58c2667b.png', 1, '2025-09-23 04:12:15'),
(9, 'bai na  bai', 'asdas', 'uploads/medicines/med_1758608053_167a8e7a.png', 1, '2025-09-23 06:14:13');

-- --------------------------------------------------------

--
-- Table structure for table `medicine_batches`
--

CREATE TABLE `medicine_batches` (
  `id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `batch_code` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL,
  `quantity_available` int(11) NOT NULL,
  `expiry_date` date NOT NULL,
  `received_at` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medicine_batches`
--

INSERT INTO `medicine_batches` (`id`, `medicine_id`, `batch_code`, `quantity`, `quantity_available`, `expiry_date`, `received_at`, `created_at`) VALUES
(1, 1, '112', 12312, 12312, '2025-10-04', '2025-09-23', '2025-09-23 02:22:53');

-- --------------------------------------------------------

--
-- Table structure for table `pending_family_members`
--

CREATE TABLE `pending_family_members` (
  `id` int(11) NOT NULL,
  `pending_resident_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `relationship` varchar(100) NOT NULL,
  `age` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pending_family_members`
--

INSERT INTO `pending_family_members` (`id`, `pending_resident_id`, `full_name`, `relationship`, `age`, `created_at`) VALUES
(1, 1, 'Christe Hanna Mae Cuas', 'Mother', 22, '2025-09-24 00:20:17'),
(2, 1, 'Clifbelle Cabrera', 'Son', 22, '2025-09-24 00:20:17');

-- --------------------------------------------------------

--
-- Table structure for table `pending_residents`
--

CREATE TABLE `pending_residents` (
  `id` int(11) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `date_of_birth` date NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `barangay_id` int(11) NOT NULL,
  `purok_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `bhw_id` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pending_residents`
--

INSERT INTO `pending_residents` (`id`, `email`, `password_hash`, `first_name`, `last_name`, `date_of_birth`, `phone`, `address`, `barangay_id`, `purok_id`, `status`, `bhw_id`, `rejection_reason`, `created_at`, `updated_at`) VALUES
(1, 'canamocan18@gmail.com', '$2y$10$YVa27Z.RbrKby1AprgPQz.R9O7GkvCoMl.IM2/1IhE9WxVQpuVjha', 'John Mark', 'Sagetarios', '2000-02-03', '09123123121', '', 1, 1, 'pending', NULL, NULL, '2025-09-24 00:20:17', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `puroks`
--

CREATE TABLE `puroks` (
  `id` int(11) NOT NULL,
  `barangay_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `puroks`
--

INSERT INTO `puroks` (`id`, `barangay_id`, `name`) VALUES
(1, 1, 'Purok 1'),
(2, 1, 'Purok 2'),
(3, 1, 'Purok 3'),
(4, 1, 'Purok 4'),
(5, 1, 'Purok 5'),
(6, 1, 'Purok 6'),
(8, 1, 'Purok 7');

-- --------------------------------------------------------

--
-- Table structure for table `requests`
--

CREATE TABLE `requests` (
  `id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `requested_for` enum('self','family') NOT NULL DEFAULT 'self',
  `patient_name` varchar(150) DEFAULT NULL,
  `patient_age` int(11) DEFAULT NULL,
  `relationship` varchar(100) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `proof_image_path` varchar(255) DEFAULT NULL,
  `status` enum('submitted','approved','rejected','ready_to_claim','claimed') NOT NULL DEFAULT 'submitted',
  `bhw_id` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `request_fulfillments`
--

CREATE TABLE `request_fulfillments` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `residents`
--

CREATE TABLE `residents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `barangay_id` int(11) NOT NULL,
  `purok_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `date_of_birth` date NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `residents`
--

INSERT INTO `residents` (`id`, `user_id`, `barangay_id`, `purok_id`, `first_name`, `last_name`, `date_of_birth`, `email`, `phone`, `address`, `created_at`) VALUES
(1, 4, 1, 1, 'axl', 'tagat', '2020-06-10', 's2peed5@gmail.com', '09940204774', '', '2025-09-23 08:47:33');

-- --------------------------------------------------------

--
-- Table structure for table `senior_allocations`
--

CREATE TABLE `senior_allocations` (
  `id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `bhw_id` int(11) NOT NULL,
  `status` enum('pending','released','expired','returned') NOT NULL DEFAULT 'pending',
  `must_claim_before` date NOT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `key_name` varchar(100) NOT NULL,
  `value_text` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `key_name`, `value_text`, `updated_at`) VALUES
(1, 'brand_name', 'MediTrack', '2025-09-24 00:14:36'),
(2, 'brand_logo_path', NULL, '2025-09-24 00:14:36');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('super_admin','bhw','resident') NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `purok_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `purok_id`, `created_at`) VALUES
(1, 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'Super', 'Admin', NULL, '2025-09-23 02:05:22'),
(2, 's2peed3@gmail.com', '$2y$10$VLLynkZwldSlf1w3R7OOhe.rBMNA5fIITjIQ6/JpJA7APyseiKX6K', 'bhw', 'Ann', 'Canamucan', 1, '2025-09-23 02:29:01'),
(4, 's2peed5@gmail.com', '$2y$10$hQCDNu7NxJSAKRDuDbX0O.a4kz2SN4PycumnbLkArr0IASbS4ahxK', 'resident', 'axl', 'tagat', 1, '2025-09-23 08:47:33');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_residents_with_senior`
-- (See below for the actual view)
--
CREATE TABLE `v_residents_with_senior` (
`id` int(11)
,`user_id` int(11)
,`barangay_id` int(11)
,`purok_id` int(11)
,`first_name` varchar(100)
,`last_name` varchar(100)
,`date_of_birth` date
,`email` varchar(191)
,`phone` varchar(50)
,`address` varchar(255)
,`created_at` timestamp
,`is_senior` int(1)
);

-- --------------------------------------------------------

--
-- Structure for view `v_residents_with_senior`
--
DROP TABLE IF EXISTS `v_residents_with_senior`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_residents_with_senior`  AS SELECT `r`.`id` AS `id`, `r`.`user_id` AS `user_id`, `r`.`barangay_id` AS `barangay_id`, `r`.`purok_id` AS `purok_id`, `r`.`first_name` AS `first_name`, `r`.`last_name` AS `last_name`, `r`.`date_of_birth` AS `date_of_birth`, `r`.`email` AS `email`, `r`.`phone` AS `phone`, `r`.`address` AS `address`, `r`.`created_at` AS `created_at`, timestampdiff(YEAR,`r`.`date_of_birth`,curdate()) >= 60 AS `is_senior` FROM `residents` AS `r` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `allocation_disbursals`
--
ALTER TABLE `allocation_disbursals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_disbursal_program` (`program_id`),
  ADD KEY `fk_disbursal_bhw` (`bhw_id`);

--
-- Indexes for table `allocation_disbursal_batches`
--
ALTER TABLE `allocation_disbursal_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_disbursal_batches_d` (`disbursal_id`),
  ADD KEY `fk_disbursal_batches_b` (`batch_id`);

--
-- Indexes for table `allocation_programs`
--
ALTER TABLE `allocation_programs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_prog_med` (`medicine_id`),
  ADD KEY `fk_prog_barangay` (`barangay_id`),
  ADD KEY `fk_prog_purok` (`purok_id`);

--
-- Indexes for table `barangays`
--
ALTER TABLE `barangays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email_notifications_user` (`user_id`);

--
-- Indexes for table `family_members`
--
ALTER TABLE `family_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_family_resident` (`resident_id`);

--
-- Indexes for table `medicines`
--
ALTER TABLE `medicines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_medicine_name` (`name`);

--
-- Indexes for table `medicine_batches`
--
ALTER TABLE `medicine_batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_batch_per_medicine` (`medicine_id`,`batch_code`),
  ADD KEY `idx_expiry` (`expiry_date`);

--
-- Indexes for table `pending_family_members`
--
ALTER TABLE `pending_family_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pending_family_resident` (`pending_resident_id`);

--
-- Indexes for table `pending_residents`
--
ALTER TABLE `pending_residents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_pending_resident_barangay` (`barangay_id`),
  ADD KEY `fk_pending_resident_bhw` (`bhw_id`),
  ADD KEY `idx_pending_residents_purok` (`purok_id`),
  ADD KEY `idx_pending_residents_status` (`status`);

--
-- Indexes for table `puroks`
--
ALTER TABLE `puroks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_barangay_purok` (`barangay_id`,`name`);

--
-- Indexes for table `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_request_resident` (`resident_id`),
  ADD KEY `fk_request_medicine` (`medicine_id`),
  ADD KEY `fk_request_bhw` (`bhw_id`);

--
-- Indexes for table `request_fulfillments`
--
ALTER TABLE `request_fulfillments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_fulfill_req` (`request_id`),
  ADD KEY `fk_fulfill_batch` (`batch_id`);

--
-- Indexes for table `residents`
--
ALTER TABLE `residents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_resident_user` (`user_id`),
  ADD KEY `fk_resident_barangay` (`barangay_id`),
  ADD KEY `fk_resident_purok` (`purok_id`);

--
-- Indexes for table `senior_allocations`
--
ALTER TABLE `senior_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_salloc_program` (`program_id`),
  ADD KEY `fk_salloc_resident` (`resident_id`),
  ADD KEY `fk_salloc_bhw` (`bhw_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key_name` (`key_name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_purok` (`purok_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `allocation_disbursals`
--
ALTER TABLE `allocation_disbursals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `allocation_disbursal_batches`
--
ALTER TABLE `allocation_disbursal_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `allocation_programs`
--
ALTER TABLE `allocation_programs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `barangays`
--
ALTER TABLE `barangays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `email_notifications`
--
ALTER TABLE `email_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `family_members`
--
ALTER TABLE `family_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medicines`
--
ALTER TABLE `medicines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `medicine_batches`
--
ALTER TABLE `medicine_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pending_family_members`
--
ALTER TABLE `pending_family_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `pending_residents`
--
ALTER TABLE `pending_residents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `puroks`
--
ALTER TABLE `puroks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `request_fulfillments`
--
ALTER TABLE `request_fulfillments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `residents`
--
ALTER TABLE `residents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `senior_allocations`
--
ALTER TABLE `senior_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `allocation_disbursals`
--
ALTER TABLE `allocation_disbursals`
  ADD CONSTRAINT `fk_disbursal_bhw` FOREIGN KEY (`bhw_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_disbursal_program` FOREIGN KEY (`program_id`) REFERENCES `allocation_programs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `allocation_disbursal_batches`
--
ALTER TABLE `allocation_disbursal_batches`
  ADD CONSTRAINT `fk_disbursal_batches_b` FOREIGN KEY (`batch_id`) REFERENCES `medicine_batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_disbursal_batches_d` FOREIGN KEY (`disbursal_id`) REFERENCES `allocation_disbursals` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `allocation_programs`
--
ALTER TABLE `allocation_programs`
  ADD CONSTRAINT `fk_prog_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_prog_med` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_prog_purok` FOREIGN KEY (`purok_id`) REFERENCES `puroks` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD CONSTRAINT `fk_email_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `family_members`
--
ALTER TABLE `family_members`
  ADD CONSTRAINT `fk_family_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `medicine_batches`
--
ALTER TABLE `medicine_batches`
  ADD CONSTRAINT `fk_batch_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pending_family_members`
--
ALTER TABLE `pending_family_members`
  ADD CONSTRAINT `fk_pending_family_resident` FOREIGN KEY (`pending_resident_id`) REFERENCES `pending_residents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pending_residents`
--
ALTER TABLE `pending_residents`
  ADD CONSTRAINT `fk_pending_resident_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pending_resident_bhw` FOREIGN KEY (`bhw_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pending_resident_purok` FOREIGN KEY (`purok_id`) REFERENCES `puroks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `puroks`
--
ALTER TABLE `puroks`
  ADD CONSTRAINT `fk_purok_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `requests`
--
ALTER TABLE `requests`
  ADD CONSTRAINT `fk_request_bhw` FOREIGN KEY (`bhw_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_request_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_request_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `request_fulfillments`
--
ALTER TABLE `request_fulfillments`
  ADD CONSTRAINT `fk_fulfill_batch` FOREIGN KEY (`batch_id`) REFERENCES `medicine_batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fulfill_req` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `residents`
--
ALTER TABLE `residents`
  ADD CONSTRAINT `fk_resident_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_resident_purok` FOREIGN KEY (`purok_id`) REFERENCES `puroks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_resident_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `senior_allocations`
--
ALTER TABLE `senior_allocations`
  ADD CONSTRAINT `fk_salloc_bhw` FOREIGN KEY (`bhw_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_salloc_program` FOREIGN KEY (`program_id`) REFERENCES `allocation_programs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_salloc_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_user_purok` FOREIGN KEY (`purok_id`) REFERENCES `puroks` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
