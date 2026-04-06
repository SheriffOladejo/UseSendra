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
  `status` enum('pending','accepted','rejected','inreview','countered') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
  `pickup_qr_code` varchar(255) DEFAULT NULL,
  `acceptance_payment_reference` varchar(100) DEFAULT NULL,
  `bidding_expires_at` datetime DEFAULT NULL,
  `bidding_closed` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_requests_user` (`user_id`),
  KEY `idx_requests_status` (`status`),
  CONSTRAINT `delivery_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_requests`
--

LOCK TABLES `delivery_requests` WRITE;
/*!40000 ALTER TABLE `delivery_requests` DISABLE KEYS */;
INSERT INTO `delivery_requests` VALUES (13,19,'4, remi alabi street jericho ibadan',7.39853191,3.86545682,'bayse one hotel','765 fola osibo lekki phase 1, lagos',7.42399979,3.90755272,'opposite gtbank','shoe','2 pairs of shoes','uploads/items/2348164727987/item_1774880828_918139251190924.jpg','08164727987','08012345678','motorcycle',3000.00,'sender','cash','draft','2026-03-30 14:25:27',NULL,NULL,NULL,NULL,NULL,0),(14,22,'21 aromire avenue',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2348030745811',NULL,NULL,NULL,NULL,NULL,'draft','2026-04-01 08:53:59',NULL,NULL,NULL,NULL,NULL,0),(15,23,'osuntuyi medical centre, lagos',6.55000448,3.36967945,'osuntuyi medical center','no 7 alhaji salusi street',6.54997826,3.36935353,'the driver should call the pick up','clothes','jeans and tops','uploads/items/2348135048972/item_1775039802_1670506377313663.jpg','08135048972','09166552435','motorcycle',1500.00,'receiver','cash','draft','2026-04-01 09:08:07',NULL,NULL,NULL,NULL,NULL,0),(16,20,'badagry lagos',6.41501999,2.88132000,'allen avenue',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2349033008754',NULL,NULL,NULL,NULL,NULL,'draft','2026-04-03 21:21:40',NULL,NULL,NULL,NULL,NULL,0);
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
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `drivers`
--

LOCK TABLES `drivers` WRITE;
/*!40000 ALTER TABLE `drivers` DISABLE KEYS */;
INSERT INTO `drivers` VALUES (33,'2347042149011',NULL,NULL,'Taoreed','Hamzat',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 16:20:48',NULL,NULL,NULL,'en'),(34,'2347065955606','09112107878',NULL,'Adebisi','Adeniyi','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,'95820608599',NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 16:27:11',7.42353290,3.90967610,NULL,'en'),(35,'2348069018536',NULL,NULL,'Arogundade','Kehinde',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 16:28:09',NULL,NULL,NULL,'en'),(36,'2347036099672','08077231450',NULL,'Luqman','Thamin','motorcycle',NULL,'Red','uploads/drivers/2347036099672/vehicle_1774890935_1665643358184973.jpg','yre972qf','uploads/drivers/2347036099672/license_1774890722_1057141774147472.jpg',NULL,'33627432352',NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 17:07:13',7.42353290,3.90967610,'BAJAJ','en'),(37,'2349023873858','09039616868',NULL,'Yinusa','Salami','motorcycle',NULL,NULL,NULL,NULL,'uploads/drivers/2349023873858/license_1774891516_3500715190066833.jpg',NULL,'19801390548',NULL,'pending',NULL,0,5.00,0,'active','2026-03-30 17:07:41',7.42353290,3.90967610,NULL,'en'),(40,'2347019240234','08164727987',NULL,'Sheriff','Olumide','motorcycle',NULL,'Dudu','uploads/drivers/2347019240234/vehicle_1774999715_881963564870457.jpg','jhg113as','uploads/drivers/2347019240234/license_1774999693_1796424345035372.jpg',NULL,'12345678901',NULL,'pending',NULL,0,5.00,0,'active','2026-03-31 23:26:49',7.39847470,3.86539170,'BAJAJ','en'),(43,'2348165445326','08165445326',NULL,'Moses','Joseph','motorcycle',NULL,'Brown and yellow','uploads/drivers/2348165445326/vehicle_1775059466_1555942119498575.jpg','akn765uu','uploads/drivers/2348165445326/license_1775059348_3974532266173232.jpg',NULL,'58767760547',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 15:56:22',NULL,NULL,'TVSHLX','en'),(44,'2348155481188','07038326089',NULL,'Adebayo','Osinowo','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,'31352934754',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 15:56:55',NULL,NULL,NULL,'en'),(45,'2347040979318','07040979318',NULL,'Johnson','Joshua','motorcycle',NULL,'Oyo color','uploads/drivers/2347040979318/vehicle_1775059513_2811276562545215.jpg','sey 505um','uploads/drivers/2347040979318/license_1775059395_1273876190793304.jpg',NULL,'24427430411',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 15:58:58',NULL,NULL,'TVS-HLX','en'),(46,'2349061638290','09061638290',NULL,'Mukaila','Sodiq','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 15:59:21',NULL,NULL,NULL,'en'),(47,'2348164727987','08164727987',NULL,'Sheriff','Oladejo','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 16:01:21',NULL,NULL,NULL,'en'),(48,'2348130402419','09028458978',NULL,'Adejumo','Emmanuel',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 16:01:40',NULL,NULL,NULL,'en'),(49,'2349130630619','09130630619',NULL,'Akinola','Emmanuel','motorcycle',NULL,'Blue','uploads/drivers/2349130630619/vehicle_1775133200_897214576651402.jpg','bdj-263-qj','uploads/drivers/2349130630619/license_1775133136_925326350103692.jpg',NULL,'35656381947',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 16:08:15',NULL,NULL,'TVS-HLX','en'),(50,'2348056711058','08056711058',NULL,'Gbenga','Rodemade','motorcycle',NULL,'Red','uploads/drivers/2348056711058/vehicle_1775060258_974890778761095.jpg','umc 765up','uploads/drivers/2348056711058/license_1775060112_1249178843589261.jpg',NULL,'61829019098',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 16:09:01',NULL,NULL,'BAJAJ','en'),(51,'2347062346326','07062346326',NULL,'Ayobami','Joseph','motorcycle',NULL,'Blue','uploads/drivers/2347062346326/vehicle_1775063337_3047887015400143.jpg','agg 243 qf','uploads/drivers/2347062346326/license_1775063198_1358825252687852.jpg',NULL,'33735513305',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 17:03:08',NULL,NULL,'HONDA CB 110','en'),(52,'2349136048293','09136048293',NULL,'Abayomi','Sodiq','motorcycle',NULL,'Black','uploads/drivers/2349136048293/vehicle_1775063762_1442413697363301.jpg','ynf584uq','uploads/drivers/2349136048293/license_1775063588_911218481689703.jpg',NULL,'61538180464',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 17:08:53',NULL,NULL,'TVS-HLX','en'),(53,'2347035125084','07035125084',NULL,'Adeagbo','Nurudeen','motorcycle',NULL,'Black','uploads/drivers/2347035125084/vehicle_1775063855_1677994323645567.jpg','mny-470-qh','uploads/drivers/2347035125084/license_1775063680_844201348690616.jpg',NULL,'23537753310',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 17:11:40',NULL,NULL,'TVS-HLX','en'),(54,'2347064272604','08059980042',NULL,'Salam','Taofeek',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 17:15:23',NULL,NULL,NULL,'en'),(55,'2347043740092','08086854794',NULL,'Olajide','Akinkunmi',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 17:24:37',NULL,NULL,NULL,'en'),(56,'2348051025141','08051025141',NULL,'Oluwafemi','Ogedengbe','motorcycle',NULL,'Black','uploads/drivers/2348051025141/vehicle_1775065072_876910475382090.jpg','tde 509 qz','uploads/drivers/2348051025141/license_1775064820_4388510548144287.jpg',NULL,'42996091622',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 17:30:36',NULL,NULL,'TVS-HLX','en'),(57,'2349095479009','09095479009',NULL,'Adeyemo','Ibrahim','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,'83054020643',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 17:31:54',NULL,NULL,NULL,'en'),(58,'2347086686634','07086686634',NULL,'Alabi','Johnson','keke',NULL,'Red','uploads/drivers/2347086686634/vehicle_1775065456_1622561325681084.jpg','kmt-427-ue','uploads/drivers/2347086686634/license_1775065338_955781820472932.jpg',NULL,'61319696889',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 17:33:26',NULL,NULL,'TVS','en'),(59,'2348126721354','08126721354',NULL,'Abiodun','Joshua','motorcycle',NULL,NULL,NULL,'kky-620-qd','uploads/drivers/2348126721354/license_1775065447_967716832262735.jpg',NULL,'96932116370',NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 17:34:52',NULL,NULL,NULL,'en'),(60,'2349032528826','09032528826',NULL,'Adele','Tunde','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 18:45:40',NULL,NULL,NULL,'en'),(61,'2348161195773','08161195773',NULL,'Ojeniyi','Abiodun','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 18:58:31',NULL,NULL,NULL,'en'),(62,'2347034312176',NULL,NULL,'Akpa','Tochukwu',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 20:11:11',NULL,NULL,NULL,'en'),(63,'2347089188010',NULL,NULL,'Owoeye','Tobiloba',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-01 21:34:24',NULL,NULL,NULL,'en'),(64,'2348102677402','08102677402',NULL,'Sulaimon','Ridwan','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,'57909690408',NULL,'pending',NULL,0,5.00,0,'active','2026-04-02 06:05:04',NULL,NULL,NULL,'en'),(65,'2347010382297','07010382297',NULL,'Chinedu','Franklin','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,'83490726916',NULL,'pending',NULL,0,5.00,0,'active','2026-04-02 06:51:48',NULL,NULL,NULL,'en'),(66,'2348030891819','08070854408',NULL,'Adeboye','John',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-02 07:29:32',NULL,NULL,NULL,'en'),(67,'2348139637084','08139637084',NULL,'Babalola','Akinyemi','motorcycle',NULL,'Yellow and brown','uploads/drivers/2348139637084/vehicle_1775117914_916503197869855.jpg','yre-907-qu','uploads/drivers/2348139637084/license_1775117757_1513338493771195.jpg',NULL,'49170542579',NULL,'pending',NULL,0,5.00,0,'active','2026-04-02 08:09:29',NULL,NULL,'TVS','en'),(68,'2348060500101','08060500101',NULL,'Folayan','Olusegun','motorcycle',NULL,'Yellow and wine','uploads/drivers/2348060500101/vehicle_1775150732_1944344392849415.jpg','nrk 506 ug','uploads/drivers/2348060500101/license_1775150539_1452039616466937.jpg',NULL,'55015638808',NULL,'pending',NULL,0,5.00,0,'active','2026-04-02 17:20:06',NULL,NULL,'TVS-HLX','en'),(69,'2347086816170','08101535864',NULL,'Oluwafemi','Orukotan','motorcycle',NULL,'Black','uploads/drivers/2347086816170/vehicle_1775151218_1469189941494580.jpg','tan718qc','uploads/drivers/2347086816170/license_1775151155_26750786937847785.jpg',NULL,'43204933312',NULL,'pending',NULL,0,5.00,0,'active','2026-04-02 17:25:50',NULL,NULL,'TVS','en'),(70,'2348128283535','08128283535',NULL,'Adeshina','Olubunmi','motorcycle',NULL,'Black','uploads/drivers/2348128283535/vehicle_1775232268_26878289261762833.jpg','tan 684 uf','uploads/drivers/2348128283535/license_1775232220_2143198626429991.jpg',NULL,'23805736208',NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:01:59',NULL,NULL,'TVS','en'),(71,'2349013074648','09013074648',NULL,'Babalola','Joel','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:05:55',NULL,NULL,NULL,'en'),(72,'2348063244441','08063244441',NULL,'Yusuff','Opeyemi','motorcycle',NULL,'Blue','uploads/drivers/2348063244441/vehicle_1775233097_2281931462616471.jpg','ksh-046um','uploads/drivers/2348063244441/license_1775233011_1668290931029190.jpg',NULL,'74754657487',NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:12:16',NULL,NULL,'QLINK 200CC','en'),(73,'2348160359379','08160359379',NULL,'Babatunde','Kareem','motorcycle',NULL,'Red','uploads/drivers/2348160359379/vehicle_1775233260_1388954666582966.jpg','ksh 917uj','uploads/drivers/2348160359379/license_1775232916_1593571591731291.jpg',NULL,'50533064702',NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:12:37',NULL,NULL,'BAJAJ','en'),(74,'2349066242427','09066242427',NULL,'Oladele','Oluwasegun','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:13:06',NULL,NULL,NULL,'en'),(75,'2348150577761','08105781967',NULL,'Oyeniyi','Oluniyi','motorcycle',NULL,'Black','uploads/drivers/2348150577761/vehicle_1775236293_1504618761180146.jpg','dda-660-uc','uploads/drivers/2348150577761/license_1775236136_1500313611806094.jpg',NULL,'94465236519',NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:14:54',NULL,NULL,'TVS-HLX','en'),(76,'2347068406818','07068406818',NULL,'Saka','Kazeem','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:17:58',NULL,NULL,NULL,'en'),(77,'2348065012113','08065012113',NULL,'Can','I','motorcycle',NULL,'Wine colour','uploads/drivers/2348065012113/vehicle_1775234271_2349009675609412.jpg','luy802vd','uploads/drivers/2348065012113/license_1775233874_1614618039758740.jpg',NULL,'67620009790',NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:24:13',NULL,NULL,'QULINK 200CC','en'),(78,'2349134281595','09134281595',NULL,'Idris','Arisekola','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:29:05',NULL,NULL,NULL,'en'),(79,'2349022004156','09022004156',NULL,'Ismail','Abiodun','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:33:13',NULL,NULL,NULL,'en'),(80,'2349032093487','09032093487',NULL,'Azeez','Akeem','keke',NULL,'Black','uploads/drivers/2349032093487/vehicle_1775234621_955327420474575.jpg','lgb 528 up','uploads/drivers/2349032093487/license_1775234541_1426105099262116.jpg',NULL,'12261479412',NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 16:35:31',NULL,NULL,'T V S','en'),(81,'2349032291335','09032291335',NULL,'Joseph','Idowu','motorcycle',NULL,'Black','uploads/drivers/2349032291335/vehicle_1775236993_2818377111860274.jpg','fjk01qr','uploads/drivers/2349032291335/license_1775236924_1434024558051759.jpg',NULL,'48119587569',NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 17:19:52',NULL,NULL,'TVS','en'),(82,'2347085984170','07085984170',NULL,'Fadele','Damilare','motorcycle',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 17:22:35',NULL,NULL,NULL,'en'),(83,'2348076818125','08076818125',NULL,'Isiaq','Akeem','motorcycle',NULL,'Red','uploads/drivers/2348076818125/vehicle_1775240994_931923282892388.jpg','jnd808qu','uploads/drivers/2348076818125/license_1775240809_1442391194233303.jpg',NULL,'22754993287',NULL,'pending',NULL,0,5.00,0,'active','2026-04-03 18:18:22',NULL,NULL,'TVS','en'),(84,'2347032425568','07032425568',NULL,'Ukeme','Asuquo','motorcycle',NULL,'Red','uploads/drivers/2347032425568/vehicle_1775297614_1344065044219293.jpg','ejg-205-um','uploads/drivers/2347032425568/license_1775297476_1577530043352307.jpg',NULL,'62901321428',NULL,'pending',NULL,0,5.00,0,'active','2026-04-04 10:08:21',NULL,NULL,'TVS','en');
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
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (19,'2348164727987','Deen',NULL,NULL,NULL,NULL,NULL,0,'active','whatsapp','2026-03-30 14:25:07',NULL),(20,'2349033008754','Jibuo Kingsley',NULL,NULL,NULL,NULL,NULL,0,'active','whatsapp','2026-03-30 16:46:41',NULL),(22,'2348030745811','Asher Ozieh',NULL,NULL,NULL,NULL,NULL,0,'active','whatsapp','2026-04-01 08:53:06',NULL),(23,'2348135048972','Tofluxury.ng',NULL,NULL,NULL,NULL,NULL,0,'active','whatsapp','2026-04-01 09:06:08',NULL),(24,'2348105461947','Kikachukwu',NULL,NULL,NULL,NULL,NULL,0,'active','whatsapp','2026-04-01 09:54:31',NULL),(26,'2347043740092','oladeji Ibrahim',NULL,NULL,NULL,NULL,NULL,0,'active','whatsapp','2026-04-01 17:22:15',NULL),(27,'2348120343830','0xGODMAN',NULL,NULL,NULL,NULL,NULL,0,'active','whatsapp','2026-04-02 17:41:04',NULL);
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
  `counter_request_id` text,
  `pending_delivery_request` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone_number` (`phone_number`)
) ENGINE=InnoDB AUTO_INCREMENT=155 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_sessions`
--

LOCK TABLES `whatsapp_sessions` WRITE;
/*!40000 ALTER TABLE `whatsapp_sessions` DISABLE KEYS */;
INSERT INTO `whatsapp_sessions` VALUES (53,'2349033008754',NULL,'dropoff_address','16',NULL,NULL,'2026-04-03 21:22:20','2026-03-30 15:21:50','en',NULL,NULL),(54,'2347042149011',NULL,'rider_phone',NULL,NULL,NULL,'2026-03-30 16:20:48','2026-03-30 16:19:21','en',NULL,NULL),(55,'2347065955606',NULL,'driver_id',NULL,NULL,NULL,'2026-03-30 22:49:05','2026-03-30 16:26:08','en',NULL,NULL),(56,'2349052034710',NULL,'rider_name',NULL,NULL,NULL,'2026-03-31 05:21:35','2026-03-30 16:26:47','en',NULL,NULL),(57,'2348069018536',NULL,'rider_phone',NULL,NULL,NULL,'2026-03-30 16:28:09','2026-03-30 16:27:26','en',NULL,NULL),(58,'2349023873858',NULL,'plate_number',NULL,NULL,NULL,'2026-03-30 17:25:16','2026-03-30 17:05:05','en',NULL,NULL),(59,'2347036099672',NULL,'rider_ready',NULL,NULL,NULL,'2026-03-30 17:16:02','2026-03-30 17:06:25','en',NULL,NULL),(60,'2348024936767',NULL,'choose_role',NULL,NULL,NULL,'2026-03-30 17:15:04','2026-03-30 17:15:04','en',NULL,NULL),(76,'2347019240234',NULL,'rider_ready',NULL,NULL,NULL,'2026-03-31 23:28:56','2026-03-31 23:26:21','yo',NULL,NULL),(82,'2348030745811',NULL,'pickup_location','14',NULL,NULL,'2026-04-01 08:53:59','2026-04-01 08:52:27','en',NULL,NULL),(83,'2348135048972',NULL,'summary','15',NULL,NULL,'2026-04-01 10:37:39','2026-04-01 09:03:24','en',NULL,NULL),(95,'2349048833478',NULL,'rider_name',NULL,NULL,NULL,'2026-04-01 16:04:12','2026-04-01 15:55:11','en',NULL,NULL),(96,'2348165445326',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-01 16:05:53','2026-04-01 15:55:14','en',NULL,NULL),(97,'2348155481188',NULL,'driver_id',NULL,NULL,NULL,'2026-04-03 07:24:21','2026-04-01 15:56:08','en',NULL,NULL),(98,'2347040979318',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-01 16:06:54','2026-04-01 15:57:05','en',NULL,NULL),(99,'2347035125084',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-01 17:18:21','2026-04-01 15:57:08','en',NULL,NULL),(100,'2349061638290',NULL,'nin',NULL,NULL,NULL,'2026-04-01 16:00:16','2026-04-01 15:57:47','en',NULL,NULL),(101,'2348130402419',NULL,'vehicle_type',NULL,NULL,NULL,'2026-04-01 16:01:52','2026-04-01 16:00:30','en',NULL,NULL),(102,'2348164727987',NULL,'nin',NULL,NULL,NULL,'2026-04-01 16:03:10','2026-04-01 16:01:05','en',NULL,NULL),(103,'2349130630619',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-02 12:33:59','2026-04-01 16:07:42','en',NULL,NULL),(104,'2348056711058',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-01 16:18:34','2026-04-01 16:08:07','en',NULL,NULL),(105,'2347062346326',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-01 17:09:54','2026-04-01 17:02:20','en',NULL,NULL),(106,'2349136048293',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-01 19:40:24','2026-04-01 17:05:41','en',NULL,NULL),(107,'2349032528826',NULL,'nin',NULL,NULL,NULL,'2026-04-01 18:46:07','2026-04-01 17:08:08','en',NULL,NULL),(108,'2347034312176',NULL,'rider_phone',NULL,NULL,NULL,'2026-04-01 20:11:11','2026-04-01 17:08:12','en',NULL,NULL),(109,'2349072409568',NULL,'rider_name',NULL,NULL,NULL,'2026-04-01 17:10:04','2026-04-01 17:09:50','en',NULL,NULL),(110,'2347064272604',NULL,'vehicle_type',NULL,NULL,NULL,'2026-04-01 17:19:27','2026-04-01 17:14:08','en',NULL,NULL),(111,'2348139637084',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-02 08:19:14','2026-04-01 17:15:49','en',NULL,NULL),(112,'2348051025141',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-01 17:39:12','2026-04-01 17:18:13','en',NULL,NULL),(113,'2347043740092',NULL,'vehicle_type',NULL,NULL,NULL,'2026-04-01 17:24:55','2026-04-01 17:22:10','en',NULL,NULL),(114,'2349095479009',NULL,'driver_id',NULL,NULL,NULL,'2026-04-01 17:33:14','2026-04-01 17:31:10','en',NULL,NULL),(115,'2347086686634',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-01 17:45:41','2026-04-01 17:32:51','en',NULL,NULL),(116,'2348126721354',NULL,'vehicle_picture',NULL,NULL,NULL,'2026-04-01 17:45:38','2026-04-01 17:34:03','en',NULL,NULL),(117,'2348102677402',NULL,'driver_id',NULL,NULL,NULL,'2026-04-02 06:06:21','2026-04-01 17:38:41','en',NULL,NULL),(118,'2348129287273',NULL,'rider_name',NULL,NULL,NULL,'2026-04-01 17:39:00','2026-04-01 17:38:53','en',NULL,NULL),(119,'2348161195773',NULL,'nin',NULL,NULL,NULL,'2026-04-01 20:30:04','2026-04-01 18:57:03','en',NULL,NULL),(120,'2347089188010',NULL,'rider_phone',NULL,NULL,NULL,'2026-04-01 21:34:24','2026-04-01 21:33:19','en',NULL,NULL),(121,'2347010382297',NULL,'driver_id',NULL,NULL,NULL,'2026-04-02 06:52:57','2026-04-01 22:00:32','en',NULL,NULL),(122,'2348030891819',NULL,'vehicle_type',NULL,NULL,NULL,'2026-04-02 07:30:58','2026-04-02 05:01:21','en',NULL,NULL),(123,'2348060500101',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-02 17:27:59','2026-04-02 17:19:22','en',NULL,NULL),(124,'2347086816170',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-02 17:35:16','2026-04-02 17:24:40','en',NULL,NULL),(125,'2348120343830',NULL,'pickup_address',NULL,NULL,NULL,'2026-04-02 17:41:27','2026-04-02 17:40:48','en',NULL,NULL),(126,'2348136445845',NULL,'choose_role',NULL,NULL,NULL,'2026-04-03 07:07:13','2026-04-03 07:07:13','en',NULL,NULL),(127,'2348128283535',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-03 16:04:46','2026-04-03 16:00:40','en',NULL,NULL),(128,'2349013074648',NULL,'nin',NULL,NULL,NULL,'2026-04-03 16:06:57','2026-04-03 16:05:13','en',NULL,NULL),(129,'2349066242427',NULL,'nin',NULL,NULL,NULL,'2026-04-03 16:13:30','2026-04-03 16:10:44','en',NULL,NULL),(130,'2348063244441',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-03 16:19:47','2026-04-03 16:11:50','en',NULL,NULL),(131,'2348160359379',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-03 16:22:05','2026-04-03 16:12:06','en',NULL,NULL),(132,'2348150577761',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-03 17:12:19','2026-04-03 16:14:00','en',NULL,NULL),(133,'2347068406818',NULL,'nin',NULL,NULL,NULL,'2026-04-03 16:18:22','2026-04-03 16:16:46','en',NULL,NULL),(134,'2348050494079',NULL,'rider_name',NULL,NULL,NULL,'2026-04-03 16:20:26','2026-04-03 16:20:23','en',NULL,NULL),(135,'2348065012113',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-03 16:39:11','2026-04-03 16:22:46','en',NULL,NULL),(136,'2347080806808',NULL,'rider_name',NULL,NULL,NULL,'2026-04-03 16:23:39','2026-04-03 16:22:57','en',NULL,NULL),(137,'2349134281595',NULL,'nin',NULL,NULL,NULL,'2026-04-03 16:30:14','2026-04-03 16:28:28','en',NULL,NULL),(138,'2347085943396',NULL,'rider_name',NULL,NULL,NULL,'2026-04-03 16:31:22','2026-04-03 16:31:00','en',NULL,NULL),(139,'2349022004156',NULL,'nin',NULL,NULL,NULL,'2026-04-03 16:33:39','2026-04-03 16:31:51','en',NULL,NULL),(140,'2349032093487',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-03 16:44:59','2026-04-03 16:34:26','en',NULL,NULL),(141,'2347032425568',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-04 10:14:01','2026-04-03 16:40:43','en',NULL,NULL),(142,'2347036362069',NULL,'rider_name',NULL,NULL,NULL,'2026-04-03 17:07:21','2026-04-03 17:07:11','en',NULL,NULL),(143,'2348158210138',NULL,'rider_name',NULL,NULL,NULL,'2026-04-03 17:10:45','2026-04-03 17:10:39','en',NULL,NULL),(144,'2348138410690',NULL,'rider_name',NULL,NULL,NULL,'2026-04-03 17:14:23','2026-04-03 17:14:18','en',NULL,NULL),(145,'2349032291335',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-03 17:24:11','2026-04-03 17:19:27','en',NULL,NULL),(146,'2347085984170',NULL,'nin',NULL,NULL,NULL,'2026-04-03 17:24:17','2026-04-03 17:22:07','en',NULL,NULL),(147,'2348076818125',NULL,'rider_ready',NULL,NULL,NULL,'2026-04-03 18:30:43','2026-04-03 18:17:22','en',NULL,NULL);
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

-- Dump completed on 2026-04-06 10:04:18
