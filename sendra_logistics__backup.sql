-- MySQL dump 10.13  Distrib 8.0.43, for Linux (x86_64)
--
-- Host: localhost    Database: sendra_logistics_db
-- ------------------------------------------------------
-- Server version	8.0.43-0ubuntu0.22.04.2

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `delivery_offers`
--

DROP TABLE IF EXISTS `delivery_offers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_offers` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `request_id` bigint unsigned DEFAULT NULL,
  `driver_id` bigint unsigned DEFAULT NULL,
  `proposed_price` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `delivery_offers_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `delivery_requests` (`id`),
  CONSTRAINT `delivery_offers_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_offers`
--

LOCK TABLES `delivery_offers` WRITE;
/*!40000 ALTER TABLE `delivery_offers` DISABLE KEYS */;
/*!40000 ALTER TABLE `delivery_offers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `delivery_requests`
--

DROP TABLE IF EXISTS `delivery_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `pickup_address` text,
  `pickup_lat` decimal(10,8) DEFAULT NULL,
  `pickup_lng` decimal(11,8) DEFAULT NULL,
  `pickup_notes` text,
  `dropoff_address` text,
  `dropoff_lat` decimal(10,8) DEFAULT NULL,
  `dropoff_lng` decimal(11,8) DEFAULT NULL,
  `dropoff_notes` text,
  `item_name` varchar(200) DEFAULT NULL,
  `item_description` text,
  `item_photo_url` text,
  `sender_phone` varchar(20) DEFAULT NULL,
  `receiver_phone` varchar(20) DEFAULT NULL,
  `vehicle_type` enum('motorcycle','keke') DEFAULT NULL,
  `offered_price` decimal(10,2) DEFAULT NULL,
  `payer` enum('sender','receiver') DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer') DEFAULT NULL,
  `status` enum('draft','searching_driver','driver_payment_pending','driver_assigned','in_transit','delivered','payment_expired','cancelled') DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `reserved_driver_id` int DEFAULT NULL,
  `reservation_expires_at` datetime DEFAULT NULL,
  `acceptance_payment_reference` varchar(100) DEFAULT NULL,
  `bidding_expires_at` datetime DEFAULT NULL,
  `bidding_closed` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_requests_user` (`user_id`),
  KEY `idx_requests_status` (`status`),
  CONSTRAINT `delivery_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_requests`
--

LOCK TABLES `delivery_requests` WRITE;
/*!40000 ALTER TABLE `delivery_requests` DISABLE KEYS */;
INSERT INTO `delivery_requests` VALUES (13,19,'4, remi alabi street jericho ibadan',7.39853191,3.86545682,'bayse one hotel','765 fola osibo lekki phase 1, lagos',7.42399979,3.90755272,'opposite gtbank','shoe','2 pairs of shoes','uploads/items/2348164727987/item_1774880828_918139251190924.jpg','08164727987','08012345678','motorcycle',3000.00,'sender','cash','draft','2026-03-30 14:25:27',NULL,NULL,NULL,NULL,0);
/*!40000 ALTER TABLE `delivery_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `driver_availability`
--

DROP TABLE IF EXISTS `driver_availability`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `driver_availability` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `driver_id` bigint unsigned NOT NULL,
  `is_online` tinyint(1) DEFAULT '0',
  `is_available` tinyint(1) DEFAULT '1',
  `current_request_id` bigint DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `driver_availability_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `driver_availability`
--

LOCK TABLES `driver_availability` WRITE;
/*!40000 ALTER TABLE `driver_availability` DISABLE KEYS */;
/*!40000 ALTER TABLE `driver_availability` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `driver_locations`
--

DROP TABLE IF EXISTS `driver_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `driver_locations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `driver_id` bigint unsigned NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_driver_location` (`driver_id`),
  CONSTRAINT `driver_locations_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `driver_locations`
--

LOCK TABLES `driver_locations` WRITE;
/*!40000 ALTER TABLE `driver_locations` DISABLE KEYS */;
/*!40000 ALTER TABLE `driver_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `drivers`
--

DROP TABLE IF EXISTS `drivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `drivers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `preferred_phone_number` varchar(20) DEFAULT NULL,
  `whatsapp_name` text,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `vehicle_type` enum('bike','keke','car','van','motorcycle') DEFAULT NULL,
  `vehicle_plate_number` varchar(50) DEFAULT NULL,
  `vehicle_color` varchar(50) DEFAULT NULL,
  `vehicle_picture_url` varchar(255) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `license_picture_url` varchar(255) DEFAULT NULL,
  `bvn` varchar(11) DEFAULT NULL,
  `nin` varchar(11) DEFAULT NULL,
  `selfie_url` varchar(255) DEFAULT NULL,
  `verification_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `rejection_reason` text,
  `is_online` tinyint(1) DEFAULT '0',
  `rating` decimal(3,2) DEFAULT '5.00',
  `total_trips` int DEFAULT '0',
  `status` enum('active','suspended') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `preferred_latitude` decimal(10,8) DEFAULT NULL,
  `preferred_longitude` decimal(11,8) DEFAULT NULL,
  `vehicle_brand_model` varchar(100) DEFAULT NULL,
  `language` varchar(5) NOT NULL DEFAULT 'en',
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`),
  KEY `idx_drivers_phone` (`phone`),
  KEY `idx_drivers_bvn` (`bvn`),
  KEY `idx_drivers_nin` (`nin`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `drivers`
--

LOCK TABLES `drivers` WRITE;
/*!40000 ALTER TABLE `drivers` DISABLE KEYS */;
INSERT INTO `drivers` VALUES (32,'2347019240234','08164727987',NULL,'Sheriff','Oladejo','motorcycle',NULL,'Red','uploads/drivers/2347019240234/vehicle_1774880629_954104397322061.jpg','ksf233as','uploads/drivers/2347019240234/license_1774880602_1957000245692533.jpg',NULL,'12345678901',NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 13:59:41',7.39847730,3.86538920,'BAJAJ','en'),(33,'2347042149011',NULL,NULL,'Taoreed','Hamzat',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 16:20:48',NULL,NULL,NULL,'en'),(34,'2347065955606','09112107878',NULL,'Adebisi','Adeniyi','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,'95820608599',NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 16:27:11',7.42353290,3.90967610,NULL,'en'),(35,'2348069018536',NULL,NULL,'Arogundade','Kehinde',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 16:28:09',NULL,NULL,NULL,'en'),(36,'2347036099672','08077231450',NULL,'Luqman','Thamin','motorcycle',NULL,'Red','uploads/drivers/2347036099672/vehicle_1774890935_1665643358184973.jpg','yre972qf','uploads/drivers/2347036099672/license_1774890722_1057141774147472.jpg',NULL,'33627432352',NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 17:07:13',7.42353290,3.90967610,'BAJAJ','en'),(37,'2349023873858','09039616868',NULL,'Yinusa','Salami','motorcycle',NULL,NULL,NULL,NULL,'uploads/drivers/2349023873858/license_1774891516_3500715190066833.jpg',NULL,'19801390548',NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 17:07:41',7.42353290,3.90967610,NULL,'en');
/*!40000 ALTER TABLE `drivers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trips`
--

DROP TABLE IF EXISTS `trips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `trips` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `request_id` bigint unsigned DEFAULT NULL,
  `driver_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `pickup_confirmed_at` timestamp NULL DEFAULT NULL,
  `trip_started_at` timestamp NULL DEFAULT NULL,
  `trip_completed_at` timestamp NULL DEFAULT NULL,
  `pickup_qr_code` text,
  `status` enum('assigned','picked_up','in_transit','delivered','cancelled') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `driver_id` (`driver_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `trips_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `delivery_requests` (`id`),
  CONSTRAINT `trips_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`),
  CONSTRAINT `trips_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trips`
--

LOCK TABLES `trips` WRITE;
/*!40000 ALTER TABLE `trips` DISABLE KEYS */;
/*!40000 ALTER TABLE `trips` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `whatsapp_name` varchar(120) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `default_pickup_address` text,
  `default_dropoff_address` text,
  `total_orders` int DEFAULT '0',
  `status` enum('active','blocked') DEFAULT 'active',
  `signup_channel` enum('whatsapp','sms','ussd','mobile_app') DEFAULT 'whatsapp',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`),
  KEY `idx_users_phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (19,'2348164727987','Deen',NULL,NULL,NULL,NULL,NULL,0,'active','whatsapp','2026-03-30 14:25:07',NULL),(20,'2349033008754','Jibuo Kingsley',NULL,NULL,NULL,NULL,NULL,0,'active','whatsapp','2026-03-30 16:46:41',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `whatsapp_sessions`
--

DROP TABLE IF EXISTS `whatsapp_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_sessions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `phone_number` varchar(20) DEFAULT NULL,
  `user_id` bigint DEFAULT NULL,
  `current_step` varchar(100) DEFAULT NULL,
  `delivery_request_id` text,
  `session_data` json DEFAULT NULL,
  `last_message` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `language` varchar(5) DEFAULT 'en',
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone_number` (`phone_number`)
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_sessions`
--

LOCK TABLES `whatsapp_sessions` WRITE;
/*!40000 ALTER TABLE `whatsapp_sessions` DISABLE KEYS */;
INSERT INTO `whatsapp_sessions` VALUES (51,'2347019240234',NULL,'in_review',NULL,NULL,NULL,'2026-03-30 15:38:45','2026-03-30 13:59:20','en'),(53,'2349033008754',NULL,'sender_menu',NULL,NULL,NULL,'2026-03-30 16:46:41','2026-03-30 15:21:50','en'),(54,'2347042149011',NULL,'rider_phone',NULL,NULL,NULL,'2026-03-30 16:20:48','2026-03-30 16:19:21','en'),(55,'2347065955606',NULL,'driver_id',NULL,NULL,NULL,'2026-03-30 22:49:05','2026-03-30 16:26:08','en'),(56,'2349052034710',NULL,'rider_name',NULL,NULL,NULL,'2026-03-31 05:21:35','2026-03-30 16:26:47','en'),(57,'2348069018536',NULL,'rider_phone',NULL,NULL,NULL,'2026-03-30 16:28:09','2026-03-30 16:27:26','en'),(58,'2349023873858',NULL,'plate_number',NULL,NULL,NULL,'2026-03-30 17:25:16','2026-03-30 17:05:05','en'),(59,'2347036099672',NULL,'rider_ready',NULL,NULL,NULL,'2026-03-30 17:16:02','2026-03-30 17:06:25','en'),(60,'2348024936767',NULL,'choose_role',NULL,NULL,NULL,'2026-03-30 17:15:04','2026-03-30 17:15:04','en'),(72,'2348164727987',NULL,'rider_name',NULL,NULL,NULL,'2026-03-31 20:55:31','2026-03-31 20:54:53','yo');
/*!40000 ALTER TABLE `whatsapp_sessions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-31 23:05:30
