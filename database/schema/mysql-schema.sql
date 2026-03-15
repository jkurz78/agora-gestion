/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `association`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `association` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `adresse` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code_postal` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ville` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budget_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sous_categorie_id` bigint unsigned NOT NULL,
  `exercice` int NOT NULL,
  `montant_prevu` decimal(10,2) NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budget_lines_sous_categorie_id_foreign` (`sous_categorie_id`),
  CONSTRAINT `budget_lines_sous_categorie_id_foreign` FOREIGN KEY (`sous_categorie_id`) REFERENCES `sous_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comptes_bancaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comptes_bancaires` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `iban` varchar(34) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `solde_initial` decimal(10,2) NOT NULL DEFAULT '0.00',
  `date_solde_initial` date DEFAULT NULL,
  `actif_recettes_depenses` tinyint(1) NOT NULL DEFAULT '1',
  `actif_dons_cotisations` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cotisations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cotisations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `numero_piece` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exercice` int NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `date_paiement` date NOT NULL,
  `mode_paiement` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `pointe` tinyint(1) NOT NULL DEFAULT '0',
  `rapprochement_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `tiers_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cotisations_numero_piece_unique` (`numero_piece`),
  KEY `cotisations_compte_id_foreign` (`compte_id`),
  KEY `cotisations_rapprochement_id_foreign` (`rapprochement_id`),
  KEY `cotisations_tiers_id_foreign` (`tiers_id`),
  CONSTRAINT `cotisations_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `cotisations_rapprochement_id_foreign` FOREIGN KEY (`rapprochement_id`) REFERENCES `rapprochements_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cotisations_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `depense_lignes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `depense_lignes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `depense_id` bigint unsigned NOT NULL,
  `sous_categorie_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `seance` int DEFAULT NULL,
  `montant` decimal(10,2) NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `depense_lignes_depense_id_foreign` (`depense_id`),
  KEY `depense_lignes_sous_categorie_id_foreign` (`sous_categorie_id`),
  KEY `depense_lignes_operation_id_foreign` (`operation_id`),
  CONSTRAINT `depense_lignes_depense_id_foreign` FOREIGN KEY (`depense_id`) REFERENCES `depenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `depense_lignes_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `depense_lignes_sous_categorie_id_foreign` FOREIGN KEY (`sous_categorie_id`) REFERENCES `sous_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `depenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `depenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `numero_piece` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `libelle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  `mode_paiement` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `pointe` tinyint(1) NOT NULL DEFAULT '0',
  `rapprochement_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `saisi_par` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `tiers_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `depenses_numero_piece_unique` (`numero_piece`),
  KEY `depenses_compte_id_foreign` (`compte_id`),
  KEY `depenses_saisi_par_foreign` (`saisi_par`),
  KEY `depenses_rapprochement_id_foreign` (`rapprochement_id`),
  KEY `depenses_tiers_id_foreign` (`tiers_id`),
  CONSTRAINT `depenses_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `depenses_rapprochement_id_foreign` FOREIGN KEY (`rapprochement_id`) REFERENCES `rapprochements_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `depenses_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`),
  CONSTRAINT `depenses_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `donateurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `donateurs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `numero_piece` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `mode_paiement` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `objet` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `seance` int DEFAULT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `pointe` tinyint(1) NOT NULL DEFAULT '0',
  `rapprochement_id` bigint unsigned DEFAULT NULL,
  `recu_emis` tinyint(1) NOT NULL DEFAULT '0',
  `saisi_par` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `tiers_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dons_numero_piece_unique` (`numero_piece`),
  KEY `dons_operation_id_foreign` (`operation_id`),
  KEY `dons_compte_id_foreign` (`compte_id`),
  KEY `dons_saisi_par_foreign` (`saisi_par`),
  KEY `dons_rapprochement_id_foreign` (`rapprochement_id`),
  KEY `dons_tiers_id_foreign` (`tiers_id`),
  CONSTRAINT `dons_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `dons_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dons_rapprochement_id_foreign` FOREIGN KEY (`rapprochement_id`) REFERENCES `rapprochements_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dons_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`),
  CONSTRAINT `dons_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `membres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `membres` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse` text COLLATE utf8mb4_unicode_ci,
  `date_adhesion` date DEFAULT NULL,
  `statut` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'actif',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `nombre_seances` int DEFAULT NULL,
  `statut` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_cours',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rapprochements_bancaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rapprochements_bancaires` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `compte_id` bigint unsigned NOT NULL,
  `date_fin` date NOT NULL,
  `solde_ouverture` decimal(10,2) NOT NULL,
  `solde_fin` decimal(10,2) NOT NULL,
  `statut` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_cours',
  `saisi_par` bigint unsigned NOT NULL,
  `verrouille_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rapprochements_bancaires_compte_id_foreign` (`compte_id`),
  KEY `rapprochements_bancaires_saisi_par_foreign` (`saisi_par`),
  CONSTRAINT `rapprochements_bancaires_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `rapprochements_bancaires_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recette_lignes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recette_lignes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `recette_id` bigint unsigned NOT NULL,
  `sous_categorie_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `seance` int DEFAULT NULL,
  `montant` decimal(10,2) NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recette_lignes_recette_id_foreign` (`recette_id`),
  KEY `recette_lignes_sous_categorie_id_foreign` (`sous_categorie_id`),
  KEY `recette_lignes_operation_id_foreign` (`operation_id`),
  CONSTRAINT `recette_lignes_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recette_lignes_recette_id_foreign` FOREIGN KEY (`recette_id`) REFERENCES `recettes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recette_lignes_sous_categorie_id_foreign` FOREIGN KEY (`sous_categorie_id`) REFERENCES `sous_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recettes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recettes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `numero_piece` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `libelle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  `mode_paiement` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `pointe` tinyint(1) NOT NULL DEFAULT '0',
  `rapprochement_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `saisi_par` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `tiers_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recettes_numero_piece_unique` (`numero_piece`),
  KEY `recettes_compte_id_foreign` (`compte_id`),
  KEY `recettes_saisi_par_foreign` (`saisi_par`),
  KEY `recettes_rapprochement_id_foreign` (`rapprochement_id`),
  KEY `recettes_tiers_id_foreign` (`tiers_id`),
  CONSTRAINT `recettes_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `recettes_rapprochement_id_foreign` FOREIGN KEY (`rapprochement_id`) REFERENCES `rapprochements_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recettes_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`),
  CONSTRAINT `recettes_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exercice` varchar(9) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dernier_numero` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sequences_exercice_unique` (`exercice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sous_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sous_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `categorie_id` bigint unsigned NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_cerfa` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sous_categories_categorie_id_foreign` (`categorie_id`),
  CONSTRAINT `sous_categories_categorie_id_foreign` FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tiers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('entreprise','particulier') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'particulier',
  `nom` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse` text COLLATE utf8mb4_unicode_ci,
  `date_adhesion` date DEFAULT NULL,
  `statut_membre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes_membre` text COLLATE utf8mb4_unicode_ci,
  `pour_depenses` tinyint(1) NOT NULL DEFAULT '0',
  `pour_recettes` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `virements_internes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `virements_internes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `numero_piece` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `compte_source_id` bigint unsigned NOT NULL,
  `compte_destination_id` bigint unsigned NOT NULL,
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rapprochement_source_id` bigint unsigned DEFAULT NULL,
  `rapprochement_destination_id` bigint unsigned DEFAULT NULL,
  `saisi_par` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `virements_internes_numero_piece_unique` (`numero_piece`),
  KEY `virements_internes_compte_source_id_foreign` (`compte_source_id`),
  KEY `virements_internes_compte_destination_id_foreign` (`compte_destination_id`),
  KEY `virements_internes_saisi_par_foreign` (`saisi_par`),
  KEY `virements_internes_rapprochement_source_id_foreign` (`rapprochement_source_id`),
  KEY `virements_internes_rapprochement_destination_id_foreign` (`rapprochement_destination_id`),
  CONSTRAINT `virements_internes_compte_destination_id_foreign` FOREIGN KEY (`compte_destination_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `virements_internes_compte_source_id_foreign` FOREIGN KEY (`compte_source_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `virements_internes_rapprochement_destination_id_foreign` FOREIGN KEY (`rapprochement_destination_id`) REFERENCES `rapprochements_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `virements_internes_rapprochement_source_id_foreign` FOREIGN KEY (`rapprochement_source_id`) REFERENCES `rapprochements_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `virements_internes_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2025_01_01_000001_create_comptes_bancaires_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2025_01_01_000002_create_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2025_01_01_000003_create_sous_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2025_01_01_000004_create_operations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2025_01_01_000005_create_depenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2025_01_01_000006_create_depense_lignes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2025_01_01_000007_create_recettes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2025_01_01_000008_create_recette_lignes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2025_01_01_000009_create_budget_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2025_01_01_000010_create_membres_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2025_01_01_000011_create_cotisations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2025_01_01_000012_create_donateurs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2025_01_01_000013_create_dons_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2025_01_01_000014_create_virements_internes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_03_12_162329_make_date_solde_initial_nullable_on_comptes_bancaires',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_03_12_200001_create_rapprochements_bancaires_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_03_12_200002_add_rapprochement_id_to_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_03_13_000001_add_actif_fields_to_comptes_bancaires_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_03_13_100000_rename_payeur_to_tiers_on_recettes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_03_13_100001_rename_beneficiaire_to_tiers_on_depenses',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_03_13_200000_create_sequences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_03_13_200001_add_numero_piece_to_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_03_14_000001_create_association_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_03_14_100000_create_tiers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_03_14_200001_add_membre_fields_to_tiers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_03_14_200002_add_tiers_id_fk_to_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_03_14_200003_migrate_donateurs_to_tiers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_03_14_200004_migrate_membres_to_tiers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_03_14_200005_finalize_dons_tiers_id',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_03_14_200006_finalize_cotisations_tiers_id',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_03_14_200007_finalize_depenses_recettes_tiers_id',1);
