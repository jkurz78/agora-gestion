/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `adhesions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adhesions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `tiers_id` bigint unsigned NOT NULL,
  `exercice` smallint unsigned DEFAULT NULL,
  `transaction_id` bigint unsigned DEFAULT NULL,
  `formule_adhesion_id` bigint unsigned DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `montant_facial` decimal(10,2) DEFAULT NULL,
  `deductible_fiscal` tinyint(1) DEFAULT NULL,
  `mode` enum('exercice','duree','illimite') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duree_mois` smallint unsigned DEFAULT NULL,
  `label_formule` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saisi_par` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `adhesions_unique_per_exercice` (`association_id`,`tiers_id`,`exercice`),
  KEY `adhesions_transaction_id_index` (`transaction_id`),
  KEY `adhesions_saisi_par_foreign` (`saisi_par`),
  KEY `adhesions_formule_adhesion_id_foreign` (`formule_adhesion_id`),
  KEY `adhesions_dates_idx` (`tiers_id`,`date_debut`,`date_fin`),
  CONSTRAINT `adhesions_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `adhesions_formule_adhesion_id_foreign` FOREIGN KEY (`formule_adhesion_id`) REFERENCES `formules_adhesion` (`id`) ON DELETE SET NULL,
  CONSTRAINT `adhesions_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `adhesions_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `adhesions_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `association`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `association` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exercice_mois_debut` tinyint unsigned NOT NULL DEFAULT '9',
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'actif',
  `wizard_completed_at` timestamp NULL DEFAULT NULL,
  `wizard_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `wizard_current_step` tinyint unsigned NOT NULL DEFAULT '1',
  `devis_validite_jours` int NOT NULL DEFAULT '30',
  `adresse` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code_postal` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ville` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cachet_signature_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `siret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eligible_recu_fiscal` tinyint(1) NOT NULL DEFAULT '0',
  `regime_fiscal_don` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loi_coluche_eligible` tinyint(1) NOT NULL DEFAULT '0',
  `ifi_eligible` tinyint(1) NOT NULL DEFAULT '0',
  `objet_recu_fiscal` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rescrit_fiscal_numero` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rescrit_fiscal_date` date DEFAULT NULL,
  `signataire_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signataire_qualite` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `forme_juridique` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Association loi 1901',
  `facture_conditions_reglement` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facture_mentions_legales` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `facture_mentions_penalites` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `facture_compte_bancaire_id` bigint unsigned DEFAULT NULL,
  `anthropic_api_key` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `invoice_ocr_model` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `questionnaire_ocr_model` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_from` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_from_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_site_web` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_renouvellement_adhesion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url_nouveau_don` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `association_slug_unique` (`slug`),
  KEY `association_facture_compte_bancaire_id_foreign` (`facture_compte_bancaire_id`),
  CONSTRAINT `association_facture_compte_bancaire_id_foreign` FOREIGN KEY (`facture_compte_bancaire_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `association_chk_1` CHECK (json_valid(`wizard_state`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `association_api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `association_api_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `key_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret_encrypted` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `association_api_keys_key_id_unique` (`key_id`),
  KEY `association_api_keys_association_id_foreign` (`association_id`),
  KEY `association_api_keys_revoked_at_index` (`revoked_at`),
  CONSTRAINT `association_api_keys_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `association_api_keys_chk_1` CHECK (json_valid(`scopes`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `association_slug_aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `association_slug_aliases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug_ancien` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `association_id` bigint unsigned NOT NULL,
  `deprecated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `association_slug_aliases_slug_ancien_unique` (`slug_ancien`),
  KEY `association_slug_aliases_association_id_index` (`association_id`),
  CONSTRAINT `association_slug_aliases_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `association_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `association_user` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `association_id` bigint unsigned NOT NULL,
  `role` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invited_at` timestamp NULL DEFAULT NULL,
  `joined_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `association_user_user_id_association_id_unique` (`user_id`,`association_id`),
  KEY `association_user_association_id_role_index` (`association_id`,`role`),
  CONSTRAINT `association_user_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `association_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budget_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `exercice` int NOT NULL,
  `montant_prevu` decimal(10,2) NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budget_lines_association_id_index` (`association_id`),
  KEY `budget_lines_compte_id_foreign` (`compte_id`),
  CONSTRAINT `budget_lines_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_lines_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `campagnes_email`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `campagnes_email` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `objet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `corps` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pieces_jointes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `nb_destinataires` int unsigned NOT NULL DEFAULT '0',
  `nb_erreurs` int unsigned NOT NULL DEFAULT '0',
  `envoye_par` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `campagnes_email_envoye_par_foreign` (`envoye_par`),
  KEY `campagnes_email_operation_id_foreign` (`operation_id`),
  KEY `campagnes_email_association_id_index` (`association_id`),
  CONSTRAINT `campagnes_email_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `campagnes_email_envoye_par_foreign` FOREIGN KEY (`envoye_par`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `campagnes_email_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `campagnes_email_chk_1` CHECK (json_valid(`pieces_jointes`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comptes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comptes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `numero_pcg` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `intitule` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `classe` tinyint unsigned NOT NULL,
  `parent_compte_id` bigint unsigned DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `est_systeme` tinyint(1) NOT NULL DEFAULT '0',
  `pour_inscriptions` tinyint(1) NOT NULL DEFAULT '0',
  `lettrable` tinyint(1) NOT NULL DEFAULT '0',
  `iban` varchar(34) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bic` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domiciliation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `solde_initial` decimal(12,2) DEFAULT NULL,
  `date_solde_initial` date DEFAULT NULL,
  `compte_bancaire_id` bigint unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comptes_asso_numero_pcg_unique` (`association_id`,`numero_pcg`),
  KEY `comptes_parent_compte_id_foreign` (`parent_compte_id`),
  KEY `comptes_compte_bancaire_id_foreign` (`compte_bancaire_id`),
  KEY `comptes_asso_classe_idx` (`association_id`,`classe`),
  KEY `comptes_asso_lettrable_idx` (`association_id`,`lettrable`),
  CONSTRAINT `comptes_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comptes_compte_bancaire_id_foreign` FOREIGN KEY (`compte_bancaire_id`) REFERENCES `comptes_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `comptes_parent_compte_id_foreign` FOREIGN KEY (`parent_compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comptes_bancaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comptes_bancaires` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `nom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `iban` varchar(34) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bic` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domiciliation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `solde_initial` decimal(10,2) NOT NULL DEFAULT '0.00',
  `date_solde_initial` date DEFAULT NULL,
  `actif_recettes_depenses` tinyint(1) NOT NULL DEFAULT '1',
  `saisie_automatisee` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comptes_bancaires_association_id_index` (`association_id`),
  CONSTRAINT `comptes_bancaires_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `devis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `devis` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `numero` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tiers_id` bigint unsigned NOT NULL,
  `date_emission` date NOT NULL,
  `date_validite` date NOT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statut` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'brouillon',
  `montant_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `exercice` int NOT NULL,
  `accepte_par_user_id` bigint unsigned DEFAULT NULL,
  `accepte_le` datetime DEFAULT NULL,
  `refuse_par_user_id` bigint unsigned DEFAULT NULL,
  `refuse_le` datetime DEFAULT NULL,
  `annule_par_user_id` bigint unsigned DEFAULT NULL,
  `annule_le` datetime DEFAULT NULL,
  `saisi_par_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `devis_asso_exercice_numero_unique` (`association_id`,`exercice`,`numero`),
  KEY `devis_tiers_id_foreign` (`tiers_id`),
  KEY `devis_accepte_par_user_id_foreign` (`accepte_par_user_id`),
  KEY `devis_refuse_par_user_id_foreign` (`refuse_par_user_id`),
  KEY `devis_annule_par_user_id_foreign` (`annule_par_user_id`),
  KEY `devis_saisi_par_user_id_foreign` (`saisi_par_user_id`),
  KEY `devis_association_id_statut_index` (`association_id`,`statut`),
  KEY `devis_association_id_tiers_id_index` (`association_id`,`tiers_id`),
  CONSTRAINT `devis_accepte_par_user_id_foreign` FOREIGN KEY (`accepte_par_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `devis_annule_par_user_id_foreign` FOREIGN KEY (`annule_par_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `devis_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `devis_refuse_par_user_id_foreign` FOREIGN KEY (`refuse_par_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `devis_saisi_par_user_id_foreign` FOREIGN KEY (`saisi_par_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `devis_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `devis_lignes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `devis_lignes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `devis_id` bigint unsigned NOT NULL,
  `ordre` int NOT NULL DEFAULT '1',
  `type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'montant',
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prix_unitaire` decimal(12,2) DEFAULT NULL,
  `quantite` decimal(10,3) DEFAULT NULL,
  `montant` decimal(12,2) DEFAULT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `devis_lignes_devis_id_foreign` (`devis_id`),
  KEY `devis_lignes_compte_id_foreign` (`compte_id`),
  CONSTRAINT `devis_lignes_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `devis_lignes_devis_id_foreign` FOREIGN KEY (`devis_id`) REFERENCES `devis` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documents_previsionnels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents_previsionnels` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned NOT NULL,
  `participant_id` bigint unsigned NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int unsigned NOT NULL,
  `date` date NOT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  `lignes_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saisi_par` bigint unsigned NOT NULL,
  `exercice` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_prev_op_part_type_ver_unique` (`operation_id`,`participant_id`,`type`,`version`),
  UNIQUE KEY `documents_previsionnels_numero_unique` (`numero`),
  KEY `documents_previsionnels_participant_id_foreign` (`participant_id`),
  KEY `documents_previsionnels_saisi_par_foreign` (`saisi_par`),
  KEY `documents_previsionnels_association_id_index` (`association_id`),
  CONSTRAINT `documents_previsionnels_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `documents_previsionnels_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`),
  CONSTRAINT `documents_previsionnels_participant_id_foreign` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`),
  CONSTRAINT `documents_previsionnels_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`),
  CONSTRAINT `documents_previsionnels_chk_1` CHECK (json_valid(`lignes_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tiers_id` bigint unsigned DEFAULT NULL,
  `participant_id` bigint unsigned DEFAULT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `categorie` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_template_id` bigint unsigned DEFAULT NULL,
  `destinataire_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `destinataire_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `objet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `objet_rendu` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `corps_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `attachment_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `erreur_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `envoye_par` bigint unsigned DEFAULT NULL,
  `campagne_id` bigint unsigned DEFAULT NULL,
  `tracking_token` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_logs_tracking_token_unique` (`tracking_token`),
  KEY `email_logs_tiers_id_foreign` (`tiers_id`),
  KEY `email_logs_participant_id_foreign` (`participant_id`),
  KEY `email_logs_operation_id_foreign` (`operation_id`),
  KEY `email_logs_email_template_id_foreign` (`email_template_id`),
  KEY `email_logs_envoye_par_foreign` (`envoye_par`),
  KEY `email_logs_campagne_id_foreign` (`campagne_id`),
  CONSTRAINT `email_logs_campagne_id_foreign` FOREIGN KEY (`campagne_id`) REFERENCES `campagnes_email` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_logs_email_template_id_foreign` FOREIGN KEY (`email_template_id`) REFERENCES `email_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_logs_envoye_par_foreign` FOREIGN KEY (`envoye_par`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_logs_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_logs_participant_id_foreign` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_logs_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_opens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_opens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email_log_id` bigint unsigned NOT NULL,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opened_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `email_opens_email_log_id_index` (`email_log_id`),
  CONSTRAINT `email_opens_email_log_id_foreign` FOREIGN KEY (`email_log_id`) REFERENCES `email_logs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `categorie` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type_operation_id` bigint unsigned DEFAULT NULL,
  `objet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `corps` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_templates_categorie_type_operation_id_unique` (`categorie`,`type_operation_id`),
  KEY `email_templates_type_operation_id_foreign` (`type_operation_id`),
  KEY `email_templates_association_id_index` (`association_id`),
  CONSTRAINT `email_templates_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_templates_type_operation_id_foreign` FOREIGN KEY (`type_operation_id`) REFERENCES `type_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `encadrement_previsions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `encadrement_previsions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned NOT NULL,
  `tiers_id` bigint unsigned NOT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `seance_id` bigint unsigned NOT NULL,
  `montant_prevu` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `encadrement_previsions_unique` (`operation_id`,`tiers_id`,`compte_id`,`seance_id`),
  KEY `encadrement_previsions_association_id_foreign` (`association_id`),
  KEY `encadrement_previsions_tiers_id_foreign` (`tiers_id`),
  KEY `encadrement_previsions_seance_id_foreign` (`seance_id`),
  KEY `encadrement_previsions_operation_id_tiers_id_index` (`operation_id`,`tiers_id`),
  KEY `encadrement_previsions_compte_id_foreign` (`compte_id`),
  CONSTRAINT `encadrement_previsions_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `encadrement_previsions_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `encadrement_previsions_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `encadrement_previsions_seance_id_foreign` FOREIGN KEY (`seance_id`) REFERENCES `seances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `encadrement_previsions_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exercice_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exercice_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exercice_id` bigint unsigned NOT NULL,
  `action` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exercice_actions_exercice_id_foreign` (`exercice_id`),
  KEY `exercice_actions_user_id_foreign` (`user_id`),
  CONSTRAINT `exercice_actions_exercice_id_foreign` FOREIGN KEY (`exercice_id`) REFERENCES `exercices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exercice_actions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exercices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exercices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `annee` smallint NOT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ouvert',
  `date_cloture` datetime DEFAULT NULL,
  `cloture_par_id` bigint unsigned DEFAULT NULL,
  `helloasso_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exercices_association_id_annee_unique` (`association_id`,`annee`),
  KEY `exercices_cloture_par_id_foreign` (`cloture_par_id`),
  KEY `exercices_association_id_index` (`association_id`),
  CONSTRAINT `exercices_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exercices_cloture_par_id_foreign` FOREIGN KEY (`cloture_par_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `extournes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `extournes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transaction_origine_id` bigint unsigned NOT NULL,
  `transaction_extourne_id` bigint unsigned NOT NULL,
  `rapprochement_lettrage_id` bigint unsigned DEFAULT NULL,
  `association_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `extournes_transaction_origine_id_unique` (`transaction_origine_id`),
  UNIQUE KEY `extournes_transaction_extourne_id_unique` (`transaction_extourne_id`),
  KEY `extournes_created_by_foreign` (`created_by`),
  KEY `extournes_association_id_index` (`association_id`),
  KEY `extournes_rapprochement_lettrage_id_index` (`rapprochement_lettrage_id`),
  CONSTRAINT `extournes_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `extournes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `extournes_rapprochement_lettrage_id_foreign` FOREIGN KEY (`rapprochement_lettrage_id`) REFERENCES `rapprochements_bancaires` (`id`),
  CONSTRAINT `extournes_transaction_extourne_id_foreign` FOREIGN KEY (`transaction_extourne_id`) REFERENCES `transactions` (`id`),
  CONSTRAINT `extournes_transaction_origine_id_foreign` FOREIGN KEY (`transaction_origine_id`) REFERENCES `transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facture_lignes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facture_lignes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `facture_id` bigint unsigned NOT NULL,
  `transaction_ligne_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'montant',
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant` decimal(10,2) DEFAULT NULL,
  `prix_unitaire` decimal(12,2) DEFAULT NULL,
  `quantite` decimal(10,3) DEFAULT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `seance` int DEFAULT NULL,
  `ordre` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `facture_lignes_facture_id_foreign` (`facture_id`),
  KEY `facture_lignes_transaction_ligne_id_foreign` (`transaction_ligne_id`),
  KEY `facture_lignes_operation_id_foreign` (`operation_id`),
  KEY `facture_lignes_compte_id_foreign` (`compte_id`),
  CONSTRAINT `facture_lignes_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `facture_lignes_facture_id_foreign` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `facture_lignes_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `facture_lignes_transaction_ligne_id_foreign` FOREIGN KEY (`transaction_ligne_id`) REFERENCES `transaction_lignes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facture_transaction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facture_transaction` (
  `facture_id` bigint unsigned NOT NULL,
  `transaction_id` bigint unsigned NOT NULL,
  UNIQUE KEY `facture_transaction_facture_id_transaction_id_unique` (`facture_id`,`transaction_id`),
  KEY `facture_transaction_transaction_id_foreign` (`transaction_id`),
  CONSTRAINT `facture_transaction_facture_id_foreign` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `facture_transaction_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `devis_id` bigint unsigned DEFAULT NULL,
  `numero` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `statut` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'brouillon',
  `tiers_id` bigint unsigned NOT NULL,
  `compte_bancaire_id` bigint unsigned DEFAULT NULL,
  `conditions_reglement` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode_paiement_prevu` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mentions_legales` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `montant_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `numero_avoir` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_annulation` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `saisi_par` bigint unsigned NOT NULL,
  `exercice` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `factures_numero_unique` (`numero`),
  UNIQUE KEY `factures_numero_avoir_unique` (`numero_avoir`),
  KEY `factures_tiers_id_foreign` (`tiers_id`),
  KEY `factures_compte_bancaire_id_foreign` (`compte_bancaire_id`),
  KEY `factures_saisi_par_foreign` (`saisi_par`),
  KEY `factures_association_id_index` (`association_id`),
  KEY `fac_assoc_statut_idx` (`association_id`,`statut`),
  KEY `factures_devis_id_foreign` (`devis_id`),
  KEY `factures_asso_devis_idx` (`association_id`,`devis_id`),
  CONSTRAINT `factures_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `factures_compte_bancaire_id_foreign` FOREIGN KEY (`compte_bancaire_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `factures_devis_id_foreign` FOREIGN KEY (`devis_id`) REFERENCES `devis` (`id`),
  CONSTRAINT `factures_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`),
  CONSTRAINT `factures_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `factures_partenaires_deposees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `factures_partenaires_deposees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `tiers_id` bigint unsigned NOT NULL,
  `date_facture` date NOT NULL,
  `numero_facture` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pdf_taille` int unsigned NOT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'soumise',
  `motif_rejet` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `transaction_id` bigint unsigned DEFAULT NULL,
  `traitee_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `factures_partenaires_deposees_tiers_id_foreign` (`tiers_id`),
  KEY `factures_partenaires_deposees_transaction_id_foreign` (`transaction_id`),
  KEY `fpd_asso_tiers_statut_idx` (`association_id`,`tiers_id`,`statut`),
  KEY `fpd_asso_statut_created_idx` (`association_id`,`statut`,`created_at`),
  CONSTRAINT `factures_partenaires_deposees_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`),
  CONSTRAINT `factures_partenaires_deposees_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`),
  CONSTRAINT `factures_partenaires_deposees_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `familles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `familles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `code` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `familles_association_id_code_unique` (`association_id`,`code`),
  CONSTRAINT `familles_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `formulaire_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `formulaire_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `participant_id` bigint unsigned NOT NULL,
  `token` varchar(9) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expire_at` date NOT NULL,
  `rempli_at` datetime DEFAULT NULL,
  `rempli_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `formulaire_tokens_participant_id_unique` (`participant_id`),
  UNIQUE KEY `formulaire_tokens_token_unique` (`token`),
  KEY `formulaire_tokens_association_id_index` (`association_id`),
  CONSTRAINT `formulaire_tokens_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `formulaire_tokens_participant_id_foreign` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `formules_adhesion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `formules_adhesion` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `nom` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `mode` enum('exercice','duree','illimite') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `duree_mois` smallint unsigned DEFAULT NULL,
  `duree_jours` int unsigned DEFAULT NULL,
  `montant_par_defaut` decimal(10,2) DEFAULT NULL,
  `deductible_fiscal` tinyint(1) NOT NULL DEFAULT '0',
  `compte_id` bigint unsigned DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `est_helloasso` tinyint(1) NOT NULL DEFAULT '0',
  `helloasso_form_slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `helloasso_tier_id` int unsigned DEFAULT NULL,
  `helloasso_start_date` date DEFAULT NULL,
  `helloasso_end_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `formules_adhesion_helloasso_unique` (`association_id`,`helloasso_form_slug`,`helloasso_tier_id`),
  KEY `formules_adhesion_actif_idx` (`association_id`,`actif`),
  KEY `formules_adhesion_helloasso_actif_idx` (`est_helloasso`,`actif`),
  KEY `formules_adhesion_compte_id_foreign` (`compte_id`),
  CONSTRAINT `formules_adhesion_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `formules_adhesion_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `helloasso_form_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helloasso_form_mappings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `helloasso_parametres_id` bigint unsigned NOT NULL,
  `form_slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `form_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `form_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `state` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `ignore` tinyint(1) NOT NULL DEFAULT '0',
  `imported_at` timestamp NULL DEFAULT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `helloasso_form_mappings_helloasso_parametres_id_form_slug_unique` (`helloasso_parametres_id`,`form_slug`),
  KEY `helloasso_form_mappings_operation_id_foreign` (`operation_id`),
  KEY `helloasso_form_mappings_compte_id_foreign` (`compte_id`),
  CONSTRAINT `helloasso_form_mappings_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `helloasso_form_mappings_helloasso_parametres_id_foreign` FOREIGN KEY (`helloasso_parametres_id`) REFERENCES `helloasso_parametres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helloasso_form_mappings_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `helloasso_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helloasso_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `event_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `helloasso_notifications_association_id_foreign` (`association_id`),
  CONSTRAINT `helloasso_notifications_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `helloasso_notifications_chk_1` CHECK (json_valid(`payload`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `helloasso_parametres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `helloasso_parametres` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `organisation_slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `environnement` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'production',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `compte_helloasso_id` bigint unsigned DEFAULT NULL,
  `compte_versement_id` bigint unsigned DEFAULT NULL,
  `compte_don_id` bigint unsigned DEFAULT NULL,
  `callback_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `helloasso_parametres_association_id_unique` (`association_id`),
  KEY `helloasso_parametres_compte_helloasso_id_foreign` (`compte_helloasso_id`),
  KEY `helloasso_parametres_compte_versement_id_foreign` (`compte_versement_id`),
  KEY `helloasso_parametres_compte_don_id_foreign` (`compte_don_id`),
  CONSTRAINT `helloasso_parametres_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`),
  CONSTRAINT `helloasso_parametres_compte_don_id_foreign` FOREIGN KEY (`compte_don_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `helloasso_parametres_compte_helloasso_id_foreign` FOREIGN KEY (`compte_helloasso_id`) REFERENCES `comptes_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `helloasso_parametres_compte_versement_id_foreign` FOREIGN KEY (`compte_versement_id`) REFERENCES `comptes_bancaires` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incoming_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incoming_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `storage_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `received_at` timestamp NOT NULL,
  `source_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `handler_attempted` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incoming_documents_source_message_id_index` (`source_message_id`),
  KEY `incoming_documents_association_id_received_at_index` (`association_id`,`received_at`),
  CONSTRAINT `incoming_documents_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incoming_mail_allowed_senders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incoming_mail_allowed_senders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incoming_mail_allowed_senders_association_id_email_unique` (`association_id`,`email`),
  CONSTRAINT `incoming_mail_allowed_senders_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incoming_mail_parametres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incoming_mail_parametres` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `imap_host` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imap_port` smallint unsigned NOT NULL DEFAULT '993',
  `imap_encryption` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ssl',
  `imap_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imap_password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `processed_folder` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INBOX.Processed',
  `errors_folder` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INBOX.Errors',
  `max_per_run` smallint unsigned NOT NULL DEFAULT '50',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incoming_mail_parametres_association_id_unique` (`association_id`),
  CONSTRAINT `incoming_mail_parametres_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lettrage_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lettrage_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `action` enum('lettre','delettre') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lettrage_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `compte_id` bigint unsigned NOT NULL,
  `transaction_ligne_ids` json NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `motif` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `lettrage_audit_compte_id_foreign` (`compte_id`),
  KEY `lettrage_audit_user_id_foreign` (`user_id`),
  KEY `lettrage_audit_asso_code_idx` (`association_id`,`lettrage_code`),
  KEY `lettrage_audit_asso_created_idx` (`association_id`,`created_at`),
  CONSTRAINT `lettrage_audit_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lettrage_audit_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lettrage_audit_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `categorie` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operation',
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `objet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `corps` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type_operation_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `message_templates_type_operation_id_foreign` (`type_operation_id`),
  KEY `message_templates_association_id_index` (`association_id`),
  CONSTRAINT `message_templates_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_templates_type_operation_id_foreign` FOREIGN KEY (`type_operation_id`) REFERENCES `type_operations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newsletter_subscription_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsletter_subscription_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','confirmed','unsubscribed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `confirmation_token_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmation_expires_at` timestamp NULL DEFAULT NULL,
  `unsubscribe_token_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subscribed_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_key_id` bigint unsigned DEFAULT NULL,
  `tiers_id` bigint unsigned DEFAULT NULL,
  `ignored_at` timestamp NULL DEFAULT NULL,
  `desinscription_traitee_at` timestamp NULL DEFAULT NULL,
  `desinscription_action` enum('optout','deleted','noop') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `processed_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `newsletter_subscription_requests_unsubscribe_token_hash_unique` (`unsubscribe_token_hash`),
  KEY `newsletter_subscription_requests_tiers_id_foreign` (`tiers_id`),
  KEY `newsletter_subscription_requests_email_index` (`email`),
  KEY `newsletter_subscription_requests_confirmation_token_hash_index` (`confirmation_token_hash`),
  KEY `newsletter_subscription_requests_association_id_status_index` (`association_id`,`status`),
  KEY `newsletter_subscription_requests_association_id_email_index` (`association_id`,`email`),
  KEY `newsletter_subscription_requests_processed_by_user_id_foreign` (`processed_by_user_id`),
  KEY `idx_newsletter_inbox_inscriptions` (`association_id`,`status`,`tiers_id`,`ignored_at`),
  KEY `idx_newsletter_inbox_desinscriptions` (`association_id`,`status`,`desinscription_traitee_at`),
  KEY `newsletter_subscription_requests_api_key_id_foreign` (`api_key_id`),
  CONSTRAINT `newsletter_subscription_requests_api_key_id_foreign` FOREIGN KEY (`api_key_id`) REFERENCES `association_api_keys` (`id`) ON DELETE SET NULL,
  CONSTRAINT `newsletter_subscription_requests_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsletter_subscription_requests_processed_by_user_id_foreign` FOREIGN KEY (`processed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `newsletter_subscription_requests_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notes_de_frais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notes_de_frais` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `tiers_id` bigint unsigned NOT NULL,
  `date` date DEFAULT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statut` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'brouillon',
  `abandon_creance_propose` tinyint(1) NOT NULL DEFAULT '0',
  `motif_rejet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_id` bigint unsigned DEFAULT NULL,
  `don_transaction_id` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `validee_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notes_de_frais_tiers_id_foreign` (`tiers_id`),
  KEY `notes_de_frais_transaction_id_foreign` (`transaction_id`),
  KEY `notes_de_frais_association_id_tiers_id_index` (`association_id`,`tiers_id`),
  KEY `ndf_asso_statut_idx` (`association_id`,`statut`),
  KEY `notes_de_frais_don_transaction_id_foreign` (`don_transaction_id`),
  CONSTRAINT `notes_de_frais_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`),
  CONSTRAINT `notes_de_frais_don_transaction_id_foreign` FOREIGN KEY (`don_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notes_de_frais_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`),
  CONSTRAINT `notes_de_frais_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notes_de_frais_lignes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notes_de_frais_lignes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `note_de_frais_id` bigint unsigned NOT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `seance` smallint unsigned DEFAULT NULL,
  `type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `montant` decimal(10,2) NOT NULL,
  `piece_jointe_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notes_de_frais_lignes_note_de_frais_id_foreign` (`note_de_frais_id`),
  KEY `notes_de_frais_lignes_operation_id_foreign` (`operation_id`),
  KEY `notes_de_frais_lignes_compte_id_foreign` (`compte_id`),
  CONSTRAINT `notes_de_frais_lignes_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notes_de_frais_lignes_note_de_frais_id_foreign` FOREIGN KEY (`note_de_frais_id`) REFERENCES `notes_de_frais` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notes_de_frais_lignes_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notes_de_frais_lignes_chk_1` CHECK (json_valid(`metadata`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `nom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `nombre_seances` int DEFAULT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_cours',
  `type_operation_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `operations_type_operation_id_foreign` (`type_operation_id`),
  KEY `operations_association_id_index` (`association_id`),
  KEY `ops_assoc_date_debut_idx` (`association_id`,`date_debut`),
  CONSTRAINT `operations_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operations_type_operation_id_foreign` FOREIGN KEY (`type_operation_id`) REFERENCES `type_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `participant_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `participant_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `participant_id` bigint unsigned NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `participant_documents_participant_id_foreign` (`participant_id`),
  KEY `participant_documents_association_id_index` (`association_id`),
  CONSTRAINT `participant_documents_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `participant_documents_participant_id_foreign` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `participant_donnees_medicales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `participant_donnees_medicales` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `participant_id` bigint unsigned NOT NULL,
  `date_naissance` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sexe` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `poids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `taille` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `medecin_nom` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `medecin_prenom` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `medecin_telephone` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `medecin_email` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `medecin_adresse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `medecin_code_postal` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `medecin_ville` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `therapeute_nom` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `therapeute_prenom` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `therapeute_telephone` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `therapeute_email` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `therapeute_adresse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `therapeute_code_postal` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `therapeute_ville` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `participant_donnees_medicales_participant_id_unique` (`participant_id`),
  CONSTRAINT `participant_donnees_medicales_participant_id_foreign` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `tiers_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned NOT NULL,
  `type_operation_tarif_id` bigint unsigned DEFAULT NULL,
  `date_inscription` date DEFAULT NULL,
  `est_helloasso` tinyint(1) NOT NULL DEFAULT '0',
  `helloasso_item_id` int unsigned DEFAULT NULL,
  `helloasso_order_id` int unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `refere_par_id` bigint unsigned DEFAULT NULL,
  `nom_jeune_fille` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationalite` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_par_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_par_prenom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_par_etablissement` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_par_telephone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_par_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_par_adresse` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_par_code_postal` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_par_ville` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `droit_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode_paiement_choisi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `moyen_paiement_choisi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `autorisation_contact_medecin` tinyint(1) NOT NULL DEFAULT '0',
  `rgpd_accepte_at` datetime DEFAULT NULL,
  `medecin_tiers_id` bigint unsigned DEFAULT NULL,
  `therapeute_tiers_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `participants_tiers_id_operation_id_unique` (`tiers_id`,`operation_id`),
  KEY `participants_operation_id_index` (`operation_id`),
  KEY `participants_refere_par_id_foreign` (`refere_par_id`),
  KEY `participants_type_operation_tarif_id_foreign` (`type_operation_tarif_id`),
  KEY `participants_medecin_tiers_id_foreign` (`medecin_tiers_id`),
  KEY `participants_therapeute_tiers_id_foreign` (`therapeute_tiers_id`),
  KEY `participants_association_id_index` (`association_id`),
  CONSTRAINT `participants_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `participants_medecin_tiers_id_foreign` FOREIGN KEY (`medecin_tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `participants_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`),
  CONSTRAINT `participants_refere_par_id_foreign` FOREIGN KEY (`refere_par_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `participants_therapeute_tiers_id_foreign` FOREIGN KEY (`therapeute_tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `participants_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`),
  CONSTRAINT `participants_type_operation_tarif_id_foreign` FOREIGN KEY (`type_operation_tarif_id`) REFERENCES `type_operation_tarifs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `seance_id` bigint unsigned NOT NULL,
  `participant_id` bigint unsigned NOT NULL,
  `statut` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `kine` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `commentaire` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `presences_seance_id_participant_id_unique` (`seance_id`,`participant_id`),
  KEY `presences_participant_id_foreign` (`participant_id`),
  CONSTRAINT `presences_participant_id_foreign` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presences_seance_id_foreign` FOREIGN KEY (`seance_id`) REFERENCES `seances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `provisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `provisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `exercice` smallint NOT NULL,
  `type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `tiers_id` bigint unsigned DEFAULT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `seance` int DEFAULT NULL,
  `date` date NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `piece_jointe_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `piece_jointe_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `piece_jointe_mime` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saisi_par` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `provisions_tiers_id_foreign` (`tiers_id`),
  KEY `provisions_operation_id_foreign` (`operation_id`),
  KEY `provisions_saisi_par_foreign` (`saisi_par`),
  KEY `provisions_exercice_index` (`exercice`),
  KEY `provisions_association_id_index` (`association_id`),
  KEY `provisions_compte_id_foreign` (`compte_id`),
  CONSTRAINT `provisions_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `provisions_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `provisions_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`),
  CONSTRAINT `provisions_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`),
  CONSTRAINT `provisions_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_answers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `submission_id` bigint unsigned NOT NULL,
  `campaign_question_id` bigint unsigned NOT NULL,
  `value_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `value_integer` int DEFAULT NULL,
  `value_boolean` tinyint(1) DEFAULT NULL,
  `value_option` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `value_meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `questionnaire_answers_submission_id_campaign_question_id_unique` (`submission_id`,`campaign_question_id`),
  KEY `questionnaire_answers_association_id_foreign` (`association_id`),
  KEY `questionnaire_answers_campaign_question_id_foreign` (`campaign_question_id`),
  CONSTRAINT `questionnaire_answers_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_answers_campaign_question_id_foreign` FOREIGN KEY (`campaign_question_id`) REFERENCES `questionnaire_campaign_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_answers_submission_id_foreign` FOREIGN KEY (`submission_id`) REFERENCES `questionnaire_submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_answers_chk_1` CHECK (json_valid(`value_meta`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_bearer_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_bearer_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `campaign_id` bigint unsigned NOT NULL,
  `token_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_clair` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `questionnaire_bearer_tokens_token_hash_unique` (`token_hash`),
  KEY `questionnaire_bearer_tokens_association_id_foreign` (`association_id`),
  KEY `questionnaire_bearer_tokens_campaign_id_foreign` (`campaign_id`),
  CONSTRAINT `questionnaire_bearer_tokens_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_bearer_tokens_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `questionnaire_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_campaign_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_campaign_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `campaign_id` bigint unsigned NOT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `aide` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordre` int unsigned NOT NULL DEFAULT '0',
  `obligatoire` tinyint(1) NOT NULL DEFAULT '0',
  `grouper_avec_precedente` tinyint(1) NOT NULL DEFAULT '0',
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `questionnaire_campaign_questions_association_id_foreign` (`association_id`),
  KEY `questionnaire_campaign_questions_campaign_id_ordre_index` (`campaign_id`,`ordre`),
  CONSTRAINT `questionnaire_campaign_questions_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_campaign_questions_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `questionnaire_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_campaign_questions_chk_1` CHECK (json_valid(`config`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `titre_affiche` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `intro` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `remerciement` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `statut` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'brouillon',
  `ouverte_at` timestamp NULL DEFAULT NULL,
  `cloturee_at` timestamp NULL DEFAULT NULL,
  `anonymise` tinyint(1) NOT NULL DEFAULT '1',
  `autoriser_retour` tinyint(1) NOT NULL DEFAULT '1',
  `afficher_progression` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `questionnaire_campaigns_association_id_foreign` (`association_id`),
  KEY `questionnaire_campaigns_template_id_foreign` (`template_id`),
  KEY `questionnaire_campaigns_operation_id_statut_index` (`operation_id`,`statut`),
  CONSTRAINT `questionnaire_campaigns_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_campaigns_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_campaigns_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `questionnaire_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `campaign_id` bigint unsigned NOT NULL,
  `participant_id` bigint unsigned NOT NULL,
  `token_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_chiffre` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_court` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `statut` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'non_ouvert',
  `sent_at` timestamp NULL DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `questionnaire_invitations_campaign_id_participant_id_unique` (`campaign_id`,`participant_id`),
  UNIQUE KEY `questionnaire_invitations_token_hash_unique` (`token_hash`),
  KEY `questionnaire_invitations_association_id_foreign` (`association_id`),
  KEY `questionnaire_invitations_participant_id_foreign` (`participant_id`),
  KEY `questionnaire_invitations_campaign_id_index` (`campaign_id`),
  CONSTRAINT `questionnaire_invitations_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_invitations_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `questionnaire_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_invitations_participant_id_foreign` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_ocr_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_ocr_drafts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `scan_id` bigint unsigned NOT NULL,
  `invitation_id` bigint unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `statut` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'brouillon',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `bearer_token_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `questionnaire_ocr_drafts_association_id_foreign` (`association_id`),
  KEY `questionnaire_ocr_drafts_scan_id_foreign` (`scan_id`),
  KEY `questionnaire_ocr_drafts_invitation_id_foreign` (`invitation_id`),
  KEY `questionnaire_ocr_drafts_bearer_token_id_foreign` (`bearer_token_id`),
  CONSTRAINT `questionnaire_ocr_drafts_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_ocr_drafts_bearer_token_id_foreign` FOREIGN KEY (`bearer_token_id`) REFERENCES `questionnaire_bearer_tokens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `questionnaire_ocr_drafts_invitation_id_foreign` FOREIGN KEY (`invitation_id`) REFERENCES `questionnaire_invitations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `questionnaire_ocr_drafts_scan_id_foreign` FOREIGN KEY (`scan_id`) REFERENCES `questionnaire_paper_scans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_ocr_drafts_chk_1` CHECK (json_valid(`payload`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_paper_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_paper_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `campaign_id` bigint unsigned NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cree_par` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `questionnaire_paper_batches_association_id_foreign` (`association_id`),
  KEY `questionnaire_paper_batches_campaign_id_foreign` (`campaign_id`),
  KEY `questionnaire_paper_batches_cree_par_foreign` (`cree_par`),
  CONSTRAINT `questionnaire_paper_batches_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_paper_batches_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `questionnaire_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_paper_batches_cree_par_foreign` FOREIGN KEY (`cree_par`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_paper_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_paper_scans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `invitation_id` bigint unsigned DEFAULT NULL,
  `batch_id` bigint unsigned DEFAULT NULL,
  `incoming_document_id` bigint unsigned DEFAULT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `chemin_fichier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `qr_statut` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'illisible',
  `statut` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_attente',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `bearer_token_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `questionnaire_paper_scans_association_id_foreign` (`association_id`),
  KEY `questionnaire_paper_scans_invitation_id_foreign` (`invitation_id`),
  KEY `questionnaire_paper_scans_batch_id_foreign` (`batch_id`),
  KEY `questionnaire_paper_scans_incoming_document_id_foreign` (`incoming_document_id`),
  KEY `questionnaire_paper_scans_campaign_id_statut_index` (`campaign_id`,`statut`),
  KEY `questionnaire_paper_scans_bearer_token_id_foreign` (`bearer_token_id`),
  CONSTRAINT `questionnaire_paper_scans_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_paper_scans_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `questionnaire_paper_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `questionnaire_paper_scans_bearer_token_id_foreign` FOREIGN KEY (`bearer_token_id`) REFERENCES `questionnaire_bearer_tokens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `questionnaire_paper_scans_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `questionnaire_campaigns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `questionnaire_paper_scans_incoming_document_id_foreign` FOREIGN KEY (`incoming_document_id`) REFERENCES `incoming_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `questionnaire_paper_scans_invitation_id_foreign` FOREIGN KEY (`invitation_id`) REFERENCES `questionnaire_invitations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_submissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `campaign_id` bigint unsigned NOT NULL,
  `invitation_id` bigint unsigned DEFAULT NULL,
  `statut` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_cours',
  `accepte_contact` tinyint(1) NOT NULL DEFAULT '0',
  `contact_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_ligne',
  `remplacee_par_id` bigint unsigned DEFAULT NULL,
  `active_key` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `bearer_token_id` bigint unsigned DEFAULT NULL,
  `paper_scan_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `questionnaire_submissions_active_key_unique` (`active_key`),
  KEY `questionnaire_submissions_association_id_foreign` (`association_id`),
  KEY `questionnaire_submissions_campaign_id_statut_index` (`campaign_id`,`statut`),
  KEY `questionnaire_submissions_invitation_id_index` (`invitation_id`),
  KEY `questionnaire_submissions_remplacee_par_id_foreign` (`remplacee_par_id`),
  KEY `questionnaire_submissions_bearer_token_id_foreign` (`bearer_token_id`),
  KEY `questionnaire_submissions_paper_scan_id_foreign` (`paper_scan_id`),
  CONSTRAINT `questionnaire_submissions_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_submissions_bearer_token_id_foreign` FOREIGN KEY (`bearer_token_id`) REFERENCES `questionnaire_bearer_tokens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `questionnaire_submissions_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `questionnaire_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_submissions_invitation_id_foreign` FOREIGN KEY (`invitation_id`) REFERENCES `questionnaire_invitations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_submissions_paper_scan_id_foreign` FOREIGN KEY (`paper_scan_id`) REFERENCES `questionnaire_paper_scans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `questionnaire_submissions_remplacee_par_id_foreign` FOREIGN KEY (`remplacee_par_id`) REFERENCES `questionnaire_submissions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_template_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_template_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned NOT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `aide` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordre` int unsigned NOT NULL DEFAULT '0',
  `obligatoire` tinyint(1) NOT NULL DEFAULT '0',
  `grouper_avec_precedente` tinyint(1) NOT NULL DEFAULT '0',
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `questionnaire_template_questions_association_id_foreign` (`association_id`),
  KEY `questionnaire_template_questions_template_id_ordre_index` (`template_id`,`ordre`),
  CONSTRAINT `questionnaire_template_questions_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_template_questions_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `questionnaire_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questionnaire_template_questions_chk_1` CHECK (json_valid(`config`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `questionnaire_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questionnaire_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `titre_interne` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `titre_affiche` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `intro` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `remerciement` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `anonymise` tinyint(1) NOT NULL DEFAULT '1',
  `autoriser_retour` tinyint(1) NOT NULL DEFAULT '1',
  `afficher_progression` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `questionnaire_templates_association_id_index` (`association_id`),
  CONSTRAINT `questionnaire_templates_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rapprochements_bancaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rapprochements_bancaires` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `compte_id` bigint unsigned NOT NULL,
  `date_fin` date NOT NULL,
  `solde_ouverture` decimal(10,2) NOT NULL,
  `solde_fin` decimal(10,2) NOT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_cours',
  `type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bancaire',
  `saisi_par` bigint unsigned NOT NULL,
  `verrouille_at` timestamp NULL DEFAULT NULL,
  `piece_jointe_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `piece_jointe_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `piece_jointe_mime` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rapprochements_bancaires_compte_id_foreign` (`compte_id`),
  KEY `rapprochements_bancaires_saisi_par_foreign` (`saisi_par`),
  KEY `rapprochements_bancaires_association_id_index` (`association_id`),
  KEY `rapprochements_bancaires_type_index` (`type`),
  CONSTRAINT `rapprochements_bancaires_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rapprochements_bancaires_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `rapprochements_bancaires_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recus_fiscaux_emis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recus_fiscaux_emis` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `numero` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `annee_civile` smallint NOT NULL,
  `tiers_id` bigint unsigned NOT NULL,
  `transaction_ligne_id` bigint unsigned DEFAULT NULL,
  `montant_centimes` int NOT NULL,
  `date_versement` date NOT NULL,
  `mode_versement` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `forme_don` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `article_cgi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pdf_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `emitted_at` timestamp NOT NULL,
  `emitted_by_user_id` bigint unsigned DEFAULT NULL,
  `annule_at` timestamp NULL DEFAULT NULL,
  `annule_motif` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `remplace_par_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recus_fiscaux_emis_association_id_numero_unique` (`association_id`,`numero`),
  KEY `recus_fiscaux_emis_tiers_id_foreign` (`tiers_id`),
  KEY `recus_fiscaux_emis_transaction_ligne_id_foreign` (`transaction_ligne_id`),
  KEY `recus_fiscaux_emis_emitted_by_user_id_foreign` (`emitted_by_user_id`),
  KEY `recus_fiscaux_emis_remplace_par_id_foreign` (`remplace_par_id`),
  KEY `recus_fiscaux_emis_association_id_tiers_id_annee_civile_index` (`association_id`,`tiers_id`,`annee_civile`),
  KEY `recus_fiscaux_emis_association_id_transaction_ligne_id_index` (`association_id`,`transaction_ligne_id`),
  CONSTRAINT `recus_fiscaux_emis_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recus_fiscaux_emis_emitted_by_user_id_foreign` FOREIGN KEY (`emitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recus_fiscaux_emis_remplace_par_id_foreign` FOREIGN KEY (`remplace_par_id`) REFERENCES `recus_fiscaux_emis` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recus_fiscaux_emis_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`),
  CONSTRAINT `recus_fiscaux_emis_transaction_ligne_id_foreign` FOREIGN KEY (`transaction_ligne_id`) REFERENCES `transaction_lignes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reglements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reglements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `participant_id` bigint unsigned NOT NULL,
  `seance_id` bigint unsigned NOT NULL,
  `mode_paiement` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `montant_prevu` decimal(10,2) NOT NULL DEFAULT '0.00',
  `remise_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reglements_participant_id_seance_id_unique` (`participant_id`,`seance_id`),
  KEY `reglements_seance_id_foreign` (`seance_id`),
  KEY `reglements_remise_id_foreign` (`remise_id`),
  CONSTRAINT `reglements_participant_id_foreign` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reglements_remise_id_foreign` FOREIGN KEY (`remise_id`) REFERENCES `remises_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `reglements_seance_id_foreign` FOREIGN KEY (`seance_id`) REFERENCES `seances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `remises_bancaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `remises_bancaires` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `numero` int unsigned NOT NULL,
  `date` date NOT NULL,
  `mode_paiement` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `compte_cible_id` bigint unsigned NOT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `saisi_par` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `comptabilisee_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `remises_bancaires_numero_unique` (`numero`),
  KEY `remises_bancaires_compte_cible_id_foreign` (`compte_cible_id`),
  KEY `remises_bancaires_saisi_par_foreign` (`saisi_par`),
  KEY `remises_bancaires_association_id_index` (`association_id`),
  CONSTRAINT `remises_bancaires_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `remises_bancaires_compte_cible_id_foreign` FOREIGN KEY (`compte_cible_id`) REFERENCES `comptes_bancaires` (`id`),
  CONSTRAINT `remises_bancaires_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned NOT NULL,
  `numero` int unsigned NOT NULL,
  `date` date DEFAULT NULL,
  `titre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `feuille_signee_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feuille_signee_at` timestamp NULL DEFAULT NULL,
  `feuille_signee_source` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feuille_signee_sender_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seances_operation_id_numero_unique` (`operation_id`,`numero`),
  KEY `seances_association_id_index` (`association_id`),
  CONSTRAINT `seances_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seances_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `exercice` varchar(9) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dernier_numero` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sequences_association_id_exercice_unique` (`association_id`,`exercice`),
  KEY `sequences_association_id_index` (`association_id`),
  CONSTRAINT `sequences_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `smtp_parametres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `smtp_parametres` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `smtp_host` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_port` smallint unsigned NOT NULL DEFAULT '587',
  `smtp_encryption` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tls',
  `smtp_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `timeout` smallint unsigned NOT NULL DEFAULT '30',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `smtp_parametres_association_id_unique` (`association_id`),
  CONSTRAINT `smtp_parametres_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `super_admin_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `super_admin_access_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `association_id` bigint unsigned DEFAULT NULL,
  `action` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `super_admin_access_log_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `super_admin_access_log_association_id_created_at_index` (`association_id`,`created_at`),
  CONSTRAINT `super_admin_access_log_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE SET NULL,
  CONSTRAINT `super_admin_access_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `super_admin_access_log_chk_1` CHECK (json_valid(`payload`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tiers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `type` enum('entreprise','particulier') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'particulier',
  `nom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prenom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `civilite` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entreprise` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_naissance` date DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_ligne1` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `code_postal` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ville` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pays` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'France',
  `pour_depenses` tinyint(1) NOT NULL DEFAULT '0',
  `pour_recettes` tinyint(1) NOT NULL DEFAULT '0',
  `est_helloasso` tinyint(1) NOT NULL DEFAULT '0',
  `email_optout` tinyint(1) NOT NULL DEFAULT '0',
  `helloasso_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `helloasso_prenom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tiers_association_id_index` (`association_id`),
  KEY `tiers_assoc_nom_idx` (`association_id`,`nom`),
  KEY `tiers_association_id_email_index` (`association_id`,`email`),
  CONSTRAINT `tiers_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tiers_portail_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tiers_portail_otps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `last_sent_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tiers_portail_otps_association_id_email_index` (`association_id`,`email`),
  CONSTRAINT `tiers_portail_otps_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaction_ligne_affectations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_ligne_affectations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transaction_ligne_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `seance` int unsigned DEFAULT NULL,
  `montant` decimal(10,2) NOT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_ligne_affectations_operation_id_foreign` (`operation_id`),
  KEY `tla_tl_idx` (`transaction_ligne_id`),
  CONSTRAINT `transaction_ligne_affectations_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaction_ligne_affectations_transaction_ligne_id_foreign` FOREIGN KEY (`transaction_ligne_id`) REFERENCES `transaction_lignes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaction_lignes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_lignes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned DEFAULT NULL,
  `seance` int DEFAULT NULL,
  `montant` decimal(10,2) NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `piece_jointe_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `helloasso_item_id` bigint unsigned DEFAULT NULL,
  `helloasso_option_id` int unsigned DEFAULT NULL,
  `helloasso_tier_id` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `debit` decimal(12,2) NOT NULL DEFAULT '0.00',
  `credit` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tiers_id` bigint unsigned DEFAULT NULL,
  `lettrage_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tl_ha_item_option_unique` (`helloasso_item_id`,`helloasso_option_id`),
  KEY `tl_operation_idx` (`operation_id`),
  KEY `transaction_lignes_helloasso_tier_id_idx` (`helloasso_tier_id`),
  KEY `transaction_lignes_tiers_id_foreign` (`tiers_id`),
  KEY `transaction_lignes_compte_id_tiers_id_lettrage_code_index` (`compte_id`,`tiers_id`,`lettrage_code`),
  KEY `transaction_lignes_lettrage_code_index` (`lettrage_code`),
  KEY `transaction_lignes_compte_id_tiers_id_index` (`compte_id`,`tiers_id`),
  KEY `transaction_lignes_transaction_id_index` (`transaction_id`),
  CONSTRAINT `transaction_lignes_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaction_lignes_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaction_lignes_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaction_lignes_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  `equilibree` tinyint(1) NOT NULL DEFAULT '0',
  `type_ecriture` enum('normale','an','od','extourne') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normale',
  `journal` enum('vente','achat','banque','od') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode_paiement` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tiers_id` bigint unsigned DEFAULT NULL,
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compte_id` bigint unsigned DEFAULT NULL,
  `statut_reglement` enum('en_attente','recu','pointe','en_main') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_attente',
  `extournee_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `saisi_par` bigint unsigned DEFAULT NULL,
  `rapprochement_id` bigint unsigned DEFAULT NULL,
  `remise_id` bigint unsigned DEFAULT NULL,
  `reglement_id` bigint unsigned DEFAULT NULL,
  `numero_piece` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `piece_jointe_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `piece_jointe_nom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `piece_jointe_mime` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `helloasso_order_id` bigint unsigned DEFAULT NULL,
  `helloasso_cashout_id` bigint unsigned DEFAULT NULL,
  `virement_interne_id` bigint unsigned DEFAULT NULL,
  `provision_id` bigint unsigned DEFAULT NULL,
  `helloasso_payment_id` bigint unsigned DEFAULT NULL,
  `helloasso_form_slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `motif_suppression` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `supprime_par` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transactions_ha_order_tiers_unique` (`helloasso_order_id`,`tiers_id`),
  KEY `transactions_tiers_id_foreign` (`tiers_id`),
  KEY `transactions_compte_id_foreign` (`compte_id`),
  KEY `transactions_saisi_par_foreign` (`saisi_par`),
  KEY `transactions_rapprochement_id_foreign` (`rapprochement_id`),
  KEY `transactions_helloasso_cashout_id_index` (`helloasso_cashout_id`),
  KEY `transactions_helloasso_payment_id_index` (`helloasso_payment_id`),
  KEY `transactions_remise_id_foreign` (`remise_id`),
  KEY `transactions_reglement_id_foreign` (`reglement_id`),
  KEY `transactions_association_id_index` (`association_id`),
  KEY `trx_assoc_date_idx` (`association_id`,`date`),
  KEY `transactions_extournee_at_index` (`extournee_at`),
  KEY `transactions_helloasso_form_slug_idx` (`helloasso_form_slug`),
  KEY `transactions_supprime_par_foreign` (`supprime_par`),
  KEY `transactions_virement_interne_id_index` (`virement_interne_id`),
  KEY `transactions_provision_id_foreign` (`provision_id`),
  CONSTRAINT `transactions_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_provision_id_foreign` FOREIGN KEY (`provision_id`) REFERENCES `provisions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_rapprochement_id_foreign` FOREIGN KEY (`rapprochement_id`) REFERENCES `rapprochements_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_reglement_id_foreign` FOREIGN KEY (`reglement_id`) REFERENCES `reglements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_remise_id_foreign` FOREIGN KEY (`remise_id`) REFERENCES `remises_bancaires` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_saisi_par_foreign` FOREIGN KEY (`saisi_par`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_supprime_par_foreign` FOREIGN KEY (`supprime_par`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_tiers_id_foreign` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_virement_interne_id_foreign` FOREIGN KEY (`virement_interne_id`) REFERENCES `virements_internes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `two_factor_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `two_factor_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `two_factor_codes_user_id_foreign` (`user_id`),
  CONSTRAINT `two_factor_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `type_operation_seances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `type_operation_seances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type_operation_id` bigint unsigned NOT NULL,
  `numero` int unsigned NOT NULL,
  `titre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_operation_seances_type_operation_id_numero_unique` (`type_operation_id`,`numero`),
  CONSTRAINT `type_operation_seances_type_operation_id_foreign` FOREIGN KEY (`type_operation_id`) REFERENCES `type_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `type_operation_tarifs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `type_operation_tarifs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type_operation_id` bigint unsigned NOT NULL,
  `libelle` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_operation_tarifs_type_operation_id_libelle_unique` (`type_operation_id`,`libelle`),
  CONSTRAINT `type_operation_tarifs_type_operation_id_foreign` FOREIGN KEY (`type_operation_id`) REFERENCES `type_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `type_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `type_operations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `nom` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle_article` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `compte_id` bigint unsigned DEFAULT NULL,
  `nombre_seances` int DEFAULT NULL,
  `reserve_adherents` tinyint(1) NOT NULL DEFAULT '0',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attestation_medicale_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `formulaire_actif` tinyint(1) NOT NULL DEFAULT '0',
  `formulaire_prescripteur` tinyint(1) NOT NULL DEFAULT '0',
  `formulaire_parcours_therapeutique` tinyint(1) NOT NULL DEFAULT '0',
  `formulaire_droit_image` tinyint(1) NOT NULL DEFAULT '0',
  `formulaire_prescripteur_titre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `formulaire_qualificatif_atelier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_from` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_from_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_operations_association_id_nom_unique` (`association_id`,`nom`),
  KEY `type_operations_association_id_index` (`association_id`),
  KEY `type_operations_compte_id_foreign` (`compte_id`),
  CONSTRAINT `type_operations_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `type_operations_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usages_comptes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usages_comptes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `compte_id` bigint unsigned NOT NULL,
  `usage` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usages_comptes_unique` (`association_id`,`compte_id`,`usage`),
  KEY `usages_comptes_compte_id_index` (`compte_id`),
  KEY `usages_comptes_asso_usage_idx` (`association_id`,`usage`),
  CONSTRAINT `usages_comptes_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
  CONSTRAINT `usages_comptes_compte_id_foreign` FOREIGN KEY (`compte_id`) REFERENCES `comptes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dernier_espace` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'compta',
  `derniere_association_id` bigint unsigned DEFAULT NULL,
  `peut_voir_donnees_sensibles` tinyint(1) NOT NULL DEFAULT '0',
  `role_systeme` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `two_factor_method` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `two_factor_recovery_codes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `two_factor_trusted_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_derniere_association_id_foreign` (`derniere_association_id`),
  CONSTRAINT `users_derniere_association_id_foreign` FOREIGN KEY (`derniere_association_id`) REFERENCES `association` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `virements_internes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `virements_internes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `association_id` bigint unsigned NOT NULL,
  `numero_piece` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `helloasso_cashout_id` bigint unsigned DEFAULT NULL,
  `date` date NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `compte_source_id` bigint unsigned NOT NULL,
  `compte_destination_id` bigint unsigned NOT NULL,
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rapprochement_source_id` bigint unsigned DEFAULT NULL,
  `rapprochement_destination_id` bigint unsigned DEFAULT NULL,
  `saisi_par` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `virements_internes_numero_piece_unique` (`numero_piece`),
  UNIQUE KEY `virements_internes_helloasso_cashout_id_unique` (`helloasso_cashout_id`),
  KEY `virements_internes_compte_source_id_foreign` (`compte_source_id`),
  KEY `virements_internes_compte_destination_id_foreign` (`compte_destination_id`),
  KEY `virements_internes_saisi_par_foreign` (`saisi_par`),
  KEY `virements_internes_rapprochement_source_id_foreign` (`rapprochement_source_id`),
  KEY `virements_internes_rapprochement_destination_id_foreign` (`rapprochement_destination_id`),
  KEY `virements_internes_association_id_index` (`association_id`),
  CONSTRAINT `virements_internes_association_id_foreign` FOREIGN KEY (`association_id`) REFERENCES `association` (`id`) ON DELETE CASCADE,
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
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_03_14_000001_create_association_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_03_14_100000_create_tiers_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_03_14_200001_add_membre_fields_to_tiers',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_03_14_200002_add_tiers_id_fk_to_transactions',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_03_14_200003_migrate_donateurs_to_tiers',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_03_14_200004_migrate_membres_to_tiers',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_03_14_200005_finalize_dons_tiers_id',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_03_14_200006_finalize_cotisations_tiers_id',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_03_14_200007_finalize_depenses_recettes_tiers_id',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_03_15_000001_alter_depenses_recettes_reference_libelle',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_03_15_100000_drop_membre_columns_from_tiers',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_03_16_000001_add_sous_categorie_flags_and_fks',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_03_17_000001_create_recette_ligne_affectations_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_03_17_000002_create_depense_ligne_affectations_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_03_18_100000_create_transactions_unified',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_03_18_130659_drop_legacy_depenses_recettes_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_03_21_000001_create_helloasso_parametres_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_03_21_100000_restructure_tiers_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_03_22_000001_convert_helloasso_id_to_est_helloasso',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_03_22_100000_make_tiers_nom_nullable',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_03_22_100001_add_helloasso_columns_to_transactions',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_03_22_100002_add_helloasso_columns_to_transaction_lignes',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_03_22_100003_add_helloasso_cashout_id_to_virements_internes',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_03_22_100004_add_pour_inscriptions_to_sous_categories',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_03_22_200001_drop_dons_and_cotisations_tables',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_03_23_000001_add_sync_config_to_helloasso_parametres',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_03_23_000002_create_helloasso_form_mappings_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_03_23_113411_add_helloasso_name_to_tiers',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_03_23_200001_add_helloasso_payment_id_to_transactions',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_03_23_200002_add_dates_state_to_helloasso_form_mappings',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_03_24_090415_add_sous_categorie_id_to_operations_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_03_24_100001_create_helloasso_notifications_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_03_24_100002_add_callback_token_to_helloasso_parametres',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_03_24_100000_create_exercices_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_03_24_100001_create_exercice_actions_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_03_24_100002_make_sous_categorie_id_nullable_in_transaction_lignes',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_03_24_200000_add_dernier_espace_to_users_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_03_24_210000_create_participants_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_03_24_210001_create_participant_donnees_medicales_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_03_24_210002_add_peut_voir_donnees_sensibles_to_users_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_03_25_100000_add_taille_notes_to_participant_donnees_medicales',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_03_25_100001_add_refere_par_id_to_participants',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_03_25_120000_create_seances_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_03_25_120001_create_presences_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_03_25_120002_create_reglements_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_03_26_100001_add_est_systeme_to_comptes_bancaires',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_03_26_100002_create_remises_bancaires_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_03_26_100003_add_remise_id_and_reglement_id_to_transactions',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_03_26_100004_add_fk_remise_id_on_reglements',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_03_26_200001_create_formulaire_tokens_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_03_27_100000_create_type_operations_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_03_27_100001_create_type_operation_tarifs_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_03_27_100002_add_type_operation_id_to_operations_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_03_27_100003_add_type_operation_tarif_id_to_participants_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_03_27_161300_add_email_fields_to_type_operations_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_03_27_164522_add_email_template_fields_to_type_operations_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_03_27_200000_drop_exercice_from_transaction_lignes',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_03_28_100001_add_code_to_operations_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_03_28_200001_create_email_templates_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_03_29_100001_add_medecin_therapeute_to_participant_donnees_medicales',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_03_29_100002_add_inscription_fields_to_participants',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_03_29_100003_add_helloasso_url_to_exercices',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_03_29_100004_add_attestation_medicale_path_to_type_operations',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_03_29_100005_add_cp_ville_etablissement_to_formulaire',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_03_30_100001_add_formulaire_flags_to_type_operations',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_03_30_100002_add_tiers_mapping_to_participants',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_03_30_200001_create_email_logs_table',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_03_30_200002_add_cachet_signature_to_association',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_03_30_200003_add_libelle_article_to_type_operations',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_03_30_200004_seed_attestation_email_template',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_03_30_200005_seed_default_email_templates',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_03_30_220000_drop_code_from_operations_and_type_operations',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_03_31_100001_add_bic_domiciliation_to_comptes_bancaires',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_03_31_100002_add_siret_facture_to_association',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_03_31_100003_create_factures_table',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_03_31_100004_create_facture_lignes_table',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_03_31_100005_create_facture_transaction_table',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_04_01_100000_create_documents_previsionnels_table',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_04_01_100001_create_creances_a_recevoir_compte',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_04_01_100002_drop_actif_dons_cotisations_from_comptes_bancaires',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_04_02_100001_rename_facture_to_document_email_template',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_04_04_123225_add_performance_indexes',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_04_04_123953_add_role_to_users',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_04_04_221233_add_two_factor_to_users',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_04_04_221234_create_two_factor_codes_table',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_04_05_100001_make_mode_paiement_nullable_in_transactions',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_04_05_100002_make_date_inscription_nullable_in_participants',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_04_06_100000_add_piece_jointe_to_transactions',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_04_06_100001_add_anthropic_api_key_to_association',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_04_08_180839_add_feuille_signee_to_seances_table',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_04_08_181256_create_incoming_documents_table',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_04_08_185002_create_incoming_mail_parametres_table',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_04_08_185005_create_incoming_mail_allowed_senders_table',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_04_10_000001_create_provisions_table',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_04_10_000002_reroute_helloasso_cheque_especes',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_04_10_100000_create_participant_documents_table',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_04_11_100001_add_reglement_fields_to_transactions',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_04_11_200001_reroute_helloasso_cheque_especes_to_creances',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_04_11_000001_create_message_templates_table',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_04_11_000002_create_campagnes_email_table',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_04_11_000003_add_corps_html_to_email_logs',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_04_11_000004_add_pieces_jointes_to_campagnes_email',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_04_12_000001_create_email_tracking',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_04_12_100000_create_type_operation_seances_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_04_12_120000_seed_message_templates',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_04_12_150000_enlarge_corps_columns_to_mediumtext',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_04_12_200001_add_email_optout_to_tiers',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_04_12_200002_add_email_from_to_association',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_04_12_200003_make_campagnes_email_operation_id_nullable',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_04_12_200004_add_categorie_to_message_templates',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_04_13_100001_add_statut_reglement_to_transactions',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_04_13_100002_migrate_statut_reglement_data',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_04_13_100003_remove_legacy_transaction_columns',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_04_13_100004_remove_virement_id_from_remises_bancaires',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2026_04_13_100005_deactivate_system_accounts',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2026_04_14_000001_create_smtp_parametres_table',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2026_04_14_111655_encrypt_helloasso_callback_token',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2026_04_15_100001_enrich_associations_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2026_04_15_100002_create_association_slug_aliases_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2026_04_15_100003_create_association_user_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2026_04_15_100004_enrich_users_table_multi_tenant',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2026_04_15_100005_create_super_admin_access_log_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2026_04_15_100010_add_association_id_group_a_tiers_referentiels',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2026_04_15_100011_add_association_id_group_b_comptabilite',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2026_04_15_100012_add_association_id_group_c_operations',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2026_04_15_100013_add_association_id_group_d_facturation_budget',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2026_04_15_100014_add_association_id_group_e_communications',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2026_04_15_100020_backfill_association_id_svs',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_04_15_100030_make_association_id_not_null',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_04_15_100040_add_unique_composites_multi_tenant',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_04_15_100041_add_association_id_to_sequences',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_04_15_100050_migrate_user_role_to_pivot',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_04_15_100051_drop_users_role_column',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_04_17_100000_backfill_association_logo_paths',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_04_17_100010_backfill_type_operation_paths',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_04_17_100015_add_association_id_to_participant_documents',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_04_17_100020_backfill_participant_document_paths',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_04_17_100030_backfill_incoming_document_paths',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_04_17_100040_backfill_seance_feuille_paths',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_04_17_100050_backfill_transaction_piece_jointe_paths',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_04_17_100060_backfill_document_previsionnel_paths',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_04_17_100070_backfill_provision_piece_jointe_paths',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_04_17_190001_add_wizard_state_to_associations_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_04_17_200001_add_composite_indexes_association_id',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_04_18_120000_add_piece_jointe_to_rapprochements_bancaires',41);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_04_19_130013_deactivate_legacy_system_accounts',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_04_19_200217_create_tiers_portail_otps_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_04_19_200221_add_email_index_to_tiers_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_04_19_225824_create_notes_de_frais_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_04_19_225828_create_notes_de_frais_lignes_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_04_20_100000_add_type_and_metadata_to_notes_de_frais_lignes_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_04_20_100001_add_pour_frais_kilometriques_to_sous_categories_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_04_20_112136_add_seance_and_drop_seance_id_on_notes_de_frais_lignes_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_04_21_000000_add_piece_jointe_path_to_transaction_lignes',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_04_21_000001_add_index_to_notes_de_frais_statut',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_04_21_120000_create_usages_sous_categories_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_04_21_120100_migrate_sous_categorie_flags_to_usages',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_04_21_120200_drop_flag_columns_from_sous_categories_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_04_21_201442_add_abandon_creance_columns_to_notes_de_frais',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_04_22_000000_add_archived_at_to_notes_de_frais',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_04_21_100000_refonte_comptes_bancaires_saisie',43);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_04_24_000000_create_factures_partenaires_deposees_table',44);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_04_27_000001_create_devis_table',45);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_04_27_000002_create_devis_lignes_table',45);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_04_27_000003_add_devis_validite_jours_to_associations',45);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_04_27_000004_add_attachment_path_to_email_logs',45);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_04_27_100001_add_type_to_devis_lignes',45);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_04_27_212048_rename_devis_envoye_to_valide',45);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_04_28_120000_add_devis_id_and_mode_paiement_prevu_to_factures',46);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_04_28_120001_add_libre_columns_to_facture_lignes',46);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (196,'2026_04_28_130000_rename_montant_libre_to_montant_manuel',46);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (197,'2026_05_01_120000_create_extournes_table',47);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (198,'2026_05_01_120001_add_extournee_at_to_transactions',47);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (199,'2026_05_01_120002_add_type_to_rapprochements_bancaires',47);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (200,'2026_05_02_222744_create_newsletter_subscription_requests_table',48);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (201,'2026_05_03_100000_create_association_api_keys_table',48);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (202,'2026_05_06_113801_add_nom_to_newsletter_subscription_requests_table',48);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (203,'2026_05_06_162134_add_admin_processing_columns_to_newsletter_subscription_requests_table',49);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (204,'2026_05_07_100016_add_api_key_id_to_newsletter_subscription_requests_table',49);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (205,'2026_05_07_100001_add_recu_fiscal_fields_to_associations',50);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (206,'2026_05_07_100002_create_recus_fiscaux_emis_table',50);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (207,'2026_05_08_100001_replace_regime_fiscal_don_and_add_loi_coluche_ifi_to_associations',50);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (208,'2026_05_08_180901_create_adhesions_table',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (209,'2026_05_09_063420_add_saisi_par_to_adhesions_table',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (210,'2026_05_09_100000_create_formules_adhesion_table',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (211,'2026_05_09_100100_create_helloasso_tier_mappings_table',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (212,'2026_05_09_100200_alter_adhesions_add_formule_dates_notes',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (213,'2026_05_09_100300_add_helloasso_form_slug_to_transactions',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (214,'2026_05_09_100400_add_helloasso_tier_id_to_transaction_lignes',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (215,'2026_05_10_100000_alter_adhesions_add_snapshot_fields',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (216,'2026_05_10_100100_alter_formules_adhesion_add_helloasso_flags',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (217,'2026_05_10_100200_alter_formules_adhesion_mode_add_illimite',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (218,'2026_05_10_100300_alter_helloasso_form_mappings_add_ignore_imported_at_souscat',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (219,'2026_05_10_100400_drop_helloasso_tier_mappings_table',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (220,'2026_05_10_100500_drop_souscat_globales_from_helloasso_parametres',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (221,'2026_05_10_120000_add_sous_categorie_don_id_to_helloasso_parametres',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (222,'2026_05_10_120100_add_helloasso_dates_to_formules_adhesion',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (223,'2026_05_10_200000_add_helloasso_option_id_to_transaction_lignes',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (224,'2026_05_11_100000_add_duree_jours_to_formules_adhesion',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (225,'2026_05_11_110000_add_soft_deletes_to_operations',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (226,'2026_05_13_120000_create_encadrement_previsions_table',52);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (227,'2026_05_13_140000_add_civilite_to_tiers_table',53);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (228,'2026_05_14_205416_add_url_fields_to_association',54);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (229,'2026_05_16_000001_consolidate_email_categorie_operation_to_message',54);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (230,'2026_06_20_000001_soft_delete_adhesions_legacy_doublons',55);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (231,'2026_06_20_000002_add_invoice_ocr_model_to_association',55);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (232,'2026_06_22_100001_create_questionnaire_templates_tables',56);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (233,'2026_06_22_100002_create_questionnaire_campaign_tables',56);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (234,'2026_06_22_100003_create_questionnaire_submission_tables',56);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (235,'2026_06_25_100001_add_settings_to_questionnaire_tables',56);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (236,'2026_06_25_100002_add_grouper_to_questionnaire_questions',56);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (237,'2026_06_26_100001_create_questionnaire_paper_tables',57);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (238,'2026_06_26_100002_add_questionnaire_ocr_model_to_association',57);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (239,'2026_06_26_100003_add_remplacement_to_questionnaire_submissions',57);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (240,'2026_07_01_165656_trim_tiers_email_et_champs_texte',58);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (241,'2026_07_01_100001_add_anonymisation_bearer_tokens',59);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (242,'2026_07_03_100000_add_token_clair_to_questionnaire_bearer_tokens',59);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (243,'2026_07_03_100001_add_contact_nom_to_questionnaire_submissions',59);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (244,'2026_07_06_100001_add_paper_scan_id_to_questionnaire_submissions',60);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (245,'2026_05_20_000001_create_comptes_table',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (246,'2026_05_20_000002_seed_comptes_bancaires_into_comptes',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (247,'2026_05_20_000003_seed_comptes_systeme',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (248,'2026_05_20_000004_add_partie_double_columns_to_transaction_lignes',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (249,'2026_05_20_000005_add_equilibree_and_type_ecriture_to_transactions',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (250,'2026_05_20_000006_create_lettrage_audit_table',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (251,'2026_05_25_000001_backfill_compte_id_in_transaction_lignes',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (252,'2026_06_01_000001_add_journal_to_transactions',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (253,'2026_06_01_000002_backfill_journal_in_transactions',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (254,'2026_06_01_120000_add_comptabilisee_at_to_remises_bancaires',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (255,'2026_06_04_120000_add_en_main_to_statut_reglement',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (256,'2026_06_04_120100_reclasser_statuts_en_main',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (257,'2026_06_11_113131_add_compte_467_system',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (258,'2026_06_12_100000_add_motif_suppression_to_transactions',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (259,'2026_06_17_100000_add_virement_interne_id_to_transactions',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (260,'2026_06_18_000001_add_provision_id_to_transactions',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (261,'2026_07_07_100001_create_familles_table',62);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (262,'2026_07_07_100002_add_compte_id_to_ventilation_tables',63);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (263,'2026_07_09_100001_make_sous_categorie_id_nullable_on_ventilation_tables',64);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (264,'2026_07_09_100002_add_compte_don_id_to_helloasso_parametres',65);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (265,'2026_07_12_100001_fix_encadrement_previsions_unique_to_compte_id',66);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (266,'2026_07_12_200001_drop_sous_categories_and_categories',66);
