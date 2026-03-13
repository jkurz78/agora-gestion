# Numérotation des pièces comptables — Spec

## Objectif

Assigner automatiquement un numéro de pièce unique et séquentiel à chaque transaction financière (recette, dépense, don, cotisation, virement interne) lors de sa création, conformément à l'obligation légale de numérotation chronologique des pièces comptables.

---

## Format du numéro

```
{exercice}:{sequence}
```

- `exercice` : format `YYYY-YYYY+1`, ex. `2025-2026`
- `sequence` : entier séquentiel par exercice, padé sur 5 chiffres, ex. `00001`
- Exemple complet : `2025-2026:00001`

**Calcul de l'exercice depuis une date :**
- Si le mois de la date ≥ 9 (septembre) → `{year}-{year+1}`
- Sinon → `{year-1}-{year}`

---

## Périmètre

Tous les types de transactions :

| Type | Table |
|---|---|
| Recette | `recettes` |
| Dépense | `depenses` |
| Don | `dons` |
| Cotisation | `cotisations` |
| Virement interne | `virements_internes` |

---

## Modèle de données

### Nouvelle table `sequences`

```sql
CREATE TABLE sequences (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exercice        VARCHAR(9) NOT NULL,  -- ex. "2025-2026"
    dernier_numero  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    UNIQUE KEY sequences_exercice_unique (exercice)
);
```

### Colonne `numero_piece` sur les 5 tables

Ajoutée par migration sur chaque table :

```sql
ALTER TABLE recettes ADD COLUMN numero_piece VARCHAR(20) NULL UNIQUE AFTER id;
ALTER TABLE depenses ADD COLUMN numero_piece VARCHAR(20) NULL UNIQUE AFTER id;
ALTER TABLE dons ADD COLUMN numero_piece VARCHAR(20) NULL UNIQUE AFTER id;
ALTER TABLE cotisations ADD COLUMN numero_piece VARCHAR(20) NULL UNIQUE AFTER id;
ALTER TABLE virements_internes ADD COLUMN numero_piece VARCHAR(20) NULL UNIQUE AFTER id;
```

Nullable pour compatibilité avec les éventuelles données existantes avant la mise en place du système.

---

## Architecture technique

### Nouveau fichier

| Fichier | Rôle |
|---|---|
| `database/migrations/…_create_sequences_table.php` | Table sequences |
| `database/migrations/…_add_numero_piece_to_transactions.php` | Colonne sur les 5 tables |
| `app/Services/NumeroPieceService.php` | Génère et assigne les numéros |
| `tests/Feature/Services/NumeroPieceServiceTest.php` | Tests unitaires du service |

### Fichiers modifiés

| Fichier | Modification |
|---|---|
| `app/Models/Recette.php` | Ajouter `'numero_piece'` au `$fillable` |
| `app/Models/Depense.php` | Idem |
| `app/Models/Don.php` | Idem |
| `app/Models/Cotisation.php` | Idem |
| `app/Models/VirementInterne.php` | Idem |
| `app/Services/RecetteService.php` | Appel `NumeroPieceService::assign()` dans `store()` |
| `app/Services/DepenseService.php` | Idem |
| `app/Services/DonService.php` | Idem |
| `app/Services/CotisationService.php` | Idem |
| `app/Services/VirementInterneService.php` | Idem |
| `resources/views/livewire/recette-list.blade.php` | Colonne `N°` en première position |
| `resources/views/livewire/depense-list.blade.php` | Idem |
| `resources/views/livewire/recette-form.blade.php` | Affichage `numero_piece` en lecture seule (édition) |
| `resources/views/livewire/depense-form.blade.php` | Idem |
| `resources/views/livewire/transaction-compte-list.blade.php` | Colonne `N° pièce` en première position |
| `app/Services/TransactionCompteService.php` | Inclure `numero_piece` dans le SELECT de chaque branche UNION |

---

## `NumeroPieceService`

```php
final class NumeroPieceService
{
    public function assign(Carbon $date): string
    {
        $exercice = $this->exerciceFromDate($date);

        // Upsert : crée la ligne si elle n'existe pas, sinon l'incrémente.
        // Puis verrouille avec SELECT FOR UPDATE pour éviter les doublons sous concurrence.
        DB::table('sequences')->upsert(
            ['exercice' => $exercice, 'dernier_numero' => 0],
            ['exercice'],
            [],  // rien à mettre à jour si déjà existant
        );

        $sequence = DB::table('sequences')
            ->where('exercice', $exercice)
            ->lockForUpdate()
            ->first();

        $numero = $sequence->dernier_numero + 1;

        DB::table('sequences')
            ->where('exercice', $exercice)
            ->update(['dernier_numero' => $numero, 'updated_at' => now()]);

        return $exercice . ':' . str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
    }

    public function exerciceFromDate(Carbon $date): string
    {
        $year = $date->year;
        if ($date->month >= 9) {
            return "{$year}-" . ($year + 1);
        }
        return ($year - 1) . "-{$year}";
    }
}
```

**Intégration dans les services existants :**

```php
// Exemple dans RecetteService::create()
public function create(array $data): Recette
{
    return DB::transaction(function () use ($data): Recette {
        $date = Carbon::parse($data['date']);
        $data['numero_piece'] = app(NumeroPieceService::class)->assign($date);
        // ... reste de la logique existante
    });
}
```

Le `assign()` est toujours appelé **à l'intérieur** d'un `DB::transaction()`, ce qui garantit qu'un numéro assigné mais dont la transaction DB échoue est annulé (rollback sur la table `sequences` aussi).

**Cas particulier — `CotisationService::create()`** : ce service ne wrappait pas encore de `DB::transaction()`. Il faudra en ajouter un pour que le `assign()` soit correctement rollbacké en cas d'échec.

---

## Affichage

### Vue transactions par compte (`transaction-compte-list.blade.php`)

Colonne `N° pièce` ajoutée en **première position** du tableau (avant `Date`). Le UNION ALL dans `TransactionCompteService` inclut `numero_piece` dans **chacune des 6 branches SELECT** : recettes (`r.numero_piece`), dépenses (`d.numero_piece`), dons (`dn.numero_piece`), cotisations (`c.numero_piece`), virements sortants (`vi.numero_piece`) **et** virements entrants (`vi.numero_piece`). Les deux branches virement référencent la même colonne de la table `virements_internes` — le même numéro apparaît donc dans les deux lignes, ce qui est correct (c'est la même pièce).

### Liste recettes (`recette-list.blade.php`)

Colonne `N°` ajoutée en première position, affichant `$recette->numero_piece ?? '—'`.

### Liste dépenses (`depense-list.blade.php`)

Idem.

### Formulaires recette et dépense

Quand `$recetteId` / `$depenseId` est non null (mode édition), afficher au-dessus du formulaire :

```blade
@if ($recetteId)
    <div class="text-muted small mb-2">
        N° pièce : <strong>{{ $recette->numero_piece ?? '—' }}</strong>
    </div>
@endif
```

Dons et cotisations : pas de formulaire dédié modifié — le numéro est visible via la vue transactions par compte uniquement. Les listes dédiées `don-list` et `virement-interne-list` n'affichent pas non plus le numéro pour garder la portée minimale ; la vue transactions par compte est le point d'entrée privilégié pour la traçabilité numérique.

---

## Gestion des suppressions

Le numéro de pièce assigné à une transaction supprimée (soft delete) **reste dans la table** de la transaction (enregistrement `deleted_at` non null). Le numéro est donc conservé pour la traçabilité mais la séquence présente un trou, ce qui est conforme à la pratique comptable française (PCG).

La table `sequences` ne sait pas que des numéros ont été supprimés : elle maintient uniquement `dernier_numero`, le plus grand numéro assigné pour l'exercice.

---

## Tests

Tous les tests utilisent `RefreshDatabase` pour repartir d'une base propre à chaque test.

### `NumeroPieceServiceTest`

- `exerciceFromDate()` : date en septembre → exercice `{year}-{year+1}` ; date en août → exercice `{year-1}-{year}`
- `assign()` sur une date de septembre 2025 retourne `2025-2026:00001`
- `assign()` sur une date de février 2026 (2ème appel, même exercice) retourne `2025-2026:00002`
- `assign()` sur une date de septembre 2026 retourne `2026-2027:00001` (nouvel exercice, séquence indépendante)
- Deux appels consécutifs dans le même test donnent deux numéros distincts

### Tests services

- `RecetteService::create()` assigne un `numero_piece` non null
- Idem pour `DepenseService::create()`, `DonService::create()`, `CotisationService::create()`, `VirementInterneService::create()`

### Tests Livewire

- La colonne `N°` apparaît dans `recette-list` et `depense-list`
- La colonne `N° pièce` apparaît dans `transaction-compte-list`
- Le `numero_piece` est affiché en mode édition dans `recette-form` et `depense-form`
