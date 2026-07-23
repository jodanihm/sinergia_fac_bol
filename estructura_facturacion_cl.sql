-- MySQL dump 10.13  Distrib 8.4.8, for Linux (x86_64)
--
-- Host: localhost    Database: facturacion_cl
-- ------------------------------------------------------
-- Server version	8.4.8

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
-- Table structure for table `dte_caf`
--

DROP TABLE IF EXISTS `dte_caf`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dte_caf` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rut_emisor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'RUT con DV, ej 77724622-4',
  `tipo_dte` int NOT NULL COMMENT '33,34,39,41,52,56,61',
  `ambiente` enum('certificacion','produccion') COLLATE utf8mb4_unicode_ci NOT NULL,
  `folio_desde` int unsigned NOT NULL,
  `folio_hasta` int unsigned NOT NULL,
  `caf_xml_cifrado` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` enum('activo','agotado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activo',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_caf_rango` (`rut_emisor`,`tipo_dte`,`ambiente`,`folio_desde`,`folio_hasta`),
  KEY `ix_caf_busqueda` (`rut_emisor`,`tipo_dte`,`ambiente`,`estado`,`folio_desde`),
  CONSTRAINT `chk_caf_rango` CHECK ((`folio_hasta` >= `folio_desde`))
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dte_certificado`
--

DROP TABLE IF EXISTS `dte_certificado`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dte_certificado` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rut_emisor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ambiente` enum('certificacion','produccion') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cert_data_cifrado` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `pkey_data_cifrado` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cert_emisor` (`rut_emisor`,`ambiente`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dte_emisor`
--

DROP TABLE IF EXISTS `dte_emisor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dte_emisor` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rut_emisor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ambiente` enum('certificacion','produccion') COLLATE utf8mb4_unicode_ci NOT NULL,
  `razon_social` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `giro` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acteco` int NOT NULL,
  `dir_origen` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cmna_origen` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resolucion_fecha` date NOT NULL,
  `resolucion_numero` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_emisor` (`rut_emisor`,`ambiente`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dte_emitido`
--

DROP TABLE IF EXISTS `dte_emitido`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dte_emitido` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rut_emisor` varchar(20) NOT NULL,
  `ambiente` enum('certificacion','produccion') NOT NULL,
  `tipo_dte` int NOT NULL,
  `folio` int unsigned NOT NULL,
  `track_id` varchar(40) DEFAULT NULL,
  `estado` varchar(20) NOT NULL,
  `xml` longblob NOT NULL,
  `fecha_emision` date NOT NULL,
  `neto` int unsigned NOT NULL DEFAULT '0',
  `iva` int unsigned NOT NULL DEFAULT '0',
  `total` int unsigned NOT NULL DEFAULT '0',
  `receptor_rut` varchar(20) NOT NULL,
  `folio_ref` int unsigned DEFAULT NULL,
  `tipo_dte_ref` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emitido` (`rut_emisor`,`ambiente`,`tipo_dte`,`folio`),
  KEY `idx_estado` (`estado`),
  KEY `idx_receptor` (`receptor_rut`),
  KEY `idx_periodo` (`rut_emisor`,`ambiente`,`fecha_emision`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dte_folio`
--

DROP TABLE IF EXISTS `dte_folio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dte_folio` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `caf_id` bigint unsigned NOT NULL,
  `rut_emisor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_dte` int NOT NULL,
  `ambiente` enum('certificacion','produccion') COLLATE utf8mb4_unicode_ci NOT NULL,
  `proximo_folio` int unsigned NOT NULL,
  `folio_hasta` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_folio_caf` (`caf_id`),
  KEY `ix_folio_busqueda` (`rut_emisor`,`tipo_dte`,`ambiente`),
  CONSTRAINT `fk_folio_caf` FOREIGN KEY (`caf_id`) REFERENCES `dte_caf` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dte_folio_log`
--

DROP TABLE IF EXISTS `dte_folio_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dte_folio_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rut_emisor` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_dte` int NOT NULL,
  `ambiente` enum('certificacion','produccion') COLLATE utf8mb4_unicode_ci NOT NULL,
  `folio` int unsigned NOT NULL,
  `emision_exitosa` tinyint(1) DEFAULT NULL,
  `asignado_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_log_folio` (`rut_emisor`,`tipo_dte`,`ambiente`,`folio`)
) ENGINE=InnoDB AUTO_INCREMENT=278 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dte_idempotencia`
--

DROP TABLE IF EXISTS `dte_idempotencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dte_idempotencia` (
  `ambiente` enum('certificacion','produccion') NOT NULL,
  `clave` varchar(255) NOT NULL,
  `tipo_dte` int DEFAULT NULL,
  `folio` int unsigned DEFAULT NULL,
  `http_status` smallint unsigned DEFAULT NULL,
  `respuesta_json` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ambiente`,`clave`),
  KEY `idx_folio` (`ambiente`,`tipo_dte`,`folio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-10 17:56:29
