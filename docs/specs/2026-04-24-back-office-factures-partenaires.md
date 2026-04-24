# Back-office — Traitement des factures déposées par les tiers sur le portail

**Date** : 2026-04-24
**Statut** : spec PASS, prête pour `/plan`
**Programme** : Portail Tiers — bloc "Factures partenaires" (suite du MVP portail livré 2026-04-24)
**Branche** : `feat/portail-factures-partenaires` (Option A — un seul PR final portail + back-office)
**Spec portail associée** : [docs/specs/2026-04-23-portail-factures-partenaires.md](2026-04-23-portail-factures-partenaires.md)

---

## 1. Intent Description

Permettre au comptable de **traiter** les factures déposées par les partenaires (statut `Soumise`) : les lister, les consulter, les **comptabiliser** (créer la Transaction Dépense avec PDF auto-attaché), les **rejeter** (motif obligatoire, PDF conservé, dépôt visible côté portail pour que le partenaire puisse redéposer une version corrigée).

Le service `FacturePartenaireService::comptabiliser()` existe déjà (testé unitairement au MVP portail). Il reste l'UI back-office et l'action de rejet.

**Architecture UI — option (a)** privilégiée (cf. [project_inbox_unifiee.md](../../memory/project_inbox_unifiee.md)) : écran spécifique "Factures à comptabiliser". L'agrégateur multi-sources ("Pièces à traiter") viendra dans un chantier séparé quand les 3 sources seront stables en prod.

**Réutilisation maximale** : le workflow de comptabilisation passe par le `TransactionForm` Dépense **existant** (composant Livewire modale offcanvas) et le `InvoiceOcrService` **existant** (API Anthropic Claude). Pattern rigoureusement parallèle au flux IncomingDocument IMAP. La différence : côté factures déposées, on connaît déjà tiers, date, numéro — ces valeurs sont pré-remplies avant OCR et passées en **contexte encadrant** à l'IA, qui complète montant + lignes et génère des warnings en cas de discordance.

L'IA est **optionnelle** : si l'association n'a pas de `anthropic_api_key`, les 3 champs connus (tiers, date, référence) + PDF preview suffisent et le comptable saisit le reste manuellement. **Le PDF du dépôt est attaché à la Transaction dans tous les cas** via `comptabiliser()`.

**Impact portail** (modification du MVP livré) : la boîte de dépôt affiche désormais les dépôts `Rejetee` (en plus des `Soumise`) pour notifier le partenaire du rejet et afficher le motif. Aucun nouveau écran portail.

---

## 2. User-Facing Behavior (BDD)

```gherkin
Feature: Back-office — Traitement des factures déposées par les partenaires

  Background:
    Étant donné un comptable authentifié sur "Asso X"
    Et trois dépôts sur "Asso X" :
      | numero      | tiers     | date       | statut    |
      | F-2026-0042 | Acme SARL | 2026-04-15 | Soumise   |
      | F-2026-0010 | Beta SAS  | 2026-04-12 | Soumise   |
      | F-2026-0005 | Acme SARL | 2026-04-01 | Traitee   |

  # === Liste back-office ===

  Scenario: Accès depuis la sidebar
    Quand le comptable clique "Factures à comptabiliser" dans le groupe Comptabilité
    Alors il arrive sur la liste des dépôts statut "Soumise" du tenant
    Et les dépôts "Traitee" et "Rejetee" ne figurent pas par défaut

  Scenario: Contenu et tri de la liste
    Alors chaque ligne affiche :
      | Colonne       | Source                                |
      | Date facture  | FacturePartenaireDeposee.date_facture |
      | Tiers         | tiers.nom                             |
      | N° facture    | numero_facture                        |
      | Déposée le    | created_at                            |
      | Taille PDF    | pdf_taille (Ko)                       |
      | Actions       | Voir PDF / Comptabiliser / Rejeter    |
    Et le tri par défaut est "Date facture desc"

  Scenario: Onglets
    Alors la page expose les onglets "À traiter", "Traitées", "Rejetées", "Toutes"
    Et l'onglet "À traiter" est actif par défaut (statut=Soumise)

  Scenario: Ouverture du PDF depuis la liste
    Quand le comptable clique l'icône PDF
    Alors le PDF s'ouvre dans un nouvel onglet via une route signée back-office

  # === Comptabilisation — pattern TransactionForm existant ===

  Scenario: Clic "Comptabiliser" depuis la liste
    Étant donné le dépôt "F-2026-0042" Soumise, Acme SARL, 2026-04-15
    Quand le comptable clique "Comptabiliser"
    Alors le composant TransactionForm s'ouvre (modale offcanvas standard)
    Et tiers_id est pré-rempli à Acme SARL
    Et date à 2026-04-15
    Et reference à F-2026-0042
    Et une preview du PDF du dépôt s'affiche via route signée back-office
    Et factureDeposeeId est conservé en mémoire du composant
    Et le champ upload PJ manuel n'est pas exposé

  Scenario: Pré-remplissage IA si configurée
    Étant donné InvoiceOcrService::isConfigured() retourne true
    Quand TransactionForm s'ouvre depuis un dépôt
    Alors analyzeFromPath() est appelée avec le PDF et
      context = {tiers_attendu, reference_attendue, date_attendue}
    Et montant total, lignes, sous-catégories sont pré-remplis depuis l'extraction IA
    Et les éventuelles discordances apparaissent en warnings (bandeau jaune)

  Scenario: IA non configurée
    Étant donné l'association n'a pas de anthropic_api_key
    Quand TransactionForm s'ouvre depuis un dépôt
    Alors seuls tiers/date/reference/PDF preview sont pré-remplis
    Et le comptable saisit montant + lignes manuellement
    Et aucun message d'erreur IA n'apparaît

  Scenario: IA en erreur (timeout, 5xx, JSON invalide)
    Quand l'appel OCR échoue
    Alors un bandeau rouge affiche le message et un bouton "Réessayer"
    Et le form reste utilisable avec les 3 champs déjà pré-remplis

  Scenario: Warning IA — partenaire a menti sur le numéro
    Étant donné le dépôt déclare "F-2026-0042" mais le PDF contient "F-2026-0099"
    Quand l'IA analyse avec context
    Alors warnings contient une mention de la discordance de numéro
    Et le comptable décide : corriger reference, ou fermer le form et rejeter le dépôt

  Scenario: Validation finale → Transaction + comptabiliser()
    Étant donné le form est complet et valide
    Quand le comptable clique "Enregistrer"
    Alors TransactionForm::save() crée la Transaction Dépense (flux standard)
    Et finalizeFactureDeposeeCleanup(tx) est appelée (symétrique de finalizeIncomingDocumentCleanup)
    Et FacturePartenaireService::comptabiliser($depot, $tx) est appelée
    Et le PDF est déplacé depuis factures-deposees/... vers transactions/{txid}/...
    Et piece_jointe_path / piece_jointe_nom / piece_jointe_mime sont renseignés sur la Transaction
    Et le dépôt bascule Traitee + transaction_id + traitee_at
    Et FactureDeposeeComptabilisee est émis
    Et le form se ferme, la liste est rafraîchie, flash succès

  Scenario: PDF toujours attaché, quelle que soit la branche IA
    Étant donné TransactionForm ouvert depuis un dépôt (IA-on OU IA-off OU IA-erreur)
    Quand le comptable valide
    Alors la Transaction créée porte le PDF du dépôt attaché
    Et le dépôt est en statut Traitee

  Scenario: Exercice clôturé
    Étant donné date_piece tombe sur un exercice clôturé
    Quand le comptable tente de valider
    Alors un message d'erreur "Exercice clôturé" s'affiche
    Et aucune Transaction n'est créée
    Et le dépôt reste Soumise
    Et le PDF n'est pas déplacé

  Scenario: Transaction avec PJ préexistante (collision garde)
    Étant donné une Transaction cible a déjà une pièce jointe
    Quand comptabiliser() est appelée
    Alors une DomainException est levée
    Et le dépôt reste Soumise, le PDF source n'est pas déplacé

  # === Rejet ===

  Scenario: Rejet d'un dépôt illisible ou non dû
    Étant donné le dépôt "F-2026-0042" (Soumise)
    Quand le comptable clique "Rejeter" et saisit motif "PDF illisible, merci de redéposer"
    Et confirme via la modale Bootstrap
    Alors le dépôt bascule en statut "Rejetee" avec motif_rejet renseigné
    Et le PDF est conservé (pas de suppression)
    Et le dépôt disparaît de l'onglet "À traiter" back-office et apparaît dans "Rejetées"
    Et un event FactureDeposeeRejetee est émis (notifications hors MVP)

  Scenario: Motif obligatoire au rejet
    Quand le comptable valide un rejet avec motif vide
    Alors message d'erreur "Le motif est obligatoire"

  Scenario: Rejet refusé si statut ≠ Soumise
    Étant donné un dépôt en statut Traitee ou Rejetee
    Quand rejeter() est appelée
    Alors une DomainException est levée
    Et aucun changement d'état n'a lieu

  # === Impact portail — modification MVP livré ===

  Scenario: Dépôt rejeté visible dans la boîte de dépôt portail
    Étant donné un dépôt "F-2026-0042" rejeté avec motif "PDF illisible, merci de redéposer"
    Quand le partenaire affiche "Vos factures à traiter" côté portail
    Alors la ligne apparaît avec badge "Rejetée" et le motif est visible
    Et le bouton "Supprimer" reste disponible (hard delete)
    Et le bouton "Déposer une facture" permet d'en soumettre une nouvelle

  Scenario: Tri côté portail
    Étant donné deux dépôts : un "Rejetee" et un "Soumise"
    Quand le partenaire affiche "Vos factures à traiter"
    Alors les dépôts "Rejetee" apparaissent en premier (pour attirer l'œil)
    Et les "Soumise" ensuite, tri secondaire created_at desc

  Scenario: Dépôt traité retiré de la boîte + visible dans l'historique
    Étant donné un dépôt comptabilisé (Traitee)
    Quand le partenaire affiche "Vos factures à traiter"
    Alors il n'y figure plus
    Et la Transaction associée apparaît dans "Historique de vos dépenses"
    Avec statut règlement "En attente" ou "Réglée" selon la Transaction

  # === Sécurité ===

  Scenario: Autorisation — comptables uniquement
    Étant donné un utilisateur rôle "Utilisateur" (non-comptable)
    Quand il tente d'accéder à /factures-partenaires/a-comptabiliser
    Alors 403

  Scenario: Isolation tenant liste + actions
    Étant donné un dépôt sur "Asso Y"
    Quand un comptable de "Asso X" tente d'y accéder (liste, PDF, comptabiliser, rejeter)
    Alors 404 sur chaque point (TenantScope fail-closed + scope explicite)
```

---

## 3. Architecture Specification

### Routes back-office

| Verbe | Route | Composant / action |
|---|---|---|
| GET | `/factures-partenaires/a-comptabiliser` | `BackOffice\FacturePartenaire\Index` |
| GET | `/factures-partenaires/a-comptabiliser/{depot}/pdf` | route signée back-office |

Middleware : `auth` + policy `treat` sur `FacturePartenaireDeposee` (pattern NDF, [app/Livewire/BackOffice/NoteDeFrais/Index.php:21](../../app/Livewire/BackOffice/NoteDeFrais/Index.php#L21)).

**Pas d'écran Show dédié** : la comptabilisation passe par le `TransactionForm` existant en modale offcanvas.

### Composant Livewire

`App\Livewire\BackOffice\FacturePartenaire\Index` — liste avec 4 onglets (À traiter / Traitées / Rejetées / Toutes), actions par ligne (Voir PDF, Comptabiliser, Rejeter).

Dispatcher simple vers le form standard :

```php
public function comptabiliser(int $depotId): void
{
    $this->authorize('treat', FacturePartenaireDeposee::class);
    $this->dispatch('open-transaction-form-from-depot-facture', depotId: $depotId);
}

public function ouvrirRejet(int $depotId): void { /* modale motif */ }
public function confirmerRejet(): void         { /* wire:confirm Bootstrap */ }
```

### Modifications à `App\Livewire\TransactionForm`

Pattern rigoureusement parallèle au flux IncomingDocument (déjà en place).

- Nouvelle propriété `public ?int $factureDeposeeId = null`.
- Nouveau handler `#[On('open-transaction-form-from-depot-facture')] openFormFromDepotFacture(int $depotId)` :
  - Charge `FacturePartenaireDeposee` (scope tenant auto via `TenantModel`), vérifie statut `Soumise`, 404 sinon.
  - Appelle `showNewForm('depense')`, set `ocrMode = true`, `ocrWaitingForFile = false`.
  - Pré-remplit `tiers_id`, `date`, `reference` depuis le dépôt.
  - Stocke `factureDeposeeId`, expose la preview PDF via route signée back-office.
  - Si `InvoiceOcrService::isConfigured()` : appelle `runOcrAnalysis(fn($svc) => $svc->analyzeFromPath($diskPath, 'application/pdf', $context))` avec :
    ```php
    $context = [
        'tiers_attendu'       => $depot->tiers->displayName(),
        'reference_attendue'  => $depot->numero_facture,
        'date_attendue'       => $depot->date_facture->format('Y-m-d'),
    ];
    ```
- Hook `save()` : après création Transaction, **si `factureDeposeeId !== null`** → `finalizeFactureDeposeeCleanup($tx)` qui appelle `FacturePartenaireService::comptabiliser($depot, $tx)` puis reset `factureDeposeeId`.
- `retryOcr()` : brancher le cas `factureDeposeeId !== null` (symétrique d'`incomingDocumentId`).
- Reset : inclure `factureDeposeeId` dans les listes de resets existantes ([TransactionForm.php:129-130, 397-398](../../app/Livewire/TransactionForm.php#L129-L130)).
- **Upload manuel PJ masqué** en mode dépôt (le PDF vient de `comptabiliser()`, évite toute collision avec le guard "piece_jointe préexistante").

### Extension `InvoiceOcrService`

Le `$context` accepte déjà `tiers_attendu`, `operation_attendue`, `seance_attendue` ([app/Services/InvoiceOcrService.php:33-35](../../app/Services/InvoiceOcrService.php#L33-L35)). Ajouter :
- `reference_attendue` → warning si extraction diverge du numéro déclaré
- `date_attendue` → warning si extraction diverge de la date déclarée

Delta : mise à jour de `buildPrompt()` (bloc "CONTEXTE ENCADRANT" + exemples de warnings). Tests unitaires sur la génération du prompt.

### Extensions `App\Services\Portail\FacturePartenaireService`

Service conservé tel quel (pas de découpe — pragmatique, scope cohérent).

- **Nouveau** : `rejeter(FacturePartenaireDeposee $depot, string $motif): void`
  - Guard : `statut === Soumise`, sinon `DomainException`.
  - `statut = rejetee`, `motif_rejet = $motif`.
  - PDF **conservé** (piste d'audit + redépôt potentiel).
  - Émet `App\Events\Portail\FactureDeposeeRejetee` (nouveau, symétrique de `FactureDeposeeComptabilisee`).
  - `Log::info('facture_partenaire.rejetee', [...])`.
- `comptabiliser()` : **inchangé** (déjà testé, utilisé tel quel).
- `oublier()` : **guard élargi** — statut passe de `=== Soumise` à `in [Soumise, Rejetee]` pour permettre au partenaire d'oublier un dépôt rejeté.

### Modifications côté portail (MVP livré)

**`App\Livewire\Portail\FacturePartenaire\AtraiterIndex`** :
- Scope liste : `whereIn('statut', [Soumise, Rejetee])` (était `Soumise` uniquement).
- Tri : `Rejetee` d'abord (priorité visuelle), puis `Soumise` ; tri secondaire `created_at desc`.
- Colonne "Statut" ajoutée :
  - `Soumise` → badge neutre "En attente de traitement"
  - `Rejetee` → badge rouge "Rejetée" + affichage du `motif_rejet`
- Bouton "Supprimer" reste disponible pour les deux statuts.

**Tests portail à ajuster** :
- Le scénario spec portail v1 "Pas d'action sur un dépôt déjà traité" reste vrai pour `Traitee` uniquement. Un nouveau scénario couvre l'affichage `Rejetee`.

### Policy

`App\Policies\FacturePartenaireDeposeePolicy` avec ability `treat` (comptable + super-admin). Pattern NDF.

### Sidebar

Entrée "Factures à comptabiliser" dans le groupe **Comptabilité** (cohérent avec "Notes de frais"). Pas de badge compteur en MVP (confirmé hors scope).

### Événements

- `App\Events\Portail\FactureDeposeeComptabilisee` : **existant**.
- `App\Events\Portail\FactureDeposeeRejetee` : **nouveau**. Payload minimal (`$depot`). Utile pour notifications futures.

### Multi-tenant

- `FacturePartenaireDeposee` étend `TenantModel` (scope fail-closed déjà en place).
- Routes signées PDF back-office : scope tenant vérifié par `TenantScope` + contrôle explicite dans le controller signé.
- Policy check dans l'Index back-office : `mount()` appelle `$this->authorize('treat', ...)`.
- Logs `Log::info` portent automatiquement `association_id` + `user_id` (via `LogContext`).

### Hors scope MVP — confirmés

- **Agrégateur "Pièces à traiter"** (reporté, cf. [project_inbox_unifiee.md](../../memory/project_inbox_unifiee.md)).
- Compteur badge sidebar ("X factures à comptabiliser").
- **Notifications email** aux transitions (`Soumise → Traitee`, `Soumise → Rejetee`).
- Détection doublons (responsabilité du comptable).
- Re-upload / remplacement PDF par le comptable dans le form.
- Workflow Factur-X (parsing XML embarqué dans le PDF).
- Re-soumission d'un dépôt rejeté (le partenaire crée un nouveau dépôt via le bouton Déposer).

---

## 4. Acceptance Criteria

### Fonctionnel — back-office

- [ ] Route `/factures-partenaires/a-comptabiliser` : accès comptable (403 sinon), 404 cross-tenant.
- [ ] Liste 4 onglets (À traiter / Traitées / Rejetées / Toutes), tri date facture desc, colonnes conformes spec.
- [ ] Icône PDF ouvre via route signée back-office (isolation tenant testée).
- [ ] Bouton "Comptabiliser" dispatche `open-transaction-form-from-depot-facture`.
- [ ] `TransactionForm::openFormFromDepotFacture()` : pré-remplit tiers/date/reference + preview PDF.
- [ ] Upload manuel PJ **non exposé** en mode dépôt (test render).
- [ ] IA configurée : `analyzeFromPath()` appelée avec `context` (tiers_attendu + reference_attendue + date_attendue), résultats appliqués via `applyOcrResult()`.
- [ ] IA non configurée : pas d'appel, form utilisable avec 3 champs + preview.
- [ ] IA en erreur : bandeau rouge + bouton Réessayer, form utilisable.
- [ ] Warnings IA affichés en bandeau jaune, non bloquants.
- [ ] `retryOcr()` branché pour le mode dépôt.
- [ ] `save()` en mode dépôt : Transaction créée + `comptabiliser()` appelée + PDF déplacé + dépôt Traitee + event émis.
- [ ] **PDF toujours attaché à la Transaction, quelle que soit la branche IA** (scénario dédié IA-off).
- [ ] Exercice clôturé bloque la comptabilisation (`ExerciceCloturedException`), aucun changement d'état ni de disque.
- [ ] Garde `piece_jointe préexistante` levée : `comptabiliser()` lève `DomainException`, dépôt reste Soumise.
- [ ] Bouton "Rejeter" : modale Bootstrap motif obligatoire, `statut=Rejetee`, `motif_rejet` renseigné, PDF conservé.
- [ ] `rejeter()` refuse si statut ≠ Soumise (test).
- [ ] Sidebar Comptabilité : entrée "Factures à comptabiliser".

### Fonctionnel — impact portail

- [ ] `AtraiterIndex` scope élargi à `statut IN (Soumise, Rejetee)`.
- [ ] Tri : Rejetee en premier, puis Soumise, secondaire `created_at desc`.
- [ ] Colonne "Statut" + motif visible pour Rejetee.
- [ ] Bouton "Supprimer" disponible sur Rejetee (hard delete + fichier).
- [ ] `oublier()` : guard élargi à `in [Soumise, Rejetee]`.
- [ ] Scénario portail "dépôt traité disparaît de la boîte" reste vert (Traitee absent du scope).

### Architecture

- [ ] `rejeter()` + event `FactureDeposeeRejetee` testés unitairement.
- [ ] `finalizeFactureDeposeeCleanup()` testé : Transaction créée + `comptabiliser()` appelée + PDF déplacé + reset propre du state.
- [ ] `InvoiceOcrService` : support `reference_attendue` + `date_attendue` dans `$context`, warnings correspondants générés (tests unitaires sur le prompt).
- [ ] Policy `FacturePartenaireDeposeePolicy` enregistrée et testée.
- [ ] `DB::transaction` englobant autour de `save()` + `comptabiliser()` (rollback si l'un échoue).

### Non-fonctionnel

- [ ] Tests d'intrusion : cross-tenant sur liste + PDF back-office + comptabiliser + rejeter (4 cas minimum).
- [ ] Logs `Log::info` sur `rejeter` et `finalizeFactureDeposeeCleanup` portent `association_id` + `user_id`.
- [ ] PSR-12 via `./vendor/bin/pint`.
- [ ] Suite Pest verte, ≥ 20 tests dédiés back-office + portail (modifs AtraiterIndex).
- [ ] Doc utilisateur `docs/portail-tiers.md` section "Factures partenaires" mise à jour (affichage Rejetee côté portail).

### Sécurité

- [ ] Route signée PDF back-office refuse autre tenant (test dédié).
- [ ] Policy `treat` refuse non-comptables (403).
- [ ] Scope explicite `FacturePartenaireDeposee::where('statut', 'soumise')` dans le dispatcher — ne jamais comptabiliser un dépôt déjà traité.

---

## Consistency gate — ✅ PASS

- Intent univoque : back-office spécifique + réutilisation maximale du stack IncomingDocument + IA optionnelle + PDF toujours attaché + rejet visible portail.
- Chaque comportement de l'Intent → ≥ 1 scénario BDD (y compris branches IA-on/off/erreur et impact portail).
- Architecture contrainte au strict nécessaire : pas d'écran Show dédié, pas de service nouveau, delta ciblé sur `TransactionForm` et `InvoiceOcrService`.
- Vocabulaire cohérent avec la spec portail v1 : "dépôt" (objet `FacturePartenaireDeposee`) vs "Transaction" (objet comptable) vs "facture déposée" (concept utilisateur).
- Aucune contradiction avec la spec portail v1 ; les modifications portail (scope `AtraiterIndex`, guard `oublier()`) sont explicites et testées.

---

## Étapes suivantes

1. `/agentic-dev-team:plan` sur cette spec → plan TDD incrémental.
2. `/agentic-dev-team:build` → exécution Subagent-Driven (Sonnet).
3. Post-merge : décision agrégateur "Pièces à traiter" activée par friction utilisateur ressentie, spec dédiée à ce moment-là.
