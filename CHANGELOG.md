# Changelog

Toutes les modifications notables de AgoraGestion sont documentées ici.
Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/).

---

## [v3.0.3] — 2026-04-14
### Améliorations
- **Facturation — Enregistrer règlement** — bouton fonctionnel sur la fiche facture : coche les transactions sélectionnées comme « reçues » (statut_reglement = recu). Filtre corrigé pour utiliser statut_reglement plutôt que les colonnes supprimées date_reglement/reference_reglement. Suppression du garde-fou est_systeme devenu obsolète en v3.
- **Paramètres Association — Informations** — mise en page en deux colonnes avec cartes (Identité, Coordonnées, Logo, Cachet), style harmonisé avec l'écran type-opération
- **Paramètres — Dirty state** — protection « modifications non enregistrées » sur les 5 écrans Paramètres (Association, HelloAsso, HelloAsso Sync, Réception mail, SMTP) : modale Bootstrap « Enregistrer et quitter / Abandonner » identique à l'écran type-opération
- **Paramètres — Aide contextuelle** — cachet : mention attestations ; onglet Facturation : mention factures ; Réception mail : « Ingestion active » renommé « Relève activée », aide crontab avec commande complète calculée dynamiquement, aide expéditeurs autorisés (rôle sécurité + libellé)
- **Paramètres — Onglets élargis** — suppression des contraintes max-width sur les onglets Facturation, OCR/IA et Communication
- **Expéditeur email — repli association** — si un type d'opération n'a pas d'adresse d'expédition configurée, l'adresse paramétrée dans Paramètres > Association > Communication est utilisée automatiquement (AttestationModal, ParticipantShow, ParticipantTable, ReglementTable, OperationCommunication, FactureShow). Méthodes `effectiveEmailFrom()` / `effectiveEmailFromName()` sur le modèle TypeOperation.
- **Paramètres Association — Communication** — harmonisation avec l'écran type-opération : layout 3 colonnes (nom / email / bouton Tester), mini-modale de test identique. La description mentionne le repli pour les types d'opération.
- **Type-opération — onglet Emails** — note sous l'adresse d'expédition : lien vers Paramètres > Association si le champ est vide.

## [v2.7.5] — 2026-04-08
### Améliorations
- **PDFs — footer unifié** — tous les PDFs (rapprochement bancaire, remise bancaire, émargement, matrice présences, participants liste/annuaire, fiche participant, droit image, attestation présence, rapports compte-résultat/opérations/flux trésorerie) partagent le même pied de page : logo association à gauche (selon contexte), pagination centrée « Page X / Y » correcte sur toutes les pages, « AgoraGestion · date » + logo à droite
- **PDF émargement** — remplissage dynamique de la dernière page avec des lignes vides en mode formation (pour accueillir des participants de dernière minute). Pas de lignes vides en parcours thérapeutique (liste fermée).
- **Modale d'ajout de participant** — message d'erreur « déjà inscrit » s'efface correctement au changement ou à la suppression de la sélection de tiers
- **Import tiers** — le message d'erreur pour email invalide inclut maintenant l'adresse fautive

### Correctifs
- **Anonymizer** — dates générées au format ISO Y-m-d (cohérent avec la prod) pour éviter les erreurs de parsing Carbon en préprod

## [v2.7.4] — 2026-04-07
### Améliorations
- **PDF émargement** — pied de page structuré : logo association à gauche, pagination centrée, « AgoraGestion · date » + logo à droite
- **Seeder** — correction enum `facture` → `document` dans EmailTemplateSeeder

## [v2.7.3] — 2026-04-07
### Rebranding
- **AgoraGestion** — renommage complet du projet (SVS Accounting → AgoraGestion) : layouts, scripts de déploiement, docker-compose, seeders, tests, README
- **Logo SVG** — remplacement du logo PNG par le logo vectoriel `agora-gestion.svg`
- **CSS** — renommage des classes `.navbar-svs` → `.navbar-app`, fonction `svsParseFlatpickrDate` → `parseFlatpickrDate`

## [v1.2.9] — 2026-03-21
### Correctif
- **Footer — numéro de version** — `app:version-stamp` lit désormais le fichier `VERSION` commité dans le repo au lieu de `git describe --tags`. Élimine la dépendance au fetch des tags git lors du déploiement. Le fichier `VERSION` est la source de vérité.

## [v1.2.8] — 2026-03-21
### Correctif
- **Deploy** — ajout de `git fetch --tags` dans `deploy.sh` pour que `app:version-stamp` résolve le tag semver via `git describe` (correctif partiel, remplacé par v1.2.9)

## [v1.2.7] — 2026-03-21
### Améliorations
- **Uniformisation des en-têtes** — le titre de page est affiché à l'intérieur du composant `TransactionUniverselle` sur tous les écrans, sur la même ligne que les boutons de filtre : Toutes les transactions, Transactions par tiers, Transactions par compte, Dons, Cotisations
### Correctif infrastructure
- **Footer production** — `APP_ENV` corrigé à `production` sur O2Switch (était `local` → footer orange, version affichée comme SHA git)

## [v1.2.6] — 2026-03-21
### Amélioration
- **Page Recettes & dépenses** — réduction de 5 à 2 lignes dans l'en-tête : ligne 1 = titre + filtres Toutes/DÉP/REC + boutons Import ; ligne 2 = bouton Nouvelle transaction

## [v1.2.5] — 2026-03-21
### Amélioration
- **Accès contextuel aux transactions par compte** — suppression de l'item « Transactions » du menu Banques au profit d'une icône après chaque solde sur le dashboard et d'un bouton dans la liste des comptes bancaires (Paramètres). La route `/comptes-bancaires/{compte}/transactions` filtre automatiquement sur le compte sélectionné.

## [v1.2.4] — 2026-03-21
### Amélioration
- **Navigation** — l'entrée « Virements » déplacée du menu Transactions vers le menu Banques (un virement interne est un mouvement entre comptes bancaires, pas une transaction comptable)

## [v1.2.3] — 2026-03-21
### Correctif
- **Pipeline de déploiement** — ajout d'un délai de 30s après le whitelisting IP cPanel pour laisser CSF/iptables appliquer la règle avant la connexion SSH (timeout exit 255)

## [v1.2.2] — 2026-03-21
### Correctif
- **Date vide à l'ouverture d'un formulaire de modification** — `parseFlatpickrDate` ne gérait pas le format ISO `aaaa-mm-jj` stocké dans le champ caché. Corrigé pour tous les formulaires utilisant `x-date-input` (virements, dépenses, recettes, dons, cotisations)

## [v1.2.1] — 2026-03-21
### Correctif
- **Formulaire cotisation** — paramètre `tiersId` (camelCase) aligné sur la convention Livewire 4 (`open-cotisation-for-tiers` recevait `tiers_id` en snake_case → valeur null)

## [v1.2.0] — 2026-03-21
### Nouvelles fonctionnalités
- **Transaction Universelle** — composant unifié remplaçant les cinq listes existantes (TransactionList, TransactionCompteList, TiersTransactions, DonList, CotisationList)
  - Table UNION SQL sur 6 branches (dépenses, recettes, dons, cotisations, virements entrants/sortants)
  - Filtres QBE par colonne (date, libellé, tiers, compte, mode de paiement, sous-catégorie, montant)
  - Loupe rouge + badge sous le libellé de colonne quand un filtre est actif
  - Boutons de filtre par type : Toutes / DÉP / REC / DON / COT / VIR
  - Ligne dépliable (▶/▼) avec mini-tableau (sous-catégorie, opération, séance, notes, montant)
  - Icône `bi-chat-left-text` dans la colonne libellé quand des notes existent
- **Remplacement des vues (Lots 3–7)** — `/transactions`, `/comptes-bancaires/transactions`, `/tiers/{id}/transactions`, `/dons`, `/cotisations`
- **Navigation** — renommage « Transactions » → « Recettes & dépenses », ajout « Toutes les transactions »
- **Infrastructure staging NAS** — environnement Docker sur Synology, hook post-receive, script clone-prod

## [v1.1.0] — 2026-03-19
### Nouvelles fonctionnalités
- Harmonisation visuelle des listes (en-têtes tableaux fond bleu foncé)
- Colonne Pointé avec icônes Bootstrap sur DonList, CotisationList, MembreList
- Notes en tooltip sur TransactionList et VirementInterneList
- Navigation inter-écrans depuis MembreList
- Formulaires modaux autonomes DonForm, CotisationForm, VirementInterneForm
- Mémorisation des derniers choix (poste, mode, compte) dans les formulaires
