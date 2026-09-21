-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 21, 2026 at 01:45 PM
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
-- Database: `kms`
--

-- --------------------------------------------------------

--
-- Table structure for table `cc_certification_engagements`
--

CREATE TABLE `cc_certification_engagements` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `engagement_type` enum('Part-time','OJT/Training') NOT NULL,
  `title` varchar(150) NOT NULL,
  `organization` varchar(150) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('Pending','Ongoing','For Review','Approved','Completed','Archived') NOT NULL DEFAULT 'Pending',
  `outcome` enum('Continue','Regularize','End Engagement','Not Applicable') NOT NULL DEFAULT 'Not Applicable',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `certificate_generated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cc_events`
--

CREATE TABLE `cc_events` (
  `event_id` int(11) NOT NULL,
  `template_id` int(11) DEFAULT NULL,
  `event_title` varchar(255) NOT NULL,
  `event_type` enum('Academic','Meeting','Seminar','Institutional Event','Cultural Event','Sports Event','Orientation','Other') NOT NULL,
  `event_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `target_audience` varchar(100) DEFAULT 'All Students',
  `status` enum('upcoming','ongoing','completed','cancelled') NOT NULL DEFAULT 'upcoming',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cc_event_templates`
--

CREATE TABLE `cc_event_templates` (
  `template_id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `event_type` enum('Academic','Meeting','Seminar','Institutional Event','Cultural Event','Sports Event','Orientation','Other') NOT NULL,
  `default_title` varchar(255) NOT NULL,
  `default_description` text DEFAULT NULL,
  `default_location` varchar(255) DEFAULT NULL,
  `default_target_audience` varchar(100) DEFAULT NULL,
  `default_status` enum('upcoming','ongoing','completed','cancelled') DEFAULT 'upcoming',
  `priority` enum('Normal','High','Urgent') DEFAULT 'Normal',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cc_exams`
--

CREATE TABLE `cc_exams` (
  `id` int(11) NOT NULL,
  `exam_name` varchar(100) NOT NULL,
  `exam_type` enum('Preliminary','Midterm','Final','Special') NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('Draft','Scheduled','Ongoing','Completed','Cancelled') DEFAULT 'Draft',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cc_exam_proctor`
--

CREATE TABLE `cc_exam_proctor` (
  `id` int(11) NOT NULL,
  `exam_schedule_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `role` enum('Lead Proctor','Proctor','Reliever') DEFAULT 'Proctor',
  `status` enum('Assigned','Confirmed','Completed','Cancelled') DEFAULT 'Assigned',
  `assigned_at` datetime DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cc_exam_schedule`
--

CREATE TABLE `cc_exam_schedule` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `schedule_type` enum('Exam','Break Time') NOT NULL DEFAULT 'Exam',
  `subject_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `exam_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('Scheduled','Ongoing','Completed','Cancelled') DEFAULT 'Scheduled',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cc_faculty`
--

CREATE TABLE `cc_faculty` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `faculty_code` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `max_load` int(11) NOT NULL DEFAULT 15,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cc_faculty`
--

INSERT INTO `cc_faculty` (`id`, `employee_id`, `faculty_code`, `first_name`, `middle_name`, `last_name`, `email`, `department`, `max_load`, `created_at`) VALUES
(1, NULL, 'FAC-001', 'Juan', 'Dela', 'Cruz', 'juan.delacruz@bestlink.edu.ph', 'Information Systems', 30, '2026-09-16 16:29:13');

-- --------------------------------------------------------

--
-- Table structure for table `cc_faculty_load`
--

CREATE TABLE `cc_faculty_load` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cc_faculty_load`
--

INSERT INTO `cc_faculty_load` (`id`, `faculty_id`, `section_id`, `subject_id`, `school_year_id`, `semester_id`, `created_at`) VALUES
(1, 1, 1, 1, 1, 1, '2026-09-16 16:29:13'),
(2, 1, 1, 2, 1, 1, '2026-09-16 16:29:13'),
(3, 1, 2, 3, 1, 2, '2026-09-16 16:29:13'),
(4, 1, 2, 4, 1, 2, '2026-09-16 16:29:13'),
(5, 1, 3, 5, 1, 1, '2026-09-16 16:29:13'),
(6, 1, 3, 6, 1, 1, '2026-09-16 16:29:13'),
(7, 1, 4, 7, 1, 2, '2026-09-16 16:29:13'),
(8, 1, 4, 8, 1, 2, '2026-09-16 16:29:13'),
(9, 1, 5, 9, 1, 1, '2026-09-16 16:29:13'),
(10, 1, 5, 10, 1, 1, '2026-09-16 16:29:13'),
(11, 1, 6, 11, 1, 2, '2026-09-16 16:29:13'),
(12, 1, 6, 12, 1, 2, '2026-09-16 16:29:13'),
(13, 1, 7, 13, 1, 1, '2026-09-16 16:29:13'),
(14, 1, 7, 14, 1, 1, '2026-09-16 16:29:13'),
(15, 1, 8, 15, 1, 2, '2026-09-16 16:29:13'),
(16, 1, 8, 16, 1, 2, '2026-09-16 16:29:13');

-- --------------------------------------------------------

--
-- Table structure for table `cc_faculty_load_summary`
--

CREATE TABLE `cc_faculty_load_summary` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `total_units` int(11) NOT NULL DEFAULT 0,
  `max_load` int(11) NOT NULL,
  `load_status` enum('Underloaded','Normal Load','Overloaded') NOT NULL,
  `computed_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cc_room`
--

CREATE TABLE `cc_room` (
  `id` int(11) NOT NULL,
  `room_code` varchar(20) NOT NULL,
  `room_name` varchar(100) NOT NULL,
  `building` varchar(100) DEFAULT 'Main Building',
  `floor` enum('1st Floor','2nd Floor','3rd Floor','4th Floor') NOT NULL,
  `room_type` enum('Lecture Room','Computer Laboratory','Science Laboratory','Library','Office','AVR','Court Room','Other') NOT NULL,
  `capacity` int(11) DEFAULT 40,
  `status` enum('Available','Maintenance','Unavailable') DEFAULT 'Available',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cc_room`
--

INSERT INTO `cc_room` (`id`, `room_code`, `room_name`, `building`, `floor`, `room_type`, `capacity`, `status`, `created_at`) VALUES
(1, 'RM-101', 'IT Lecture Room 1', 'Main Building', '1st Floor', 'Lecture Room', 40, 'Available', '2026-09-16 16:29:13'),
(2, 'CL-201', 'Computer Laboratory 1', 'IT Building', '2nd Floor', 'Computer Laboratory', 40, 'Available', '2026-09-16 16:29:13');

-- --------------------------------------------------------

--
-- Table structure for table `cc_schedule`
--

CREATE TABLE `cc_schedule` (
  `id` int(11) NOT NULL,
  `faculty_load_id` int(11) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `schedule_type` enum('Class','Break Time') DEFAULT 'Class',
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `status` enum('Scheduled','Completed','Cancelled') DEFAULT 'Scheduled',
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `faculty_id` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `school_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cc_schedule`
--

INSERT INTO `cc_schedule` (`id`, `faculty_load_id`, `room_id`, `schedule_type`, `start_time`, `end_time`, `status`, `day_of_week`, `section_id`, `faculty_id`, `subject_id`, `school_year_id`, `semester_id`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Class', '07:30:00', '09:00:00', 'Scheduled', 'Monday', 1, 1, 1, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(2, 1, 1, 'Class', '07:30:00', '09:00:00', 'Scheduled', 'Wednesday', 1, 1, 1, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(3, 2, 2, 'Class', '09:15:00', '10:45:00', 'Scheduled', 'Monday', 1, 1, 2, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(4, 2, 2, 'Class', '09:15:00', '10:45:00', 'Scheduled', 'Wednesday', 1, 1, 2, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(5, 3, 1, 'Class', '07:30:00', '09:00:00', 'Scheduled', 'Tuesday', 2, 1, 3, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(6, 3, 1, 'Class', '07:30:00', '09:00:00', 'Scheduled', 'Thursday', 2, 1, 3, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(7, 4, 2, 'Class', '09:15:00', '10:45:00', 'Scheduled', 'Tuesday', 2, 1, 4, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(8, 4, 2, 'Class', '09:15:00', '10:45:00', 'Scheduled', 'Thursday', 2, 1, 4, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(9, 5, 1, 'Class', '10:00:00', '11:30:00', 'Scheduled', 'Monday', 3, 1, 5, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(10, 5, 1, 'Class', '10:00:00', '11:30:00', 'Scheduled', 'Wednesday', 3, 1, 5, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(11, 6, 2, 'Class', '13:00:00', '14:30:00', 'Scheduled', 'Monday', 3, 1, 6, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(12, 6, 2, 'Class', '13:00:00', '14:30:00', 'Scheduled', 'Wednesday', 3, 1, 6, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(13, 7, 1, 'Class', '10:00:00', '11:30:00', 'Scheduled', 'Tuesday', 4, 1, 7, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(14, 7, 1, 'Class', '10:00:00', '11:30:00', 'Scheduled', 'Thursday', 4, 1, 7, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(15, 8, 2, 'Class', '13:00:00', '14:30:00', 'Scheduled', 'Tuesday', 4, 1, 8, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(16, 8, 2, 'Class', '13:00:00', '14:30:00', 'Scheduled', 'Thursday', 4, 1, 8, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(17, 9, 1, 'Class', '14:45:00', '16:15:00', 'Scheduled', 'Monday', 5, 1, 9, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(18, 9, 1, 'Class', '14:45:00', '16:15:00', 'Scheduled', 'Wednesday', 5, 1, 9, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(19, 10, 2, 'Class', '16:30:00', '18:00:00', 'Scheduled', 'Monday', 5, 1, 10, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(20, 10, 2, 'Class', '16:30:00', '18:00:00', 'Scheduled', 'Wednesday', 5, 1, 10, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(21, 11, 1, 'Class', '14:45:00', '16:15:00', 'Scheduled', 'Tuesday', 6, 1, 11, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(22, 11, 1, 'Class', '14:45:00', '16:15:00', 'Scheduled', 'Thursday', 6, 1, 11, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(23, 12, 2, 'Class', '16:30:00', '18:00:00', 'Scheduled', 'Tuesday', 6, 1, 12, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(24, 12, 2, 'Class', '16:30:00', '18:00:00', 'Scheduled', 'Thursday', 6, 1, 12, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(25, 13, 1, 'Class', '08:00:00', '10:00:00', 'Scheduled', 'Friday', 7, 1, 13, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(26, 13, 1, 'Class', '08:00:00', '10:00:00', 'Scheduled', 'Saturday', 7, 1, 13, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(27, 14, 2, 'Class', '10:15:00', '12:15:00', 'Scheduled', 'Friday', 7, 1, 14, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(28, 14, 2, 'Class', '10:15:00', '12:15:00', 'Scheduled', 'Saturday', 7, 1, 14, 1, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(29, 15, 1, 'Class', '08:00:00', '10:00:00', 'Scheduled', 'Friday', 8, 1, 15, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(30, 15, 1, 'Class', '08:00:00', '10:00:00', 'Scheduled', 'Saturday', 8, 1, 15, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(31, 16, 2, 'Class', '10:15:00', '12:15:00', 'Scheduled', 'Friday', 8, 1, 16, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(32, 16, 2, 'Class', '10:15:00', '12:15:00', 'Scheduled', 'Saturday', 8, 1, 16, 1, 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13');

-- --------------------------------------------------------

--
-- Table structure for table `cc_sections`
--

CREATE TABLE `cc_sections` (
  `id` int(11) NOT NULL,
  `section_code` varchar(50) DEFAULT NULL,
  `grade_level` varchar(50) DEFAULT NULL,
  `program_id` int(11) DEFAULT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `adviser_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cc_sections`
--

INSERT INTO `cc_sections` (`id`, `section_code`, `grade_level`, `program_id`, `school_year_id`, `semester_id`, `adviser_id`, `created_at`) VALUES
(1, 'BSIS-1A', '1st Year', 1, 1, 1, NULL, '2026-09-16 16:29:13'),
(2, 'BSIS-1B', '1st Year', 1, 1, 2, NULL, '2026-09-16 16:29:13'),
(3, 'BSIS-2A', '2nd Year', 1, 1, 1, NULL, '2026-09-16 16:29:13'),
(4, 'BSIS-2B', '2nd Year', 1, 1, 2, NULL, '2026-09-16 16:29:13'),
(5, 'BSIS-3A', '3rd Year', 1, 1, 1, NULL, '2026-09-16 16:29:13'),
(6, 'BSIS-3B', '3rd Year', 1, 1, 2, NULL, '2026-09-16 16:29:13'),
(7, 'BSIS-4A', '4th Year', 1, 1, 1, NULL, '2026-09-16 16:29:13'),
(8, 'BSIS-4B', '4th Year', 1, 1, 2, NULL, '2026-09-16 16:29:13');

-- --------------------------------------------------------

--
-- Table structure for table `cc_section_faculty`
--

CREATE TABLE `cc_section_faculty` (
  `id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `school_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `status` enum('Active','Completed','Reassigned','Cancelled') NOT NULL DEFAULT 'Active',
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cc_section_faculty`
--

INSERT INTO `cc_section_faculty` (`id`, `section_id`, `faculty_id`, `role`, `school_year_id`, `semester_id`, `status`, `assigned_at`, `ended_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Adviser', 1, 1, 'Active', '2026-09-16 16:29:14', NULL, '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(2, 3, 1, 'Adviser', 1, 1, 'Active', '2026-09-16 16:29:14', NULL, '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(3, 5, 1, 'Adviser', 1, 1, 'Active', '2026-09-16 16:29:14', NULL, '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(4, 7, 1, 'Adviser', 1, 1, 'Active', '2026-09-16 16:29:14', NULL, '2026-09-16 16:29:14', '2026-09-16 16:29:14');

-- --------------------------------------------------------

--
-- Table structure for table `em_documents`
--

CREATE TABLE `em_documents` (
  `document_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `document_name` varchar(100) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` varchar(50) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `mime_type` varchar(100) DEFAULT NULL,
  `category` varchar(100) DEFAULT 'Other',
  `expiry_date` date DEFAULT NULL,
  `verification_status` enum('Pending','Verified','Rejected','Expired') NOT NULL DEFAULT 'Pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verification_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `em_employees`
--

CREATE TABLE `em_employees` (
  `employee_id` int(11) NOT NULL,
  `employee_number` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `status` enum('Active','Inactive','On Leave','Resigned','Terminated') DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enr_applicants`
--

CREATE TABLE `enr_applicants` (
  `applicant_id` int(11) NOT NULL,
  `surname` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `admission_type` enum('freshmen','transferee','returnee','senior_high') DEFAULT NULL,
  `working_student` enum('Yes','No') DEFAULT NULL,
  `sex` enum('Male','Female','Other') NOT NULL,
  `address_barangay` varchar(100) NOT NULL,
  `address_city` varchar(100) NOT NULL,
  `address_province` varchar(100) NOT NULL,
  `address_complete` text DEFAULT NULL,
  `school_last_attended` varchar(150) NOT NULL,
  `year_graduated` varchar(20) DEFAULT NULL,
  `how_hear` varchar(50) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `date_of_birth` date NOT NULL,
  `place_of_birth` varchar(150) NOT NULL,
  `age` int(11) DEFAULT NULL,
  `civil_status` enum('Single','Married','Divorced','Widowed') NOT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) NOT NULL,
  `facebook` varchar(100) DEFAULT NULL,
  `messenger` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `parent_full_name` varchar(150) NOT NULL,
  `parent_contact` varchar(20) DEFAULT NULL,
  `parent_address` text DEFAULT NULL,
  `course_id` int(10) DEFAULT NULL,
  `preferred_section_id` int(11) DEFAULT NULL,
  `status` enum('pending','converted','rejected') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enr_applicants`
--

INSERT INTO `enr_applicants` (`applicant_id`, `surname`, `first_name`, `middle_name`, `suffix`, `admission_type`, `working_student`, `sex`, `address_barangay`, `address_city`, `address_province`, `address_complete`, `school_last_attended`, `year_graduated`, `how_hear`, `email`, `date_of_birth`, `place_of_birth`, `age`, `civil_status`, `religion`, `contact_number`, `facebook`, `messenger`, `address`, `parent_full_name`, `parent_contact`, `parent_address`, `course_id`, `preferred_section_id`, `status`, `notes`, `submitted_at`, `updated_at`) VALUES
(1, 'Santos', 'Maria', 'Lopez', NULL, 'freshmen', 'No', 'Female', 'Barangay San Roque', 'Quezon City', 'Metro Manila', '123 San Roque St., Quezon City', 'San Roque National High School', '2026', 'social_media', 'maria.santos@example.com', '2008-03-15', 'Quezon City', 18, 'Single', 'Catholic', '09171234567', 'maria.santos', 'maria.santos', '123 San Roque St., QC', 'Pedro Santos', '09181234567', '123 San Roque St., QC', 1, 1, 'converted', NULL, '2026-09-16 16:29:13', '2026-09-16 16:41:54'),
(2, 'Reyes', 'Jose', 'Cruz', 'Jr.', 'transferee', 'Yes', 'Male', 'Barangay Poblacion', 'Makati City', 'Metro Manila', '456 Poblacion Ave., Makati', 'Makati Science High School', '2025', 'friend', 'jose.reyes@example.com', '2007-08-22', 'Makati City', 19, 'Single', 'Iglesia ni Cristo', '09172345678', 'jose.reyes', 'jose.reyes', '456 Poblacion Ave., Makati', 'Ana Reyes', '09182345678', '456 Poblacion Ave., Makati', 1, 1, 'converted', NULL, '2026-09-16 16:29:13', '2026-09-17 01:12:32'),
(3, 'Cruz', 'Anna', 'Reyes', NULL, 'freshmen', 'No', 'Female', 'Barangay Kalayaan', 'Quezon City', 'Metro Manila', '789 Kalayaan Ave., QC', 'Quezon City High School', '2026', 'social_media', 'anna.cruz@example.com', '2008-01-10', 'Quezon City', 18, 'Single', 'Catholic', '09173456789', 'anna.cruz', 'anna.cruz', '789 Kalayaan Ave., QC', 'Rosa Cruz', '09183456789', '789 Kalayaan Ave., QC', 1, 1, 'converted', NULL, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(4, 'Bautista', 'Mark', 'Villanueva', NULL, 'freshmen', 'No', 'Male', 'Barangay Commonwealth', 'Quezon City', 'Metro Manila', '111 Commonwealth Ave., QC', 'Commonwealth High School', '2025', 'friend', 'mark.bautista@example.com', '2007-05-20', 'Manila', 19, 'Single', 'Christian', '09174567890', 'mark.bautista', 'mark.bautista', '111 Commonwealth Ave., QC', 'Liza Bautista', '09184567890', '111 Commonwealth Ave., QC', 1, 3, 'converted', NULL, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(5, 'Torres', 'Carlo', 'Mendoza', NULL, 'transferee', 'No', 'Male', 'Barangay Sikatuna', 'Quezon City', 'Metro Manila', '222 Sikatuna Village, QC', 'Sikatuna High School', '2024', 'advertisement', 'carlo.torres@example.com', '2006-11-03', 'Quezon City', 20, 'Single', 'Catholic', '09175678901', 'carlo.torres', 'carlo.torres', '222 Sikatuna Village, QC', 'Maria Torres', '09185678901', '222 Sikatuna Village, QC', 1, 5, 'converted', NULL, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(6, 'Ramos', 'Bea', 'Diaz', NULL, 'freshmen', 'No', 'Female', 'Barangay Bagong Pag-asa', 'Quezon City', 'Metro Manila', '333 Bagong Pag-asa, QC', 'Bagong Pag-asa High School', '2022', 'school', 'bea.ramos@example.com', '2005-07-18', 'Quezon City', 21, 'Single', 'Catholic', '09176789012', 'bea.ramos', 'bea.ramos', '333 Bagong Pag-asa, QC', 'Pedro Ramos', '09186789012', '333 Bagong Pag-asa, QC', 1, 7, 'converted', NULL, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(7, 'Villanueva', 'Karla', 'Cruz', NULL, 'freshmen', 'No', 'Female', 'Barangay Batasan', 'Quezon City', 'Metro Manila', '444 Batasan Hills, QC', 'Batasan National High School', '2026', 'friend', 'karla.villanueva@example.com', '2008-02-14', 'Quezon City', 18, 'Single', 'Catholic', '09177890123', 'karla.villanueva', 'karla.villanueva', '444 Batasan Hills, QC', 'Jose Villanueva', '09187890123', '444 Batasan Hills, QC', 1, 1, 'converted', NULL, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(8, 'Elyasen', 'Yazier', 'J', '', 'freshmen', 'No', 'Male', 'adsfadsfaadsfadsf', 'adsfadsfadsfads', 'asdfadsf', 'adsfadsf', 'adsfadsfa', '2024-2025', 'social_media', 'yasierelyasin@gmail.com', '2004-05-15', 'Taguig City', 22, 'Single', 'Islam', '09123456789', 'asdhf', 'dasfasdf', NULL, 'yazierhadsfhjadsf', '0912345678', 'dsfgadsf', 1, NULL, 'converted', NULL, '2026-09-17 01:14:58', '2026-09-17 01:29:02');

-- --------------------------------------------------------

--
-- Table structure for table `enr_enrollments`
--

CREATE TABLE `enr_enrollments` (
  `enrollment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `enrollment_date` date NOT NULL,
  `enrollment_status` enum('enrolled','dropped','completed') DEFAULT 'enrolled',
  `academic_standing` varchar(50) DEFAULT 'Good Standing',
  `schedule_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enr_enrollments`
--

INSERT INTO `enr_enrollments` (`enrollment_id`, `student_id`, `section_id`, `school_year`, `semester`, `enrollment_date`, `enrollment_status`, `academic_standing`, `schedule_id`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(2, 1, 1, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 2, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(3, 1, 1, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 3, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(4, 1, 1, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 4, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(5, 2, 1, '2025-2026', '1st Semester', '2025-09-16', 'completed', 'Good Standing', 1, '2025-09-16 16:29:13', '2026-09-16 16:29:13'),
(6, 2, 1, '2025-2026', '1st Semester', '2025-09-16', 'completed', 'Good Standing', 3, '2025-09-16 16:29:13', '2026-09-16 16:29:13'),
(7, 2, 2, '2025-2026', '2nd Semester', '2026-03-16', 'completed', 'Good Standing', 5, '2026-03-16 16:29:13', '2026-09-16 16:29:13'),
(8, 2, 2, '2025-2026', '2nd Semester', '2026-03-16', 'completed', 'Failed', 7, '2026-03-16 16:29:13', '2026-09-16 16:29:13'),
(9, 2, 3, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 9, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(10, 2, 3, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 11, '2026-09-16 16:29:13', '2026-09-16 16:29:13'),
(11, 3, 1, '2024-2025', '1st Semester', '2024-09-16', 'completed', 'Good Standing', 1, '2024-09-16 16:29:14', '2026-09-16 16:29:14'),
(12, 3, 1, '2024-2025', '1st Semester', '2024-09-16', 'completed', 'Good Standing', 3, '2024-09-16 16:29:14', '2026-09-16 16:29:14'),
(13, 3, 2, '2024-2025', '2nd Semester', '2025-03-16', 'completed', 'Good Standing', 5, '2025-03-16 16:29:14', '2026-09-16 16:29:14'),
(14, 3, 2, '2024-2025', '2nd Semester', '2025-03-16', 'completed', 'Good Standing', 7, '2025-03-16 16:29:14', '2026-09-16 16:29:14'),
(15, 3, 3, '2025-2026', '1st Semester', '2025-09-16', 'completed', 'Good Standing', 9, '2025-09-16 16:29:14', '2026-09-16 16:29:14'),
(16, 3, 3, '2025-2026', '1st Semester', '2025-09-16', 'completed', 'Good Standing', 11, '2025-09-16 16:29:14', '2026-09-16 16:29:14'),
(17, 3, 4, '2025-2026', '2nd Semester', '2026-03-16', 'completed', 'Good Standing', 13, '2026-03-16 16:29:14', '2026-09-16 16:29:14'),
(18, 3, 4, '2025-2026', '2nd Semester', '2026-03-16', 'completed', 'Good Standing', 15, '2026-03-16 16:29:14', '2026-09-16 16:29:14'),
(19, 3, 5, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 17, '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(20, 3, 5, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 19, '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(21, 4, 1, '2023-2024', '1st Semester', '2023-09-16', 'completed', 'Good Standing', 1, '2023-09-16 16:29:14', '2026-09-16 16:29:14'),
(22, 4, 1, '2023-2024', '1st Semester', '2023-09-16', 'completed', 'Good Standing', 3, '2023-09-16 16:29:14', '2026-09-16 16:29:14'),
(23, 4, 2, '2023-2024', '2nd Semester', '2024-03-16', 'completed', 'Good Standing', 5, '2024-03-16 16:29:14', '2026-09-16 16:29:14'),
(24, 4, 2, '2023-2024', '2nd Semester', '2024-03-16', 'completed', 'Good Standing', 7, '2024-03-16 16:29:14', '2026-09-16 16:29:14'),
(25, 4, 3, '2024-2025', '1st Semester', '2024-09-16', 'completed', 'Good Standing', 9, '2024-09-16 16:29:14', '2026-09-16 16:29:14'),
(26, 4, 3, '2024-2025', '1st Semester', '2024-09-16', 'completed', 'Good Standing', 11, '2024-09-16 16:29:14', '2026-09-16 16:29:14'),
(27, 4, 4, '2024-2025', '2nd Semester', '2025-03-16', 'completed', 'Good Standing', 13, '2025-03-16 16:29:14', '2026-09-16 16:29:14'),
(28, 4, 4, '2024-2025', '2nd Semester', '2025-03-16', 'completed', 'Good Standing', 15, '2025-03-16 16:29:14', '2026-09-16 16:29:14'),
(29, 4, 5, '2025-2026', '1st Semester', '2025-09-16', 'completed', 'Good Standing', 17, '2025-09-16 16:29:14', '2026-09-16 16:29:14'),
(30, 4, 5, '2025-2026', '1st Semester', '2025-09-16', 'completed', 'Good Standing', 19, '2025-09-16 16:29:14', '2026-09-16 16:29:14'),
(31, 4, 6, '2025-2026', '2nd Semester', '2026-03-16', 'completed', 'Good Standing', 21, '2026-03-16 16:29:14', '2026-09-16 16:29:14'),
(32, 4, 6, '2025-2026', '2nd Semester', '2026-03-16', 'completed', 'Good Standing', 23, '2026-03-16 16:29:14', '2026-09-16 16:29:14'),
(33, 4, 7, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 25, '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(34, 4, 7, '2026-2027', '1st Semester', '2026-09-16', 'enrolled', 'Good Standing', 27, '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(35, 5, 1, '2026-2027', '1st Semester', '2026-06-16', 'dropped', 'Dropped', 1, '2026-06-16 16:29:14', '2026-09-16 16:29:14'),
(36, 5, 1, '2026-2027', '1st Semester', '2026-06-16', 'dropped', 'Dropped', 3, '2026-06-16 16:29:14', '2026-09-16 16:29:14'),
(38, 1, 2, '2026-2027', '2nd Semester', '2026-09-17', 'enrolled', 'Good Standing', 5, '2026-09-16 16:48:10', NULL),
(39, 1, 2, '2026-2027', '2nd Semester', '2026-09-17', 'enrolled', 'Good Standing', 7, '2026-09-16 16:48:10', NULL),
(40, 2, 4, '2026-2027', '2nd Semester', '2026-09-17', 'enrolled', 'Good Standing', 7, '2026-09-17 00:52:31', NULL),
(41, 2, 4, '2026-2027', '2nd Semester', '2026-09-17', 'enrolled', 'Good Standing', 13, '2026-09-17 00:52:31', NULL),
(42, 2, 4, '2026-2027', '2nd Semester', '2026-09-17', 'enrolled', 'Good Standing', 15, '2026-09-17 00:52:31', NULL),
(43, 6, 1, '2026-2027', '1st Semester', '2026-09-17', 'enrolled', 'Good Standing', 1, '2026-09-17 01:12:32', NULL),
(44, 6, 1, '2026-2027', '1st Semester', '2026-09-17', 'enrolled', 'Good Standing', 3, '2026-09-17 01:12:32', NULL),
(45, 7, 1, '2026-2027', '1st Semester', '2026-09-17', 'enrolled', 'Good Standing', 1, '2026-09-17 01:29:02', NULL),
(46, 7, 1, '2026-2027', '1st Semester', '2026-09-17', 'enrolled', 'Good Standing', 3, '2026-09-17 01:29:02', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `enr_prerequisites`
--

CREATE TABLE `enr_prerequisites` (
  `id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL COMMENT 'The subject that has a prerequisite',
  `prerequisite_subject_id` int(11) NOT NULL COMMENT 'The subject that must be passed first',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enr_requirements`
--

CREATE TABLE `enr_requirements` (
  `requirement_id` int(11) NOT NULL,
  `requirement_name` varchar(100) NOT NULL,
  `requirement_category` enum('freshmen','transferee','continuing') NOT NULL,
  `is_mandatory` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `student_id` int(11) DEFAULT NULL,
  `is_submitted` tinyint(1) DEFAULT 0,
  `submitted_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enr_requirements`
--

INSERT INTO `enr_requirements` (`requirement_id`, `requirement_name`, `requirement_category`, `is_mandatory`, `created_at`, `student_id`, `is_submitted`, `submitted_date`) VALUES
(1, 'Form 138 (Report Card)', 'freshmen', 1, '2026-09-16 16:29:14', NULL, 0, NULL),
(2, 'Good Moral Certificate', 'freshmen', 1, '2026-09-16 16:29:14', NULL, 0, NULL),
(3, 'Birth Certificate (PSA)', 'freshmen', 1, '2026-09-16 16:29:14', NULL, 0, NULL),
(4, '2x2 ID Picture', 'freshmen', 0, '2026-09-16 16:29:14', NULL, 0, NULL),
(5, 'Honorable Dismissal', 'transferee', 1, '2026-09-16 16:29:14', NULL, 0, NULL),
(6, 'Transcript of Records', 'transferee', 1, '2026-09-16 16:29:14', NULL, 0, NULL),
(7, 'Certificate of Good Standing', 'transferee', 1, '2026-09-16 16:29:14', NULL, 0, NULL),
(8, 'Updated Form 138', 'continuing', 1, '2026-09-16 16:29:14', NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `enr_students`
--

CREATE TABLE `enr_students` (
  `student_id` int(11) NOT NULL,
  `applicant_id` int(11) NOT NULL,
  `student_number` varchar(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `course_id` int(10) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `year_level` int(11) DEFAULT 1,
  `enrollment_status` enum('enrolled','on_leave','graduated','dropped') DEFAULT 'enrolled',
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `followup_date` date DEFAULT NULL,
  `followup_notes` text DEFAULT NULL,
  `followup_status` enum('pending','done') DEFAULT 'pending',
  `status` enum('active','inactive','graduated','transferred') DEFAULT 'active',
  `archived_at` datetime DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enr_students`
--

INSERT INTO `enr_students` (`student_id`, `applicant_id`, `student_number`, `user_id`, `course_id`, `section_id`, `year_level`, `enrollment_status`, `enrolled_at`, `followup_date`, `followup_notes`, `followup_status`, `status`, `archived_at`, `archive_reason`, `archived_by`) VALUES
(1, 3, '260001', 0, 1, 2, 1, 'enrolled', '2026-09-16 16:29:13', NULL, NULL, 'pending', 'active', NULL, NULL, NULL),
(2, 4, '260002', 0, 1, 4, 2, 'enrolled', '2026-09-16 16:29:13', NULL, NULL, 'pending', 'active', NULL, NULL, NULL),
(3, 5, '260003', 0, 1, 5, 3, 'enrolled', '2026-09-16 16:29:13', NULL, NULL, 'pending', 'active', NULL, NULL, NULL),
(4, 6, '260004', 0, 1, 7, 4, 'enrolled', '2026-09-16 16:29:13', NULL, NULL, 'pending', 'active', NULL, NULL, NULL),
(5, 7, '260005', 0, 1, 1, 1, 'dropped', '2026-06-16 16:29:13', NULL, NULL, 'done', 'inactive', '2026-08-16 16:29:13', 'Dropped', 1),
(6, 2, '260917001', 2, 1, 1, 1, 'enrolled', '2026-09-17 01:12:32', NULL, NULL, 'pending', 'active', NULL, NULL, NULL),
(7, 8, '260917002', 3, 1, 1, 1, 'enrolled', '2026-09-17 01:29:02', NULL, NULL, 'pending', 'active', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `enr_student_requirements`
--

CREATE TABLE `enr_student_requirements` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `requirement_id` int(11) NOT NULL,
  `is_submitted` tinyint(1) DEFAULT 0,
  `submitted_date` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enr_student_requirements`
--

INSERT INTO `enr_student_requirements` (`id`, `student_id`, `requirement_id`, `is_submitted`, `submitted_date`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2026-09-16 00:00:00', 'Submitted during enrollment', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(2, 1, 2, 1, '2026-09-16 00:00:00', 'Submitted during enrollment', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(3, 1, 3, 0, NULL, NULL, '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(4, 1, 4, 0, NULL, NULL, '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(5, 2, 8, 1, '2025-09-16 00:00:00', 'Updated form', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(6, 3, 8, 1, '2025-09-16 00:00:00', 'Submitted', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(7, 4, 8, 1, '2025-09-16 00:00:00', 'Submitted', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(8, 6, 5, 1, '2026-09-17 00:00:00', '', '2026-09-17 01:12:32', '2026-09-17 01:12:38'),
(9, 6, 6, 1, '2026-09-17 00:00:00', '', '2026-09-17 01:12:32', '2026-09-17 01:12:38'),
(10, 6, 7, 1, '2026-09-17 00:00:00', '', '2026-09-17 01:12:32', '2026-09-17 01:12:38'),
(11, 7, 1, 1, '2026-09-17 00:00:00', '', '2026-09-17 01:29:02', '2026-09-17 01:29:12'),
(12, 7, 2, 1, '2026-09-17 00:00:00', '', '2026-09-17 01:29:02', '2026-09-17 01:29:12'),
(13, 7, 3, 1, '2026-09-17 00:00:00', '', '2026-09-17 01:29:02', '2026-09-17 01:29:12'),
(14, 7, 4, 1, '2026-09-17 00:00:00', '', '2026-09-17 01:29:02', '2026-09-17 01:29:12');

-- --------------------------------------------------------

--
-- Table structure for table `enr_users`
--

CREATE TABLE `enr_users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `student_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enr_users`
--

INSERT INTO `enr_users` (`user_id`, `username`, `password`, `email`, `full_name`, `student_id`, `is_active`, `created_at`, `last_login`, `updated_at`) VALUES
(1, 'admin', '$2y$10$abcdefghijklmnopqrstuvwxyz0123456789012345678901234567890', 'admin@bestlink.edu.ph', 'System Administrator', 0, 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13', NULL),
(2, '260917001', '$2y$10$cfjP5uo.v.Q20rddkSojvuJ.AeYQDuHFLeenOIcsXkk3k3BTdbG6O', 'jose.reyes@example.com', 'Jose Cruz Reyes Jr.', 6, 1, '2026-09-17 09:12:32', NULL, NULL),
(3, '260917002', '$2y$10$81.0JwZhgEczNuCOZlC1nu2lkkFn8K5uG0lXmXwMDT7kjLE8ua1aa', 'yasierelyasin@gmail.com', 'Yazier J Elyasen', 7, 1, '2026-09-17 09:29:02', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `rgr_courses`
--

CREATE TABLE `rgr_courses` (
  `id` int(10) NOT NULL,
  `code` varchar(250) NOT NULL,
  `name` varchar(250) NOT NULL,
  `years` int(10) NOT NULL,
  `description` varchar(250) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rgr_courses`
--

INSERT INTO `rgr_courses` (`id`, `code`, `name`, `years`, `description`) VALUES
(1, 'BSIS', 'Bachelor of Science in Information Systems', 4, 'Four-year degree program in Information Systems');

-- --------------------------------------------------------

--
-- Table structure for table `rgr_curriculums`
--

CREATE TABLE `rgr_curriculums` (
  `id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `curriculum_name` varchar(120) NOT NULL,
  `effective_year` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rgr_curriculums`
--

INSERT INTO `rgr_curriculums` (`id`, `course_id`, `curriculum_name`, `effective_year`, `is_active`) VALUES
(1, 1, 'BSIS Curriculum 2026', 2026, 1);

-- --------------------------------------------------------

--
-- Table structure for table `rgr_curriculum_subjects`
--

CREATE TABLE `rgr_curriculum_subjects` (
  `id` int(11) NOT NULL,
  `curriculum_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `year_level` int(11) NOT NULL,
  `semester` varchar(250) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rgr_curriculum_subjects`
--

INSERT INTO `rgr_curriculum_subjects` (`id`, `curriculum_id`, `subject_id`, `year_level`, `semester`) VALUES
(1, 1, 1, 1, 'First'),
(2, 1, 2, 1, 'First'),
(3, 1, 3, 1, 'Second'),
(4, 1, 4, 1, 'Second'),
(5, 1, 5, 2, 'First'),
(6, 1, 6, 2, 'First'),
(7, 1, 7, 2, 'Second'),
(8, 1, 8, 2, 'Second'),
(9, 1, 9, 3, 'First'),
(10, 1, 10, 3, 'First'),
(11, 1, 11, 3, 'Second'),
(12, 1, 12, 3, 'Second'),
(13, 1, 13, 4, 'First'),
(14, 1, 14, 4, 'First'),
(15, 1, 15, 4, 'Second'),
(16, 1, 16, 4, 'Second');

-- --------------------------------------------------------

--
-- Table structure for table `rgr_grades`
--

CREATE TABLE `rgr_grades` (
  `id` int(10) NOT NULL,
  `enrollment_id` int(10) DEFAULT NULL,
  `prelim` int(10) DEFAULT NULL,
  `midterm` int(10) DEFAULT NULL,
  `finals` int(10) DEFAULT NULL,
  `grade` decimal(4,2) DEFAULT NULL,
  `remarks` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rgr_grades`
--

INSERT INTO `rgr_grades` (`id`, `enrollment_id`, `prelim`, `midterm`, `finals`, `grade`, `remarks`, `created_at`, `updated_at`) VALUES
(1, 5, 85, 88, 90, 87.67, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(2, 6, 80, 82, 85, 82.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(3, 7, 88, 90, 92, 90.00, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(4, 8, 70, 68, 72, 70.00, 'Failed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(5, 11, 85, 86, 88, 86.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(6, 12, 82, 85, 87, 84.67, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(7, 13, 88, 90, 91, 89.67, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(8, 14, 85, 87, 89, 87.00, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(9, 15, 90, 92, 94, 92.00, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(10, 16, 88, 89, 91, 89.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(11, 17, 86, 88, 90, 88.00, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(12, 18, 89, 91, 93, 91.00, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(13, 21, 90, 91, 93, 91.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(14, 22, 88, 89, 91, 89.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(15, 23, 89, 90, 92, 90.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(16, 24, 87, 88, 90, 88.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(17, 25, 91, 92, 94, 92.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(18, 26, 90, 91, 93, 91.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(19, 27, 92, 93, 95, 93.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(20, 28, 89, 90, 92, 90.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(21, 29, 93, 94, 96, 94.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(22, 30, 91, 92, 94, 92.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(23, 31, 92, 93, 95, 93.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(24, 32, 90, 91, 93, 91.33, 'Passed', '2026-09-16 16:29:14', '2026-09-16 16:29:14'),
(25, 1, 88, 90, 92, 90.00, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53'),
(26, 2, 87, 89, 91, 89.00, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53'),
(27, 3, 85, 87, 89, 87.00, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53'),
(28, 4, 86, 88, 90, 88.00, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53'),
(29, 9, 82, 85, 88, 85.00, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53'),
(30, 10, 80, 84, 86, 83.33, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53'),
(31, 19, 89, 91, 93, 91.00, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53'),
(32, 20, 87, 90, 92, 89.67, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53'),
(33, 33, 92, 94, 96, 94.00, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53'),
(34, 34, 90, 92, 94, 92.00, 'Passed', '2026-09-16 16:43:53', '2026-09-16 16:43:53');

-- --------------------------------------------------------

--
-- Table structure for table `rgr_school_years`
--

CREATE TABLE `rgr_school_years` (
  `id` int(10) NOT NULL,
  `name` varchar(250) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rgr_school_years`
--

INSERT INTO `rgr_school_years` (`id`, `name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '2026-2027', 1, '2026-09-16 16:29:13', '2026-09-16 16:29:13');

-- --------------------------------------------------------

--
-- Table structure for table `rgr_semesters`
--

CREATE TABLE `rgr_semesters` (
  `id` int(10) NOT NULL,
  `name` varchar(250) NOT NULL,
  `school_year_id` int(10) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rgr_semesters`
--

INSERT INTO `rgr_semesters` (`id`, `name`, `school_year_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '1st Semester', 1, 1, '2026-09-16 16:29:13', '2026-09-17 01:01:40'),
(2, '2nd Semester', 1, 0, '2026-09-16 16:29:13', '2026-09-17 01:01:40');

-- --------------------------------------------------------

--
-- Table structure for table `rgr_subjects`
--

CREATE TABLE `rgr_subjects` (
  `id` int(10) NOT NULL,
  `code` varchar(250) NOT NULL,
  `name` varchar(250) NOT NULL,
  `units` int(11) NOT NULL,
  `lecture_hours` int(10) NOT NULL,
  `lab_hours` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rgr_subjects`
--

INSERT INTO `rgr_subjects` (`id`, `code`, `name`, `units`, `lecture_hours`, `lab_hours`) VALUES
(1, 'GE101', 'Understanding the Self', 3, 3, 0),
(2, 'IT101', 'Introduction to Computing', 3, 2, 3),
(3, 'GE102', 'Readings in Philippine History', 3, 3, 0),
(4, 'IT102', 'Computer Programming 1', 3, 2, 3),
(5, 'IT201', 'Data Structures and Algorithms', 3, 2, 3),
(6, 'IT202', 'Object-Oriented Programming', 3, 2, 3),
(7, 'IT203', 'Database Management Systems', 3, 2, 3),
(8, 'IT204', 'Web Systems and Technologies', 3, 2, 3),
(9, 'IT301', 'Systems Analysis and Design', 3, 2, 3),
(10, 'IT302', 'Networking 1', 3, 2, 3),
(11, 'IT303', 'Information Assurance and Security', 3, 2, 3),
(12, 'IT304', 'Mobile Application Development', 3, 2, 3),
(13, 'IT401', 'Capstone Project 1', 3, 1, 6),
(14, 'IT402', 'Systems Integration and Architecture', 3, 2, 3),
(15, 'IT403', 'Capstone Project 2', 3, 1, 6),
(16, 'IT404', 'Professional Issues in IT', 3, 3, 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cc_faculty`
--
ALTER TABLE `cc_faculty`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cc_faculty_load`
--
ALTER TABLE `cc_faculty_load`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cc_room`
--
ALTER TABLE `cc_room`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cc_schedule`
--
ALTER TABLE `cc_schedule`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cc_sections`
--
ALTER TABLE `cc_sections`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cc_section_faculty`
--
ALTER TABLE `cc_section_faculty`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `enr_applicants`
--
ALTER TABLE `enr_applicants`
  ADD PRIMARY KEY (`applicant_id`);

--
-- Indexes for table `enr_enrollments`
--
ALTER TABLE `enr_enrollments`
  ADD PRIMARY KEY (`enrollment_id`);

--
-- Indexes for table `enr_requirements`
--
ALTER TABLE `enr_requirements`
  ADD PRIMARY KEY (`requirement_id`);

--
-- Indexes for table `enr_students`
--
ALTER TABLE `enr_students`
  ADD PRIMARY KEY (`student_id`);

--
-- Indexes for table `enr_student_requirements`
--
ALTER TABLE `enr_student_requirements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `enr_users`
--
ALTER TABLE `enr_users`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `rgr_courses`
--
ALTER TABLE `rgr_courses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rgr_curriculums`
--
ALTER TABLE `rgr_curriculums`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rgr_curriculum_subjects`
--
ALTER TABLE `rgr_curriculum_subjects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rgr_grades`
--
ALTER TABLE `rgr_grades`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rgr_school_years`
--
ALTER TABLE `rgr_school_years`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rgr_semesters`
--
ALTER TABLE `rgr_semesters`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rgr_subjects`
--
ALTER TABLE `rgr_subjects`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cc_faculty`
--
ALTER TABLE `cc_faculty`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `cc_faculty_load`
--
ALTER TABLE `cc_faculty_load`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `cc_room`
--
ALTER TABLE `cc_room`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `cc_schedule`
--
ALTER TABLE `cc_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `cc_sections`
--
ALTER TABLE `cc_sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `cc_section_faculty`
--
ALTER TABLE `cc_section_faculty`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `enr_applicants`
--
ALTER TABLE `enr_applicants`
  MODIFY `applicant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `enr_enrollments`
--
ALTER TABLE `enr_enrollments`
  MODIFY `enrollment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `enr_requirements`
--
ALTER TABLE `enr_requirements`
  MODIFY `requirement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `enr_students`
--
ALTER TABLE `enr_students`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `enr_student_requirements`
--
ALTER TABLE `enr_student_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `enr_users`
--
ALTER TABLE `enr_users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `rgr_courses`
--
ALTER TABLE `rgr_courses`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rgr_curriculums`
--
ALTER TABLE `rgr_curriculums`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rgr_curriculum_subjects`
--
ALTER TABLE `rgr_curriculum_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `rgr_grades`
--
ALTER TABLE `rgr_grades`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `rgr_school_years`
--
ALTER TABLE `rgr_school_years`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rgr_semesters`
--
ALTER TABLE `rgr_semesters`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `rgr_subjects`
--
ALTER TABLE `rgr_subjects`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
