# Changelog

Toutes les modifications notables de AgoraGestion sont documentées ici.
Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/).

---

## [v4.2.0] — 2026-04-28

### Ajouts — Démo en ligne

- **Environnement démo public** sur `demo.agoragestion.org` (sous-domaine O2Switch dédié, DB MySQL dédiée, `.env.demo` versionné côté serveur)
- **Bandeau `/login`** listant les comptes démo (`admin@demo.fr / demo`, `jean@demo.fr / demo`) — visible ssi `APP_ENV=demo`, absent en prod
- **Bridage des sorties externes** en `APP_ENV=demo` : mails routés vers le log (`MAIL_MAILER=log`) avec flash UI "Email enregistré (mode démo)", webhook HelloAsso retourne 200 no-op, commande `helloasso:sync` no-op, `incoming-mail:fetch` no-op, OCR factures partenaires retourne un payload stub statique
- **Lecture seule sur paramètres sensibles** : écrans Livewire SMTP + HelloAsso affichent un bandeau d'information, inputs `disabled`, bouton "Enregistrer" absent ; middleware `EnforceDemoReadOnly` refuse les requêtes d'écriture HTTP sur ces routes (403)
- **Refus des opérations destructives** : suppression et archivage d'association en démo lèvent `DemoOperationBlockedException`
- **Commande `demo:capture`** : extrait toutes les tables tenant-scopées dans `database/demo/snapshot.yaml` versionné, convertit les dates en deltas relatifs (`-13d`, `-3M`), écrase les hash mots de passe en `demo`, refuse si > 1 association ou si `APP_ENV=production`
- **Commande `demo:reset`** : rejoue le snapshot YAML (dates rehydratées par rapport à `now()`), restaure les fichiers depuis `database/demo/files/`, garantit `php artisan up` en `finally`
- **Workflow `deploy-demo.yml`** déclenché sur `push main`, en parallèle du workflow prod (clone strict) : pull → composer → config:cache → migrate:fresh → demo:reset → up
- **Cron O2Switch** `0 4 * * *` — réinitialisation automatique chaque nuit, log dans `storage/logs/demo-reset.log`
- **Helper `App\Support\Demo::isActive()`** — point de bascule unique pour tous les comportements démo (délègue à `app()->environment('demo')`)
- **Documentation** : `docs/runbook-demo.md` — guide opérateur complet (installation, snapshot, charte, recette manuelle, dépannage)

### Technique

- `App\Support\Demo` (helper statique)
- `App\Support\FlashMessages::emailSent()` (message conditionnel démo/normal)
- `App\Http\Middleware\EnforceDemoReadOnly` — alias `demo.read-only`, liste des routes protégées en constante de classe
- `App\Exceptions\DemoOperationBlockedException` (extends `RuntimeException`)
- `App\Console\Commands\DemoCaptureCommand` + `App\Support\Demo\SnapshotConfig` + `App\Support\Demo\DateDelta`
- `App\Console\Commands\DemoResetCommand` + `App\Support\Demo\SnapshotLoader`
- Composant Blade `<x-demo-readonly-banner />` (réutilisé SMTP + HelloAsso)
- Composant Blade `<x-demo-login-banner />` (bandeau `/login`)
- `.github/workflows/deploy-demo.yml`

---

## [v4.1.9] — 2026-04-28

### Nouveau : Facture manuelle (invoice-first)

- **Transformation Devis Accepté → Facture brouillon** — bouton "Transformer en facture" sur la fiche devis ; lignes recopiées en `MontantManuel` / `Texte` ; `factures.devis_id` renseigné ; bouton désactivé si une facture existe déjà
- **Création directe** — bouton "Nouvelle facture" sur la liste des factures ; modale sélecteur de tiers ; aucun devis source requis
- **3 types de lignes facture** : `Montant` (ref vers transaction existante) / `MontantManuel` (manuelle, génère une transaction à la validation) / `Texte` (information, sans impact comptable) — mix autorisé sur la même facture
- **Génération automatique d'une `Transaction` recette** à la validation d'une facture portant des lignes `MontantManuel` : 1 transaction recette + N `TransactionLignes` (1 par ligne manuelle) ; statut "à recevoir" ; mode = `facture.mode_paiement_prevu`
- **Champ `mode_paiement_prevu`** (énum `ModePaiement`) sur la facture, visible et requis à la validation ssi ≥ 1 ligne `MontantManuel`
- **Édition inline PU / Qté** sur les lignes `MontantManuel` en mode brouillon : inputs `wire:blur` directement dans le tableau de lignes
- **Encaissement inchangé** : le flow Créances v2.4.3 traite la transaction générée comme n'importe quelle créance — bouton "Encaisser" existant
- **ADR-002** : décision architecturale invoice-first à 3 types de lignes (`docs/adr/ADR-002-facture-libre-invoice-first.md`)

### Correctif

- **PDF devis** : les lignes `Texte` n'affichent plus `0,00 €` dans les colonnes PU / Qté / Montant (bug préexistant S1 — cellules vides désormais)

### Changements

- **PDF facture** : colonnes PU / Qté / Montant rendues selon le type de ligne (option α "asymétrie honnête") : `MontantManuel` affiche 4 colonnes, `Montant` ref affiche libellé + montant total uniquement, `Texte` affiche libellé seul

### Technique

- Enum `App\Enums\TypeLigneFacture` : 3e valeur `MontantManuel = 'montant_manuel'` + helpers `genereTransactionLigne()`, `aImpactComptable()`
- `factures` += `devis_id` (FK nullable, ON DELETE RESTRICT), `mode_paiement_prevu` (enum `ModePaiement` nullable), index `(association_id, devis_id)`
- `facture_lignes` += `prix_unitaire`, `quantite`, `sous_categorie_id`, `operation_id`, `seance` (toutes nullables)
- `FactureService` += `creerManuelleVierge()`, `ajouterLigneManuelle()`, `ajouterLigneTexteManuelle()`, `majPrixUnitaireLigneManuelle()`, `majQuantiteLigneManuelle()` ; `valider()` étendu
- `DevisService` += `transformerEnFacture()`
- Routes `/devis-manuels/` (ex `/devis-libres/`) — classes et vues renommées en conséquence
- Migrations up + down réversibles, aucun backfill
- Suite de tests : 3171 tests verts, 0 failed

---

## [v4.1.8] — 2026-04-27
### Nouveau module : Devis libres (Slice 1)
- **Devis libre autonome** — création, édition et cycle de vie d'un devis adressé à un `Tiers` quelconque, sans rattachement à une `Operation` ou à des `Participants`
- **5 statuts tracés** : `brouillon → validé → accepté | refusé | annulé` avec utilisateur + date sur chaque transition ; annulation possible depuis tout statut
- **Numérotation séquence dédiée** `D-{exercice}-NNN` attribuée à la première validation, immuable ensuite, avec lock pessimiste anti-doublon concurrent
- **Lignes libres** : libellé, prix unitaire, quantité, sous-catégorie optionnelle ; recalcul automatique `montant_total`
- **Modification d'un validé** re-bascule en brouillon (numéro conservé) ; statuts `accepté|refusé|annulé` verrouillent l'édition
- **Export PDF** : filigrane "BROUILLON" et sans numéro pour brouillon ; numéroté pour `validé+` ; footer unifié `PdfFooterRenderer`
- **Envoi email** avec PJ PDF, tracé dans `email_logs` ; refusé pour brouillon ou devis vide
- **Duplication** depuis tout statut → nouveau brouillon, lignes recopiées, dates recalculées
- **Vue 360° tiers** : bloc "Devis libres" avec count par statut et total des `accepté`
- **Sidebar** : entrée "Devis libres" sous le groupe Facturation
- **Création rapide** : clic "Nouveau devis" → modal de sélection du tiers → création et redirection directe
- **Isolation multi-tenant** fail-closed (`Devis extends TenantModel`) ; tests d'intrusion dédiés
- **Seeders dev** : 4 devis libres d'exemples (brouillon, validé, accepté, refusé) sur l'asso démo
- **Suite Pest** : 0 failed, 0 errored

### Technique
- Modèle `App\Models\Devis` + `App\Models\DevisLigne` (softDeletes, TenantModel)
- Enum `App\Enums\StatutDevis` avec helpers `peutEtreModifie()`, `peutEtreDuplique()`, `peutPasserEnvoye()`
- Service `App\Services\DevisService` (toutes mutations en `DB::transaction()`)
- Composants Livewire `DevisList` + `DevisEdit` ; routes `/devis-libres` + `/devis-libres/{devis}`
- Mailable `App\Mail\DevisLibreMail`
- Migrations : `devis`, `devis_lignes`, `associations.devis_validite_jours`

## [v4.1.2] — 2026-04-22
### Changements majeurs
- **Refonte du modèle `comptes_bancaires`** — suppression de la notion bancale `est_systeme`, introduction du flag orthogonal `saisie_automatisee` pour les comptes alimentés par intégration externe (HelloAsso aujourd'hui, Stripe/SumUp demain)
- **Compte HelloAsso non sélectionnable** dans les formulaires de saisie manuelle (transactions, factures, remises, virements)
- **Transactions HelloAsso existantes** — champs source (compte, date, montant, mode de paiement, tiers) verrouillés en édition ; seuls libellé, notes, ventilation et pièce jointe restent modifiables. Bandeau d'information dans le formulaire.

### Suppressions
- Comptes legacy « Créances à recevoir » et « Remises en banque » supprimés définitivement (audit prod vérifié : zéro transaction liée)
- Colonne `comptes_bancaires.est_systeme` droppée
- Section « Comptes système » du rapport Flux de trésorerie (builder + vues + PDF + export Excel)

### Technique
- Nouveau scope Eloquent `CompteBancaire::saisieManuelle()` — source de vérité unique pour les sélecteurs de saisie (`actif_recettes_depenses=true AND saisie_automatisee=false`)
- Migration atomique `2026_04_21_100000_refonte_comptes_bancaires_saisie` avec garde FK bloquante (9 tables vérifiées)
- Guards serveur + readonly UI sur `TransactionForm` pour l'édition des transactions HelloAsso
- Suite de tests : 2385 tests verts, 0 failed

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
