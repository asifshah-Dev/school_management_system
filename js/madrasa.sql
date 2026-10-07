-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 19, 2025 at 08:46 PM
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
-- Database: `madrasa`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'Supplier',
  `mobile_no` varchar(255) NOT NULL,
  `other_information` text DEFAULT NULL,
  `balance` int(11) NOT NULL DEFAULT 0,
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `accounts_details`
--

CREATE TABLE `accounts_details` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `ref_type` varchar(11) DEFAULT NULL,
  `amount` double NOT NULL DEFAULT 0,
  `balance` double NOT NULL DEFAULT 0,
  `description` varchar(999) DEFAULT NULL,
  `dated` datetime NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `arrange_exam`
--

CREATE TABLE `arrange_exam` (
  `id` int(11) NOT NULL,
  `exam_type_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `arrange_exam`
--

INSERT INTO `arrange_exam` (`id`, `exam_type_id`, `class_id`, `start_date`, `created_at`, `status`) VALUES
(1, 1, 1, '2025-08-16', '2025-08-31 02:58:02', 0),
(4, 4, 1, '2025-09-27', '2025-09-01 00:43:50', 0),
(5, 4, 1, '2025-09-25', '2025-09-01 00:44:36', 0),
(6, 4, 1, '2025-09-12', '2025-09-01 00:45:44', 0),
(7, 4, 1, '2025-09-20', '2025-09-01 00:47:22', 0),
(8, 1, 4, '2025-09-18', '2025-09-01 00:57:03', 0),
(9, 1, 5, '2025-09-04', '2025-09-01 02:10:10', 0),
(10, 1, 3, '2025-09-18', '2025-09-01 02:10:37', 0),
(11, 1, 2, '2025-09-27', '2025-09-01 04:06:52', 0),
(12, 1, 6, '2025-09-10', '2025-09-04 15:11:17', 0);

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('P','L') NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `dated` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `student_id`, `date`, `status`, `remarks`, `dated`) VALUES
(10, 1, '2025-09-17', 'L', 'Sick leave', '2025-09-17 16:11:46'),
(11, 5, '2025-09-17', 'P', '', '2025-09-17 16:11:46'),
(16, 4, '2025-09-17', 'P', '', '2025-09-18 01:56:32'),
(17, 3, '2025-09-17', 'P', '', '2025-09-18 02:15:06'),
(21, 1, '2025-09-18', 'L', 'Sick leave', '2025-09-19 00:29:02'),
(22, 5, '2025-09-18', 'P', '', '2025-09-19 00:29:02'),
(23, 4, '2025-09-18', 'P', '', '2025-09-19 00:29:02'),
(24, 2, '2025-09-18', 'L', 'for testing', '2025-09-19 00:31:17'),
(25, 2, '2025-09-19', 'P', NULL, '2025-09-19 18:04:46'),
(26, 3, '2025-09-19', 'P', NULL, '2025-09-19 18:04:48'),
(27, 1, '2025-09-19', 'P', NULL, '2025-09-19 18:04:57'),
(28, 5, '2025-09-19', 'P', NULL, '2025-09-19 18:04:59'),
(30, 4, '2025-09-19', 'P', NULL, '2025-09-19 18:05:02');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `course_id`, `title`, `dated`, `status`) VALUES
(1, NULL, 'Ist', '2025-09-03', NULL),
(2, NULL, '2nd', '2025-09-03', NULL),
(3, NULL, '4th', '2025-09-03', NULL),
(6, NULL, '5th', '2025-09-03', NULL),
(7, NULL, '9th', '2025-09-06', NULL),
(8, NULL, '7th', '2025-09-06', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `class_fee_types`
--

CREATE TABLE `class_fee_types` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `fee_type_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` tinyint(1) DEFAULT 0,
  `dated` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `class_fee_types`
--

INSERT INTO `class_fee_types` (`id`, `class_id`, `fee_type_id`, `session_id`, `amount`, `status`, `dated`) VALUES
(1, 6, 2, 1, 200.00, 0, '2025-09-09 19:11:08'),
(2, 6, 1, 1, 600.00, 0, '2025-09-09 19:11:08'),
(3, 6, 3, 1, 100.00, 0, '2025-09-13 15:25:42');

-- --------------------------------------------------------

--
-- Table structure for table `class_sections`
--

CREATE TABLE `class_sections` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `status` int(11) NOT NULL DEFAULT 0 COMMENT '1 inactive, 0 active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `class_sections`
--

INSERT INTO `class_sections` (`id`, `class_id`, `section_id`, `status`) VALUES
(1, 1, 1, 0),
(2, 1, 2, 0),
(3, 1, 3, 0),
(7, 2, 1, 0),
(8, 2, 2, 0),
(9, 3, 1, 0),
(10, 3, 2, 0),
(11, 3, 3, 0),
(13, 6, 1, 0),
(14, 6, 2, 0),
(15, 6, 3, 0),
(19, 7, 1, 0),
(20, 7, 2, 0),
(21, 7, 3, 0),
(22, 8, 1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `duration` varchar(255) DEFAULT NULL,
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `title`, `duration`, `dated`, `status`) VALUES
(1, 'Maths', '4', '2025-08-27', 0);

-- --------------------------------------------------------

--
-- Table structure for table `course_fee`
--

CREATE TABLE `course_fee` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `fee_type_id` int(11) NOT NULL,
  `amount` double NOT NULL DEFAULT 0,
  `dated` date NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_datesheet`
--

CREATE TABLE `exam_datesheet` (
  `id` int(11) NOT NULL,
  `arrange_exam_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `dated` datetime NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0,
  `sub_subject_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `exam_datesheet`
--

INSERT INTO `exam_datesheet` (`id`, `arrange_exam_id`, `class_id`, `subject_id`, `start_date`, `start_time`, `end_time`, `dated`, `status`, `sub_subject_id`) VALUES
(1, 9, 5, 2, '2025-09-26', '09:00:00', '12:30:00', '2025-09-01 16:04:02', 0, 6),
(2, 9, 5, 1, '2025-09-29', '07:00:00', '11:30:00', '2025-09-01 16:04:02', 0, NULL),
(3, 8, 4, 6, '2025-09-02', '09:00:00', '12:00:00', '2025-09-01 17:15:16', 0, 9),
(4, 8, 4, 6, '2025-09-21', '09:30:00', '11:00:00', '2025-09-01 17:15:16', 0, 10),
(5, 8, 4, 7, '2025-09-03', '09:00:00', '12:00:00', '2025-09-01 17:15:16', 0, 11),
(6, 8, 4, 7, '2025-09-22', '10:00:00', '12:00:00', '2025-09-01 17:15:16', 0, 12),
(7, 8, 4, 2, '2025-09-04', '09:00:00', '12:00:00', '2025-09-01 17:15:16', 0, 6),
(8, 8, 4, 1, '2025-09-05', '09:00:00', '12:00:00', '2025-09-01 17:15:16', 0, NULL),
(9, 8, 4, 5, '2025-09-06', '09:00:00', '11:00:00', '2025-09-01 17:15:16', 0, NULL),
(10, 8, 4, 3, '2025-09-08', '09:00:00', '12:00:00', '2025-09-01 17:15:16', 0, 7),
(11, 8, 4, 3, '2025-09-23', '09:30:00', '11:00:00', '2025-09-01 17:15:16', 0, 8),
(12, 8, 4, 4, '2025-09-09', '09:00:00', '12:00:00', '2025-09-01 17:15:16', 0, NULL),
(13, 11, 2, 1, '2025-09-03', '09:00:00', '00:00:00', '2025-09-01 21:42:13', 0, NULL),
(14, 11, 2, 3, '2025-09-04', '09:00:00', '00:00:00', '2025-09-01 21:42:13', 0, 7),
(15, 11, 2, 3, '2025-09-05', '09:00:00', '00:00:00', '2025-09-01 21:42:13', 0, 8),
(16, 12, 6, 6, '2025-09-04', '09:00:00', '12:00:00', '2025-09-04 15:11:52', 0, 9),
(17, 12, 6, 6, '2025-09-05', '09:00:00', '12:00:00', '2025-09-04 15:11:52', 0, 10),
(18, 12, 6, 7, '2025-09-06', '09:00:00', '12:00:00', '2025-09-04 15:11:52', 0, 11),
(19, 12, 6, 7, '2025-09-08', '09:00:00', '12:00:00', '2025-09-04 15:11:52', 0, 12),
(20, 12, 6, 4, '2025-09-09', '09:00:00', '12:00:00', '2025-09-04 15:11:52', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `exam_types`
--

CREATE TABLE `exam_types` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `dated` datetime NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `exam_types`
--

INSERT INTO `exam_types` (`id`, `title`, `dated`, `status`) VALUES
(1, 'Monthly', '2025-08-29 17:48:33', 0),
(2, 'Semester', '2025-08-29 17:51:26', 0);

-- --------------------------------------------------------

--
-- Table structure for table `expenses_details`
--

CREATE TABLE `expenses_details` (
  `id` int(11) NOT NULL,
  `expense_id` int(11) NOT NULL,
  `expense_head_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses_master`
--

CREATE TABLE `expenses_master` (
  `id` int(11) NOT NULL,
  `from_account_id` int(11) NOT NULL,
  `expense_head_id` int(11) DEFAULT NULL,
  `payment_type` varchar(50) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `invoice_date` date NOT NULL DEFAULT curdate(),
  `status` int(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses_payments`
--

CREATE TABLE `expenses_payments` (
  `id` int(11) NOT NULL,
  `expense_id` int(11) NOT NULL,
  `payment_date` date NOT NULL DEFAULT curdate(),
  `payment_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_heads`
--

CREATE TABLE `expense_heads` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fee_types`
--

CREATE TABLE `fee_types` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'Monthly',
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_types`
--

INSERT INTO `fee_types` (`id`, `title`, `type`, `dated`, `status`) VALUES
(1, 'Monthly', 'Monthly', '2025-09-09', 0),
(2, 'Exam fee', 'Monthly', '2025-09-09', 0),
(3, 'TESTING', 'Monthly', '2025-09-13', 0);

-- --------------------------------------------------------

--
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `title` varchar(100) NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT '#',
  `description` varchar(255) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `type` enum('Parent','Child') NOT NULL DEFAULT 'Parent'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `modules`
--

INSERT INTO `modules` (`id`, `parent_id`, `title`, `url`, `description`, `status`, `created_at`, `type`) VALUES
(2, 4, 'Village', 'village_council.php', 'Adding Villages', 1, '2025-09-17 07:33:01', 'Child'),
(4, NULL, 'Settings', '', '', 1, '2025-09-17 07:35:51', 'Parent'),
(5, 4, 'Class', 'classes.php', 'Adding classes', 1, '2025-09-17 07:47:27', 'Child'),
(6, NULL, 'Student Management', '#', '', 1, '2025-09-17 09:27:45', 'Parent'),
(7, 6, 'Resgister Student', 'student_registration.php', '', 1, '2025-09-17 09:28:41', 'Child'),
(8, NULL, 'Exam Management', '#', '', 1, '2025-09-19 13:13:00', 'Parent'),
(9, 8, 'Exam Types', 'exam_types.php', '', 1, '2025-09-19 13:13:30', 'Child'),
(10, 8, 'Arrange Exam', 'arrange_exam.php', '', 1, '2025-09-19 13:25:09', 'Child'),
(11, 8, 'Make Datesheet', 'datesheet.php', '', 1, '2025-09-19 13:26:14', 'Child'),
(12, 4, 'Assign Subjects', 'subject_class.php', '', 1, '2025-09-19 13:36:23', 'Child'),
(13, NULL, 'Attendance', '#', '', 1, '2025-09-19 14:20:11', 'Parent'),
(14, 13, 'Class Attendance', 'attendance_class.php', '', 1, '2025-09-19 14:22:13', 'Child'),
(15, 13, 'All Attendance', 'attendance.php', '', 1, '2025-09-19 14:22:32', 'Child');

-- --------------------------------------------------------

--
-- Table structure for table `payment_receipts`
--

CREATE TABLE `payment_receipts` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `receipt_number` varchar(255) NOT NULL,
  `dated` datetime NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `payment_receipts`
--

INSERT INTO `payment_receipts` (`id`, `payment_id`, `receipt_number`, `dated`, `status`) VALUES
(1, 1, 'RCPT-20250915-00001', '2025-09-15 18:04:06', 0),
(2, 8, 'RCPT-20250915-00008', '2025-09-15 22:05:15', 0);

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE `results` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `arrange_exam_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `sub_subject_id` int(11) DEFAULT 0,
  `marks` decimal(5,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `results`
--

INSERT INTO `results` (`id`, `student_id`, `arrange_exam_id`, `subject_id`, `sub_subject_id`, `marks`, `created_at`, `updated_at`) VALUES
(1, 1, 12, 1, 0, 99.00, '2025-09-06 19:43:14', '2025-09-08 10:43:50'),
(2, 1, 12, 6, 9, 50.00, '2025-09-06 19:43:23', '2025-09-08 10:42:21'),
(4, 1, 12, 6, 10, 3.00, '2025-09-06 19:58:25', '2025-09-06 19:58:25'),
(5, 1, 12, 7, 11, 33.00, '2025-09-06 21:26:07', '2025-09-06 21:26:07'),
(6, 1, 12, 7, 12, 9.00, '2025-09-06 21:26:08', '2025-09-06 21:26:08'),
(7, 1, 12, 5, 0, 44.00, '2025-09-06 22:11:09', '2025-09-08 10:43:59'),
(8, 1, 12, 4, 0, 98.00, '2025-09-08 11:32:50', '2025-09-08 11:32:50'),
(9, 1, 12, 3, 7, 67.00, '2025-09-08 11:33:05', '2025-09-08 11:33:05'),
(10, 1, 12, 3, 8, 15.00, '2025-09-08 11:33:05', '2025-09-08 11:33:05'),
(11, 1, 12, 8, 0, 33.00, '2025-09-08 11:45:20', '2025-09-08 11:45:20'),
(12, 1, 12, 2, 6, 77.00, '2025-09-08 11:45:31', '2025-09-08 11:45:31'),
(13, 1, 12, 9, 0, 76.00, '2025-09-08 11:45:49', '2025-09-08 11:45:49'),
(14, 4, 12, 6, 9, 72.00, '2025-09-08 20:11:37', '2025-09-08 20:11:48'),
(15, 4, 12, 6, 10, 10.00, '2025-09-08 20:11:37', '2025-09-08 20:11:37'),
(16, 4, 12, 7, 11, 50.00, '2025-09-08 20:12:05', '2025-09-08 20:12:05'),
(17, 4, 12, 7, 12, 12.00, '2025-09-08 20:12:05', '2025-09-08 20:12:05'),
(18, 4, 12, 9, 0, 66.00, '2025-09-08 20:12:16', '2025-09-08 20:12:16'),
(19, 4, 12, 2, 6, 34.00, '2025-09-08 20:12:30', '2025-09-08 20:12:30'),
(20, 4, 12, 8, 0, 45.00, '2025-09-08 20:12:51', '2025-09-08 20:12:51'),
(21, 4, 12, 1, 0, 7.00, '2025-09-08 20:12:59', '2025-09-08 20:47:33'),
(22, 4, 12, 5, 0, 45.00, '2025-09-08 20:13:06', '2025-09-08 20:13:06'),
(23, 4, 12, 3, 7, 70.00, '2025-09-08 20:13:22', '2025-09-08 20:13:22'),
(24, 4, 12, 3, 8, 20.00, '2025-09-08 20:13:22', '2025-09-08 20:13:22'),
(25, 4, 12, 4, 0, 55.00, '2025-09-08 20:13:31', '2025-09-08 20:13:31'),
(26, 4, 12, 10, 0, 67.00, '2025-09-09 08:35:49', '2025-09-09 08:35:49'),
(27, 1, 12, 10, 0, 78.00, '2025-09-09 08:35:49', '2025-09-09 08:36:00');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0,
  `can_view` tinyint(1) DEFAULT 0,
  `can_add` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_delete` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `title`, `dated`, `status`, `can_view`, `can_add`, `can_edit`, `can_delete`) VALUES
(1, 'Admin', '2025-05-20', 0, 1, 1, 1, 1),
(2, 'User', '2025-05-20', 0, 1, 0, 0, 0),
(3, 'Teacher', '2025-09-17', 1, 0, 0, 0, 0),
(4, 'Student', '2025-09-17', 1, 1, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `role_modules`
--

CREATE TABLE `role_modules` (
  `id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `can_view` tinyint(1) DEFAULT 1,
  `can_add` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_delete` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `role_modules`
--

INSERT INTO `role_modules` (`id`, `role_id`, `module_id`, `can_view`, `can_add`, `can_edit`, `can_delete`) VALUES
(9, 4, 4, 1, 0, 0, 0),
(10, 4, 5, 1, 0, 0, 0),
(42, 1, 8, 1, 1, 1, 1),
(43, 1, 10, 1, 1, 0, 0),
(44, 1, 9, 1, 1, 1, 1),
(45, 1, 4, 1, 0, 0, 0),
(46, 1, 12, 1, 0, 0, 0),
(47, 1, 5, 1, 1, 1, 0),
(48, 1, 2, 1, 1, 1, 0),
(49, 1, 6, 1, 0, 0, 0),
(50, 1, 7, 1, 1, 1, 1),
(58, 3, 13, 1, 1, 0, 0),
(59, 3, 14, 1, 0, 0, 0),
(60, 3, 8, 1, 0, 0, 0),
(61, 3, 10, 1, 0, 0, 0),
(62, 3, 9, 1, 0, 0, 0),
(63, 3, 11, 1, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `title` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `title`) VALUES
(1, 'A'),
(2, 'B'),
(3, 'C'),
(4, 'D');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `from_dated` date DEFAULT current_timestamp(),
  `to_dated` date NOT NULL DEFAULT current_timestamp(),
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `title`, `from_dated`, `to_dated`, `dated`, `status`) VALUES
(1, '2025-26', '2025-06-14', '2026-06-14', '2025-06-14', 0);

-- --------------------------------------------------------

--
-- Table structure for table `student_class`
--

CREATE TABLE `student_class` (
  `id` int(11) NOT NULL,
  `student_registration_id` int(11) DEFAULT NULL,
  `session_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL COMMENT 'PK OF class_section_id',
  `promotion_date` date DEFAULT NULL,
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_class`
--

INSERT INTO `student_class` (`id`, `student_registration_id`, `session_id`, `class_id`, `promotion_date`, `dated`, `status`) VALUES
(1, 1, 1, 13, '2025-09-04', '2025-09-04', 0),
(2, 2, 1, 2, '2025-09-04', '2025-09-04', 0),
(3, 3, 1, 14, '2025-09-04', '2025-09-05', 0),
(4, 4, 1, 13, '2025-09-08', '2025-09-09', 0),
(5, 5, 1, 13, '2025-09-16', '2025-09-16', 0);

-- --------------------------------------------------------

--
-- Table structure for table `student_fee_card`
--

CREATE TABLE `student_fee_card` (
  `id` int(11) NOT NULL,
  `student_class_id` int(11) NOT NULL,
  `fee_type_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','partial','paid') DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `dated` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_fee_card`
--

INSERT INTO `student_fee_card` (`id`, `student_class_id`, `fee_type_id`, `total_amount`, `due_date`, `status`, `remarks`, `dated`) VALUES
(1, 1, 1, 600.00, '2025-09-30', 'pending', 'Monthly fee for September 2025', '2025-09-10 15:36:49'),
(2, 1, 2, 200.00, '2025-09-30', 'pending', 'Monthly fee for September 2025', '2025-09-10 15:36:49'),
(3, 3, 1, 600.00, '2025-09-30', 'paid', 'Monthly fee for September 2025', '2025-09-10 15:36:49'),
(4, 3, 2, 200.00, '2025-09-30', 'paid', 'Monthly fee for September 2025', '2025-09-10 15:36:49'),
(5, 4, 1, 600.00, '2025-09-30', 'paid', 'Monthly fee for September 2025', '2025-09-10 15:36:49'),
(6, 4, 2, 200.00, '2025-09-30', 'paid', 'Monthly fee for September 2025', '2025-09-10 15:36:49'),
(7, 1, 3, 100.00, '2025-09-30', 'pending', 'Monthly fee for September 2025', '2025-09-13 15:26:05'),
(8, 3, 3, 100.00, '2025-09-30', 'paid', 'Monthly fee for September 2025', '2025-09-13 15:26:05'),
(9, 4, 3, 100.00, '2025-09-30', 'paid', 'Monthly fee for September 2025', '2025-09-13 15:26:05'),
(10, 1, 1, 600.00, '2025-10-31', 'pending', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(11, 1, 2, 200.00, '2025-10-31', 'pending', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(12, 1, 3, 100.00, '2025-10-31', 'pending', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(13, 3, 1, 600.00, '2025-10-31', 'pending', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(14, 3, 2, 200.00, '2025-10-31', 'pending', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(15, 3, 3, 100.00, '2025-10-31', 'pending', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(16, 4, 1, 600.00, '2025-10-31', 'pending', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(17, 4, 2, 200.00, '2025-10-31', 'paid', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(18, 4, 3, 100.00, '2025-10-31', 'paid', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(19, 5, 1, 600.00, '2025-10-31', 'pending', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(20, 5, 2, 200.00, '2025-10-31', 'paid', 'Monthly fee for October 2025', '2025-09-17 20:22:32'),
(21, 5, 3, 100.00, '2025-10-31', 'pending', 'Monthly fee for October 2025', '2025-09-17 20:22:32');

-- --------------------------------------------------------

--
-- Table structure for table `student_fee_payments`
--

CREATE TABLE `student_fee_payments` (
  `id` int(11) NOT NULL,
  `fee_card_id` int(11) NOT NULL,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_ref` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_fee_payments`
--

INSERT INTO `student_fee_payments` (`id`, `fee_card_id`, `paid_amount`, `payment_date`, `payment_method`, `transaction_ref`, `remarks`, `status`) VALUES
(1, 6, 200.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(2, 17, 200.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(3, 5, 600.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(4, 9, 100.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(5, 18, 100.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(6, 6, 200.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(7, 17, 200.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(8, 5, 600.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(9, 9, 100.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(10, 18, 100.00, '2025-09-17', 'cash', NULL, 'fdahkdhfak', '0'),
(11, 20, 200.00, '2025-09-17', 'cash', NULL, 'exam fee', '0');

-- --------------------------------------------------------

--
-- Table structure for table `student_registration`
--

CREATE TABLE `student_registration` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 1,
  `village_council_id` int(11) DEFAULT NULL,
  `reg_no` varchar(255) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `cnic` varchar(20) DEFAULT NULL,
  `current_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `guardian_name` varchar(100) DEFAULT NULL,
  `guardian_mobile` varchar(20) DEFAULT NULL,
  `guardian_address` text DEFAULT NULL,
  `guardian_cnic` varchar(20) DEFAULT NULL,
  `previous_schools_description` text DEFAULT NULL,
  `student_other_description` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `registration_date` date NOT NULL DEFAULT current_timestamp(),
  `is_transport` int(11) NOT NULL DEFAULT 1,
  `transport_fee` decimal(10,2) DEFAULT 0.00,
  `is_old_dues` int(11) NOT NULL DEFAULT 1,
  `old_dues_amount` int(11) NOT NULL DEFAULT 0,
  `is_admission` int(11) NOT NULL DEFAULT 1,
  `dated` date DEFAULT NULL,
  `status` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_registration`
--

INSERT INTO `student_registration` (`id`, `branch_id`, `village_council_id`, `reg_no`, `name`, `dob`, `father_name`, `mobile`, `cnic`, `current_address`, `permanent_address`, `guardian_name`, `guardian_mobile`, `guardian_address`, `guardian_cnic`, `previous_schools_description`, `student_other_description`, `image_path`, `registration_date`, `is_transport`, `transport_fee`, `is_old_dues`, `old_dues_amount`, `is_admission`, `dated`, `status`) VALUES
(1, 1, 1, '2372', 'khan', '2005-10-23', 'Dada', '123456', '1560324534533', 'ddd', 'sas', 'Khan saib', '33333333333', '', '2222222222222', 'fsdf', 'hhh', '', '2025-09-04', 1, 0.00, 0, 0, 1, NULL, 0),
(2, 1, 1, '2', 'lala g', '2008-05-11', 'Dada', '77', '1560324534533', 'sadfasdjf', 'asdfdasfasd', 'Khan saib', '33333333333', '', '2121333333333', 'asdf', 'asdfadsf', '', '2025-09-04', 1, 0.00, 0, 0, 1, NULL, 0),
(3, 1, 1, '3', 'Hassan', '2003-02-23', 'Gul khan', '11', '4512451234513', 'kk', 'SS', 'sami', '32342312222', '', '2124325221321', 'EWR', 'DSF', '', '2025-09-04', 1, 0.00, 0, 0, 1, NULL, 0),
(4, 1, 2, '5594', 'Afaq khan', '2001-09-21', 'Bahir khan', '000', '1560432342212', 'Swat', 'Swat ', 'Shaheer', '03424892322', '', '1560452342432', 'no', 'no', '', '2025-09-08', 1, 0.00, 0, 0, 1, NULL, 0),
(5, 1, 2, '55', 'iQBAL SHAH', '2001-06-20', 'TAHIR', '234', '1560324234324', '234WERWEREWRWE2342', '23424234EWR', 'gul haji', '32423325324', '', '3333333332222', 'DSFSDF', 'SDFS', '', '2025-09-16', 1, 0.00, 0, 0, 1, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `type` enum('standalone','composite','') NOT NULL,
  `total_marks` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `title`, `type`, `total_marks`, `created_at`, `status`) VALUES
(1, 'Maths', 'standalone', 100, '2025-08-31 15:10:50', 0),
(2, 'English', 'composite', 0, '2025-08-31 15:11:38', 0),
(3, 'Physics', 'composite', 0, '2025-08-31 15:41:26', 0),
(4, 'Urdu', 'standalone', 100, '2025-08-31 16:15:48', 0),
(5, 'Pak studies', 'standalone', 50, '2025-08-31 16:16:16', 0),
(6, 'Biology', 'composite', 0, '2025-08-31 16:16:43', 0),
(7, 'Chemistry', 'composite', 0, '2025-08-31 16:18:09', 0),
(8, 'Islamyait', 'standalone', 50, '2025-09-08 16:43:14', 0),
(9, 'Drawing', 'standalone', 100, '2025-09-08 16:44:29', 0),
(10, 'HPE', 'standalone', 100, '2025-09-09 13:33:09', 0);

-- --------------------------------------------------------

--
-- Table structure for table `subject_class`
--

CREATE TABLE `subject_class` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `marks` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `subject_class`
--

INSERT INTO `subject_class` (`id`, `class_id`, `subject_id`, `marks`, `created_at`, `status`) VALUES
(102, 6, 1, 100, '2025-09-09 13:34:57', 0),
(103, 6, 2, 0, '2025-09-09 13:34:57', 0),
(104, 6, 3, 0, '2025-09-09 13:34:57', 0),
(105, 6, 4, 100, '2025-09-09 13:34:57', 0),
(106, 6, 5, 50, '2025-09-09 13:34:57', 0),
(107, 6, 6, 0, '2025-09-09 13:34:57', 0),
(108, 6, 7, 0, '2025-09-09 13:34:57', 0),
(109, 6, 8, 50, '2025-09-09 13:34:57', 0),
(110, 6, 9, 100, '2025-09-09 13:34:57', 0),
(111, 6, 10, 100, '2025-09-09 13:34:57', 0),
(112, 2, 1, 100, '2025-09-09 13:35:08', 0),
(113, 2, 2, 0, '2025-09-09 13:35:08', 0),
(114, 2, 3, 0, '2025-09-09 13:35:08', 0),
(115, 2, 4, 100, '2025-09-09 13:35:08', 0),
(116, 2, 5, 50, '2025-09-09 13:35:08', 0),
(117, 2, 6, 0, '2025-09-09 13:35:08', 0),
(118, 2, 7, 0, '2025-09-09 13:35:08', 0),
(119, 2, 8, 50, '2025-09-09 13:35:08', 0),
(120, 2, 9, 100, '2025-09-09 13:35:08', 0),
(121, 2, 10, 100, '2025-09-09 13:35:08', 0);

-- --------------------------------------------------------

--
-- Table structure for table `subject_class_sub`
--

CREATE TABLE `subject_class_sub` (
  `id` int(11) NOT NULL,
  `subject_class_id` int(11) DEFAULT NULL,
  `sub_subject_id` int(11) DEFAULT NULL,
  `marks` float DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `subject_class_sub`
--

INSERT INTO `subject_class_sub` (`id`, `subject_class_id`, `sub_subject_id`, `marks`, `created_at`, `status`) VALUES
(87, 103, 6, 100, '2025-09-09 13:34:57', 0),
(88, 104, 7, 75, '2025-09-09 13:34:57', 0),
(89, 104, 8, 25, '2025-09-09 13:34:57', 0),
(90, 107, 9, 75, '2025-09-09 13:34:57', 0),
(91, 107, 10, 25, '2025-09-09 13:34:57', 0),
(92, 108, 11, 75, '2025-09-09 13:34:57', 0),
(93, 108, 12, 25, '2025-09-09 13:34:57', 0),
(94, 113, 6, 100, '2025-09-09 13:35:08', 0),
(95, 114, 7, 75, '2025-09-09 13:35:08', 0),
(96, 114, 8, 25, '2025-09-09 13:35:08', 0),
(97, 117, 9, 75, '2025-09-09 13:35:08', 0),
(98, 117, 10, 25, '2025-09-09 13:35:08', 0),
(99, 118, 11, 75, '2025-09-09 13:35:08', 0),
(100, 118, 12, 25, '2025-09-09 13:35:08', 0);

-- --------------------------------------------------------

--
-- Table structure for table `sub_subjects`
--

CREATE TABLE `sub_subjects` (
  `id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `marks` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `sub_subjects`
--

INSERT INTO `sub_subjects` (`id`, `subject_id`, `title`, `marks`, `created_at`, `status`) VALUES
(6, 2, 'English 1', 50, '2025-08-31 15:16:26', 0),
(7, 3, 'Physcis', 75, '2025-08-31 15:41:26', 0),
(8, 3, 'Practical', 25, '2025-08-31 15:41:26', 0),
(9, 6, 'Biology', 75, '2025-08-31 16:16:43', 0),
(10, 6, 'Practical', 25, '2025-08-31 16:16:43', 0),
(11, 7, 'Chemistry', 75, '2025-08-31 16:18:09', 0),
(12, 7, 'Practical', 25, '2025-08-31 16:18:09', 0);

-- --------------------------------------------------------

--
-- Table structure for table `teacher_classes`
--

CREATE TABLE `teacher_classes` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_section_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `teacher_classes`
--

INSERT INTO `teacher_classes` (`id`, `teacher_id`, `class_section_id`) VALUES
(3, 17, 13),
(4, 17, 14),
(5, 17, 15),
(6, 18, 7),
(7, 18, 8),
(8, 16, 13),
(9, 16, 14);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL DEFAULT 2,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `mobile_no` varchar(11) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `status` int(11) NOT NULL DEFAULT 0,
  `village_council_id` int(11) DEFAULT NULL,
  `dated` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `role_id`, `username`, `password`, `mobile_no`, `email`, `status`, `village_council_id`, `dated`) VALUES
(1, 1, 'habib', 'khan', NULL, NULL, 0, NULL, '2025-09-17 11:28:48'),
(8, 2, 'hanzala', 'khan', '0234234342', 'asifshah9530608@gmail.com', 0, 2, '2025-09-17 11:28:48'),
(16, 3, 'Teacher 1', '123', '03423434234', 'asif@gmail.com', 0, 2, '2025-09-17 11:29:56'),
(17, 3, 'Teacher 2', '343', '04323435435', 'asifshah9530608@gmail.com', 0, 2, '2025-09-17 11:58:00'),
(18, 3, 'Teacher', '00', '03424232325', 'asifshah9530608@gmail.com', 0, 2, '2025-09-17 12:07:39');

-- --------------------------------------------------------

--
-- Table structure for table `user_attendance`
--

CREATE TABLE `user_attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('P','L') NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `dated` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `user_attendance`
--

INSERT INTO `user_attendance` (`id`, `user_id`, `status`, `remarks`, `dated`) VALUES
(28, 1, 'P', NULL, '2025-09-19 00:00:00'),
(29, 8, 'P', NULL, '2025-09-19 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `village_councils`
--

CREATE TABLE `village_councils` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `transport_fee` decimal(10,2) DEFAULT 0.00,
  `dated` date NOT NULL DEFAULT current_timestamp(),
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `village_councils`
--

INSERT INTO `village_councils` (`id`, `title`, `transport_fee`, `dated`, `status`) VALUES
(1, 'Monthl', 0.00, '2025-08-27', 0),
(2, 'Matta', 0.00, '2025-09-06', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `accounts_details`
--
ALTER TABLE `accounts_details`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `arrange_exam`
--
ALTER TABLE `arrange_exam`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`,`date`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `class_fee_types`
--
ALTER TABLE `class_fee_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `class_sections`
--
ALTER TABLE `class_sections`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `course_fee`
--
ALTER TABLE `course_fee`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `exam_datesheet`
--
ALTER TABLE `exam_datesheet`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `exam_types`
--
ALTER TABLE `exam_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expenses_details`
--
ALTER TABLE `expenses_details`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expenses_master`
--
ALTER TABLE `expenses_master`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expenses_payments`
--
ALTER TABLE `expenses_payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expense_heads`
--
ALTER TABLE `expense_heads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fee_types`
--
ALTER TABLE `fee_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `results`
--
ALTER TABLE `results`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `role_modules`
--
ALTER TABLE `role_modules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_class`
--
ALTER TABLE `student_class`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_fee_card`
--
ALTER TABLE `student_fee_card`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_fee_payments`
--
ALTER TABLE `student_fee_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fee_card_id` (`fee_card_id`);

--
-- Indexes for table `student_registration`
--
ALTER TABLE `student_registration`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reg_no` (`reg_no`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `subject_class`
--
ALTER TABLE `subject_class`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `subject_class_sub`
--
ALTER TABLE `subject_class_sub`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subject_class_id` (`subject_class_id`),
  ADD KEY `sub_subject_id` (`sub_subject_id`);

--
-- Indexes for table `sub_subjects`
--
ALTER TABLE `sub_subjects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `teacher_classes`
--
ALTER TABLE `teacher_classes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_attendance`
--
ALTER TABLE `user_attendance`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `village_councils`
--
ALTER TABLE `village_councils`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `accounts_details`
--
ALTER TABLE `accounts_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `arrange_exam`
--
ALTER TABLE `arrange_exam`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `class_fee_types`
--
ALTER TABLE `class_fee_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `class_sections`
--
ALTER TABLE `class_sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `course_fee`
--
ALTER TABLE `course_fee`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_datesheet`
--
ALTER TABLE `exam_datesheet`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `exam_types`
--
ALTER TABLE `exam_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `expenses_details`
--
ALTER TABLE `expenses_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses_master`
--
ALTER TABLE `expenses_master`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses_payments`
--
ALTER TABLE `expenses_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_heads`
--
ALTER TABLE `expense_heads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fee_types`
--
ALTER TABLE `fee_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `role_modules`
--
ALTER TABLE `role_modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student_class`
--
ALTER TABLE `student_class`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `student_fee_card`
--
ALTER TABLE `student_fee_card`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `student_fee_payments`
--
ALTER TABLE `student_fee_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `student_registration`
--
ALTER TABLE `student_registration`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `subject_class`
--
ALTER TABLE `subject_class`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=122;

--
-- AUTO_INCREMENT for table `subject_class_sub`
--
ALTER TABLE `subject_class_sub`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=101;

--
-- AUTO_INCREMENT for table `sub_subjects`
--
ALTER TABLE `sub_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `teacher_classes`
--
ALTER TABLE `teacher_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `user_attendance`
--
ALTER TABLE `user_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `village_councils`
--
ALTER TABLE `village_councils`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
