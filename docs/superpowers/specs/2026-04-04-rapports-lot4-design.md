# Lot 4 — Exports sur tous les rapports

**Date :** 2026-04-04
**Statut :** Design validé

## Contexte

Les rapports comptables (lots 1-3) sont fonctionnels à l'écran mais sans possibilité d'export hormis un CSV basique sur le Compte de résultat. Le lot 4 ajoute des exports Excel et PDF sur les 4 rapports, avec un PDF mis en page pour annexe AG (pas copie écran).

## Périmètre

| Rapport | Excel | PDF | CSV |
|---|---|---|---|
| Compte de résultat | ✅ | ✅ portrait | ❌ supprimé (remplacé par Excel) |
| CR par opérations | ✅ | ✅ paysage | ❌ |
| Flux de trésorerie | ✅ | ✅ portrait | ❌ |
| Analyse financière | ✅ | ❌ | ❌ |
| Analyse participants | ✅ | ❌ | ❌ |

## Architecture

### Controller unique

`RapportExportController` avec route :

```
GET /compta/rapports/export/{rapport}/{format}
```

- `{rapport}` ∈ `compte-resultat`, `operations`, `flux-tresorerie`, `analyse-financier`, `analyse-participants`
- `{format}` ∈ `xlsx`, `pdf`
- Filtres transmis en query string (exercice, selectedOperationIds, parSeances, parTiers)
- Le controller valide les paramètres, appelle `RapportService` pour les données, et délègue à la méthode d'export appropriée

### Trait Livewire `HasRapportExport`

`app/Livewire/Concerns/HasRapportExport.php`

- Propriété `$exportFormats` déclarée par chaque composant (ex. `['xlsx', 'pdf']`)
- Méthode `exportUrl(string $format): string` — construit l'URL vers le controller avec les query params actuels du composant (exercice, filtres, toggles)

### Trait Controller `ResolvesLogos`

`app/Http/Controllers/Concerns/ResolvesLogos.php`

Extraction de la méthode `resolveLogos()` dupliquée dans 5+ controllers. Pour les rapports comptables, uniquement le logo asso en en-tête (pas de logo type opération).

## Layout PDF partagé

`resources/views/pdf/rapport-layout.blade.php`

### En-tête

Identique aux PDF participants existants :
- Logo association à gauche (max 96×192px)
- Nom + adresse association
- Titre du rapport à droite (couleur `#A9014F`)
- Sous-titre (exercice, filtres actifs)

### Pied de page

`position: fixed; bottom: 5mm` — ancré en bas de chaque page indépendamment du contenu.

| Bas-gauche | Centre | Bas-droite |
|---|---|---|
| `config('app.name')` | page / pages (`counter(page) " / " counter(pages)`) | Généré le dd/mm/YYYY à HH:ii |

Police : `font-size: 9px; color: #999`.

### Contenu

`@yield('content')` — chaque rapport fournit son propre HTML dans une vue Blade dédiée qui `@extends('pdf.rapport-layout')`.

### Variables du layout

`$title`, `$subtitle`, `$orientation` (portrait/landscape), `$headerLogoBase64`, `$headerLogoMime`, `$association`.

## Détail par rapport

### Compte de résultat

**PDF (portrait)** :
- Section "Charges" puis "Produits"
- Ligne catégorie grisée (`#dce6f0`), sous-catégories indentées
- Colonnes : Libellé, N-1, N, Budget, Écart
- Lignes "Total charges" / "Total produits" en gras
- Ligne "Excédent/Déficit" finale (vert positif, rouge négatif)

**Excel** :
- En-tête : Type | Catégorie | Sous-catégorie | N-1 | N | Budget | Écart
- Lignes de totaux en gras
- Colonnes montant formatées en nombre
- Auto-sizing des colonnes

### CR par opérations

**PDF (paysage)** — reflète les filtres actifs :
- Sous-titre indiquant les opérations sélectionnées
- Ligne catégorie = sous-total (grisé, gras)
- Lignes sous-catégories indentées
- Si `parTiers` : lignes tiers indentées sous chaque sous-catégorie
- Si `parSeances` : colonnes HS, S1, S2, ..., Total — sous-totaux par catégorie sur chaque colonne
- Si ni l'un ni l'autre : colonnes Libellé, Montant
- Lignes "Total charges" / "Total produits" + "Résultat net"

**Excel** : même structure hiérarchique avec sous-totaux, colonnes dynamiques selon toggles, auto-sizing.

### Flux de trésorerie

**PDF (portrait)** — trois blocs :
1. **Synthèse** : solde ouverture, total recettes, total dépenses, variation, solde théorique
2. **Détail mensuel** : tableau mois par mois (toujours déplié, pas de toggle)
3. **Rapprochement** : écritures non pointées + comptes système → solde réel

**Excel** — deux onglets :
- Onglet "Synthèse + Mensuel" : une ligne par mois avec recettes/dépenses/cumul
- Onglet "Rapprochement" : écritures non pointées et comptes système
- Auto-sizing des colonnes

### Analyse financière / participants (Excel uniquement)

Export des données brutes du pivot :
- Mode `financier` : Opération, Type opération, Séance n°, Tiers, Date, Montant, Sous-catégorie, Catégorie, Type, Compte, Mois, Trimestre, Semestre
- Mode `participants` : Opération, Type opération, Séance, Date séance, Nom, Prénom, Ville, Date inscription, Mode paiement, Montant prévu, Présence

Données brutes que l'utilisateur peut pivoter librement dans Excel.

## Bouton d'export

### Rapports avec Excel + PDF

Dropdown Bootstrap "Exporter" dans la barre de filtres, à droite, à côté du sélecteur d'exercice :

```
[Exercice: 2025 ▾]                    [Exporter ▾]
                                        ├─ Excel
                                        └─ PDF
```

- PDF : `target="_blank"` (stream dans le navigateur)
- Excel : téléchargement direct

### Analyse pivot

Lien direct "Exporter en Excel" avec icône `bi-file-earmark-spreadsheet` (pas de dropdown pour une seule option).

### Migration CR existant

Le bouton CSV du Compte de résultat est remplacé par le dropdown unifié. La méthode `exportCsv()` du composant Livewire est supprimée.

## Nommage des fichiers

Convention : `{Asso} - {Rapport} {Exercice}.{ext}`

| Rapport | Exemple |
|---|---|
| Compte de résultat | `SVS - Compte de resultat 2025-2026.xlsx` / `.pdf` |
| CR par opérations | `SVS - CR par operations 2025-2026.xlsx` / `.pdf` |
| Flux de trésorerie | `SVS - Flux de tresorerie 2025-2026.xlsx` / `.pdf` |
| Analyse financière | `SVS - Analyse financiere 2025-2026.xlsx` |
| Analyse participants | `SVS - Analyse participants 2025-2026.xlsx` |

Nom de l'asso via `Str::ascii($association->nom)` pour retirer les accents (compatibilité maximale).

## Icônes menu Rapports

Ajout des icônes manquantes dans le dropdown "Rapports" de la navbar :

| Entrée | Icône |
|---|---|
| Compte de résultat | `bi-journal-text` |
| CR par opérations | `bi-diagram-3` |
| Flux de trésorerie | `bi-cash-stack` (existant) |
| Analyse financière | `bi-graph-up` (existant) |

## Fichiers à créer

- `app/Http/Controllers/RapportExportController.php`
- `app/Livewire/Concerns/HasRapportExport.php`
- `app/Http/Controllers/Concerns/ResolvesLogos.php`
- `resources/views/pdf/rapport-layout.blade.php`
- `resources/views/pdf/rapport-compte-resultat.blade.php`
- `resources/views/pdf/rapport-operations.blade.php`
- `resources/views/pdf/rapport-flux-tresorerie.blade.php`

## Fichiers à modifier

- `routes/web.php` — route d'export
- `app/Livewire/RapportCompteResultat.php` — trait, supprimer `exportCsv()`
- `app/Livewire/RapportCompteResultatOperations.php` — trait
- `app/Livewire/RapportFluxTresorerie.php` — trait
- `app/Livewire/AnalysePivot.php` — lien Excel direct
- Vues Blade des 4 rapports — bouton/dropdown d'export
- `resources/views/layouts/app.blade.php` — 2 icônes menu
- Controllers PDF existants — migrer vers trait `ResolvesLogos`

## Packages

Aucun à ajouter. Déjà installés :
- `barryvdh/laravel-dompdf` (PDF)
- `phpoffice/phpspreadsheet` (Excel)
