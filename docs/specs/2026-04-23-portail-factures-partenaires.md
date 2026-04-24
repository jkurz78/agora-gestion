# Portail Tiers — Dépôt et suivi de factures par les partenaires

**Date** : 2026-04-23
**Statut** : spec PASS, prête pour `/plan`
**Programme** : Portail Tiers — bloc "Partenaires, bénévoles" (`pour_depenses=true`)
**Périmètre** : portail uniquement (back-office hors MVP, vision actée)

---

## 1. Intent Description

Permettre aux Tiers `pour_depenses=true` de **déposer leurs factures** (PDF + date + numéro) depuis leur espace portail, et leur offrir une **visibilité simple** sur l'état de règlement de leurs dépenses chez l'association.

Deux écrans distincts, deux finalités :

1. **Vos factures à traiter** — boîte de dépôt. Liste les factures soumises non encore comptabilisées. Le partenaire peut **déposer** une nouvelle facture ou **supprimer** un dépôt qu'il regrette (oubli total, comme s'il n'avait jamais uploadé).
2. **Historique de vos dépenses** — vision simplifiée de toutes les `Transaction` de dépense liées au Tiers, peu importe leur source (dépôt portail, saisie comptable directe, facture papier scannée). Colonnes : date, référence, montant, statut (En attente / Réglée), lien PDF si pièce jointe disponible.

L'historique **exclut** les Transactions issues de notes de frais (déjà visibles dans l'écran "Vos notes de frais" du portail). Pas de doublon entre les deux écrans.

Modèle de dépôt **délibérément minimal** (4 champs métier). Toute la richesse comptable vit dans `Transaction`. Aucun `SoftDeletes` sur `FacturePartenaireDeposee` : un dépôt supprimé est oublié.

**Pourquoi cette asymétrie avec NDF** : pour NDF, le bénévole connaît la nature de ses frais (libellé, sous-cat, séance, montant) → modèle dédié. Pour une facture fournisseur, le partenaire n'a pas la lecture comptable → on minimise sa contribution et on délègue tout au comptable.

---

## 2. User-Facing Behavior (BDD)

```gherkin
Feature: Dépôt et suivi de factures partenaire

  Background:
    Étant donné un Tiers "Acme SARL" pour_depenses=true authentifié sur "Asso X"

  Scenario: Deux cartes sur l'accueil portail
    Quand j'affiche l'accueil portail
    Alors la section "Partenaires, bénévoles" contient :
      | Carte                       |
      | Vos notes de frais          |
      | Vos factures à traiter      |
      | Historique de vos dépenses  |

  # === Écran 1 : Vos factures à traiter ===

  Scenario: Dépôt d'une facture
    Étant donné je suis sur "Vos factures à traiter"
    Quand je clique "Déposer une facture"
    Et je saisis date "2026-04-15", numéro "F-2026-0042", PDF valide
    Et je valide
    Alors un dépôt est créé en statut "Soumise"
    Et il apparaît dans la liste de cet écran
    Et le PDF est stocké sous storage/app/associations/{id}/factures-deposees/...

  Scenario: Suppression par le partenaire (oubli total)
    Étant donné j'ai un dépôt "F-2026-0042" en statut "Soumise"
    Quand je clique "Supprimer" et confirme via la modale Bootstrap
    Alors l'enregistrement FacturePartenaireDeposee est supprimé (hard delete)
    Et le fichier PDF est supprimé physiquement du disque
    Et la ligne disparaît immédiatement

  Scenario: Pas d'action sur un dépôt déjà traité
    Étant donné un dépôt comptabilisé (statut "Traitée", transaction_id renseigné)
    Quand j'affiche "Vos factures à traiter"
    Alors ce dépôt n'y figure plus

  Scenario: Validation du formulaire
    Quand je soumets sans PDF
    Alors un message d'erreur "Le fichier PDF est obligatoire" s'affiche
    Quand je soumets un fichier non-PDF
    Alors "Seuls les fichiers PDF sont acceptés"
    Quand je soumets un PDF > 10 Mo
    Alors "Le fichier ne doit pas dépasser 10 Mo"
    Quand je soumets sans date ou date future
    Alors un message d'erreur explicite s'affiche
    Quand je soumets sans numéro ou avec un numéro de plus de 50 caractères
    Alors un message d'erreur explicite s'affiche

  # === Écran 2 : Historique de vos dépenses ===

  Scenario: Historique = toutes les Transaction de dépense du Tiers (hors NDF)
    Étant donné le Tiers a 4 Transaction de type Dépense :
      | source                                      |
      | dépôt portail comptabilisé                  |
      | saisie comptable directe                    |
      | scan de facture papier                      |
      | remboursement de note de frais (NDF liée)   |
    Quand j'affiche "Historique de vos dépenses"
    Alors seules les 3 premières apparaissent
    Et la Transaction liée à une note de frais est exclue
    Et un texte muted en pied d'écran rappelle :
      "Vos remboursements de notes de frais sont visibles dans l'écran Vos notes de frais."

  Scenario: Champs publics uniquement
    Quand j'affiche "Historique de vos dépenses"
    Alors chaque ligne affiche : Date, Référence, Montant, Statut, PDF (si dispo)
    Et aucune autre information de la Transaction n'est exposée
      (pas de notes internes, pas de sous-catégorie, pas d'imputation analytique)

  Scenario: Consultation du PDF depuis l'historique
    Étant donné une Transaction avec une pièce jointe PDF
    Quand je clique l'icône PDF sur cette ligne
    Alors le PDF s'ouvre via une URL signée scoped au Tiers connecté

  Scenario: Statuts de règlement visibles
    Alors le statut affiché reflète l'état de règlement courant de la Transaction :
      | Statut interne | Affichage portail |
      | non réglé      | En attente        |
      | réglé          | Réglée            |

  # === Sécurité ===

  Scenario: Isolation tenant + Tiers
    Étant donné un dépôt et une Transaction sur "Asso X" pour Acme
    Quand un Tiers homonyme se connecte sur "Asso Y"
    Ou un autre Tiers de "Asso X" se connecte
    Alors aucune ligne ni PDF de Acme/Asso X n'est accessible
```

---

## 3. Architecture Specification

### Modèle `FacturePartenaireDeposee`

`App\Models\FacturePartenaireDeposee` — étend `App\Models\TenantModel`. **Pas de SoftDeletes** : suppression par le partenaire = hard delete BDD + fichier physique.

| Champ | Type | Note |
|---|---|---|
| `id` | bigint | |
| `association_id` | FK | scope tenant fail-closed |
| `tiers_id` | FK | déposant |
| `date_facture` | date | saisi |
| `numero_facture` | string(50) | saisi |
| `pdf_path` | string | relatif au disk privé |
| `pdf_taille` | int | bytes (audit) |
| `statut` | enum `soumise\|traitee\|rejetee` | `rejetee` modélisé, non utilisé MVP |
| `motif_rejet` | text nullable | modélisé, non utilisé MVP |
| `transaction_id` | FK nullable | renseigné à la comptabilisation |
| `traitee_at` | datetime nullable | |
| `created_at` / `updated_at` | | |

**Index** : `(association_id, tiers_id, statut)` (liste portail) ; `(association_id, statut, created_at)` (back-office futur).

### Composants Livewire portail

| Composant | Rôle |
|---|---|
| `App\Livewire\Portail\FacturePartenaire\AtraiterIndex` | Liste dépôts `soumise` du Tiers + bouton "Déposer" + action "Supprimer" |
| `App\Livewire\Portail\FacturePartenaire\Depot` | Formulaire dépôt (`WithFileUploads`) |
| `App\Livewire\Portail\HistoriqueDepenses\Index` | Vue projetée des `Transaction` de dépense du Tiers |

Suppression via `wire:confirm` avec modale Bootstrap (jamais le `confirm()` natif).

### Service

`App\Services\Portail\FacturePartenaireService`

- `submit(Tiers $tiers, array $data, UploadedFile $pdf): FacturePartenaireDeposee`
  - Crée l'enregistrement, stocke le PDF sous `associations/{id}/factures-deposees/{Y}/{m}/{Y-m-d}-{numero-slug}-{rand6}.pdf`.
  - Encapsulé dans `DB::transaction`.
- `oublier(FacturePartenaireDeposee $depot, Tiers $tiers): void`
  - Refuse si `statut !== 'soumise'` OU `tiers_id` ≠ propriétaire.
  - Hard delete BDD + `Storage::delete($depot->pdf_path)` dans la même transaction.
- `comptabiliser(FacturePartenaireDeposee $depot, Transaction $transaction): void`
  - **Pour back-office futur — testée unitairement dès MVP.**
  - Attache le PDF du dépôt comme pièce jointe de `Transaction` (réutilise `transaction_pieces_jointes`).
  - `statut = traitee`, `transaction_id`, `traitee_at = now()`.
  - Émet event `FactureDeposeeComptabilisee` (utile pour notifications futures).

### Projection de l'historique (whitelist stricte)

`App\Http\Resources\Portail\TransactionDepensePubliqueResource` — DTO ou Resource projeté avant rendu.

Champs **exposés uniquement** :
- `date_piece` → "Date"
- `reference` → `numero_piece` si renseigné, fallback `libelle`
- `montant_ttc` → "Montant"
- `statut_reglement` → "En attente" / "Réglée"
- `pdf_url` → URL signée vers la 1ʳᵉ pièce jointe PDF si présente, sinon null

**Query** :

```php
Transaction::query()
    ->where('tiers_id', $tiers->id)
    ->where('type', 'depense') // adapter si convention différente
    ->whereDoesntHave('noteDeFrais') // exclut Transactions issues de NDF
    ->orderByDesc('date_piece');
```

Exclusion NDF via `whereDoesntHave('noteDeFrais')` — relation `hasOne` déjà déclarée sur `app/Models/Transaction.php:109`.

### Routes portail

| Verbe | Route | Composant / action |
|---|---|---|
| GET | `/portail/{slug}/factures` | `AtraiterIndex` |
| GET | `/portail/{slug}/factures/depot` | `Depot` |
| GET | `/portail/{slug}/factures/{depot}/pdf` | route signée, scoped Tiers |
| GET | `/portail/{slug}/historique` | `HistoriqueDepenses\Index` |
| GET | `/portail/{slug}/historique/{transaction}/pdf` | route signée, scoped Tiers |

Toutes derrière `auth:tiers-portail` + middleware `EnsurePourDepenses` (existant, déjà utilisé pour NDF).

### Multi-tenant & stockage

- `FacturePartenaireDeposee` étend `TenantModel` → scope global `association_id` fail-closed.
- Stockage : `storage/app/associations/{association_id}/factures-deposees/{Y}/{m}/...pdf`.
- Hard delete : `DB::transaction` avec rollback du `Storage::delete()` si échec BDD.
- Logs `Log::info` portent automatiquement `association_id` + `user_id` (via `LogContext`).

### Vision back-office (hors MVP, à acter au prochain dev)

Trois flux d'arrivée coexisteront : `IncomingDocument` (email IMAP), `NoteDeFrais` (portail bénévole), `FacturePartenaireDeposee` (portail fournisseur).

**Décision** : pas de modèle commun. UI cible = écran **"Pièces à traiter"** agrégateur avec onglets par source + compteur unifié sur la sidebar. Chaque onglet renvoie au workflow métier spécifique existant.

Décision déférée au dev back-office factures partenaires : créer un écran spécifique "Factures à comptabiliser" OU livrer directement l'agrégateur. La spec `FacturePartenaireDeposee` est compatible avec les deux options.

Trace mémoire dédiée : `project_inbox_unifiee.md`.

### Hors scope MVP — confirmés

- Rejet (UI) — modèle prêt, pas d'action exposée.
- Notifications email aux transitions.
- Multi-PDF par dépôt.
- Factur-X entrant (parsing XML embarqué) / OCR.
- Détection doublons (responsabilité comptable).
- Back-office : écran comptable + flux de comptabilisation UI.
- Indicateur sidebar "À comptabiliser".

---

## 4. Acceptance Criteria

### Fonctionnel MVP

- [ ] 2 cartes ajoutées sur la home portail sous "Partenaires, bénévoles" : "Vos factures à traiter" et "Historique de vos dépenses". Visibles ssi `pour_depenses=true`.
- [ ] Dépôt valide → `FacturePartenaireDeposee.statut=soumise` + PDF stocké au bon emplacement tenant.
- [ ] Action "Supprimer" hard delete BDD + fichier ; refuse si statut ≠ `soumise` ou autre Tiers.
- [ ] Écran "À traiter" liste uniquement `statut=soumise` du Tiers connecté, tri date facture desc.
- [ ] Écran "Historique" liste toutes `Transaction` de dépense du Tiers via la Resource publique.
- [ ] Une Transaction de dépense liée à une `NoteDeFrais` (via `notes_de_frais.transaction_id`) **n'apparaît pas** dans l'historique.
- [ ] Texte muted en pied d'historique : « Vos remboursements de notes de frais sont visibles dans l'écran Vos notes de frais. »
- [ ] Affichage statut règlement : "En attente" / "Réglée".
- [ ] Lien PDF historique disponible ssi pièce jointe PDF présente.
- [ ] Validation form : PDF obligatoire, MIME `application/pdf`, ≤ 10 Mo, date ≤ today, numéro 1-50 caractères.

### Architecture

- [ ] Champs `statut=rejetee`, `motif_rejet`, `transaction_id`, `traitee_at` présents en base même si non utilisés UI.
- [ ] `comptabiliser()` testée unitairement (attache PDF, bascule statut, lie transaction, émet event).
- [ ] Resource publique : whitelist stricte (test asserte qu'aucun champ interne ne fuit côté JSON/render).

### Non-fonctionnel

- [ ] Tests d'intrusion : cross-tenant (2 cas) + cross-tiers même tenant (2 cas) sur dépôts ET historique ET routes PDF.
- [ ] Logs `Log::info` sur `submit` / `oublier` / `comptabiliser` portent `association_id` + `user_id`.
- [ ] PSR-12 via `./vendor/bin/pint`.
- [ ] Suite Pest verte, ≥ 25 tests dédiés (Portail + service + Resource).

### Sécurité

- [ ] MIME validé serveur (pas seulement extension côté front).
- [ ] Nom de fichier généré côté serveur, jamais le nom uploadé (anti path-traversal).
- [ ] Routes PDF signées, refusent autre Tiers / autre tenant (test dédié).
- [ ] Hard delete encapsulé `DB::transaction` avec rollback Storage si échec BDD.

---

## Consistency gate — ✅ PASS

- Intent univoque, deux écrans clairement délimités.
- Chaque comportement de l'Intent → ≥ 1 scénario BDD.
- Architecture contrainte au strict nécessaire MVP (champs dormants justifiés par contrat back-office).
- Vocabulaire cohérent : "dépôt" (objet portail) vs "Transaction" (objet comptable) vs "facture déposée" (concept utilisateur).
- Aucune contradiction inter-artefacts.

---

## Étapes suivantes

1. `/agentic-dev-team:plan` sur cette spec → plan TDD incrémental.
2. `/agentic-dev-team:build` → exécution Subagent-Driven (Sonnet).
3. Dev back-office factures partenaires (spec dédiée à venir) → décision sur écran spécifique vs agrégateur "Pièces à traiter".
