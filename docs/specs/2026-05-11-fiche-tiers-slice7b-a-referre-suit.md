# Fiche tiers 360° — Slice 7b : sections « A référé » + « Suit »

**Date** : 2026-05-11
**Programme** : Fiche tiers 360°
**Position dans la roadmap** : Slice 7 (Opérations), sous-slice **7b**
**Prérequis** : Slice 7a livré (conteneur Opérations + section Participation). Slice 7c (Encadrement) à venir séparément.
**Statut** : SPEC POUR /plan

---

## 1. Intention

Compléter l'onglet « **Opérations** » de la fiche tiers 360° avec **2 nouvelles sections** dans le conteneur multi-sections déjà posé en 7a :

- **« A référé »** — les tiers que ce tiers a recommandés/adressés (rôle de référeur, à cheval CRM ∩ médical).
- **« Suit »** — les tiers que ce tiers suit en qualité de Médecin ou de Thérapeute (notion extensible).

Aucun changement de modèle BD : les 3 FKs existent déjà sur `participants`. C'est uniquement de la lecture en perspective inverse.

## 2. Périmètre

### 2.1 Inclus dans 7b

- **Section « A référé »** : tableau des participants pour lesquels `participants.refere_par_id = $tiers->id`.
- **Section « Suit »** : tableau des participants pour lesquels `participants.medecin_tiers_id = $tiers->id` OU `participants.therapeute_tiers_id = $tiers->id`, avec colonne **Qualité** = `Médecin` / `Thérapeute`.
- **Compteur par section** : nombre de **tiers distincts** (`count(distinct tiers_id)`), pas de lignes. *"Marie a été référée sur 3 opérations différentes, mais reste 1 personne dans le compteur."*
- **Lignes affichées** : **Option A — 1 ligne par lien** (tiers × opération × qualité). Si une personne apparaît sur N opérations, elle a N lignes consécutives (tri alphabétique par NOM). Si une personne a 2 qualités (Médecin + Thérapeute) sur la même opération, 2 lignes (1 par FK).
- **Tri** : section A référé → `tiers.nom ASC, operation.dateDebut DESC` ; section Suit → idem (`tiers.nom ASC, operation.dateDebut DESC`).
- **Onglet** : visible si la **somme des 3 compteurs** (Participation + A référé + Suit) > 0. Compteur d'onglet = somme.
- Méthodes ajoutées au service existant `App\Services\Tiers\TiersOperationsTimelineService` : `aReferreForTiers(Tiers $tiers): AReferreTimelineDTO` et `suitForTiers(Tiers $tiers): SuitTimelineDTO`.
- 4 nouveaux DTOs dans `App\Services\Tiers\DTO\` : `AReferreTimelineDTO`, `AReferreLigneDTO`, `SuitTimelineDTO`, `SuitLigneDTO`.
- 2 nouveaux partials Blade : `a-referre-table.blade.php`, `suit-table.blade.php`. Le composant Livewire `Operations` les rend dans le même conteneur, derrière le partial Participation déjà en place.
- Composant Blade `<x-tiers.operations.section-card>` réutilisé tel quel.
- Tests Pest : ~25 nouveaux tests (unit service + Livewire composant + intrusion multi-tenant).

### 2.2 Hors scope 7b

- **Slice 7c** — Section « Encadrement » : tiers payés sur transactions portant `operation_id`. Pas dans 7b.
- Métriques de paiement / statut / séances dans les sections 7b : non pertinentes car ce n'est pas le tiers consulté qui paie/suit ces séances. La perspective est **relationnelle**, pas **transactionnelle**.
- Section Documents (slice 8) : accroche bilatérale différée.
- Action « Voir l'attestation du tiers référé » : différée slice 8.
- Filtre par rôle (Médecin / Thérapeute) sur la section Suit : non MVP — la colonne Qualité suffit à différencier visuellement.
- Pagination : bornée par construction. À revoir si SVS dépasse ~50-100 lignes par section.

### 2.3 Symétrie portail

Comme pour 7a, dette tracée : pas d'écran portail aujourd'hui pour « les personnes que je suis » ou « les personnes que j'ai référées ». Les services et DTOs créés sont côté domain, consommables tel quels par un futur portail.

## 3. Modèle de données existant (rappel)

```
Participant
├─ FK tiers_id           [NOT NULL]   ← périmètre 7a (déjà fait)
├─ FK refere_par_id      [nullable]   ← périmètre 7b section "A référé"
├─ FK medecin_tiers_id   [nullable]   ← périmètre 7b section "Suit" (qualité=Médecin)
├─ FK therapeute_tiers_id [nullable]  ← périmètre 7b section "Suit" (qualité=Thérapeute)
├─ FK operation_id
├─ date_inscription
└─ unique(tiers_id, operation_id)
```

Aucune migration. Aucune modification de modèle. Lecture seule.

## 4. Architecture data

### 4.1 Service composite (méthodes ajoutées)

```php
namespace App\Services\Tiers;

final class TiersOperationsTimelineService
{
    public function forTiers(Tiers $tiers): ParticipationsTimelineDTO;          // existant slice 7a

    public function aReferreForTiers(Tiers $tiers): AReferreTimelineDTO;       // nouveau
    public function suitForTiers(Tiers $tiers): SuitTimelineDTO;               // nouveau
}
```

**Si la classe dépasse ~300 lignes**, on splittera en sous-services. Sinon on reste composite (pattern cohérent avec `TiersDonsTimelineService` qui n'a qu'une méthode mais qui pourrait grossir).

### 4.2 DTOs

```php
final class AReferreTimelineDTO
{
    /** @param array<int, AReferreLigneDTO> $lignes */
    public function __construct(
        public readonly array $lignes,
        public readonly int $totalCount,   // nb tiers DISTINCTS
    ) {}
}

final class AReferreLigneDTO
{
    public function __construct(
        public readonly Participant $participant,
    ) {}

    public function tiersReferreId(): int            { return (int) $this->participant->tiers_id; }
    public function tiersReferreNomComplet(): string { /* "prenom NOM" */ }
    public function operationId(): int               { return (int) $this->participant->operation_id; }
    public function operationNom(): string           { return $this->participant->operation->nom; }
    public function typeOperationNom(): string       { return $this->participant->operation->typeOperation->nom; }
    public function estHelloasso(): bool             { return (bool) $this->participant->est_helloasso; }
    public function operationArchivee(): bool        { return $this->participant->operation->deleted_at !== null; }
    public function dateDebut(): ?Carbon             { return $this->participant->operation->seances->pluck('date')->min(); }
    public function dateFin(): ?Carbon               { return $this->participant->operation->seances->pluck('date')->max(); }
    public function dateInscription(): Carbon        { return $this->participant->date_inscription; }
}
```

```php
final class SuitTimelineDTO
{
    /** @param array<int, SuitLigneDTO> $lignes */
    public function __construct(
        public readonly array $lignes,
        public readonly int $totalCount,   // nb tiers DISTINCTS
    ) {}
}

final class SuitLigneDTO
{
    public function __construct(
        public readonly Participant $participant,
        public readonly string $qualite,   // 'medecin' | 'therapeute' — injecté par le service
    ) {}

    public function tiersSuiviId(): int             { return (int) $this->participant->tiers_id; }
    public function tiersSuiviNomComplet(): string  { /* "prenom NOM" */ }
    public function qualite(): string               { return $this->qualite; }           // 'medecin'|'therapeute'
    public function qualiteLabel(): string          { return $this->qualite === 'medecin' ? 'Médecin' : 'Thérapeute'; }
    // + mêmes méthodes que AReferreLigneDTO pour operation, dates, archivée, helloasso
}
```

### 4.3 Requêtes principales

**Section "A référé"** :

```php
Participant::query()
    ->where('refere_par_id', $tiers->id)
    ->with([
        'tiers:id,nom,prenom',
        'operation' => fn ($q) => $q->withTrashed()->select('id', 'nom', 'type_operation_id', 'deleted_at'),
        'operation.typeOperation:id,nom',
        'operation.seances:id,operation_id,date',
    ])
    ->join('tiers as t', 't.id', '=', 'participants.tiers_id')
    ->orderBy('t.nom')
    ->orderBy('t.prenom')
    // tri secondaire par date opération desc se fait après-coup en collection (min séance par opération)
    ->select('participants.*')
    ->get();
```

**Section "Suit"** — 2 unions (1 par FK), résultats fusionnés en mémoire avec attribut `qualite` :

```php
$medecins = Participant::query()
    ->where('medecin_tiers_id', $tiers->id)
    ->with([...même eager loading...])
    ->get()
    ->map(fn ($p) => ['p' => $p, 'qualite' => 'medecin']);

$therapeutes = Participant::query()
    ->where('therapeute_tiers_id', $tiers->id)
    ->with([...même eager loading...])
    ->get()
    ->map(fn ($p) => ['p' => $p, 'qualite' => 'therapeute']);

$all = $medecins->concat($therapeutes);

// Tri en collection : tiers.nom asc, puis date_opération desc
$sorted = $all->sortBy([
    fn ($a, $b) => strcmp($a['p']->tiers->nom, $b['p']->tiers->nom),
    fn ($a, $b) => $b['p']->operation->seances->pluck('date')->min() <=> $a['p']->operation->seances->pluck('date')->min(),
]);

$lignes = $sorted->map(fn ($e) => new SuitLigneDTO(participant: $e['p'], qualite: $e['qualite']))->all();
```

**totalCount** :

```php
// Section A référé
$totalCount = Participant::where('refere_par_id', $tiers->id)
    ->distinct()
    ->count('tiers_id');

// Section Suit (distinct tiers, fusion des 2 FKs)
$totalCount = Participant::where(fn ($q) => $q
    ->where('medecin_tiers_id', $tiers->id)
    ->orWhere('therapeute_tiers_id', $tiers->id)
)->distinct()->count('tiers_id');
```

**Note perf** : le DTO `SuitTimelineDTO->totalCount` ≠ `count($lignes)` (dissonance assumée). Documenter clairement dans le code et la vue.

### 4.4 Tri secondaire (date opération desc)

Le tri SQL primaire est `tiers.nom ASC`. Le tri secondaire `date_opération DESC` se calcule **en collection** après chargement, via `usort` ou `Collection::sortBy(...)`. Critère : `min(seances.date)` de l'opération.

Si l'opération n'a aucune séance créée, `dateDebut = null`. Ces lignes sont placées **après** les opérations datées (tri naturel des `null` en queue).

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

        return view('livewire.tiers.onglets.operations', compact('participations', 'aReferre', 'suit'));
    }
}
```

### 5.2 Vue `operations.blade.php` enrichie

```blade
<div class="operations-container">
    {{-- Section Participation (existante slice 7a) --}}
    @if ($participations->totalCount > 0)
        <x-tiers.operations.section-card id="participation" titre="Participation" :compteur="$participations->totalCount">
            @include('livewire.tiers.onglets.partials.participations-table', ['lignes' => $participations->lignes])
        </x-tiers.operations.section-card>
    @endif

    {{-- Section A référé (slice 7b) --}}
    @if ($aReferre->totalCount > 0)
        <x-tiers.operations.section-card id="a-referre" titre="A référé" :compteur="$aReferre->totalCount">
            @include('livewire.tiers.onglets.partials.a-referre-table', ['lignes' => $aReferre->lignes])
        </x-tiers.operations.section-card>
    @endif

    {{-- Section Suit (slice 7b) --}}
    @if ($suit->totalCount > 0)
        <x-tiers.operations.section-card id="suit" titre="Suit" :compteur="$suit->totalCount">
            @include('livewire.tiers.onglets.partials.suit-table', ['lignes' => $suit->lignes])
        </x-tiers.operations.section-card>
    @endif

    {{-- Slice 7c : Encadrement --}}
</div>
```

### 5.3 Partial `a-referre-table.blade.php`

| Tiers référé | Opération | Type | Période | Date d'inscription | Actions |
|---|---|---|---|---|---|

- **En-tête** : `table-dark` + style bleu foncé.
- **Tiers référé** : `<a href="route('tiers.show', tiersReferreId)" class="no-row-click">{{ tiersReferreNomComplet }}</a>` (cohérent slice 7a référent).
- **Opération** : `<a href="route('operations.show', operationId)" class="no-row-click">{{ operationNom }}</a>` + badges HelloAsso, Archivée.
- **Type** : texte secondaire.
- **Période** : `dateDebut->format('d/m/Y') → dateFin->format('d/m/Y')` ou `—`.
- **Date d'inscription** : `dateInscription->format('d/m/Y')`, `data-sort` ISO.
- **Actions** : bouton `<a class="btn btn-sm btn-outline-secondary no-row-click">` icon `bi-eye` → `operations.show`.

**Ligne cliquable** : `onclick="window.location='operations.show'"` avec guard `closest('button,a,…')`. Donc :
- Clic sur cellule **Tiers référé** → fiche tiers (via `<a>` qui intercepte)
- Clic sur cellule **Opération** → fiche opération (via `<a>` qui intercepte)
- Clic ailleurs sur la ligne → fiche opération (via `onclick` de la `<tr>`)

### 5.4 Partial `suit-table.blade.php`

| Tiers suivi | Qualité | Opération | Type | Période | Date d'inscription | Actions |
|---|---|---|---|---|---|---|

- **Tiers suivi** : idem section A référé.
- **Qualité** : badge `<span class="badge text-bg-secondary">Médecin</span>` ou `<span class="badge text-bg-secondary">Thérapeute</span>`.
- Reste identique au partial A référé.

### 5.5 Mise à jour `FicheTiers` (compteur d'onglet)

```php
$nbPart = $this->tiers->participants()->count();
$nbRef = Participant::where('refere_par_id', $this->tiers->id)
    ->distinct()->count('tiers_id');
$nbSuit = Participant::where(fn ($q) => $q
    ->where('medecin_tiers_id', $this->tiers->id)
    ->orWhere('therapeute_tiers_id', $this->tiers->id)
)->distinct()->count('tiers_id');

$totalOperations = $nbPart + $nbRef + $nbSuit;
if ($totalOperations > 0) {
    $onglets[] = ['key' => 'operations', 'label' => 'Opérations', 'count' => $totalOperations];
}
```

**Note** : les 3 COUNT sont légers (pas d'eager). Si le tiers n'a aucun lien, l'onglet disparaît (comportement cohérent 7a).

## 6. Risques techniques à éclaircir au plan TDD

- **R1** — `Participant::tiers()` relation est-elle bien posée pour `tiers:id,nom,prenom` ? À vérifier (probablement OK car déjà eager-loadée ailleurs).
- **R2** — Tri secondaire en collection : Laravel `Collection::sortBy([...])` accepte-t-il un tableau de comparateurs custom, ou faut-il utiliser `usort` direct sur l'array ?
- **R3** — Pour la section Suit, si on fait 2 queries séparées (medecin + therapeute), un même `Participant` (même couple `tiers_id`, `operation_id`) peut apparaître 2 fois si la personne est à la fois médecin et thérapeute. C'est **voulu** (option A "1 ligne par lien") mais doit être testé.

## 7. Tests Pest

### 7.1 Tests unit du service (`tests/Unit/Services/Tiers/TiersOperationsTimelineServiceTest.php`)

**Section A référé** :
- Tiers sans personne référée → DTO vide.
- N participants distincts référés → `totalCount = N (distincts)`.
- 1 tiers référé sur 3 opérations → `totalCount = 1`, lignes = 3.
- Tri alphabétique par NOM, puis date opération desc.
- Eager loading correct (assertSeeNoQueries / count queries).
- Isolation tiers (un référent d'un autre tiers ne fuit pas).
- Intrusion multi-tenant (référent d'une autre association ne fuit pas).

**Section Suit** :
- Tiers sans personne suivie → DTO vide.
- N participants distincts suivis (médecin seul) → `totalCount = N`.
- 1 tiers à la fois médecin et thérapeute d'une même personne sur 1 opération → `totalCount = 1` (distinct), lignes = 2 (1 par qualité).
- Qualité correctement injectée dans le DTO.
- Tri alphabétique NOM + date desc.
- Isolation + intrusion multi-tenant.

### 7.2 Tests Livewire du composant (`tests/Livewire/Tiers/Onglets/OperationsTest.php` extension)

- Section A référé visible si `count > 0`, absente sinon.
- Lien tiers référé → `route('tiers.show', id)`.
- Lien opération → `route('operations.show', id)`.
- Section Suit visible si `count > 0`, badge Qualité correct par ligne.
- Cas "ligne par lien" : 1 tiers référé sur 3 opérations → 3 lignes affichées.

### 7.3 Tests feature `FicheTiers`

- Onglet "Opérations (N)" présent avec compteur = somme des 3 sections.
- Tiers strictement sans aucun lien → onglet absent.

## 8. Procédure de recette manuelle (localhost)

À dérouler avant push + PR :

1. Sur un tiers ayant des participations propres + ayant référé d'autres tiers + suivant en médecin/thérapeute (cumul des 3 rôles si possible), vérifier que l'onglet affiche un compteur total > 0.
2. Cliquer sur l'onglet → 3 cartes empilées : Participation, A référé, Suit.
3. Section A référé : tableau trié alpha par nom, clic colonne Tiers → fiche tiers, clic Opération → fiche opération.
4. Section Suit : colonne Qualité (Médecin / Thérapeute) bien affichée. Si une personne a 2 rôles, 2 lignes consécutives.
5. Tester un tiers qui n'a aucun lien → onglet absent.
6. Tester un tiers qui n'a que des participations → seule la section Participation s'affiche (pas de "A référé" ni "Suit" cards vides).

## 9. Définition de "fait"

- ✅ Aucune migration BD.
- ✅ Code : 2 nouvelles méthodes service, 4 nouveaux DTOs, 2 nouveaux partials Blade, composant Livewire `Operations` enrichi, `FicheTiers` compteur mis à jour.
- ✅ Tests : ~25 tests Pest verts, suite globale 0 failed.
- ✅ Pint clean.
- ✅ Recette manuelle locale OK.
- ✅ Spec relue par utilisateur, commit du spec.

## 10. Vocabulaire arrêté

- **A référé** = ce tiers a recommandé/adressé des personnes (côté actif). FK `participants.refere_par_id`. *Notion CRM ∩ médical : "il nous envoie des patients/adhérents".*
- **Référeur** = synonyme de "celui qui a référé" — utilisé dans la mémoire / dans les conversations métier.
- **Suit** = ce tiers est rattaché à des participants en qualité de Médecin ou de Thérapeute. FKs `medecin_tiers_id` OU `therapeute_tiers_id`.
- **Qualité** = colonne discriminante dans la section Suit : `Médecin` ou `Thérapeute`. Extensible plus tard (autres rôles de suivi).
- **Tiers distincts** = unité de compteur dans les sections 7b. Le **compteur n'est PAS égal au nombre de lignes** (dissonance assumée, mitigée par le tri alphabétique).
- **Lignes par lien** (option A) = 1 ligne par couple (tiers, opération, qualité). Si Marie est référée 3 fois, 3 lignes. Si Marie est médecin et thérapeute sur la même opération, 2 lignes.
