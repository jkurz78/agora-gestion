# Fiche tiers 360° — Slice 7c : section « Encadrement »

**Date** : 2026-05-11
**Programme** : Fiche tiers 360°
**Position dans la roadmap** : Slice 7 (Opérations), sous-slice **7c** (dernier de la trilogie 7a/7b/7c)
**Prérequis** : Slices 7a + 7b livrés (conteneur Opérations + 3 sections actuelles).
**Statut** : SPEC POUR /plan

---

## 1. Intention

Compléter l'onglet « **Opérations** » de la fiche tiers 360° avec la **section « Encadrement »** :

> Liste des opérations sur lesquelles ce tiers a été **payé pour intervenir** (animateur, formateur, intervenant kiné, etc.).

C'est le miroir de la section Participation : Participation = ce tiers a payé pour participer ; Encadrement = ce tiers a été payé pour intervenir.

Aucune migration BD. Lecture seule sur le modèle existant `Transaction` + `TransactionLigne`.

## 2. Périmètre

### 2.1 Inclus dans 7c

- **Section « Encadrement »** : tableau des opérations sur lesquelles ce tiers apparaît comme **payee** sur une transaction de type dépense, avec au moins une ligne portant `operation_id`.
- Critère d'éligibilité d'une ligne dans la section :
  - `Transaction.tiers_id = $tiers->id` (ce tiers est la contrepartie de la transaction)
  - `Transaction.type = TypeTransaction::Depense` (dépense, donc le tiers est payé)
  - `TransactionLigne.operation_id` non null (la dépense porte une opération)
  - **Tous statuts de règlement confondus** (encaissés ET en attente)
- **Granularité** : **1 ligne par opération distincte** (agrégée). Si le tiers est payé 3 fois sur la même opération (acompte + solde + complément), il n'apparaît que sur **1 ligne** avec montant agrégé.
- **Compteur de section** : `count(distinct operation_id)` = nb d'opérations distinctes encadrées par ce tiers.
- **Tri** : `min(seances.date)` DESC sur l'opération. Les opérations sans séance se placent en queue.
- **Compteur d'onglet** total = somme des 4 sections (Participation + A référé + Suit + Encadrement). Onglet visible si > 0.
- Méthode ajoutée au service `App\Services\Tiers\TiersOperationsTimelineService` : `encadrementForTiers(Tiers $tiers): EncadrementTimelineDTO`.
- 2 nouveaux DTOs dans `App\Services\Tiers\DTO\` : `EncadrementTimelineDTO`, `EncadrementLigneDTO`.
- 1 nouveau partial Blade : `encadrement-table.blade.php`.
- Composant Blade `<x-tiers.operations.section-card>` réutilisé tel quel.
- Tests Pest : ~10-12 nouveaux tests (unit service + Livewire + feature + intrusion multi-tenant).

### 2.2 Hors scope 7c

- Détail des paiements individuels (acomptes, soldes) : la section est agrégée par opération. Le détail appartient à la fiche transaction ou à la fiche opération (drill-down via le bouton "Voir l'opération").
- Filtre par statut de règlement (encaissés / en attente) : non MVP.
- Distinction par type de fonction (animateur principal / suppléant / intervenant ponctuel) : non MVP — la sémantique est portée par le tarif/libellé sur la fiche transaction, pas dans cette vue 360.
- **Pas de badge HelloAsso** : non pertinent côté intervenant payé (HelloAsso est côté recettes participants).
- Badge **Archivée** : conservé (cohérent avec autres sections — si l'opération est soft-deleted, on l'indique).
- Démontage du `TiersQuickView` (slice final post-7c) : différé.

### 2.3 Symétrie portail

Dette tracée comme pour 7a/7b : aucun écran portail aujourd'hui pour « les opérations que j'ai encadrées ». Le service `encadrementForTiers` est factorisé côté domain (DTO immuable), consommable tel quel par un futur portail intervenant.

## 3. Modèle de données existant

```
Transaction
├─ FK tiers_id           [NOT NULL]    contrepartie (payeur si Recette, payee si Depense)
├─ type                  [enum]        TypeTransaction::Recette | ::Depense
├─ statut_reglement      [enum]        EnAttente | Recu | Pointe
└─ HasMany TransactionLigne

TransactionLigne
├─ FK transaction_id     [NOT NULL]
├─ FK operation_id       [nullable]    null si dépense hors opération
├─ seance                [unsignedInt nullable]   numéro de séance (pas FK)
├─ montant               [decimal:2]
└─ ...
```

**Sémantique** :
- `Transaction.type = Recette` + `tiers_id = X` : X a payé l'asso (participant, donateur…).
- `Transaction.type = Depense` + `tiers_id = X` : X a été payé par l'asso (intervenant, fournisseur…).

Encadrement = `type = Depense` + lignes portant `operation_id`.

## 4. Architecture data

### 4.1 Service composite (méthode ajoutée)

```php
namespace App\Services\Tiers;

final class TiersOperationsTimelineService
{
    public function forTiers(Tiers $tiers): ParticipationsTimelineDTO;       // 7a
    public function aReferreForTiers(Tiers $tiers): AReferreTimelineDTO;     // 7b
    public function suitForTiers(Tiers $tiers): SuitTimelineDTO;             // 7b
    public function encadrementForTiers(Tiers $tiers): EncadrementTimelineDTO; // 7c
}
```

Si la classe dépasse ~300 lignes en cible, split à envisager (déjà 188 lignes après 7b, donc on approche).

### 4.2 DTOs

```php
final class EncadrementTimelineDTO
{
    /** @param array<int, EncadrementLigneDTO> $lignes */
    public function __construct(
        public readonly array $lignes,
        public readonly int $totalCount,   // nb opérations distinctes
    ) {}
}

final class EncadrementLigneDTO
{
    public function __construct(
        public readonly Operation $operation,
        private readonly int $nbSeances,         // distinct seance non null
        private readonly float $montantTotal,    // sum TransactionLigne.montant, tous statuts
    ) {}

    public function operationId(): int          { return (int) $this->operation->id; }
    public function operationNom(): string      { return (string) $this->operation->nom; }
    public function typeOperationNom(): string  { return (string) $this->operation->typeOperation->nom; }
    public function operationArchivee(): bool   { return $this->operation->deleted_at !== null; }
    public function dateDebut(): ?Carbon        { return $this->operation->seances->pluck('date')->min(); }
    public function dateFin(): ?Carbon          { return $this->operation->seances->pluck('date')->max(); }
    public function nbSeances(): int            { return $this->nbSeances; }
    public function montantTotal(): float       { return $this->montantTotal; }
}
```

Pattern cohérent avec les DTOs des autres sections : wrapper d'un modèle Eloquent + champs précalculés en propriétés privées + méthodes calculées.

### 4.3 Requête principale + agrégats

Stratégie en 3 étapes :

```php
// 1. Identifier les operation_id distinctes encadrées
$operationIds = TransactionLigne::query()
    ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
    ->where('transactions.tiers_id', $tiers->id)
    ->where('transactions.type', TypeTransaction::Depense->value)
    ->whereNotNull('transaction_lignes.operation_id')
    ->distinct()
    ->pluck('transaction_lignes.operation_id')
    ->all();

if (empty($operationIds)) {
    return new EncadrementTimelineDTO(lignes: [], totalCount: 0);
}

// 2. Précalculer agrégats (nb séances distinctes + sum montant) par operation_id
$aggregats = TransactionLigne::query()
    ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
    ->where('transactions.tiers_id', $tiers->id)
    ->where('transactions.type', TypeTransaction::Depense->value)
    ->whereIn('transaction_lignes.operation_id', $operationIds)
    ->groupBy('transaction_lignes.operation_id')
    ->select(
        'transaction_lignes.operation_id',
        DB::raw('COUNT(DISTINCT CASE WHEN transaction_lignes.seance IS NOT NULL THEN transaction_lignes.seance END) as nb_seances'),
        DB::raw('SUM(transaction_lignes.montant) as montant_total'),
    )
    ->get()
    ->keyBy('operation_id');

// 3. Charger les opérations avec eager loading
$operations = Operation::withTrashed()
    ->whereIn('id', $operationIds)
    ->with(['typeOperation:id,nom', 'seances:id,operation_id,date'])
    ->get();

// 4. Tri par dateDebut DESC (min seance.date), nulls en queue
$sorted = $operations->sort(function (Operation $a, Operation $b) {
    $da = $a->seances->pluck('date')->min();
    $db = $b->seances->pluck('date')->min();
    if ($da === null && $db === null) return 0;
    if ($da === null) return 1;
    if ($db === null) return -1;
    return $db <=> $da;
})->values();

// 5. Construire les DTOs
$lignes = $sorted->map(fn (Operation $op) => new EncadrementLigneDTO(
    operation: $op,
    nbSeances: (int) ($aggregats[$op->id]->nb_seances ?? 0),
    montantTotal: (float) ($aggregats[$op->id]->montant_total ?? 0.0),
))->all();

return new EncadrementTimelineDTO(lignes: $lignes, totalCount: count($lignes));
```

### 4.4 Multi-tenant — garde de sécurité

- `TransactionLigne` n'étend probablement pas `TenantModel`. L'isolation tenant est **transitive** via `transactions.tiers_id = $tiers->id` : `Tiers` étend `TenantModel`, donc un tiers d'un autre tenant ne peut pas être passé en paramètre du service (le `Tiers $tiers` injecté provient déjà du scope global).
- Pattern identique au calcul `montantPaye` du slice 7a (commit `69dfa2db`).
- Le `withTrashed()` sur `Operation` n'affecte pas le `TenantScope` (Operation étend `TenantModel`).

### 4.5 Compteur d'onglet (FicheTiers)

```php
$nbPart = $this->tiers->participants()->count();
$nbRef = Participant::where('refere_par_id', $this->tiers->id)->distinct()->count('tiers_id');
$nbSuit = Participant::where(fn ($q) => $q
    ->where('medecin_tiers_id', $this->tiers->id)
    ->orWhere('therapeute_tiers_id', $this->tiers->id)
)->distinct()->count('tiers_id');
$nbEnc = TransactionLigne::query()
    ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
    ->where('transactions.tiers_id', $this->tiers->id)
    ->where('transactions.type', TypeTransaction::Depense->value)
    ->whereNotNull('transaction_lignes.operation_id')
    ->distinct()
    ->count('transaction_lignes.operation_id');

$total = $nbPart + $nbRef + $nbSuit + $nbEnc;
if ($total > 0) {
    $onglets[] = ['key' => 'operations', 'label' => 'Opérations', 'count' => $total];
}
```

**Note** : 4 COUNT SQL légers. Les COUNT sont indexés (FK indexées par Laravel via `foreignId()->constrained()`). Acceptable au volume actuel.

## 5. Architecture UI

### 5.1 Mise à jour du composant `Operations`

```php
final class Operations extends Component
{
    public Tiers $tiers;

    public function render(): View
    {
        $service = app(TiersOperationsTimelineService::class);
        $participations = $service->forTiers($this->tiers);
        $aReferre = $service->aReferreForTiers($this->tiers);
        $suit = $service->suitForTiers($this->tiers);
        $encadrement = $service->encadrementForTiers($this->tiers);

        return view('livewire.tiers.onglets.operations', compact(
            'participations', 'aReferre', 'suit', 'encadrement'
        ));
    }
}
```

### 5.2 Vue `operations.blade.php` enrichie

Ajout d'une 4e `<x-tiers.operations.section-card>` après la section Suit :

```blade
{{-- Section Encadrement (slice 7c) --}}
@if ($encadrement->totalCount > 0)
    <x-tiers.operations.section-card id="encadrement" titre="Encadrement" :compteur="$encadrement->totalCount">
        @include('livewire.tiers.onglets.partials.encadrement-table', ['lignes' => $encadrement->lignes])
    </x-tiers.operations.section-card>
@endif
```

### 5.3 Partial `encadrement-table.blade.php`

| Opération | Type | Période | Nb séances | Montant | Actions |
|---|---|---|---|---|---|

- **En-tête** : `table-dark` + style bleu foncé (convention CLAUDE.md).
- **Opération** : `<a href="route('operations.show', operationId)" class="no-row-click">{{ operationNom }}</a>`. **Pas de badge HelloAsso**. Badge `Archivée` si `operationArchivee`.
- **Type** : `typeOperationNom` (texte secondaire).
- **Période** : `dateDebut->format('d/m/Y') → dateFin->format('d/m/Y')` ou `—`. `data-sort` ISO sur la date début.
- **Nb séances** : `nbSeances` numérique (0 si aucune séance numérotée touchée — paiement forfaitaire uniquement). `data-sort` numérique.
- **Montant** : `number_format(montantTotal, 2, ',', ' ') €`. `data-sort` numérique.
- **Actions** : bouton `<a class="btn btn-sm btn-outline-secondary no-row-click">` icon `bi-eye` → `route('operations.show', operationId)`.

**Ligne entière cliquable** : `onclick="window.location='operations.show'"` avec guard `closest('button,a,…')`. Pas de cellule pointant vers un autre tiers ici (le tiers consulté **est** l'intervenant). Clic ligne → fiche opération.

### 5.4 Composant `<x-tiers.operations.section-card>` réutilisé

Aucune modification. Le composant accepte déjà `id`, `titre`, `compteur` (props).

### 5.5 Note UX : 0 séance numérotée mais montant > 0

Si un intervenant a été payé uniquement en forfait (lignes `seance IS NULL`), la colonne **Nb séances** affichera `0`, mais la colonne **Montant** affichera la somme. Si tu veux affiner plus tard (badge "Forfait" par exemple), c'est un slice futur. En 7c, simple : 0 = 0.

## 6. Risques techniques à éclaircir au plan TDD

- **R1** — `TransactionLigne` étend-il `TenantModel` ? À vérifier en phase 1. Si oui, la query est doublement protégée ; si non, transitive via `Tiers` (comme 7a `montantPaye`).
- **R2** — Le `COUNT(DISTINCT CASE WHEN seance IS NOT NULL THEN seance END)` SQL : syntaxe MySQL-friendly à confirmer. Alternative équivalente : `COUNT(DISTINCT NULLIF(transaction_lignes.seance, NULL))` ou simplement compter en collection PHP après chargement si la complexité SQL pose souci.
- **R3** — Le `Transaction.type` est stocké en string (cast `TypeTransaction::class`). Filtre via `->where('transactions.type', TypeTransaction::Depense->value)` (= `'depense'`).
- **R4** — Pour les tests, la factory `Transaction` randomise `type` (slice 0+1) ; set explicite `type => TypeTransaction::Depense` obligatoire.
- **R5** — Modèle `Operation` doit avoir la relation `transactionLignes()` ? On n'en a pas besoin ici (on charge par `Operation::whereIn('id', ...)`), mais à vérifier si la mémoire la mentionne.

## 7. Tests Pest

### 7.1 Tests unit du service (`TiersOperationsTimelineServiceTest.php` extension)

- `encadrementForTiers` retourne DTO vide si le tiers n'a aucune dépense avec operation_id.
- `totalCount` = nb d'opérations distinctes encadrées.
- Agrégation : tiers payé 3 fois sur même opération → 1 ligne, `montantTotal` = somme des 3.
- `nbSeances` = count distinct `seance` non null (3 paiements sur séances 1, 2, 1 → nbSeances = 2 ; 1 paiement seance=null → nbSeances = 0).
- **Tous statuts de règlement** : transactions EnAttente incluses dans le calcul (différent de slice 7a).
- **Type Recette exclu** : une transaction Recette avec operation_id n'apparaît PAS dans Encadrement (sinon on confondrait avec Participation).
- Tri par `dateDebut` opération DESC.
- Isolation tiers : autre tiers payé sur la même opération ne fuit pas.
- Intrusion multi-tenant : pattern `Association::factory() + TenantContext::boot()`.

### 7.2 Tests Livewire du composant (`OperationsTest.php` extension)

- Section Encadrement visible si `totalCount > 0`, absente sinon.
- Colonnes Nb séances + Montant rendues correctement (assertSee sur valeurs formatées).
- Badge Archivée si opération soft-deleted.
- **Pas** de badge HelloAsso (vérifier `assertDontSee('HelloAsso')` ou absence du markup correspondant).
- Lien Opération → `route('operations.show', id)`.

### 7.3 Tests feature `FicheTiers`

- Compteur d'onglet inclut les opérations encadrées (somme des 4).
- Tiers strictement intervenant (encadrement seul) → onglet visible avec count correct.

## 8. Procédure de recette manuelle (localhost)

À dérouler avant push :

1. Sur un tiers payé en tant qu'intervenant (Dépense type) sur au moins 2 opérations distinctes → onglet « Opérations (N) » avec compteur correct.
2. Section Encadrement visible avec 1 ligne par opération distincte.
3. Vérifier l'agrégation : si le tiers a 3 paiements sur la même opération, 1 ligne avec montant total = somme.
4. Vérifier `Nb séances` : conforme aux séances distinctes touchées par les paiements (ignorant les lignes hors séance).
5. Tri : opération démarrée le plus récemment en haut.
6. Cliquer ligne / cellule Opération / bouton Voir → fiche opération.
7. Badge Archivée si opération soft-deleted.
8. **Pas** de badge HelloAsso.
9. Tiers strictement participant (jamais intervenant) → section Encadrement absente.

## 9. Définition de "fait"

- ✅ Aucune migration BD.
- ✅ Code : 1 nouvelle méthode service, 2 nouveaux DTOs, 1 nouveau partial Blade, composant Livewire enrichi (4 props), `FicheTiers` compteur somme étendu à 4 sections.
- ✅ Tests : ~10-12 nouveaux tests Pest verts, suite globale 0 failed.
- ✅ Pint clean.
- ✅ Recette manuelle locale OK.
- ✅ Spec relue par utilisateur, commit du spec.

## 10. Vocabulaire arrêté

- **Encadrement** = action d'intervenir/animer une opération en étant rémunéré. FK virtuelle = `transactions.tiers_id` + `transactions.type = Depense` + `transaction_lignes.operation_id`.
- **Intervenant** = synonyme métier de « tiers en encadrement » (animateur, formateur, kiné…).
- **Montant** (colonne) = somme `transaction_lignes.montant` pour ce tiers sur cette opération, **tous statuts confondus** (encaissés + en attente). Sémantique : montant facturé / engagé.
- **Nb séances** (colonne) = `COUNT(DISTINCT transaction_lignes.seance)` excluant les NULL — uniquement les séances numérotées touchées par les paiements.
- **Compteur de section** = `count(distinct operation_id)` = nb d'opérations distinctes encadrées.
- **Pas de HelloAsso** : la sémantique HelloAsso est côté participants/recettes, pas côté intervenants/dépenses.
