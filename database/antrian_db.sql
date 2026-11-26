CREATE DATABASE IF NOT EXISTS `antrian_db`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE `antrian_db`;

CREATE TABLE `call_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `called_at` datetime NOT NULL DEFAULT current_timestamp(),
  `call_number` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_call_logs_ticket` (`ticket_id`,`call_number`),
  KEY `idx_call_logs_user` (`user_id`),
  CONSTRAINT `fk_call_logs_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_call_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rooms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `room_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rooms_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `rooms` WRITE;
INSERT INTO `rooms` VALUES
(1,'Ruangan 1',1,'2026-11-24 11:45:13','2026-11-24 11:45:13'),
(2,'Ruangan 2',1,'2026-11-24 11:45:13','2026-11-24 11:45:13'),
(3,'Ruangan 3',1,'2026-11-24 11:45:13','2026-11-24 11:45:13');
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

CREATE TABLE `staff_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `wave_id` int(10) unsigned NOT NULL,
  `room_id` int(10) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_assignment` (`user_id`,`wave_id`,`room_id`),
  KEY `idx_assignment_lookup` (`user_id`,`wave_id`,`room_id`,`is_active`),
  KEY `fk_assignments_wave` (`wave_id`),
  KEY `fk_assignments_room` (`room_id`),
  CONSTRAINT `fk_assignments_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_wave` FOREIGN KEY (`wave_id`) REFERENCES `waves` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `staff_assignments` WRITE;
INSERT INTO `staff_assignments` VALUES
(1,2,1,1,1,'2026-11-24 11:45:14','2026-11-24 11:45:14'),
(2,2,2,1,1,'2026-11-24 11:45:14','2026-11-24 11:45:14'),
(3,3,1,2,1,'2026-11-24 11:45:14','2026-11-24 11:45:14'),
(4,3,2,2,1,'2026-11-24 11:45:14','2026-11-24 11:45:14'),
(5,4,1,3,1,'2026-11-24 11:45:14','2026-11-24 11:45:14'),
(6,4,3,3,1,'2026-11-24 11:45:14','2026-11-24 11:45:14');
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_code` varchar(80) NOT NULL,
  `queue_number` int(10) unsigned NOT NULL,
  `service_date` date NOT NULL,
  `wave_id` int(10) unsigned NOT NULL,
  `room_id` int(10) unsigned NOT NULL,
  `status` enum('waiting','called','completed') NOT NULL DEFAULT 'waiting',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `called_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tickets_code` (`ticket_code`),
  UNIQUE KEY `uq_tickets_date_wave_number` (`service_date`,`wave_id`,`queue_number`),
  KEY `idx_tickets_operational` (`service_date`,`wave_id`,`room_id`,`status`,`deleted_at`),
  KEY `idx_tickets_queue_order` (`service_date`,`wave_id`,`room_id`,`queue_number`),
  KEY `fk_tickets_wave` (`wave_id`),
  KEY `fk_tickets_room` (`room_id`),
  CONSTRAINT `fk_tickets_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_wave` FOREIGN KEY (`wave_id`) REFERENCES `waves` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=313 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','petugas') NOT NULL DEFAULT 'petugas',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role_active` (`role`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `users` WRITE;
INSERT INTO `users` VALUES
(1,'Administrator Sistem','admin','$2y$12$kN4kPj4mMMHgRBKGc/Y1neFS5cr/BzujRXcutXIAnY/LBAaUydQ4m','admin',1,'2026-11-24 11:45:13','2026-11-24 15:41:24'),
(2,'Petugas 1','petugas1','$2y$12$kN4kPj4mMMHgRBKGc/Y1neFS5cr/BzujRXcutXIAnY/LBAaUydQ4m','petugas',1,'2026-11-24 11:45:13','2026-11-26 17:23:45'),
(3,'Petugas 2','petugas2','$2y$12$kN4kPj4mMMHgRBKGc/Y1neFS5cr/BzujRXcutXIAnY/LBAaUydQ4m','petugas',1,'2026-11-24 11:45:13','2026-11-26 17:23:45'),
(4,'Petugas 3','petugas3','$2y$12$kN4kPj4mMMHgRBKGc/Y1neFS5cr/BzujRXcutXIAnY/LBAaUydQ4m','petugas',1,'2026-11-24 11:45:13','2026-11-26 17:23:46');
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

DROP TABLE IF EXISTS `waves`;
CREATE TABLE `waves` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `wave_name` varchar(100) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `quota` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_waves_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `waves` WRITE;
INSERT INTO `waves` VALUES
(1,'Gelombang 1 (Pagi)','08:00:00','10:00:00',200,1,'2026-11-24 11:45:13','2026-11-24 16:20:34'),
(2,'Gelombang 2 (Siang)','10:30:00','12:30:00',200,1,'2026-11-24 11:45:13','2026-11-24 16:20:34'),
(3,'Gelombang 3 (Sore)','13:30:00','15:30:00',200,1,'2026-11-24 11:45:13','2026-11-24 16:22:49');
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;