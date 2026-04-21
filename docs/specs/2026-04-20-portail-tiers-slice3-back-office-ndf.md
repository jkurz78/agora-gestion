# Spec — Portail Tiers, Slice 3 : Back-office NDF comptable

> **Date** : 2026-04-20
> **Auteur** : Jurgen Kurz + assistant agent
> **Statut** : Spec validée — prête pour /plan puis /build
> **Parent** : Programme "Notes de frais" (3 slices)
> **Slice** : 3/3 — dépend de Slice 1 (auth portail OTP) + Slice 2 (NDF côté portail) déjà livrées
> **Branche** : `feat/portail-tiers-slice1-auth-otp` (option A — même branche que Slices 1+2)

## 1. Intent Description

Ajouter au back-office comptable un espace **Notes de frais** permettant à un utilisateur avec le rôle Admin ou Comptable de :

- Lister les NDF soumises par les Tiers (filtrage par statut via onglets).
- Consulter le détail d'une NDF, incluant les pièces justificatives (ouverture nouvel onglet).
- **Valider** une NDF en créant une `Transaction` de type `Depense` (statut `EnAttente`) avec copie des PJ au niveau ligne.
- **Rejeter** une NDF avec motif obligatoire.
- **Dé-comptabilisation implicite** : suppression de la `Transaction` liée repasse la NDF en `soumise` via observer.

Un badge dans la top-bar informe Admin/Comptable du nombre de NDF en attente de traitement.

### Périmètre livré

**Routes** (préfixe `/comptabilite`, middleware `auth` + `EnsureTenantAccess` + Policy `treat,App\Models\NoteDeFrais`)

- `GET /comptabilite/notes-de-frais` — liste avec onglets À traiter / Validées / Rejetées / Toutes.
- `GET /comptabilite/notes-de-frais/{noteDeFrais}` — détail back-office (valider / rejeter).

**Écrans**

- **Liste** : tableau Date | Tiers | Libellé | Montant | Statut | Actions. Tri date décroissante par défaut. Onglets statut. Pas de pagination (dette v0).
- **Détail** : en-tête (Tiers, date, libellé, montant, statut, soumise le…), tableau des lignes avec lien PJ (nouvel onglet), actions contextuelles selon statut.
  - Statut **Soumise** : boutons "Valider & comptabiliser" (mini-form) et "Rejeter" (modal motif).
  - Statut **Validée** : panneau "Transaction #XXX" + lien "Ouvrir la transaction comptable" (vers `/comptabilite/transactions?edit={id}`).
  - Statut **Rejetée** : affichage du motif + retour à la liste.
  - Statut **Payée** : lecture seule (dérivé depuis Transaction rapprochée).

**Badge top-bar**

- Dans `layouts/app.blade.php`, nav-item à côté de "Documents" (pattern identique à `incomingDocumentsCount`).
- Visible uniquement pour rôles Admin ou Comptable.
- Texte : "NDF" + icône `bi-receipt` + badge warning avec count si > 0.
- Injection via View Composer dans `AppServiceProvider` (`ndfPendingCount`).
- Clic → `/comptabilite/notes-de-frais` (onglet À traiter par défaut).

**Mini-form de comptabilisation** (inline dans écran détail)

Trois champs :
1. **Compte bancaire** (select, comptes actifs du tenant) — pas de default, requis.
2. **Mode règlement** (select, enum `ModePaiement`) — default `Virement`, requis.
3. **Date comptabilisation** (date input) — default = `ndf.date`, bouton "Aujourd'hui" pour basculer au jour courant, requise.

Tous les autres champs de la `Transaction` sont pré-remplis depuis la NDF (libellé, lignes, tiers_id, montants).

**Modal rejet**

Textarea motif (requis, min 1 caractère). Bouton "Confirmer le rejet" en rouge.

### Règles métier

- **Rôles autorisés** : Admin et Comptable uniquement (via Policy `treat`). Gestionnaire et Consultation → 403.
- **Règle "propre NDF" abandonnée en v0** : aucun filtrage sur NDF émise par le Tiers rattaché au user connecté (les users ne sont pas liés à un Tiers aujourd'hui). Dette tracée pour slice ultérieure.
- **Transition statuts** :
  - `soumise → validee` (via validation) : crée la Transaction liée, renseigne `transaction_id` + `validee_at`.
  - `soumise → rejetee` (via rejet) : renseigne `motif_rejet`.
  - `validee → soumise` (via observer) : quand la Transaction liée est supprimée (soft ou force), `transaction_id = null`, `validee_at = null`.
  - `validee → payee` (dérivé accessor, déjà en place) : quand la Transaction liée est en statut `Pointe` (rapprochée).
- **Invariants Transaction issue d'une NDF** : aucun lock spécifique NDF. Les locks existants (rapprochement, facture, remise) s'appliquent normalement. Dès le pointage bancaire, la Transaction devient verrouillée par le circuit existant.
- **Granularité Transaction** : 1 NDF → 1 Transaction à N `TransactionLigne` (1 ligne NDF = 1 ligne Transaction). Mapping : `sous_categorie_id`, `operation_id`, `seance`, `libelle`, `montant`, `piece_jointe_path` (nouveau).
- **Validation comptable** : `ExerciceService::assertOuvert()` appliqué sur la date choisie → exception visible dans le mini-form si exercice clôturé.
- **Concurrence** : lock pessimiste (`lockForUpdate`) sur la NDF dans le service de validation. Deux comptables ne peuvent pas valider la même NDF simultanément.
- **Mode support super-admin** : `BlockWritesInSupport` middleware bloque valider/rejeter en support read-only.

### Justificatifs

- **Copie** (pas déplacement) depuis la NDF vers la Transaction lors de la comptabilisation.
- **Source** : `associations/{asso_id}/notes-de-frais/{note_id}/ligne-{ligne_id}.{ext}` (existant).
- **Destination** : `associations/{asso_id}/transactions/{transaction_id}/ligne-{N}-{slug(libelle)}.{ext}` (nouveau).
  - `N` = ordre 1-based de la ligne dans la NDF (`orderBy('id')`).
  - `slug(libelle)` = `Str::slug($ligne->libelle ?? 'justif')`, fallback "justif" si libellé vide.
  - `ext` = extension du fichier source (`pdf`, `jpg`, `png`, `heic`).
- **Disque** : `local`, scopé tenant.
- **Atomicité** : copie dans la transaction DB. Si `Storage::copy` échoue → rollback DB → NDF reste en Soumise.
- **Les originaux NDF sont préservés** (traçabilité audit). Doubleur espace accepté.

### Migrations

1. `transaction_lignes.piece_jointe_path` (nullable string) — nouveau champ pour PJ au niveau ligne.
2. Index composite `(association_id, statut)` sur `notes_de_frais` pour la query du badge.

### Décisions actées

- **Q1 Scope** : validation + rejet + comptabilisation. Le passage en Payée est assuré par le rapprochement bancaire existant.
- **Q2 Navigation** : entrée sidebar dans groupe Comptabilité, entre Transactions et Budget. Badge top-bar à côté de "Documents" (pattern `incomingDocumentsCount`).
- **Q3 Rôles** : Admin + Comptable uniquement. Gestionnaire / Consultation : 403.
- **Q4 Règle "propre NDF"** : abandonnée en v0 (pas de rattachement user→tiers aujourd'hui). Dette tracée.
- **Q5 Granularité Transaction** : 1 NDF → 1 Transaction à N lignes.
- **Q6 Mini-form comptabilisation** : 3 champs (compte, mode, date) + bouton "Aujourd'hui". Reste pré-rempli.
- **Q7 Rejet** : motif libre obligatoire (pas de template). Pas de retour en brouillon possible côté comptable.
- **Q8 PJ** : copie (pas move) au niveau ligne. Ajout colonne `transaction_lignes.piece_jointe_path`. Refactor complet des écrans Transaction → **Slice 4**.
- **Q9 Dé-comptabilisation** : implicite via suppression Transaction (observer). Pas de bouton dédié.
- **Q10 Preview PJ** : lien "Ouvrir" dans nouvel onglet, pas de lightbox.
- **Q11 Badge** : query DB directe à chaque render (index composite), pas de cache.

### Hors scope

- Refonte écrans Transaction (affichage / édition PJ ligne) → **Slice 4**.
- Notifications email (validation / rejet) → slice dédiée ultérieure.
- Abandon de frais CERFA → programme dédié.
- Re-soumission auto après rejet (Tiers supprime + recrée, déjà acté Slice 2).
- Pagination back-office (cohérent Slice 2, dette tracée).
- Lightbox preview PJ.
- Règle "propre NDF" basée sur rattachement user→tiers.
- Export liste NDF (CSV/PDF).
- Statistiques / tableau de bord NDF.

## 2. User-Facing Behavior (BDD)

### Liste back-office (AC-1, AC-2)

- **Scénario 1** : un comptable connecté voit le menu "Notes de frais" dans le groupe Comptabilité entre Transactions et Budget.
- **Scénario 2** : un gestionnaire connecté ne voit pas le menu Notes de frais.
- **Scénario 3** : l'ouverture de la liste affiche par défaut l'onglet "À traiter" (statut=Soumise).
- **Scénario 4** : les onglets affichent respectivement les NDF Soumises / Validées / Rejetées / Toutes.
- **Scénario 5** : la liste est triée par date décroissante par défaut.
- **Scénario 6** : la liste est scopée au tenant courant (asso A ne voit pas les NDF de asso B).

### Détail NDF (AC-3)

- **Scénario 7** : le comptable ouvre une NDF, voit l'en-tête complet + la liste des lignes.
- **Scénario 8** : chaque ligne avec PJ expose un lien "Ouvrir" qui ouvre la PJ dans un nouvel onglet.

### Valider & comptabiliser (AC-4 à AC-6)

- **Scénario 9** : clic sur "Valider & comptabiliser" ouvre un mini-form inline avec 3 champs (compte bancaire, mode règlement, date).
- **Scénario 10** : la date est pré-remplie avec la date de la NDF.
- **Scénario 11** : clic sur "Aujourd'hui" bascule la date au jour courant.
- **Scénario 12** : soumission valide crée une Transaction de type Depense avec statut_reglement=EnAttente.
- **Scénario 13** : la Transaction contient 1 ligne par ligne NDF (sous-cat, opération, séance, libellé, montant).
- **Scénario 14** : les PJ sont copiées vers `transactions/{id}/ligne-{N}-{slug}.{ext}`.
- **Scénario 15** : la NDF passe en statut Validée, `transaction_id` et `validee_at` renseignés.
- **Scénario 16** : la NDF Validée affiche un panneau "Transaction #XXX" + lien vers `/comptabilite/transactions?edit={id}`.

### Rejeter (AC-7)

- **Scénario 17** : clic sur "Rejeter" ouvre une modal Bootstrap avec textarea motif.
- **Scénario 18** : motif vide → validation échoue, message "Le motif est obligatoire".
- **Scénario 19** : soumission avec motif → NDF passe en statut Rejetée, `motif_rejet` renseigné.
- **Scénario 20** : la NDF Rejetée affiche le motif dans l'écran détail.

### Dé-comptabilisation implicite (AC-8)

- **Scénario 21** : suppression (softdelete) d'une Transaction liée à une NDF remet la NDF en Soumise (`statut=Soumise`, `transaction_id=null`, `validee_at=null`).
- **Scénario 22** : suppression d'une Transaction non liée à une NDF ne touche aucune NDF.
- **Scénario 23** : forceDelete d'une Transaction liée déclenche aussi le revert de la NDF.

### Badge top-bar (AC-13)

- **Scénario 24** : un comptable avec 3 NDF Soumises voit un badge "NDF 3" dans la top-bar.
- **Scénario 25** : un gestionnaire ne voit pas le badge.
- **Scénario 26** : clic sur le badge → redirection vers `/comptabilite/notes-de-frais` (onglet À traiter).
- **Scénario 27** : le count est scopé au tenant courant.

### Transitions interdites & edge cases

- **Scénario 28** : tentative de valider une NDF déjà Validée → DomainException, flash erreur.
- **Scénario 29** : tentative de valider une NDF Brouillon → DomainException.
- **Scénario 30** : date de comptabilisation tombant dans un exercice clôturé → exception, flash erreur.
- **Scénario 31** : 2 comptables valident simultanément la même NDF → un seul succès (lock pessimiste).
- **Scénario 32** : échec copie PJ (fichier manquant) → rollback DB, NDF reste Soumise, flash erreur.
- **Scénario 33** : super-admin en mode support ne peut pas valider ni rejeter (middleware BlockWritesInSupport).

### Isolation multi-tenant

- **Scénario 34** : comptable asso A tente d'ouvrir une NDF de asso B → 404 (TenantScope fail-closed).
- **Scénario 35** : comptable asso A tente de valider une NDF de asso B via URL directe → 404.

## 3. Architecture Specification

### Modèles

- **`NoteDeFrais`** (inchangé, `TenantModel` + `SoftDeletes`) : accessor `statut` déjà en place pour dériver Payée depuis Transaction rapprochée.
- **`Transaction`** (inchangé, `TenantModel` + `SoftDeletes`) : observer ajouté (nouveau).
- **`TransactionLigne`** : ajout `piece_jointe_path` (nullable string) au schéma + `$fillable`. Accessor `piece_jointe_url()` pour lien consultation (nouveau).

### Migrations

```
2026_04_21_000000_add_piece_jointe_path_to_transaction_lignes.php
2026_04_21_000001_add_index_to_notes_de_frais_statut.php
```

### Composants Livewire (namespace `App\Livewire\BackOffice\NoteDeFrais`)

- `Index` : liste + onglets + tri + filtres. Vue : `livewire.back-office.note-de-frais.index`.
- `Show` : détail + mini-form valider + modal rejet. Vue : `livewire.back-office.note-de-frais.show`.

### Service

`App\Services\NoteDeFrais\NoteDeFraisValidationService` (nouveau fichier, côté app, **pas** dans `Portail/`) :

- `valider(NoteDeFrais $ndf, ValidationData $data): Transaction`
- `rejeter(NoteDeFrais $ndf, string $motif): void`

`ValidationData` = DTO avec `compte_id`, `mode_paiement`, `date`.

Dépendances injectées : `TransactionService`, `ExerciceService`.

### Policy

`App\Policies\NoteDeFraisPolicy` (étend l'existant Slice 2) :

- `view(User $user, NoteDeFrais $ndf)` — déjà existant côté portail, étendu pour Admin/Comptable.
- `treat(User $user, NoteDeFrais $ndf)` — nouveau, `true` ssi role ∈ {Admin, Comptable} dans le tenant courant.
- Enregistrée dans `AuthServiceProvider::$policies`.

### Observer

`App\Observers\TransactionObserver` (nouveau) :

- `deleted(Transaction $transaction)` + `forceDeleted(Transaction $transaction)` :
  - Cherche `NoteDeFrais::where('transaction_id', $transaction->id)->first()`.
  - Si trouvé → update `statut=Soumise`, `transaction_id=null`, `validee_at=null`.
  - Log `comptabilite.ndf.reverted_to_submitted`.
- Enregistré dans `AppServiceProvider::boot()` via `Transaction::observe(TransactionObserver::class)`.

### Routes (groupe `comptabilite`)

```php
Route::middleware(['auth', EnsureTenantAccess::class])->prefix('comptabilite')->name('comptabilite.')->group(function () {
    Route::prefix('notes-de-frais')->name('ndf.')->group(function () {
        Route::get('/', Index::class)->name('index');
        Route::get('/{noteDeFrais}', Show::class)->name('show');
    });
});
```

Policy gate via `can:treat,App\Models\NoteDeFrais` sur les composants.

### Layout & sidebar

- `resources/views/layouts/app.blade.php` :
  - Ajout nav-item "NDF" entre "Documents" et "Paramètres" dans la top-bar, visible via `@can('treat', App\Models\NoteDeFrais::class)`.
- Sidebar partial groupe Comptabilité : ajout entrée "Notes de frais" entre "Transactions" et "Budget", gated par policy.

### View Composer

- `App\Providers\AppServiceProvider::boot()` : `View::composer('layouts.app', function ($view) { ... })` injecte `ndfPendingCount` si user Admin/Comptable.

### Contraintes & invariants

- **Isolation tenant** : TenantScope fail-closed sur NDF et Transaction, Policy défensive.
- **Atomicité** : validation = 1 transaction DB qui inclut (create Transaction, create lignes, copie PJ, update NDF).
- **Concurrence** : `$ndf->lockForUpdate()` dans la transaction du service.
- **Exercice clôturé** : `ExerciceService::assertOuvert()` dans le service.
- **`strict_types=1` + `final class` + type hints**.
- **`pint` vert**.
- **Cast `(int)` des deux côtés PK/FK**.

## 4. Acceptance Criteria

### Fonctionnel (AC-1 à AC-8)

- **AC-1** : Entrée sidebar "Notes de frais" dans groupe Comptabilité entre Transactions et Budget, visible Admin/Comptable.
- **AC-2** : Liste avec onglets À traiter / Validées / Rejetées / Toutes, tri date décroissante.
- **AC-3** : Détail NDF affiche lignes avec lien PJ (nouvel onglet) — une PJ par ligne.
- **AC-4** : Bouton "Valider & comptabiliser" ouvre mini-form inline (compte, mode, date, bouton Aujourd'hui).
- **AC-5** : Validation crée Transaction type Depense, statut_reglement=EnAttente, 1 TransactionLigne par ligne NDF, PJ copiées au nommage `ligne-{N}-{slug(libelle)}.{ext}`.
- **AC-6** : Validation update NDF statut=Validee, transaction_id, validee_at.
- **AC-7** : Rejet ouvre modal, motif obligatoire, update statut=Rejetee + motif_rejet.
- **AC-8** : Suppression (soft ou force) de la Transaction liée à une NDF déclenche observer qui remet NDF en Soumise.

### Sécurité (AC-9 à AC-12)

- **AC-9** : Policy `treat` accessible Admin/Comptable uniquement (403 Gestionnaire / Consultation).
- **AC-10** : TenantScope fail-closed sur NDF et Transaction (asso A ↔ asso B).
- **AC-11** : Path stockage PJ tenant-scopé (`associations/{id}/transactions/...`).
- **AC-12** : Mode support super-admin (`BlockWritesInSupport`) bloque valider/rejeter.

### UX (AC-13 à AC-16)

- **AC-13** : Badge top-bar "NDF N" visible Admin/Comptable, masqué pour autres rôles, lien vers liste À traiter.
- **AC-14** : Messages d'erreur en français.
- **AC-15** : Confirmations via modale Bootstrap (jamais `confirm()` natif).
- **AC-16** : Panneau "Transaction #XXX" + lien "Ouvrir la transaction comptable" sur NDF Validée.

### Conformité projet (AC-17 à AC-19)

- **AC-17** : `declare(strict_types=1)` + `final class` + type hints.
- **AC-18** : `./vendor/bin/pint` vert.
- **AC-19** : Cast `(int)` des deux côtés dans les `===` PK/FK.

### Tests (AC-20 à AC-22)

- **AC-20** : ≥1 test Pest par scénario BDD ci-dessus (~21 tests totaux).
- **AC-21** : Tests d'intrusion tenant (asso A ↔ asso B) sur liste, détail, validation, rejet.
- **AC-22** : Suite complète verte.

## 5. Consistency Gate — PASS

- **Intent non-ambigu** : valider, rejeter, comptabiliser, badge, dé-comptabilisation implicite via observer.
- **BDD couvre Intent** : 35 scénarios répartis sur liste, détail, valider, rejeter, observer, badge, interdictions, isolation.
- **Architecture contraint sans over-engineering** : 2 migrations légères, 1 observer, 1 service, 1 policy étendue, 2 composants Livewire. Pas de refactor des écrans Transaction (Slice 4).
- **Concepts nommés uniformément** : `NoteDeFrais`, `Transaction`, `TransactionLigne`, `StatutNoteDeFrais`, `ModePaiement`, `StatutReglement`, `TenantContext`, `treat`, `ndfPendingCount`.
- **Scope = 1 slice** : ~21 tests, migrations légères, composants isolés sous `BackOffice\NoteDeFrais\`.

## 6. Dettes portées

- **Slice 4** : refonte écrans Transaction (affichage + édition PJ au niveau ligne dans tous les forms Transaction existants).
- Rattachement user → tiers et retour de la règle "Comptable ne peut pas traiter sa propre NDF".
- Notifications email (validation / rejet) → slice dédiée.
- Pagination back-office NDF si volume > 100.
- Export liste NDF (CSV/PDF) si besoin apparaît.
- Lightbox preview PJ si le lien nouvel onglet s'avère insuffisant.
- Bouton "Dé-comptabiliser" dédié si le flux observer-via-suppression-Transaction est jugé contre-intuitif en usage réel.

## 7. Après Slice 3

Le programme "Notes de frais" sera complet en v0 :

- Slice 1 : fondation portail + auth OTP (livré).
- Slice 2 : NDF côté portail (saisie + suivi) (livré).
- Slice 3 : back-office comptable NDF (la présente spec).

**Slice 4 suivant (pas du programme NDF)** : unification PJ au niveau ligne dans l'ensemble des écrans Transaction de l'application (refactor multi-écrans).
