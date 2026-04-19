# Spec — Portail Tiers, Slice 2 : Notes de frais (saisie + suivi)

> **Date** : 2026-04-19
> **Auteur** : Jurgen Kurz + assistant agent
> **Statut** : Spec validée — prête pour /plan puis /build
> **Parent** : Programme "Notes de frais" (3 slices)
> **Slice** : 2/3 — dépend de Slice 1 (fondation portail + auth OTP) déjà livrée
> **Branche** : `feat/portail-tiers-slice1-auth-otp` (option A — même branche que Slice 1)

## 1. Intent Description

Ajouter au portail Tiers (fondation Slice 1) un espace **Notes de frais** où le Tiers connecté peut créer, enregistrer en brouillon, soumettre, consulter et supprimer ses notes de frais. Aucun back-office comptable — les NDF soumises restent "en attente" jusqu'à la Slice 3.

### Périmètre livré

**Routes** (sous `/portail/{slug}/`, garde `tiers-portail`)
- `GET /notes-de-frais` — liste.
- `GET /notes-de-frais/nouvelle` — form vierge.
- `GET /notes-de-frais/{id}/edit` — form brouillon uniquement.
- `GET /notes-de-frais/{id}` — vue lecture seule (soumise/rejetée/validée/payée).
- Suppression via Livewire action + policy (pas de route DELETE).

**Écrans**
- **Liste** : tableau date/libellé/montant/statut/action + bouton "+ Nouvelle note de frais".
- **Saisie** (Livewire) : en-tête (date, libellé) + lignes dynamiques (sous-catégorie obligatoire, opération/séance optionnelles, libellé optionnel, montant >0, PJ obligatoire à la soumission). Total cumulé temps réel. Boutons "Enregistrer brouillon" / "Soumettre".
- **Consultation** : lecture seule, badge statut, motif si rejetée, date paiement si payée.

**Règles métier**
- Statuts : `brouillon`, `soumise`, `rejetee`, `validee`, `payee`.
- `payee` dérivé : `transaction_id !== null && transaction.statut_reglement === Paye`.
- Brouillon : tout tolérant (date future OK, PJ optionnelle).
- Soumission : date ≤ today, libellé rempli, ≥1 ligne, chaque ligne a sous-cat + montant>0 + PJ.
- Suppression : softdelete, uniquement brouillon, cleanup fichiers.

**Justificatifs**
- Formats : PDF, JPG, PNG, HEIC. Max 5 Mo.
- Stockage : `storage/app/associations/{association_id}/notes-de-frais/{note_id}/ligne-{ligne_id}.{ext}`.

**Référentiels filtrés**
- Sous-catégories : `pour_depenses = true` uniquement.
- Opérations : actives + exercice courant.
- Séances : liées à l'opération choisie.

### Décisions actées

- Q1 Sous-catégories : `pour_depenses = true` uniquement.
- Q2 Opérations/séances : actives + exercice courant.
- Q3 Multi-brouillons : illimité.
- Q4 PJ persistées dès l'upload, même en brouillon.
- Q5 Softdelete du brouillon OK.
- Q6-A Pas d'archivage (rejeté reste visible, supprimable).
- Q7 Aucun plafond de montant.
- Q8 Date future permise en brouillon, refusée à la soumission.

### Hors scope

- Back-office comptable (Slice 3).
- Notifications email.
- Abandon de frais CERFA.
- Modification NDF soumise (read-only côté Tiers).
- Re-soumission auto après rejet (Tiers supprime + recrée).
- Pagination (v0).

## 2. User-Facing Behavior (BDD)

Voir les 21 scénarios dans le fichier de spec session (récapitulés) :

- Liste (vide, peuplée, cloisonnement Tiers).
- Création brouillon minimal, date future OK.
- Édition brouillon, persistance PJ entre sessions.
- Suppression brouillon (softdelete + cleanup).
- Soumission réussie + 4 cas de refus (date future, PJ manquante, sous-cat manquante, montant ≤0).
- Consultation lecture seule, motif rejet, statut payée dérivé.
- Upload format invalide, >5 Mo.
- Référentiels filtrés (sous-cat pour_depenses, opérations actives/exercice).
- Isolation multi-tenant (NDF asso A invisible depuis portail asso B, NDF autre Tiers → 403/404).

## 3. Architecture Specification

### Modèles + tables

- `notes_de_frais` (TenantModel + SoftDeletes) : `id`, `association_id`, `tiers_id`, `date`, `libelle`, `statut` (enum), `motif_rejet` (nullable), `transaction_id` (FK nullable), `submitted_at` (nullable), `validee_at` (nullable), timestamps, `deleted_at`.
- `notes_de_frais_lignes` (Model simple, pas tenant direct — accès via NDF parent) : `id`, `note_de_frais_id` (FK cascade), `sous_categorie_id` (FK), `operation_id` (FK nullable), `seance_id` (FK nullable), `libelle` (nullable), `montant` (decimal 10,2), `piece_jointe_path` (nullable), timestamps.
- Enum `App\Enums\StatutNoteDeFrais` : Brouillon, Soumise, Rejetee, Validee, Payee.

### Composants

- `App\Livewire\Portail\NoteDeFrais\{Index,Form,Show}` — tous utilisent le trait `WithPortailTenant` de Slice 1.
- `App\Services\Portail\NoteDeFrais\NoteDeFraisService` — saveDraft, submit, delete.
- `App\Policies\NoteDeFraisPolicy` — view/update/delete vérifie `tiers_id === auth('tiers-portail')->id()`.

### Routes

```php
Route::prefix('notes-de-frais')->name('ndf.')->group(function () {
    Route::get('/', Index::class)->name('index');
    Route::get('/nouvelle', Form::class)->name('create');
    Route::get('/{noteDeFrais}/edit', Form::class)->name('edit');
    Route::get('/{noteDeFrais}', Show::class)->name('show');
});
```

### Contraintes & invariants

- Isolation tenant : TenantScope fail-closed + Policy.
- Transition statut : uniquement brouillon → soumise côté Tiers.
- Immutabilité post-soumission read-only.
- PJ obligatoire à la soumission, optionnelle en brouillon.
- Suppression fichier via event Eloquent `deleting` sur NoteDeFraisLigne.

## 4. Acceptance Criteria

### Fonctionnalité (AC-1 à AC-6)
- Liste filtrée par Tiers, brouillon créable/modifiable/supprimable, soumission avec validations, statut payée dérivé.

### Sécurité (AC-7 à AC-11)
- Policy tiers_id, TenantScope fail-closed, chemin tenant-scopé, validation upload stricte.

### UX (AC-12 à AC-16)
- Total temps réel, messages fr, modale Bootstrap pour confirm, référentiels filtrés.

### Conformité projet (AC-17 à AC-20)
- strict_types + final class, TenantModel + SoftDeletes, cast (int), pint vert.

### Tests (AC-21 à AC-23)
- ≥1 test Pest par scénario BDD, intrusion tenant, suite complète verte.

## 5. Consistency Gate — PASS

Intent non-ambigu, BDD couvre Intent, Architecture contraint sans over-engineering, concepts nommés uniformément, scope = 1 slice.

## 6. Dettes portées

- Archivage NDF (Q6-B) si besoin apparaît.
- Pagination si >100 NDF/Tiers.
- Notifications email en slice dédiée.
- Ajout du champ `archived` sur Tiers (porté depuis Slice 1).

## 7. Après Slice 2

- **Slice 3** : Back-office comptable NDF (validation, rejet motivé, comptabilisation → Transaction avec Tiers-bénéficiaire, justifs copiés au nommage `ligne-{N}-{libelle}.{ext}`).
