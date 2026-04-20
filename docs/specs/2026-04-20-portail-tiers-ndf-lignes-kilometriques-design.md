# Spec — Portail Tiers NDF : Lignes de frais kilométriques

> **Date** : 2026-04-20
> **Auteur** : Jurgen Kurz + assistant agent
> **Statut** : Spec validée — prête pour /plan puis /build
> **Parent** : Programme "Notes de frais" (Slice 2 livrée)
> **Branche** : `feat/portail-tiers-slice1-auth-otp` (même branche que les Slices 1-3)

## 1. Intent

Ajouter au portail Tiers, à côté des lignes de frais standards, une seconde variante : **ligne de frais kilométrique**. Le Tiers saisit la puissance fiscale du véhicule, la distance parcourue et le barème applicable. Le montant est recalculé server-side. La carte grise est obligatoire en pièce jointe de la ligne. Le comptable valide au back-office sans changement d'UI : une description humaine du déplacement est générée dans le champ `notes` de la Transaction.

### Périmètre livré

**Nouveau bouton** sur l'écran de saisie NDF portail : `+ Ajouter un déplacement` (à côté du `+ Ajouter une ligne de frais` existant).

**Wizard km 2 étapes** :
1. Upload carte grise (PDF/JPG/PNG/HEIC, max 5 Mo, obligatoire).
2. Saisie libellé + puissance fiscale (CV) + distance (km) + barème (€/km) + opération/séance facultatifs. Le montant est calculé et affiché en temps réel en lecture seule.

**Résolution de la sous-catégorie** (transparente pour le Tiers) :
- `SousCategorie.pour_frais_kilometriques = true` appliqué comme marqueur.
- Exactement 1 flaggée → assignée automatiquement à la ligne au save.
- 0 ou 2+ flaggées → `sous_categorie_id = null`, le comptable tranche au back-office (Slice 3, mini-form déjà éditable).

**Affichage ligne existante** (Form édition + Show lecture seule + Back-office Show) :
- Badge `Km` devant le libellé.
- Sous-ligne d'info : `{CV} CV · {km} km · {bareme} €/km`.
- Montant dans la colonne habituelle.
- Carte grise accessible via l'icône PJ standard.

**Back-office Slice 3** (aucune modif UI) :
- Au moment où le `ValidationService` crée la Transaction à partir de la NDF validée, chaque ligne de type `kilometrique` peuple `transaction_lignes.notes` avec `"Déplacement de {km} km avec un véhicule {CV} CV au barème de {bareme} €/km"`.
- La carte grise est copiée vers `transaction_lignes.piece_jointe_path` comme pour une ligne standard.

**Paramétrage asso** : écran Sous-catégories existant, nouvelle colonne/switch `Frais kilométriques` à côté des 4 flags actuels (`pour_dons`, `pour_cotisations`, `pour_inscriptions`, `pour_depenses`).

### Décisions actées

| # | Décision | Choix |
|---|---|---|
| Q1 | Structure data | Table unique étendue (option A') — `type` enum + `metadata` JSON + strategy classes. |
| Q2 | Calcul montant | Saisie par le Tiers (CV, km, barème), auto-calcul server-side, comptable valide. |
| Q3 | Barème | Pas de table ni config — le Tiers saisit le coefficient. Aide : lien vers impots.gouv.fr. Pas de tranches : aucun bénévole > 5000 km/an. |
| Q4 | Carte grise | Niveau ligne (option B) — réutilise `piece_jointe_path` existant. |
| Q5 | Sous-catégorie | Flag `pour_frais_kilometriques` sur `SousCategorie` (option D') — résolution server-side, jamais exposée au Tiers. |
| Q6 | Libellé | Libre, saisi par le Tiers pour décrire le déplacement. |
| Q7 | Back-office | Aucune modif UI. Texte auto-généré dans `transaction_lignes.notes`. |

### Hors scope v0

- Barème officiel auto-seedé (volontairement exclu).
- Véhicules persistés sur Tiers (pas de ré-upload carte grise évité pour la v0).
- Types repas/hébergement (architecture prête, implémentation différée).
- Abandon de créance → reçu fiscal CERFA (run suivant dédié — voir §8).
- Reporting / graphiques km.

## 2. BDD Scenarios

### Scenario 1 — Saisie et soumission d'une ligne km (nominal)

```gherkin
Given le Tiers "Jean" connecté au portail de l'asso "MonAsso"
And la sous-catégorie "Déplacements" est flaggée pour_frais_kilometriques = true
When il crée une nouvelle NDF et clique "+ Ajouter un déplacement"
And il upload sa carte grise (PDF, 500 Ko)
And il saisit libellé="Paris-Rennes AG", CV=5, km=420, barème=0.636
Then le montant affiché temps réel est "267,12 €"
When il confirme la ligne puis soumet la NDF
Then la NDF passe en statut "soumise"
And la ligne stockée a : type="kilometrique", montant=267.12, sous_categorie_id=id(Déplacements)
And metadata contient {"cv_fiscaux":5, "distance_km":420, "bareme_eur_km":0.636}
And la carte grise est stockée à associations/{id}/notes-de-frais/{ndf_id}/ligne-{id}.pdf
```

### Scenario 2 — Résolution sous-catégorie fallback (0 flag)

```gherkin
Given l'asso n'a aucune sous-catégorie flaggée pour_frais_kilometriques
When le Tiers soumet une NDF avec une ligne km
Then la ligne est sauvegardée avec sous_categorie_id = NULL
And la NDF peut être soumise (pas de blocage côté portail)
And au back-office Slice 3, le comptable voit la ligne avec sous-cat "-"
And il affecte la sous-catégorie via le mini-form éditable
```

### Scenario 3 — Ambiguïté sous-catégorie (2+ flags)

```gherkin
Given l'asso a deux sous-catégories flaggées pour_frais_kilometriques
When le Tiers soumet une NDF avec une ligne km
Then la ligne est sauvegardée avec sous_categorie_id = NULL (même comportement que 0 flag)
And le comptable arbitre au back-office
```

### Scenario 4 — Anti-tampering du montant

```gherkin
Given un Tiers malveillant modifie le montant côté client (DevTools) à 9999.99
When il soumet la NDF
Then le service recalcule le montant = distance_km × bareme_eur_km
And la ligne stockée a montant = 267.12 (pas 9999.99)
```

### Scenario 5 — Validation back-office crée la Transaction

```gherkin
Given une NDF soumise avec une ligne km (CV=5, km=420, barème=0.636)
When le comptable valide la NDF au back-office
Then une Transaction est créée
And la ligne Transaction correspondante a montant=267.12
And ligne.notes = "Déplacement de 420 km avec un véhicule 5 CV au barème de 0,636 €/km"
And ligne.piece_jointe_path pointe vers la carte grise copiée
```

### Scenario 6 — Édition d'un brouillon avec ligne km

```gherkin
Given un brouillon NDF avec une ligne km
When le Tiers rouvre la NDF en édition
Then la ligne km est affichée avec badge "Km" et ses 3 paramètres
And il peut modifier CV, km, barème
And le montant est recalculé au save
```

### Scenario 7 — Carte grise manquante bloque la soumission

```gherkin
Given un brouillon avec une ligne km sans carte grise uploadée
When le Tiers clique "Soumettre"
Then une erreur de validation signale la carte grise obligatoire
And la NDF reste en brouillon
```

### Scenario 8 — Isolation tenant

```gherkin
Given une ligne km créée dans l'asso A (sous-cat flaggée dans A)
And l'asso B a aussi une sous-cat flaggée pour_frais_kilometriques
When un Tiers B consulte ses NDF
Then il ne voit aucune ligne de l'asso A
And la résolution de sous-cat au save d'une NDF B n'utilise que les flags de B
```

## 3. Architecture Notes

### 3.1 Migrations

Une seule migration ajoute les trois colonnes nécessaires :

```php
Schema::table('notes_de_frais_lignes', function (Blueprint $table) {
    $table->string('type', 20)->default('standard')->after('seance_id');
    $table->json('metadata')->nullable()->after('piece_jointe_path');
});

Schema::table('sous_categories', function (Blueprint $table) {
    $table->boolean('pour_frais_kilometriques')->default(false)->after('pour_inscriptions');
});
```

Pas d'index sur `type` en v0 (volume faible, recherche marginale). Rollback propre via `down()`.

### 3.2 Enum et modèle

```php
// app/Enums/NoteDeFraisLigneType.php
enum NoteDeFraisLigneType: string
{
    case Standard = 'standard';
    case Kilometrique = 'kilometrique';
}

// app/Models/NoteDeFraisLigne.php — ajouts
protected $fillable = [..., 'type', 'metadata'];

protected function casts(): array
{
    return [
        'montant' => 'decimal:2',
        'seance' => 'integer',
        'type' => NoteDeFraisLigneType::class,
        'metadata' => 'array',
    ];
}
```

Le modèle `SousCategorie` expose le flag via fillable et cast bool.

### 3.3 Strategy pattern

```php
// app/Services/NoteDeFrais/LigneTypes/LigneTypeInterface.php
interface LigneTypeInterface
{
    public function key(): NoteDeFraisLigneType;

    /** @param array<string,mixed> $draft */
    public function validate(array $draft): void;  // throws ValidationException

    /** @param array<string,mixed> $draft */
    public function computeMontant(array $draft): float;

    /** @param array<string,mixed> $draft @return array<string,mixed> */
    public function metadata(array $draft): array;

    /** @param array<string,mixed> $metadata */
    public function renderDescription(array $metadata): string;

    public function resolveSousCategorieId(?int $requestedId): ?int;
}
```

Implémentations v0 :
- `StandardLigneType` : `computeMontant` retourne le montant saisi, `metadata` retourne `[]`, `renderDescription` retourne `''`, `resolveSousCategorieId` retourne l'id saisi (inchangé).
- `KilometriqueLigneType` : `computeMontant = round(distance × bareme, 2)`, `metadata = ['cv_fiscaux' => ..., 'distance_km' => ..., 'bareme_eur_km' => ...]`, `renderDescription` formate la phrase comptable, `resolveSousCategorieId` lit `SousCategorie::where('pour_frais_kilometriques', true)->get()` — si count==1, retourne l'id ; sinon null.

Registre simple (array ou container binding) :

```php
// app/Services/NoteDeFrais/LigneTypes/LigneTypeRegistry.php
final class LigneTypeRegistry
{
    public function for(NoteDeFraisLigneType $type): LigneTypeInterface { /* ... */ }
}
```

### 3.4 Service — intégration dans `NoteDeFraisService::saveDraft`

Pour chaque ligne du payload, le service :
1. Récupère la strategy via `$registry->for($ligne['type'])`.
2. Appelle `validate()` sur le draft.
3. Calcule `montant = $strategy->computeMontant($ligne)`.
4. Stocke `metadata = $strategy->metadata($ligne)`.
5. Résout `sous_categorie_id = $strategy->resolveSousCategorieId($ligne['sous_categorie_id'] ?? null)`.

Le montant client est **toujours ignoré** pour les types où la strategy calcule (sécurité anti-tampering).

### 3.5 Service — intégration dans Back-office `ValidationService`

Au moment de créer la Transaction :

```php
$transactionLigne = TransactionLigne::create([
    ...
    'montant' => $ndfLigne->montant,
    'notes' => $registry->for($ndfLigne->type)->renderDescription($ndfLigne->metadata ?? []),
    'piece_jointe_path' => $this->copyPieceJointe($ndfLigne),
]);
```

Pour `StandardLigneType`, `renderDescription` renvoie `''` → comportement identique à aujourd'hui (pas de régression).

### 3.6 Composant Livewire — Form portail

Le wizard actuel devient l'un des deux wizards. Introduire un champ `wizardType` (string : `'standard'` ou `'kilometrique'`) :

- `openLigneWizard()` → `openStandardWizard()`, wizard 3 étapes inchangé.
- `openKilometriqueWizard()` → wizard 2 étapes nouveau.

`draftLigne` étendue avec champs optionnels (`cv_fiscaux`, `distance_km`, `bareme_eur_km`). Méthode `getDraftMontantComputedProperty()` : pour type km, renvoie `round(distance × bareme, 2)` pour affichage temps réel.

La méthode `wizardConfirm()` du wizard km stocke la ligne avec `type = 'kilometrique'` et les trois champs km sur `draftLigne`. `buildData()` propage le `type` et les champs vers le service.

### 3.7 Template Form — deux boutons

```blade
<div class="d-flex gap-2">
    <button type="button" wire:click="openStandardWizard" class="btn btn-outline-primary">
        <i class="bi bi-plus"></i> Ajouter une ligne de frais
    </button>
    <button type="button" wire:click="openKilometriqueWizard" class="btn btn-outline-primary">
        <i class="bi bi-plus"></i> Ajouter un déplacement
    </button>
</div>
```

### 3.8 Affichage d'une ligne km dans le tableau

Partial Blade conditionnel sur `ligne.type === 'kilometrique'` : badge `<span class="badge bg-info">Km</span>` + sous-ligne `<small class="text-muted d-block">5 CV · 420 km · 0,636 €/km</small>`. Réutilisé dans Form (édition), Show (portail), Show (back-office).

### 3.9 Écran Sous-catégories — colonne Frais km

Ajout dans le composant Livewire existant d'une colonne `Frais kilométriques` avec un `wire:model.live="..."` sur le bool. Inline toggle identique aux autres flags.

## 4. Acceptance Criteria

### Data & modèle
- [ ] Migration `notes_de_frais_lignes` ajoute `type` enum + `metadata` JSON, défaut `standard` / `null`.
- [ ] Migration `sous_categories` ajoute `pour_frais_kilometriques` bool défaut `false`.
- [ ] Enum `NoteDeFraisLigneType` (`Standard`, `Kilometrique`).
- [ ] Cast `type` enum + `metadata` array sur `NoteDeFraisLigne`.
- [ ] Rollback des migrations propre (`down()`).

### Strategy
- [ ] Interface `LigneTypeInterface` avec 6 méthodes spécifiées.
- [ ] `StandardLigneType` — behavior identique à aujourd'hui.
- [ ] `KilometriqueLigneType` :
  - `computeMontant` : arrondi 2 décimales (`round(..., 2)`).
  - `metadata` : `['cv_fiscaux', 'distance_km', 'bareme_eur_km']` castés `int` / `float` / `float`.
  - `renderDescription` : format français `"Déplacement de {km} km avec un véhicule {CV} CV au barème de {bareme} €/km"`, décimales avec virgule.
  - `resolveSousCategorieId` : requête `SousCategorie::where('pour_frais_kilometriques', true)`, count=1 → id, sinon null.
- [ ] Registre `LigneTypeRegistry` résolvant `NoteDeFraisLigneType` → instance.

### Service NDF
- [ ] `NoteDeFraisService::saveDraft` accepte `type` sur chaque ligne, délègue à la strategy pour validate/computeMontant/metadata/resolveSousCategorieId.
- [ ] Montant client ignoré pour type `kilometrique`, recalculé server-side.
- [ ] PJ carte grise stockée selon convention existante `associations/{id}/notes-de-frais/{ndf}/ligne-{id}.{ext}`.

### Service Validation back-office
- [ ] `ValidationService` appelle `renderDescription` et remplit `transaction_lignes.notes`.
- [ ] `transaction_lignes.piece_jointe_path` reçoit la carte grise copiée.
- [ ] Lignes `standard` — `notes` reste vide (pas de régression).

### UI portail
- [ ] Deux boutons côte à côte dans Form : "+ Ajouter une ligne de frais" et "+ Ajouter un déplacement".
- [ ] Wizard km 2 étapes : (1) carte grise obligatoire, (2) libellé + CV + km + barème + opération/séance facultatifs.
- [ ] Champ barème affiche un lien d'aide discret vers impots.gouv.fr (target `_blank`, `rel="noopener noreferrer"`).
- [ ] Montant affiché en temps réel en lecture seule, format français (virgule, 2 décimales, espace insécable avant €).
- [ ] Validation côté wizard : CV 1-50 entier, km numérique >0, barème numérique >0, libellé non vide, carte grise obligatoire (mimes pdf/jpg/png/heic, max 5 Mo).

### Affichage tableau (Form édition + Show portail + Show back-office)
- [ ] Ligne km affiche badge `Km`.
- [ ] Sous-ligne `{CV} CV · {km} km · {bareme} €/km` (format FR).
- [ ] Icône PJ pour la carte grise (comportement PJ ligne existant).
- [ ] Montant aligné avec les lignes standards.

### Paramétrage
- [ ] Écran Sous-catégories : nouvelle colonne/switch "Frais kilométriques".
- [ ] Toggle inline wire:model.live, feedback visuel.

### Tests Pest
- [ ] Unit `KilometriqueLigneType` : validate, computeMontant (arrondi), metadata shape, renderDescription format, resolveSousCategorieId (0/1/2+ sous-cat).
- [ ] Feature wizard km : soumission nominale, carte grise obligatoire, montant recalculé si tampering client.
- [ ] Feature service : metadata JSON stockée, résolution sous-cat.
- [ ] Feature back-office : validation NDF km crée Transaction avec `notes` générée.
- [ ] Feature isolation tenant : ligne et flags asso A invisibles de asso B.

### Documentation
- [ ] `docs/portail-tiers.md` — section "Lignes kilométriques" (saisie, calcul, sous-catégorie, paramétrage flag).

## 5. Forward compatibility — évolutions anticipées

L'architecture `type` + `metadata` + strategy est conçue pour accueillir sans refactor :

- **Frais repas / per diem** : nouveau case `Repas`, nouvelle strategy `RepasLigneType`, plafonds URSSAF en config. Aucune migration.
- **Frais hébergement** : idem, `HebergementLigneType`.
- **Abandon de créance → reçu fiscal CERFA** : orthogonal aux lignes (décidé au niveau NDF, pas ligne). Prévu pour un run dédié — décisions comptables à trancher (engagement vs décaissement, 870/860 vs 754, statut NDF `Abandonnee`, émission du CERFA, rattachement exercice fiscal). Ne touche pas au design actuel.

## 6. Hors scope v0

Voir §1. Rappel des exclusions volontaires :
- Pas de seeder de barème officiel.
- Pas de véhicules persistés sur Tiers.
- Pas d'abandon de créance (run dédié).
- Pas d'autres types normés (repas, hébergement — architecture prête seulement).
- Pas d'export/reporting km.

## 7. Risques et points d'attention

- **Format décimal FR vs EN** : le Tiers saisit `0,636` ou `0.636` ; normaliser avec `str_replace(',', '.', ...)` comme dans `buildData` actuel avant cast.
- **Arrondi** : `round(distance × bareme, 2, PHP_ROUND_HALF_UP)` pour cohérence avec format comptable standard.
- **Carte grise volumineuse** : même limite 5 Mo que les autres justificatifs. Message d'erreur explicite si dépassé.
- **Migration sur prod** : tests de rollback en staging avant merge main.
- **Ordre des migrations** : s'assurer que `2026_04_21_000000_add_piece_jointe_path_to_transaction_lignes.php` (déjà livré) a bien été joué avant l'intégration back-office km.

## 8. Run suivant (non traité ici)

**Abandon de créance → reçu fiscal CERFA** : brainstorm + spec dédiés. Décisions ouvertes à trancher :
- Granularité : NDF entière vs ligne par ligne (préférence : NDF entière, plus simple pour CERFA).
- Méthode comptable : classe 870/860 (contributions volontaires en nature) vs classe 754 (dons manuels).
- Workflow : bouton "Abandonner au profit de l'asso" côté portail, attestation PDF générée, CERFA émis par le comptable à la validation.
- Statut NDF : nouveau `Abandonnee` ou `Validee + type_traitement=Abandon`.
- Reporting : suivi volume abandons par exercice.
