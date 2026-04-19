# Plan: Portail Tiers — Slice 2 (Notes de frais saisie + suivi)

**Created**: 2026-04-19
**Branch**: `feat/portail-tiers-slice1-auth-otp` (option A — même branche que Slice 1)
**Status**: approved
**Specs**: [docs/specs/2026-04-19-portail-tiers-slice2-notes-de-frais.md](../docs/specs/2026-04-19-portail-tiers-slice2-notes-de-frais.md)

## Goal

Livrer au portail Tiers la fonctionnalité de **saisie + consultation des notes de frais** : modèles persistants, écrans liste/saisie/consultation, service métier, policy d'autorisation, gestion des justificatifs tenant-scopés. Aucun back-office comptable (Slice 3).

## Acceptance Criteria (résumé)

### Fonctionnalité
- [ ] AC-1 Liste NDF cloisonnée par Tiers connecté.
- [ ] AC-2 Brouillon créable (validations minimales).
- [ ] AC-3 Soumission contrôle : date ≤ today, libellé, ≥1 ligne complète + PJ.
- [ ] AC-4 Brouillon éditable, NDF soumise read-only.
- [ ] AC-5 Suppression brouillon = softdelete + cleanup fichiers.
- [ ] AC-6 Statut `payee` dérivé de Transaction.

### Sécurité
- [ ] AC-7 Policy bloque update/delete d'un autre Tiers (403).
- [ ] AC-8 TenantScope fail-closed sur `NoteDeFrais`.
- [ ] AC-9 PJ stockées sous `storage/app/associations/{id}/notes-de-frais/{note_id}/`.
- [ ] AC-10 Validation upload : PDF/JPG/PNG/HEIC, max 5 Mo.
- [ ] AC-11 Policy `view` autorise uniquement tiers_id propriétaire.

### UX
- [ ] AC-12 Total cumulé en temps réel pendant la saisie.
- [ ] AC-13 Messages d'erreur en français, précis.
- [ ] AC-14 Confirmation suppression via modale Bootstrap.
- [ ] AC-15 Select sous-catégories filtré `pour_depenses = true`.
- [ ] AC-16 Select opérations filtré actives + exercice courant.

### Conformité
- [ ] AC-17 strict_types + final class + type hints.
- [ ] AC-18 NoteDeFrais étend TenantModel + SoftDeletes.
- [ ] AC-19 Cast (int) sur PK/FK.
- [ ] AC-20 Pint vert.

### Tests
- [ ] AC-21 ≥1 test Pest par scénario BDD (~25 tests).
- [ ] AC-22 Tests d'intrusion tenant + Tiers cross-lookup.
- [ ] AC-23 Suite complète verte (≥ 1955 + nouveaux).

## Steps

### Step 1 : Modèles + migrations + enum

**Complexity**: standard
**RED** : Tests présence tables, modèles, enum, scope tenant sur `NoteDeFrais`, FK cascade sur lignes, softdelete fonctionnel.
**GREEN** :
- Enum `App\Enums\StatutNoteDeFrais` (5 cases).
- Migrations `notes_de_frais` + `notes_de_frais_lignes`.
- Model `NoteDeFrais extends TenantModel use SoftDeletes` avec casts (`date` date, `submitted_at`/`validee_at` datetime, `statut` enum), fillable, relations (`tiers`, `transaction`, `lignes`).
- Model `NoteDeFraisLigne` avec casts (`montant` decimal:2), relations (`noteDeFrais`, `sousCategorie`, `operation`, `seance`).
- Accessor `payee` dérivé : override de `statut` getter si `transaction?->statut_reglement === Paye`.
**Files** : `app/Enums/StatutNoteDeFrais.php`, `app/Models/{NoteDeFrais,NoteDeFraisLigne}.php`, 2 migrations, `tests/Feature/Portail/NoteDeFrais/ModelTest.php`.
**Commit** : `feat(ndf): models, migrations and StatutNoteDeFrais enum`

---

### Step 2 : Policy + Service métier (CRUD brouillon)

**Complexity**: complex
**RED** : Tests policy view/update/delete selon tiers_id, tests `NoteDeFraisService::saveDraft($tiers, $data)` crée NDF brouillon + lignes.
**GREEN** :
- `app/Policies/NoteDeFraisPolicy.php` — 3 méthodes, compare `$noteDeFrais->tiers_id === $tiers->id`.
- Registration dans `AuthServiceProvider`.
- `app/Services/Portail/NoteDeFrais/NoteDeFraisService.php` — `saveDraft(Tiers $tiers, array $header, array $lignes): NoteDeFrais` dans DB::transaction.
**Files** : `app/Policies/NoteDeFraisPolicy.php`, `app/Services/Portail/NoteDeFrais/NoteDeFraisService.php`, tests.
**Commit** : `feat(ndf): policy + service saveDraft`

---

### Step 3 : Service `submit()` avec validations métier

**Complexity**: complex
**RED** : Tests submit d'un brouillon valide → statut soumise + submitted_at. Tests refus : date future, libellé vide, ligne sans PJ, ligne sans sous-cat, montant ≤0, ≥1 ligne requise.
**GREEN** : `NoteDeFraisService::submit(NoteDeFrais $ndf): void` avec `Validator::make` sur règles métier.
**Files** : service étendu, `SubmissionTest.php`.
**Commit** : `feat(ndf): service submit with business rule validation`

---

### Step 4 : Service `delete()` + cleanup fichiers

**Complexity**: standard
**RED** : softdelete + tous les fichiers PJ des lignes supprimés du storage.
**GREEN** :
- `NoteDeFraisService::delete(NoteDeFrais $ndf): void`.
- Event listener Eloquent `deleting` sur `NoteDeFraisLigne` qui supprime `$piece_jointe_path` du storage.
- Registration dans `boot()` du model.
**Files** : service, model, `DeletionTest.php`.
**Commit** : `feat(ndf): service delete with storage cleanup`

---

### Step 5 : Livewire Index — liste + menu home

**Complexity**: standard
**RED** : Liste affiche les NDF du Tiers auth, exclut celles d'autres Tiers, bouton créer, message si vide, bouton Modifier si brouillon / Consulter sinon.
**GREEN** :
- `App\Livewire\Portail\NoteDeFrais\Index` (trait `WithPortailTenant`).
- Vue Bootstrap avec table, badges Bootstrap par statut.
- Route `portail.ndf.index`.
- Ajout tuile "Mes notes de frais" sur `home.blade.php`.
**Files** : Livewire + vue, `routes/portail.php`, `home.blade.php`, `IndexTest.php`.
**Commit** : `feat(ndf): liste Livewire + menu home portail`

---

### Step 6 : Livewire Form — création brouillon

**Complexity**: complex
**RED** : Form affiche, ajout/suppression lignes, enregistre brouillon, total temps réel, sélects filtrés (pour_depenses + actives exercice courant).
**GREEN** :
- `App\Livewire\Portail\NoteDeFrais\Form` — propriétés `$header`, `$lignes`, méthodes `addLigne`, `removeLigne`, `saveDraft`, `submit`. Trait `WithPortailTenant`.
- Upload Livewire WithFileUploads par ligne (attention : **ne pas** utiliser `$file` comme nom de variable — feedback import_livewire).
- Routes `portail.ndf.create` et `portail.ndf.edit`.
- Vue Bootstrap.
**Files** : Livewire + vue + routes + `FormTest.php`.
**Commit** : `feat(ndf): form Livewire création brouillon + uploads`

---

### Step 7 : Livewire Form — édition + soumission

**Complexity**: standard
**RED** : Édition brouillon existant pré-remplit, soumission valide fonctionne, soumission invalide affiche erreur précise.
**GREEN** : Extension du composant Form — `mount` supporte `?NoteDeFrais $noteDeFrais`. Action `submit` appelle `NoteDeFraisService::submit`. Policy check.
**Files** : Livewire, `SubmissionTest.php`.
**Commit** : `feat(ndf): form edit brouillon + submission`

---

### Step 8 : Livewire Show — lecture seule + suppression

**Complexity**: standard
**RED** : Affichage lecture seule toutes les infos, badge statut, motif rejet si rejetée, date paiement si payée, bouton Supprimer uniquement si brouillon (avec modale Bootstrap).
**GREEN** :
- `App\Livewire\Portail\NoteDeFrais\Show`.
- Action `delete()` avec `wire:confirm` modale Bootstrap (feedback `wire_confirm_bootstrap`).
**Files** : Livewire + vue + `ShowTest.php`.
**Commit** : `feat(ndf): show read-only + deletion`

---

### Step 9 : Tests d'intrusion multi-tenant

**Complexity**: complex
**RED** : 
- NDF asso A invisible depuis portail asso B.
- Tentative édition NDF d'un autre Tiers → 403.
- Fail-closed NoteDeFrais sans TenantContext.
- Accès à `show` d'une NDF sans droit → 403.
**GREEN** : Doit passer déjà (policies + tenant scope). Sinon fix.
**Files** : `tests/Feature/Portail/NoteDeFrais/IsolationTest.php`.
**Commit** : `test(ndf): tenant intrusion + tiers cross-lookup`

---

### Step 10 : Observabilité + doc

**Complexity**: trivial
**GREEN** :
- 3 événements log : `portail.ndf.created`, `portail.ndf.submitted`, `portail.ndf.deleted`.
- Mise à jour `docs/portail-tiers.md` section NDF.
**Files** : service + doc.
**Commit** : `feat(ndf): structured logs + doc`

---

## Risques & Questions ouvertes

- **R1 — Upload Livewire + tenant path** : le chemin final depend de `association_id`. À l'upload, le fichier est en `livewire-tmp`. La copie vers tenant path ne se fait qu'au save. Gérer le cas où la NDF n'a pas encore d'ID (nouvelle) : stocker en temp jusqu'au save, puis copier.
- **R2 — Accessor `payee` dérivé** : override du getter `statut` peut surprendre (le champ DB reste `validee`). Garder cohérent : `statut` getter retourne `Payee` si Transaction payée, sinon valeur DB.
- **R3 — Validation date à la soumission** : tolérer date future en brouillon et la refuser au `submit()`. Confirmé Q8.

## Pre-PR Quality Gate

- [ ] Suite verte complète.
- [ ] Pint vert.
- [ ] `/code-review --changed` pass.
- [ ] Smoke test manuel local : création → brouillon → édition → soumission → consultation.
- [ ] MEMORY.md mis à jour.
