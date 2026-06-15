# Projection et Opérations en colonnes — CR par opérations

**Date** : 2026-06-15
**Branche** : `main` (V4 production)
**Composant** : `RapportCompteResultatOperations` (Livewire)

## 1. Contexte

Le compte de résultat par opérations (`RapportCompteResultatOperations`) affiche actuellement un toggle "Montants prévisionnels" qui ajoute 3 sous-lignes (prévu / réalisé / écart) sous chaque cellule. Ce mode **Comparaison** est utile mais ne répond pas au besoin de projection de fin d'exercice : mélanger du réel déjà saisi et du prévisionnel pour les séances à venir, afin d'obtenir un total projeté.

Par ailleurs, quand plusieurs opérations sont sélectionnées, leurs montants sont cumulés. Il manque la possibilité d'afficher une colonne par opération pour comparer leurs résultats côte à côte.

Le composant est utilisé dans deux contextes :
- **Onglet CR d'une opération** (`OperationDetail`, ligne 149) — opération unique, `selectedOperationIds` = 1 élément
- **Page Rapports** — multi-sélection d'opérations

## 2. Résumé des changements

| # | Changement | Impact |
|---|-----------|--------|
| A | Dropdown "Mode" remplace le toggle `previsionnel` | Livewire + Blade + URL + exports |
| B | Mode Projection : mix réel/prévu par cellule | Builder + Blade + exports |
| C | Code couleur projection + séances | Blade uniquement |
| D | Toggle "Opérations en colonnes" | Builder + Livewire + Blade + exports |

## 3. Dropdown "Mode" (changement A)

### 3.1 Remplacement du toggle

Le toggle switch `previsionnel` (boolean) est remplacé par un `<select>` Bootstrap :

| Valeur URL (`mode=`) | Label IHM | Comportement |
|----------------------|-----------|-------------|
| `realise` | Réalisé | Montants réels uniquement (comportement actuel `previsionnel=false`) |
| `comparaison` | Comparaison | Prévu + réalisé + écart empilés (comportement actuel `previsionnel=true`) |
| `projection` | Projection | Mix réel/prévu par cellule (nouveau) |

### 3.2 Propriété Livewire

```php
#[Url(as: 'mode')]
public string $mode = 'realise';  // 'realise' | 'comparaison' | 'projection'
```

Remplace `public bool $previsionnel = false;`. Le paramètre URL passe de `prev=0|1` à `mode=realise|comparaison|projection`.

### 3.3 Rétrocompatibilité

Aucune : le paramètre `prev` disparaît. Pas de bookmarks persistants à préserver (usage interne).

## 4. Mode Projection (changement B)

### 4.1 Logique par cellule

Pour chaque cellule (croisement sous-catégorie × séance, ou sous-catégorie × opération, ou sous-catégorie tout court) :

```
si réel > 0 → afficher réel
sinon       → afficher prévu
```

Les totaux (sous-catégorie, catégorie, section) sont la **somme des valeurs projetées** de leurs enfants, pas la somme du réel + la somme du prévu.

### 4.2 Implémentation dans le Builder

`CompteResultatBuilder::compteDeResultatOperations()` reçoit déjà les données réelles et les prévisions séparément. La fusion projection se fait **côté Blade** (ou dans un helper PHP dans la vue), pas dans le Builder. Cela évite de dupliquer la logique de fetch et garde le Builder comme source de données brutes.

Helper de projection (closure Blade) :

```php
$projeter = function (float $realise, float $prevu): float {
    return $realise > 0 ? $realise : $prevu;
};
```

### 4.3 Données nécessaires

Le mode Projection nécessite les mêmes données que le mode Comparaison : réalisé + prévisions. Le Builder est appelé avec `previsionnel: true` quand `mode` ∈ {`comparaison`, `projection`}.

## 5. Code couleur (changement C)

### 5.1 En mode Projection + Séances en colonnes

Chaque cellule est colorée selon sa source :
- **Noir** (`inherit`) : valeur réelle (réel > 0)
- **Bleu** (`#1565C0`) : valeur prévisionnelle (réel = 0, prévu affiché)

Les totaux ne sont pas colorés (ils mélangent les deux sources).

### 5.2 En mode Projection sans séances

Pas de code couleur : la cellule unique affiche un montant total projeté sans distinction de source. Le total est la somme de projections enfant.

### 5.3 En mode Projection + Opérations en colonnes

Pas de code couleur : chaque colonne opération affiche un total projeté.

## 6. Toggle "Opérations en colonnes" (changement D)

### 6.1 Visibilité

Le toggle n'apparaît que dans la **page Rapports** quand `count(selectedOperationIds) > 1`. Dans l'onglet CR d'une opération (opération unique), il est masqué.

### 6.2 Exclusivité mutuelle

"Opérations en colonnes" est mutuellement exclusif avec "Séances en colonnes" et "Tiers en lignes" :

- Activer "Opérations en colonnes" → désactive "Séances en colonnes" et "Tiers en lignes"
- Activer "Séances en colonnes" ou "Tiers en lignes" → désactive "Opérations en colonnes"

### 6.3 Propriété Livewire

```php
#[Url(as: 'parops')]
public bool $parOperations = false;
```

### 6.4 Structure de données

Quand `parOperations = true`, le Builder retourne les données ventilées par opération. Chaque noeud de la hiérarchie contient un dictionnaire `operations` en plus de `montant` :

```php
// Noeud sous-catégorie en mode parOperations
[
    'sous_categorie_id' => 5,
    'label' => 'Hébergement',
    'montant' => 1500.00,           // total toutes opérations
    'operations' => [
        42 => 800.00,               // opération id => montant
        57 => 700.00,
    ],
]
```

### 6.5 En-têtes colonnes

L'en-tête affiche le nom de chaque opération sélectionnée + une colonne "Total" :

```
| Catégorie / Sous-catégorie | Stage été | Weekend ski | Total |
```

Les noms d'opérations sont tronqués à 20 caractères si nécessaire (avec `title` pour le nom complet au survol).

### 6.6 Implémentation dans le Builder

Nouvelle branche dans `compteDeResultatOperations()` quand `parOperations = true` :
- Query GROUP BY `sous_categories.id, operations.id`
- Résultat structuré avec dictionnaire `operations` par noeud
- Liste `operation_names` dans le retour : `[id => nom]` (pour les en-têtes)

## 7. Combinaisons de modes

### 7.1 Matrice des toggles

| Mode | Séances | Tiers | Opérations | Résultat |
|------|---------|-------|------------|----------|
| Réalisé | off | off | off | 1 colonne Montant |
| Réalisé | on | off | off | N colonnes séances + Total |
| Réalisé | on | on | off | N colonnes séances + Total, lignes tiers |
| Réalisé | off | on | off | 1 colonne Montant, lignes tiers |
| Réalisé | off | off | on | N colonnes opérations + Total |
| Comparaison | off | off | off | 3 colonnes Prévu/Réalisé/Écart |
| Comparaison | on | * | off | Cellules empilées (prévu/réalisé/écart) par séance |
| Comparaison | off | off | on | 3 colonnes par opération (Prévu/Réalisé/Écart) + Total |
| Projection | off | off | off | 1 colonne Projeté |
| Projection | on | * | off | N colonnes séances projetées + Total, code couleur |
| Projection | off | off | on | N colonnes opérations projetées + Total |

`*` = Tiers est compatible avec Séances (existant), pas avec Opérations.

### 7.2 Comparaison + Opérations en colonnes

Chaque opération reçoit 3 sous-colonnes (Prévu / Réalisé / Écart) + 3 sous-colonnes Total. L'en-tête est sur 2 niveaux :

```
|              | Stage été          | Weekend ski        | Total              |
|              | Prévu | Réel | Éc. | Prévu | Réel | Éc. | Prévu | Réel | Éc. |
```

### 7.3 Projection + Opérations en colonnes

Chaque opération a 1 colonne de montant projeté + 1 colonne Total. Pas de code couleur (pas de granularité séance).

## 8. Exports

### 8.1 Excel (`xlsxOperations`)

L'export reprend exactement la structure de l'IHM :
- Nouveau paramètre URL `mode=realise|comparaison|projection` (remplace `prev`)
- Nouveau paramètre URL `parops=0|1`
- En mode Projection : les cellules contiennent la valeur projetée (pas de couleur dans Excel)
- En mode Opérations en colonnes : colonnes dynamiques par opération + Total
- En mode Comparaison + Opérations : sous-colonnes Prévu/Réalisé/Écart par opération

### 8.2 PDF

Le PDF est généré via la même vue Blade (route `rapports.export` avec `format=pdf`). Les adaptations sont automatiques car le PDF utilise le même rendu HTML. Le code couleur bleu est conservé en PDF (mode Projection + Séances).

## 9. Fichiers impactés

| Fichier | Nature du changement |
|---------|---------------------|
| `app/Livewire/RapportCompteResultatOperations.php` | Propriétés `mode` + `parOperations`, logique exclusivité, `exportUrl()` |
| `resources/views/livewire/rapport-compte-resultat-operations.blade.php` | Dropdown mode, toggle parOperations, rendu projection, rendu par opérations |
| `app/Services/Rapports/CompteResultatBuilder.php` | Branche `parOperations` dans `compteDeResultatOperations()` |
| `app/Http/Controllers/RapportExportController.php` | Paramètres `mode`/`parops`, adaptation `xlsxOperations()` |
| `resources/views/livewire/operation-detail.blade.php` | Aucun changement (opération unique, pas de toggle ops) |

## 10. Hors périmètre

- Mode Projection appliqué au CR global (hors opérations) — pas de données prévisionnelles à ce niveau
- Saisie des montants prévisionnels (écran encadrement existant, inchangé)
- Adaptation du rapprochement ou du grand livre
- Export CSV

## 11. Critères d'acceptation

1. Le dropdown "Mode" affiche 3 options et persiste en URL (`mode=`)
2. Mode Réalisé : comportement identique à l'existant (`previsionnel=false`)
3. Mode Comparaison : comportement identique à l'existant (`previsionnel=true`)
4. Mode Projection sans séances : 1 colonne "Projeté" = somme des `max(réel, prévu)` par cellule
5. Mode Projection + séances : colonnes séances avec valeur projetée, noir si réel, bleu si prévu
6. Toggle "Opérations en colonnes" visible uniquement si > 1 opération sélectionnée
7. Activer "Opérations en colonnes" désactive "Séances" et "Tiers" (et réciproquement)
8. Mode Réalisé + Opérations : 1 colonne par opération + Total
9. Mode Comparaison + Opérations : 3 sous-colonnes par opération + Total
10. Mode Projection + Opérations : 1 colonne par opération (projeté) + Total
11. Export Excel reflète fidèlement la structure IHM pour toutes les combinaisons
12. Export PDF fonctionne avec toutes les combinaisons
13. L'onglet CR d'une opération unique fonctionne avec les 3 modes (pas de toggle ops)
14. Pas de régression sur les modes existants (tests manuels avant push)
