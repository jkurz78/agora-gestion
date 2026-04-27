# Plan: Devis libre — Slice 1

**Created**: 2026-04-27
**Branch**: à créer `feat/devis-libre-s1` depuis `main`
**Status**: approved
**Spec**: [docs/specs/2026-04-27-devis-libre-s1.md](../docs/specs/2026-04-27-devis-libre-s1.md)

## Goal

Livrer un module **Devis libre autonome** : permettre la création, l'édition et le suivi du cycle de vie d'un devis adressé à un `Tiers` quelconque, sans rattachement à une `Operation` ou à des `Participants`. Modèle `Devis` indépendant (Option A), 5 statuts traçant qui-quand sur acceptation/refus/annulation, numérotation `D-{exercice}-NNN` séquence dédiée attribuée à l'émission, PDF (avec filigrane "BROUILLON" hors statut envoyé), envoi email, duplication, intégration vue 360° tiers, multi-tenant `TenantModel`. Slices 2 (devis → transaction) et 3 (facture-first) explicitement hors scope.

## Acceptance Criteria

- [ ] Un gestionnaire peut créer un devis brouillon pour n'importe quel `Tiers`, lui ajouter des lignes libres (libellé, prix unitaire, quantité, sous-cat optionnelle) avec recalcul automatique du `montant_total`
- [ ] La transition `brouillon → envoyé` exige ≥ 1 ligne avec montant > 0 et attribue un numéro `D-{exercice}-NNN` immuable
- [ ] Acceptation, refus, annulation sont tracés (utilisateur + date) ; annulation possible depuis tout statut
- [ ] Modifier un devis `envoyé` le re-bascule en `brouillon` en conservant son numéro ; les statuts `accepté|refusé|annulé` sont verrouillés à l'édition
- [ ] Un devis `envoyé` dont la `date_validite` est dépassée affiche un badge "expiré" mais conserve son statut (pas de transition auto)
- [ ] Export PDF : numéroté pour `envoyé+`, avec filigrane "BROUILLON" et sans numéro pour `brouillon` ; refusé pour devis vide
- [ ] Envoi par email avec PJ PDF, tracé dans `email_logs` ; refusé pour brouillon ou devis vide
- [ ] Duplication possible depuis tout statut → nouveau brouillon, lignes recopiées, dates recalculées
- [ ] Le devis apparaît dans la vue 360° du tiers (bloc dédié)
- [ ] Isolation multi-tenant fail-closed : aucun devis d'une asso n'est visible/modifiable depuis une autre
- [ ] Pas de doublon `(association_id, exercice, numero)` même sous course concurrente
- [ ] Suite Pest globale : 0 failed, 0 errored

## Steps

### Step 1: Migration, modèles, enum, factories

**Complexity**: standard
**RED**:
- Test `Devis extends TenantModel` + scope global fail-closed
- Test factory crée devis valide avec `association_id`, `tiers_id`, `date_emission`, `date_validite`, `statut = brouillon`
- Test `DevisLigne` belongs to `Devis` ; cascade delete sur `devis_id`
- Test enum `StatutDevis` expose helpers `peutEtreModifie()`, `peutPasserEnvoye()`, `peutEtreDuplique()`
- Test colonne `associations.devis_validite_jours` exists, default 30

**GREEN**:
- Migrations : `create_devis_table`, `create_devis_lignes_table`, `add_devis_validite_jours_to_associations`
- Enum `App\Enums\StatutDevis`
- Modèles `App\Models\Devis` (extends `TenantModel`, softDeletes, casts, relations) et `App\Models\DevisLigne`
- Factories `DevisFactory`, `DevisLigneFactory`

**REFACTOR**: None
**Files**: `database/migrations/*_create_devis_table.php`, `database/migrations/*_create_devis_lignes_table.php`, `database/migrations/*_add_devis_validite_jours_to_associations.php`, `app/Enums/StatutDevis.php`, `app/Models/Devis.php`, `app/Models/DevisLigne.php`, `database/factories/DevisFactory.php`, `database/factories/DevisLigneFactory.php`, `tests/Feature/DevisModelTest.php`
**Commit**: `feat(devis-libre): tables, modèles, enum StatutDevis (S1 step 1)`

### Step 2: DevisService::creer

**Complexity**: standard
**RED**:
- Test `creer($tiersId)` retourne devis brouillon, `date_emission = aujourd'hui`, `date_validite = +30j` (lue depuis `Association::devis_validite_jours`)
- Test `exercice` figé à `date_emission`
- Test `saisi_par_user_id = auth()->id()`
- Test transaction DB : rollback si insert ligne échoue (mock)

**GREEN**:
- `App\Services\DevisService::creer(int $tiersId, ?Carbon $date = null): Devis`
- Lecture `devis_validite_jours` via `CurrentAssociation::get()`

**REFACTOR**: None
**Files**: `app/Services/DevisService.php`, `tests/Feature/Services/DevisServiceCreerTest.php`
**Commit**: `feat(devis-libre): DevisService::creer (S1 step 2)`

### Step 3: DevisService — gestion des lignes

**Complexity**: standard
**RED**:
- Test `ajouterLigne` : ligne créée avec `montant = prix_unitaire × quantite`, `montant_total` recalculé, `ordre` incrémenté
- Test sous-catégorie optionnelle (null accepté)
- Test `modifierLigne` recalcule `montant` ligne + `montant_total` parent
- Test `supprimerLigne` recalcule `montant_total`
- Test guard : ces méthodes refusent si statut verrouillé (`accepté|refusé|annulé`)

**GREEN**:
- `ajouterLigne`, `modifierLigne`, `supprimerLigne` dans `DevisService`
- Recalcul `montant_total` privé

**REFACTOR**: None
**Files**: `app/Services/DevisService.php`, `tests/Feature/Services/DevisServiceLignesTest.php`
**Commit**: `feat(devis-libre): gestion des lignes + recalcul montant total (S1 step 3)`

### Step 4: DevisService::marquerEnvoye + numérotation séquence

**Complexity**: complex
**RED**:
- Test `marquerEnvoye` exige ≥ 1 ligne avec montant > 0, sinon `RuntimeException`
- Test attribution numéro `D-{exercice}-001`, puis `D-{exercice}-002`, etc.
- Test numéro figé après attribution (ne change pas si re-passé en brouillon puis re-envoyé)
- Test course concurrente : 2 emissions parallèles → pas de doublon `(association_id, exercice, numero)` (test concurrent en `pcntl_fork` ou via 2 transactions et lock)
- Test format 3 digits min, débordement à 4+ digits

**GREEN**:
- `marquerEnvoye(Devis): void` avec `DB::transaction` + `lockForUpdate` sur séquence
- Méthode privée d'attribution numéro filtrée par `(association_id, exercice)`

**REFACTOR**: extraire `DevisNumeroService` si la logique dépasse 30 lignes
**Files**: `app/Services/DevisService.php`, `app/Services/DevisNumeroService.php` (éventuel), `tests/Feature/Services/DevisServiceMarquerEnvoyeTest.php`, `tests/Feature/Services/DevisNumeroConcurrenceTest.php`
**Commit**: `feat(devis-libre): marquerEnvoye + numérotation séquence avec lock (S1 step 4)`

### Step 5: DevisService — accepté, refusé, annulé (avec traces)

**Complexity**: standard
**RED**:
- Test `marquerAccepte` exige `statut = envoyé`, écrit `accepte_par_user_id` + `accepte_le`
- Test `marquerRefuse` symétrique
- Test `annuler` autorisé depuis `brouillon|envoyé|accepté|refusé`, refusé depuis `annulé`, écrit `annule_par_user_id` + `annule_le`
- Test verrouillage : après `accepté|refusé|annulé`, modifs de lignes refusées

**GREEN**:
- `marquerAccepte`, `marquerRefuse`, `annuler` dans `DevisService`

**REFACTOR**: None
**Files**: `app/Services/DevisService.php`, `tests/Feature/Services/DevisServiceTransitionsTest.php`
**Commit**: `feat(devis-libre): transitions accepté/refusé/annulé tracées (S1 step 5)`

### Step 6: Modification d'un envoyé re-bascule en brouillon

**Complexity**: standard
**RED**:
- Test `modifierLigne` sur devis `envoyé` : statut redevient `brouillon`, numéro conservé, `montant_total` recalculé
- Test `ajouterLigne` / `supprimerLigne` même comportement
- Test : pas de re-bascule depuis `accepté|refusé|annulé` (verrouillé)

**GREEN**:
- Ajustement des 3 méthodes lignes pour basculer le statut

**REFACTOR**: extraire helper `rebasculer()` privé si répétition
**Files**: `app/Services/DevisService.php`, `tests/Feature/Services/DevisServiceRebasculerTest.php`
**Commit**: `feat(devis-libre): modification d'un envoyé le repasse en brouillon (S1 step 6)`

### Step 7: DevisService::dupliquer

**Complexity**: standard
**RED**:
- Test duplication depuis `accepté|refusé|annulé|expiré|envoyé|brouillon` → nouveau brouillon
- Test lignes recopiées (libellé, prix unitaire, quantité, sous-cat, ordre)
- Test nouveau devis : pas de numéro, `date_emission = aujourd'hui`, `date_validite` recalculée
- Test isolation : aucun lien retour vers l'original (pas de FK `parent_id`)

**GREEN**:
- `dupliquer(Devis): Devis`

**REFACTOR**: None
**Files**: `app/Services/DevisService.php`, `tests/Feature/Services/DevisServiceDupliquerTest.php`
**Commit**: `feat(devis-libre): duplication d'un devis (S1 step 7)`

### Step 8: Génération PDF (gabarit + filigrane brouillon)

**Complexity**: standard
**RED**:
- Test `genererPdf` retourne path stockage `storage/app/associations/{id}/devis-libres/{devis_id}/devis-{numero|brouillon}.pdf`
- Test PDF brouillon contient mention "BROUILLON" + pas de numéro
- Test PDF envoyé+ contient numéro, dates, tiers, lignes, total, mentions association
- Test refus PDF si aucune ligne avec montant > 0

**GREEN**:
- Gabarit Blade `resources/views/pdf/devis-libre.blade.php` (footer unifié `PdfFooterRenderer`)
- `genererPdf(Devis): string`

**REFACTOR**: None
**Files**: `app/Services/DevisService.php`, `resources/views/pdf/devis-libre.blade.php`, `tests/Feature/Services/DevisServicePdfTest.php`
**Commit**: `feat(devis-libre): génération PDF avec filigrane brouillon (S1 step 8)`

### Step 9: Envoi par email avec log

**Complexity**: standard
**RED**:
- Test `envoyerEmail` exige statut ≠ `brouillon` (sinon `RuntimeException`) et ≥ 1 ligne avec montant > 0
- Test email envoyé à l'adresse du tiers avec PDF en PJ
- Test entrée créée dans `email_logs` (`tiers_id`, `subject`, `attachment_path`)
- Test `Mail::fake()` côté Pest

**GREEN**:
- `envoyerEmail(Devis, string $sujet, string $corps): void`
- `App\Mail\DevisLibreMail` (mailable)

**REFACTOR**: None
**Files**: `app/Services/DevisService.php`, `app/Mail/DevisLibreMail.php`, `tests/Feature/Services/DevisServiceEmailTest.php`
**Commit**: `feat(devis-libre): envoi email avec PJ PDF + email_logs (S1 step 9)`

### Step 10: Livewire DevisList (route + filtres + badge expiré)

**Complexity**: standard
**RED**:
- Test rendu liste : devis du tenant courant uniquement
- Test filtres statut, tiers, exercice ; filtre par défaut "non annulés"
- Test badge "expiré" affiché si `statut = envoyé` et `date_validite < today`
- Test bouton "Nouveau devis" → ouvre formulaire/modale

**GREEN**:
- `App\Livewire\DevisLibre\DevisList` + view Blade
- Route `/devis-libres`
- Tri JS client (data-sort)

**REFACTOR**: None
**Files**: `app/Livewire/DevisLibre/DevisList.php`, `resources/views/livewire/devis-libre/devis-list.blade.php`, `routes/web.php`, `tests/Feature/Livewire/DevisListTest.php`
**Commit**: `feat(devis-libre): écran liste avec filtres et badge expiré (S1 step 10)`

### Step 11: Livewire DevisEdit (édition + transitions + actions)

**Complexity**: complex
**RED**:
- Test rendu édition brouillon : lignes inline, total live
- Test boutons "Envoyer", "Marquer accepté", "Marquer refusé", "Annuler", "Dupliquer", "PDF", "Email" affichés selon statut
- Test mode lecture seule sur `accepté|refusé|annulé` (boutons d'édition désactivés avec tooltip)
- Test garde-fous UI : "Envoyer" disabled si devis vide ; modale Bootstrap `wire:confirm` pour annulation
- Test envoi email ouvre modale (sujet/corps) + envoi appelle service

**GREEN**:
- `App\Livewire\DevisLibre\DevisEdit` + view Blade
- Route `/devis-libres/{devis}`

**REFACTOR**: None
**Files**: `app/Livewire/DevisLibre/DevisEdit.php`, `resources/views/livewire/devis-libre/devis-edit.blade.php`, `routes/web.php`, `tests/Feature/Livewire/DevisEditTest.php`
**Commit**: `feat(devis-libre): écran édition + transitions + actions (S1 step 11)`

### Step 12: Intégration vue 360° tiers

**Complexity**: standard
**RED**:
- Test `TiersQuickViewService::getSummary()` inclut bloc "Devis libres" : count par statut + total des `accepté`
- Test ≤ 2 queries supplémentaires (pas de N+1)
- Test rendu vue 360° affiche le bloc avec lien vers `/devis-libres?tiers_id=…`

**GREEN**:
- Ajout de l'agrégation dans `TiersQuickViewService`
- Ajout de la section dans la view de `TiersQuickView`

**REFACTOR**: None
**Files**: `app/Services/TiersQuickViewService.php`, `resources/views/livewire/tiers-quick-view.blade.php`, `tests/Feature/Services/TiersQuickViewDevisLibreTest.php`
**Commit**: `feat(devis-libre): intégration vue 360° tiers (S1 step 12)`

### Step 13: Multi-tenant intrusion + sidebar + seeders + CHANGELOG

**Complexity**: standard
**RED**:
- Test intrusion : asso B ne voit/modifie aucun devis de A (liste, fiche, PDF, email)
- Test entrée sidebar "Devis libres" sous groupe Facturation, visible uniquement pour rôles autorisés
- Test seeder : ≥ 3 devis libres avec statuts variés sur asso démo

**GREEN**:
- Test intrusion `tests/Feature/MultiTenant/DevisLibreIntrusionTest.php`
- Entrée sidebar dans `resources/views/components/sidebar.blade.php` (ou équivalent)
- `DevisLibreSeeder` appelé depuis `DatabaseSeeder`
- Mise à jour `CHANGELOG.md` (entrée `vX.Y.0` Devis libres S1)
- Bump version dans `config/app.php`

**REFACTOR**: None
**Files**: `tests/Feature/MultiTenant/DevisLibreIntrusionTest.php`, `database/seeders/DevisLibreSeeder.php`, `database/seeders/DatabaseSeeder.php`, sidebar view, `CHANGELOG.md`, `config/app.php`
**Commit**: `feat(devis-libre): wiring sidebar + seeders + intrusion + CHANGELOG (S1 step 13)`

## Complexity Classification

Comme rappel pour `/build` : steps 4 et 11 sont `complex` (numérotation concurrente, écran édition multi-actions) → revue agent suite complète. Tous les autres `standard`.

## Pre-PR Quality Gate

- [ ] `./vendor/bin/sail artisan test` : 0 failed, 0 errored
- [ ] `./vendor/bin/pint` : aucun changement à proposer
- [ ] `/code-review --changed` passe (security, perf, struct, naming, multi-tenant)
- [ ] Test manuel UI : créer un devis, ajouter lignes, marquer envoyé, accepter, dupliquer, exporter PDF (brouillon + envoyé), envoyer email
- [ ] CHANGELOG mis à jour, version bumpée
- [ ] PR créée avec description structurée + référence spec

## Risks & Open Questions

| Risque | Mitigation |
|---|---|
| Course concurrente sur attribution numéro | Step 4 a un test dédié + lock pessimiste |
| Filigrane brouillon contourné par utilisateur | Step 8 lit le statut courant côté serveur, pas de paramètre client |
| Scope creep S2 (transformation transaction) | Refus systématique, S2 = autre slice |
| `wire:confirm` natif au lieu de modale Bootstrap | Convention projet rappelée Step 11 |
| Volume devis annulés gonfle la liste | Filtre par défaut "non annulés" en `DevisList` (Step 10) |
| Tests course concurrente flaky en CI | Limiter à 1 test ciblé en local + couverture par lock pessimiste plutôt que stress test |
