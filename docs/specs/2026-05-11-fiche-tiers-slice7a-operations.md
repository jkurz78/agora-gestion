# Fiche tiers 360° — Slice 7a : onglet Opérations / section Participation

**Date** : 2026-05-11
**Programme** : Fiche tiers 360°
**Position dans la roadmap** : Slice 7 (Opérations / participations), sous-slice **7a**
**Prérequis** : Slices 0+1, 2, 3 (a/b/c/d), 4 livrés. Slices 5 (mapping HelloAsso events) et 6 (Factures + NDF) non requis pour 7a.
**Slices ouverts par cette spec** : 7b (Prescripteur), 7c (Encadrement), 8 (Documents — accroche bilatérale opérations ↔ documents)
**Statut** : SPEC POUR /plan

---

## 1. Intention

Ajouter à la fiche tiers 360° (`/tiers/{tiers}`) un onglet **Opérations** qui répond à la question : « Qu'a fait ce tiers avec nous, côté activités/séances ? ».

L'onglet est un **conteneur multi-sections** ouvert à l'extension. Slice 7a livre la **structure du conteneur** + la **section "Participation"** (le tiers en tant qu'inscrit à une opération). Slices ultérieurs ajoutent les autres perspectives (Prescripteur, Encadrement) sans toucher au conteneur.

## 2. Périmètre

### 2.1 Inclus dans 7a

- Onglet « **Opérations** » sur `App\Livewire\Tiers\FicheTiers`, **affiché conditionnellement** si `count > 0` (somme des sections matérialisées, en 7a = participations seules).
- Compteur d'onglet calculé via `count()` léger côté `FicheTiers::render()`, hors `wire:lazy` du composant onglet.
- Composant Livewire `App\Livewire\Tiers\Onglets\Operations` (`wire:lazy`, cohérent avec `Onglets/Dons` et `Onglets/Adhesion`).
- Composant Blade `<x-tiers.operations.section-card>` réutilisable (header titre + compteur + chevron repli, body collapse Bootstrap), prêt à recevoir les sections 7b/7c.
- **Conteneur multi-sections** : cards Bootstrap empilées, **toutes ouvertes par défaut**, repliables individuellement (Bootstrap collapse natif).
- **Section "Participation"** unique en 7a : tableau des opérations auxquelles le tiers est inscrit (FK `participants.tiers_id`).
- Service `App\Services\Tiers\TiersOperationsTimelineService::forTiers(Tiers $tiers): ParticipationsTimelineDTO`.
- DTOs immuables : `ParticipationsTimelineDTO`, `ParticipationLigneDTO` dans `App\Services\Tiers\DTO\`.
- Tri chronologique inverse par `Participant.date_inscription`.
- Métriques par ligne : tarif, séances suivies `X/Y`, règlement `Z €/W €`, pastille statut (4 états), référent.
- 2 actions par ligne : « Voir l'opération » (lien), « Voir le tiers référent » (lien conditionnel).
- Badge **HelloAsso** si `est_helloasso=true`.
- Badge **Archivée** si `operation.deleted_at` non null.
- Tests Pest (unit service + Livewire composant + feature intégration + intrusion multi-tenant) — ~15-20 tests.

### 2.2 Hors scope 7a (slices ultérieurs)

- **Slice 7b** — Sections « Référé par lui » (FK `participants.refere_par_id`) et « Médecin référent » (FK `participants.medecin_tiers_id`). `therapeute_tiers_id` à arbitrer au brainstorm 7b. Méthodes futures `prescripteurForTiers()` / `medecinForTiers()` sur le même service `TiersOperationsTimelineService`.
- **Slice 7c** — Section « Encadrement » : opérations sur lesquelles le tiers est tiers payé sur une ligne de transaction (`transaction_lignes.operation_id` non null). Méthode future `encadrementForTiers()`. Aucune nouvelle modélisation requise.
- **Slice 8 — Documents** : accroche bilatérale attestations + factures par opération. Liens « Attestation de présence » et « Facture » par ligne, à brancher quand l'onglet Documents existera. Réciprocité : depuis l'onglet Documents → opérations associées.
- **Symétrie portail** : aucun onglet `/portail/mes-operations` aujourd'hui. Le service `TiersOperationsTimelineService` est factorisé côté domain (DTO immuable, lookup par `Tiers`) pour être consommé tel quel par un futur composant portail. **Dette tracée** ; pas livrée en 7a.
- Filtres (exercice, statut), action « Inscrire à une opération », action « Désinscrire », export — non MVP.

## 3. Modèle de données existant (rappel)

Pas de migration BD en 7a. Les FK exploitées :

```
Tiers (1) ──< (N) Participant
                  ├─ FK tiers_id           [NOT NULL]   ← périmètre 7a
                  ├─ FK refere_par_id      [nullable]   ← périmètre 7b
                  ├─ FK medecin_tiers_id   [nullable]   ← périmètre 7b
                  ├─ FK therapeute_tiers_id [nullable]  ← à arbitrer 7b
                  ├─ FK operation_id
                  ├─ FK type_operation_tarif_id
                  ├─ est_helloasso, helloasso_item_id, helloasso_order_id
                  ├─ date_inscription
                  └─ unique(tiers_id, operation_id)

Participant (1) ──< (N) Presence    [seance_id, statut encrypted]
Participant (1) ──< (N) Reglement   [seance_id, montant_prevu]
Reglement   (1) ──> (1) Transaction [statut_reglement]
Operation   (1) ──< (N) Seance      [date]
```

## 4. Architecture data

### 4.1 Service

```php
namespace App\Services\Tiers;

final class TiersOperationsTimelineService
{
    public function forTiers(Tiers $tiers): ParticipationsTimelineDTO;
}
```

Service singleton, injecté via DI Laravel. En cible 7b/7c, on ajoute `prescripteurForTiers()`, `medecinForTiers()`, `encadrementForTiers()` sur la même classe — service composite "vue 360 opérations d'un tiers", au moins jusqu'à un seuil (~300 lignes) où on splittera.

### 4.2 DTOs (`App\Services\Tiers\DTO\`)

```php
final readonly class ParticipationsTimelineDTO {
    public function __construct(
        public array $lignes,        // ParticipationLigneDTO[]
        public int $totalCount,
    ) {}
}

final readonly class ParticipationLigneDTO {
    public function __construct(
        public int $participantId,
        public int $operationId,
        public string $operationNom,
        public string $typeOperationNom,
        public ?Carbon $dateDebut,        // min(seances.date) ou null
        public ?Carbon $dateFin,          // max(seances.date) ou null
        public Carbon $dateInscription,
        public ?string $tarifLibelle,     // null si pas de tarif
        public float $tarifMontant,
        public int $seancesSuivies,       // X (présences positives)
        public ?int $seancesTotal,        // Y (null si 0 séance créée)
        public float $montantPaye,        // Z
        public float $montantPrevu,       // W
        public string $statut,            // 'solde'|'partiel'|'non_paye'|'gratuit'
        public bool $estHelloasso,
        public bool $operationArchivee,   // dérivé de operation.deleted_at
        public ?int $refereParTiersId,    // FK ou null
        public ?string $refereParNomComplet, // dénormalisé "Prénom NOM" pour éviter N+1
    ) {}
}
```

### 4.3 Requête principale

```php
Participant::query()
    ->where('tiers_id', $tiers->id)
    ->with([
        'operation' => fn ($q) => $q->withTrashed()->select('id', 'nom', 'type_operation_id', 'deleted_at'),
        'operation.typeOperation:id,nom',
        'operation.seances:id,operation_id,date',
        'typeOperationTarif:id,libelle,montant',
        'refereParTiers:id,nom,prenom',
        'presences:id,participant_id,seance_id,statut',
        'reglements:id,participant_id,seance_id,montant_prevu,transaction_id',
        'reglements.transaction:id,statut_reglement',
    ])
    ->orderByDesc('date_inscription')
    ->get();
```

Eager loading explicite. `withTrashed()` sur l'opération pour ne pas perdre les lignes d'opérations soft-deleted (règle L1).

### 4.4 Règles de calcul

| Champ DTO | Calcul |
|---|---|
| `seancesTotal` | `operation->seances->count()`, **`null` si 0** |
| `seancesSuivies` | nombre de présences dont `statut` indique une présence positive (valeur(s) exacte(s) — hypothèse `'present'` — à confirmer en phase 1 du plan TDD, voir §6 R1) ; **filtre en PHP** car `Presence.statut` est `encrypted` (impossible à filtrer en SQL) |
| `montantPrevu` (W) | `reglements->sum('montant_prevu')` |
| `montantPaye` (Z) | `reglements->filter(fn ($r) => $r->transaction?->statut_reglement->isEncaisse())->sum('montant_prevu')` |
| `dateDebut` / `dateFin` | `min`/`max` sur `operation->seances->pluck('date')` ; `null` si pas de séance |
| `operationArchivee` | `$participant->operation->deleted_at !== null` |
| `refereParNomComplet` | `"{prenom} {NOM}"` du tiers référent (NOM en majuscules via accesseur Tiers existant), `null` si pas de référent |
| `estHelloasso` | flag direct `Participant.est_helloasso` |

**Règle des 4 statuts**

| Condition | `statut` |
|---|---|
| `W === 0.0` | `'gratuit'` |
| `W > 0` ET `Z === 0.0` | `'non_paye'` |
| `W > 0` ET `0 < Z < W` | `'partiel'` |
| `W > 0` ET `Z >= W` | `'solde'` |

Comparaisons monétaires : on fait du `float` car les `decimal:2` Eloquent sont castés en float partout dans le projet ; pas de seuil epsilon nécessaire vu la nature comptable des données (montants déjà arrondis à 0.01).

### 4.5 Cas limites confirmés

- **L1 Opération soft-deleted** : ligne **affichée**, badge "Archivée" visible.
- **L2 Opération avec 0 séance** : ligne **affichée**, `seancesTotal = null`, `dateDebut/dateFin = null`, colonne Période affiche `—`, colonne Séances affiche `—`.
- **L3 Participant soft-deleted** : non géré (la table `participants` n'a pas SoftDeletes confirmé — à vérifier en phase 1 du plan TDD ; si SoftDeletes présent, exclure via le scope par défaut Eloquent).
- **L4 Tarif null** (`type_operation_tarif_id` null) : `tarifLibelle = null`, `tarifMontant = 0.0`, colonne Tarif affiche `—`.

## 5. Architecture UI

### 5.1 Composant Livewire

`App\Livewire\Tiers\Onglets\Operations` — read-only, pas d'état, monté `wire:lazy` depuis `FicheTiers`.

```php
namespace App\Livewire\Tiers\Onglets;

final class Operations extends Component
{
    public Tiers $tiers;

    public function render(): View
    {
        $participations = app(TiersOperationsTimelineService::class)->forTiers($this->tiers);

        return view('livewire.tiers.onglets.operations', [
            'participations' => $participations,
        ]);
    }
}
```

### 5.2 Mise à jour de `FicheTiers`

Ajouter le compteur participations à l'array `$onglets` ; l'onglet apparaît si `count > 0`.

```php
$nbParticipations = $this->tiers->participants()->count();
if ($nbParticipations > 0) {
    $onglets[] = ['key' => 'operations', 'label' => 'Opérations', 'count' => $nbParticipations];
}
```

En cible 7b/7c, le compteur devient la somme des sections matérialisées. Le **label "Opérations" reste générique** dès aujourd'hui (pas "Participations") pour ne pas avoir à le renommer en 7b.

### 5.3 Vue blade

`resources/views/livewire/tiers/onglets/operations.blade.php`

```blade
<div class="operations-container">
    {{-- Section Participation --}}
    @if ($participations->totalCount > 0)
        <x-tiers.operations.section-card
            id="participation"
            titre="Participation"
            :compteur="$participations->totalCount"
        >
            @include('livewire.tiers.onglets.partials.participations-table', [
                'lignes' => $participations->lignes,
            ])
        </x-tiers.operations.section-card>
    @endif

    {{-- Slice 7b : @include partials.prescripteur-table --}}
    {{-- Slice 7b : @include partials.medecin-table --}}
    {{-- Slice 7c : @include partials.encadrement-table --}}
</div>
```

### 5.4 Composant blade `x-tiers.operations.section-card`

Réutilisable pour les futures sections.

- Header : titre + badge compteur + chevron repli.
- Body : `<div class="collapse show">` (ouvert par défaut, repliable via Bootstrap collapse natif).
- Pas de JS custom, pas de dépendance hors Bootstrap.
- Slot par défaut = contenu de la section.

### 5.5 Tableau participations

`resources/views/livewire/tiers/onglets/partials/participations-table.blade.php`

Colonnes :

| Opération | Type | Période | Tarif | Séances | Règlement | Statut | Référé par | Actions |
|---|---|---|---|---|---|---|---|---|

- **En-tête** : `table-dark` + `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"` (convention CLAUDE.md).
- **Opération** : nom + badge HelloAsso si `estHelloasso` + badge "Archivée" si `operationArchivee`. Nom cliquable → `route('operations.show', $ligne->operationId)`.
- **Type** : `typeOperationNom` (texte secondaire).
- **Période** : `dateDebut->format('d/m/Y') → dateFin->format('d/m/Y')` ; `—` si null.
- **Tarif** : `tarifLibelle (tarifMontant €)` ; `—` si `tarifLibelle = null`.
- **Séances** : `X / Y` ; `—` si `seancesTotal = null`.
- **Règlement** : `Z € / W €` ; `<span class="badge bg-info">Gratuit</span>` si `statut === 'gratuit'`.
- **Statut** : pastille `<span class="badge bg-{success|warning|danger|info}">…</span>` selon les 4 états.
- **Référé par** : `refereParNomComplet` cliquable → `route('tiers.show', $ligne->refereParTiersId)` ; vide si null.
- **Actions** : `<x-bouton-action icon="bi-eye" :href="route('operations.show', $ligne->operationId)" title="Voir l'opération" />` (à aligner sur le composant action existant utilisé dans Onglets/Dons et Onglets/Adhesion).

**Ligne entière cliquable** vers la fiche opération via `onclick="window.location='...'"` avec le guard JS standard `if (!event.target.closest('button,a,…'))` repris du slice 0+1. Le clic sur le bouton et le lien référent ne déclenche pas la navigation.

Pas de pagination en 7a — bornée par construction (un tiers a rarement >30-50 participations historiques). À revoir si SVS dépasse.

### 5.6 `data-sort` côté tri JS client

Pas de tri client en 7a (tri serveur unique par date d'inscription desc).

## 6. Risques techniques à éclaircir au plan TDD

- **R1** — Valeur exacte de `Presence.statut` pour "présent". Champ `encrypted` dans `app/Models/Presence.php`. La phase 1 du plan TDD lit l'enum ou la convention de valeur. Si plusieurs valeurs positives coexistent, on les énumère dans un constant array du service. **Conséquence architecturale** : filtrage **en PHP après chargement** (pas de `where` SQL possible sur un champ encrypted).
- **R2** — Existence et nom exact de `StatutReglement::isEncaisse()`. Mémoire slice 0+1 : `Encaisse` n'existe pas dans l'enum, `isEncaisse()` retourne `$this !== EnAttente`. À reconfirmer.
- **R3** — `SoftDeletes` sur le modèle `Participant` : à vérifier en phase 1.
- **R4** — Composant `<x-bouton-action>` existant : utiliser exactement le même chemin/API que `Onglets/Dons` et `Onglets/Adhesion`. À aligner dans la phase 1 du plan.
- **R5** — Accesseur "NOM en majuscules" sur `Tiers` : confirmé en mémoire (v2.5.3) ; à exploiter pour `refereParNomComplet`.

## 7. Tests

### 7.1 Tests unit du service (`tests/Unit/Services/Tiers/TiersOperationsTimelineServiceTest.php`)

- DTO vide si tiers sans participation.
- `totalCount` reflète exactement le nombre de lignes.
- Tri chronologique inverse par `date_inscription`.
- **Statuts** : 1 test dédié par transition (`gratuit`, `non_paye`, `partiel`, `solde`).
- **L1** : opération soft-deleted → ligne présente, `operationArchivee=true`.
- **L2** : 0 séance → `seancesTotal=null`, `dateDebut/dateFin=null`.
- **`seancesSuivies`** : filtre en PHP, présences mixtes (present/absent/excusé).
- **`montantPaye`** : exclut règlements dont `transaction.statut_reglement` n'est pas dans l'ensemble encaissé.
- **Référent** : `refereParNomComplet` dénormalisé correctement (avec/sans référent).
- **Isolation tiers** : un Participant d'un autre tiers ne fuit pas.

### 7.2 Tests Livewire du composant (`tests/Feature/Livewire/Tiers/Onglets/OperationsTest.php`)

- Composant monte avec un `Tiers`, expose `participations`.
- Tableau présent avec colonnes attendues.
- Badge HelloAsso si `est_helloasso=true`.
- Badge "Gratuit" si W=0.
- Badge "Archivée" si opération soft-deleted.
- Liens "Voir l'opération" et "Voir tiers référent" corrects.
- Pastille statut avec la bonne classe CSS par état.

### 7.3 Tests feature `FicheTiers` (extension de l'existant)

- Onglet "Opérations" **absent** si tiers sans participation.
- Onglet "Opérations" **présent** avec compteur correct sinon.
- Navigation `?onglet=operations` charge le composant lazy.

### 7.4 Test intrusion multi-tenant

- Participation d'un tiers d'une autre `Association` ne fuit pas (via `TenantScope` + `TenantTestCase`).

## 8. Procédure de recette manuelle (localhost)

À dérouler avant push + PR :

1. Login admin `admin@monasso.fr / password`.
2. Aller sur `/tiers`, trouver un tiers ayant des participations (ex: seed démo).
3. Cliquer sur la ligne → page `/tiers/{id}` s'ouvre.
4. Vérifier que l'onglet "Opérations (N)" apparaît dans la nav-tabs.
5. Cliquer dessus → vue chargée en `wire:lazy`.
6. Vérifier la carte "Participation" ouverte par défaut, repliable via chevron.
7. Vérifier le tableau : tri inverse, 4 statuts présents selon les données, badges HelloAsso/Archivée/Gratuit présents selon les cas.
8. Cliquer sur une ligne → fiche opération s'ouvre.
9. Cliquer sur un lien "Référé par" → fiche du référent s'ouvre.
10. Tester un tiers sans participation → onglet absent.

## 9. Définition de "fait"

- ✅ Migration : ~~aucune~~ **Amendement 2026-05-11** : 1 migration soft-add `add_soft_deletes_to_operations` (colonne `deleted_at` nullable, default null) + `use SoftDeletes` sur le modèle `Operation`. Nécessaire pour rendre le cas L1 testable et fonctionnel. Réversible. **Dette dormante connue** : 3 queries existantes dans `app/Livewire/FactureShow.php` (lignes 190, 305, 346) ignorent désormais les opérations soft-deleted ; cliquer sur une ligne d'opération archivée mène à 404 (route model binding sans `withTrashed`). Non actionnable tant qu'aucune UI d'archivage n'existe — à traiter au moment où on ajoutera la fonctionnalité « archiver une opération ».
- ✅ Code : service + 2 DTOs + composant Livewire + vue + composant blade `section-card` + partial tableau + extension `FicheTiers`.
- ✅ Trait `HasFactory` ajouté à `Participant`, `Seance`, `Presence`, `Reglement` (modèles qui avaient des factories non câblées).
- ✅ Tests : 36 tests Pest verts (22 unit + 11 Livewire + 3 feature), suite globale 0 failed.
- ✅ Pint clean.
- ✅ Recette manuelle locale OK (8 scénarios §8).
- ✅ Spec relue par utilisateur, commit du spec dans la branche.
- ✅ PR vers `main` ou enchaînement direct sur 7b selon arbitrage utilisateur.

## 10. Vocabulaire arrêté

- **Participation** = lien d'un tiers à une opération via FK `participants.tiers_id` (inscription).
- **Référé par** = `refere_par_id` (référent générique). Périmètre 7b.
- **Médecin référent** = `medecin_tiers_id`. Périmètre 7b.
- **Thérapeute** = `therapeute_tiers_id`. Périmètre à arbitrer 7b.
- **Encadrement** = tiers payé sur transaction portant `operation_id`. Périmètre 7c.
- **Gratuit** (vs "offert" utilisé pour les adhésions) = participation sans contrepartie monétaire (`W = 0`).
- **Soldé / Partiel / Non payé** = états de paiement de la participation.
- **Archivée** = opération soft-deleted (visible dans l'historique du tiers, marquée).
