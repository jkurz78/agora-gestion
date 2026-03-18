# Spec — Améliorations ergonomie rapprochement bancaire

**Date :** 2026-03-18
**Statut :** Approuvé par l'utilisateur
**Scope :** `RapprochementDetail`, `RapprochementPdfController`

---

## Contexte

Le rapprochement bancaire permet de pointer les écritures d'un compte bancaire par rapport au relevé bancaire reçu. L'écran de détail (`RapprochementDetail`) affiche toutes les écritures éligibles avec des cases à cocher, calcule le solde pointé et l'écart. Sept améliorations ergonomiques ont été identifiées par l'utilisateur.

---

## Point 1 — Champs date de fin et solde de fin modifiables

### Problème
Une fois un rapprochement créé, il n'est plus possible de corriger la date ou le solde de fin sans tout supprimer et recommencer. Une erreur de saisie (1 centime, mauvaise date) oblige à repointer toutes les écritures.

### Solution
Dans `RapprochementDetail`, passer les champs "date de fin" et "solde de fin" en champs `<input>` éditables directement dans l'en-tête, uniquement quand le statut est `en_cours`.

**Mécanisme de sauvegarde :**
- Utiliser `wire:model.blur` : la sauvegarde en base se déclenche automatiquement à la perte de focus (pas de bouton "Enregistrer")
- Après chaque sauvegarde, le solde pointé et l'écart sont recalculés immédiatement
- Ces champs sont en lecture seule si le statut est `verrouille`
- Aucune restriction d'autorisation : tout utilisateur authentifié peut modifier (cohérent avec le comportement global du projet)

**Contraintes de validation — date de fin :**
- La date doit être ≥ à la `date_fin` du rapprochement verrouillé le plus récent pour ce compte, déterminé par `orderByDesc('date_fin')->orderByDesc('id')` (même logique que `calculerSoldeOuverture` dans le service)
- Si aucun rapprochement verrouillé n'existe pour ce compte, la date est libre
- Une date invalide est signalée par un message d'erreur inline ; la valeur en base n'est pas modifiée

**Contraintes de validation — solde de fin :**
- Numérique, 2 décimales max

**Comportement sur changement de date de fin :**
- Les écritures déjà pointées dans ce rapprochement restent pointées même si leur date devient postérieure à la nouvelle `date_fin` (cohérent avec le fait que le tableau affiche toujours les écritures pointées, quelle que soit leur date)
- Les écritures non pointées dont la date dépasse la nouvelle `date_fin` disparaissent du tableau (car la requête filtre `date <= date_fin` pour les non-pointées)
- Ce comportement est intentionnel et ne nécessite pas d'action automatique de dé-pointage

---

## Point 2 — Totaux débits et crédits pointés

### Problème
Les relevés bancaires affichent le total des débits et le total des crédits de la période. L'absence de ces totaux dans l'écran de rapprochement rend difficile la détection d'erreurs de pointage.

### Solution
Ajouter deux nouvelles cards dans le dashboard de soldes en haut de l'écran de détail :

- **Total débits pointés** : somme des montants des dépenses + virements sortants pointés dans ce rapprochement
- **Total crédits pointés** : somme des montants des recettes + dons + cotisations + virements entrants pointés dans ce rapprochement

Ces totaux se recalculent en temps réel à chaque toggle de pointage, comme le solde pointé existant.

**Implémentation :**
- Calculés dans `render()` de `RapprochementDetail` en itérant la collection de transactions déjà chargée (pas de nouvelle méthode dans `RapprochementBancaireService`, pas de requête supplémentaire)
- `RapprochementBancaireService` n'est pas modifié

---

## Point 3 — Masquer les écritures pointées

### Problème
Quand le nombre d'écritures est important, les lignes déjà pointées encombrent l'écran et rendent difficile la navigation parmi les écritures restant à pointer.

### Solution
Ajouter une case à cocher dans l'en-tête du tableau de transactions :

> ☐ Masquer les écritures pointées

**Comportement :**
- Géré par une propriété Livewire `$masquerPointees` (booléen, `false` par défaut)
- État éphémère : réinitialisé à `false` à chaque rechargement de page (non persisté en session ni en URL)
- Quand cochée : les lignes dont `pointe = true` sont filtrées côté Livewire et n'apparaissent plus dans le tableau
- **Effet de bord intentionnel :** quand la case est cochée, pointer une écriture la fait immédiatement disparaître du tableau (réactivité Livewire normale)
- Quand décochée : toutes les écritures sont à nouveau affichées (pointées et non pointées)
- La case n'est disponible que sur l'écran de détail ; elle n'affecte pas l'export PDF

---

## Point 4 — Nommage du fichier PDF téléchargé

### Problème
Le fichier PDF téléchargé a un nom générique peu lisible.

### Solution
Modifier le header `Content-Disposition` dans `RapprochementPdfController` pour nommer le fichier :

```
<NomAssociation> - Rapprochement <NomCompte> au <DateFin>.pdf
```

Exemple : `SVS - Rapprochement Compte courant BNP au 2026-03-31.pdf`

**Détails :**
- `NomAssociation` : `Association::find(1)->nom` (modèle `Association`, table `association`) ; si l'enregistrement est absent (`find(1)` retourne null), le préfixe est omis et le fichier se nomme `Rapprochement <NomCompte> au <DateFin>.pdf`
- `NomCompte` : champ `nom` du `CompteBancaire` lié au rapprochement (le champ s'appelle `nom`, pas `libelle`)
- `DateFin` : format `Y-m-d` (ISO, pour éviter les problèmes de nommage cross-OS)
- Le nom est sanitisé avec `Str::ascii()` (translittération des accents en ASCII) puis les `/` remplacés par `-`, pour garantir la compatibilité cross-browser avec le header `Content-Disposition`

---

## Point 5 — Bouton "Ouvrir" le PDF dans le navigateur

### Problème
Le bouton actuel déclenche un téléchargement. Il n'est pas possible d'ouvrir le PDF directement dans le navigateur pour impression immédiate sans sauvegarde locale.

### Solution
Ajouter un second bouton "Ouvrir" à côté du bouton "Télécharger" existant.

**Implémentation — paramètre `?mode=inline` :**
- Le contrôleur existant vérifie `request()->query('mode') === 'inline'`
- Si `inline` : header `Content-Disposition: inline` → le navigateur affiche le PDF dans un nouvel onglet
- Si absent (défaut) : header `Content-Disposition: attachment` → comportement actuel de téléchargement
- Aucune nouvelle route : seul `routes/web.php` garde sa route existante `rapprochement.pdf`
- Le lien "Ouvrir" dans la vue ajoute `?mode=inline` à l'URL et utilise `target="_blank"`
- Le bouton "Télécharger" existant est conservé tel quel

---

## Point 6 — Colonne ID dans le tableau des transactions

### Problème
L'utilisateur ne peut pas faire le lien entre une ligne du tableau de rapprochement et la même écriture visible sur l'écran des transactions du compte, ni reporter un identifiant unique sur l'extrait de compte papier.

### Solution
Ajouter une colonne **"#"** en première position dans le tableau des transactions du détail de rapprochement, affichant l'`id` de chaque enregistrement en base de données.

**Détails :**
- L'`id` est celui du modèle source (`Depense.id`, `Recette.id`, `Don.id`, `Cotisation.id`, `VirementInterne.id`)
- Cohérent avec l'`id` affiché sur l'écran `transaction-compte-list`
- Colonne présente à l'écran ET dans l'export PDF

---

## Point 7 — Colonne Tiers dans le tableau des transactions

### Problème
Le tableau de rapprochement n'affiche pas le tiers (bénéficiaire, membre, compte opposé), ce qui oblige à naviguer vers les détails d'une écriture pour l'identifier.

### Solution
Ajouter une colonne **"Tiers"** dans le tableau des transactions du détail de rapprochement, en suivant exactement le même pattern que la colonne Tiers de `transaction-compte-list`.

**Règles d'affichage par type :**
- `Depense` / `Recette` : champ `beneficiaire` ; si `beneficiaire` est null ou vide, afficher `libelle` à la place
- `Don` : `$don->tiers->displayName()` (relation `tiers()` sur le modèle `Don`)
- `Cotisation` : `$cotisation->tiers->displayName()` (relation `tiers()` sur le modèle `Cotisation`)
- `VirementInterne` (source) : libellé (`nom`) du compte destination
- `VirementInterne` (destination) : libellé (`nom`) du compte source

**Attention — eager-loading à corriger :**
Le code existant dans `RapprochementDetail` et `RapprochementPdfController` charge les cotisations avec `->with('membre')`, or la relation `membre()` n'existe pas sur le modèle `Cotisation`. Remplacer par `->with('tiers')` dans les deux fichiers.

**Détails :**
- Colonne présente à l'écran ET dans l'export PDF
- Affichage en texte simple, sans lien cliquable

---

## Fichiers impactés

| Fichier | Modification |
|---|---|
| `app/Livewire/RapprochementDetail.php` | Points 1, 2, 3, 6, 7 |
| `resources/views/livewire/rapprochement-detail.blade.php` | Points 1, 2, 3, 6, 7 |
| `app/Http/Controllers/RapprochementPdfController.php` | Points 4, 5, 6, 7 |
| `resources/views/pdf/rapprochement.blade.php` | Points 4, 5, 6, 7 — les largeurs de colonnes du tableau PDF devront être rééquilibrées pour intégrer les colonnes "#" et "Tiers" |
| `routes/web.php` | Point 5 — aucun changement de route, paramètre `?mode=inline` seulement |

---

## Comportements non modifiés

- Le flux de création du rapprochement (écran liste) n'est pas modifié
- La logique de verrouillage/déverrouillage n'est pas modifiée
- `RapprochementBancaireService` n'est pas modifié
- La table `rapprochements_bancaires` n'est pas modifiée (pas de migration nécessaire)
