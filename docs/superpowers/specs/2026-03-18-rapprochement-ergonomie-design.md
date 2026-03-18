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

**Contraintes de validation :**
- Date de fin : doit être ≥ à la date de fin du rapprochement verrouillé précédent sur le même compte (ou libre s'il n'y en a pas)
- Solde de fin : numérique, 2 décimales max

**Comportement :**
- Modification via `wire:model` avec sauvegarde en base au `blur` ou via un bouton "Enregistrer" inline
- Le solde pointé et l'écart se recalculent immédiatement après toute modification du solde de fin
- Ces champs sont en lecture seule si le statut est `verrouille`

---

## Point 2 — Totaux débits et crédits pointés

### Problème
Les relevés bancaires affichent le total des débits et le total des crédits de la période. L'absence de ces totaux dans l'écran de rapprochement rend difficile la détection d'erreurs de pointage.

### Solution
Ajouter deux nouvelles cards (ou une ligne de synthèse) dans le dashboard de soldes en haut de l'écran de détail :

- **Total débits pointés** : somme des montants des dépenses + virements sortants pointés dans ce rapprochement
- **Total crédits pointés** : somme des montants des recettes + dons + cotisations + virements entrants pointés dans ce rapprochement

Ces totaux se recalculent en temps réel à chaque toggle de pointage, comme le solde pointé existant.

---

## Point 3 — Masquer les écritures pointées

### Problème
Quand le nombre d'écritures est important, les lignes déjà pointées encombrent l'écran et rendent difficile la navigation parmi les écritures restant à pointer.

### Solution
Ajouter une case à cocher dans l'en-tête du tableau de transactions :

> ☐ Masquer les écritures pointées

**Comportement :**
- Géré par une propriété Livewire `$masquerPointees` (booléen, `false` par défaut)
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
<NomAssociation> – Rapprochement <NomCompte> au <DateFin>.pdf
```

Exemple : `SVS – Rapprochement Compte courant BNP au 2026-03-31.pdf`

**Détails :**
- `NomAssociation` : récupéré depuis les paramètres de l'association (table `parametres`)
- `NomCompte` : `libelle` du `CompteBancaire` lié au rapprochement
- `DateFin` : format `Y-m-d` (ISO, pour éviter les problèmes de nommage cross-OS)
- Les caractères spéciaux dans les noms sont sanitisés (accents conservés si possible, `/` remplacé par `-`)

---

## Point 5 — Bouton "Ouvrir" le PDF dans le navigateur

### Problème
Le bouton actuel déclenche un téléchargement. Il n'est pas possible d'ouvrir le PDF directement dans le navigateur pour impression immédiate.

### Solution
Ajouter un second bouton "Ouvrir" à côté du bouton "Télécharger" existant.

**Implémentation :**
- Nouvelle route (ou paramètre `?mode=inline`) sur `RapprochementPdfController`
- Quand mode `inline` : header `Content-Disposition: inline` au lieu de `attachment` → le navigateur affiche le PDF dans un nouvel onglet
- Le lien "Ouvrir" s'ouvre avec `target="_blank"`
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
- `Depense` / `Recette` : champ `beneficiaire`
- `Don` : nom du membre donateur (`membre->nom_complet` ou équivalent)
- `Cotisation` : nom du membre cotisant
- `VirementInterne` (source) : libellé du compte destination
- `VirementInterne` (destination) : libellé du compte source

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
| `resources/views/rapprochement/pdf.blade.php` | Points 6, 7 |
| `routes/web.php` | Point 5 (nouvelle route ou paramètre) |

---

## Comportements non modifiés

- Le flux de création du rapprochement (écran liste) n'est pas modifié
- La logique de verrouillage/déverrouillage n'est pas modifiée
- Le calcul du solde pointé et de l'écart (`RapprochementBancaireService`) n'est pas modifié — seule l'affichage des totaux débit/crédit s'y ajoute
- La table `rapprochements_bancaires` n'est pas modifiée (pas de migration nécessaire)
