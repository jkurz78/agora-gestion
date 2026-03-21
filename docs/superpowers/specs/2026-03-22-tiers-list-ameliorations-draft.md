# Tiers — Améliorations écran liste (draft)

**Date :** 2026-03-22  
**Statut :** Réflexion préliminaire — à affiner avant planification  
**Contexte :** Suite naturelle de `2026-03-21-tiers-restructuration`. Maintenant que le modèle est enrichi, la liste doit en tirer parti.

---

## 1. Icônes à la place des badges type

### Situation actuelle
Un badge Bootstrap `bg-secondary` affiche "Entreprise" ou "Particulier" — occupe beaucoup de place, peu d'info visuelle.

### Proposition
Remplacer par les icônes déjà utilisées dans `TiersAutocomplete` et `tiers-autocomplete.blade.php` :

| Type | Icône | Déjà présente dans |
|---|---|---|
| `particulier` | 👤 | autocomplete, selected state |
| `entreprise` | 🏢 | autocomplete, selected state |

Avantages : cohérence visuelle, gain de place, lecture instantanée.

---

## 2. Badge HelloAsso dans la liste

### Proposition
Même badge violet **HA** que dans le formulaire d'édition, affiché à côté du nom si `helloasso_id` non null.

Déjà implémenté dans `tiers-list.blade.php` (commit de la session du 2026-03-22).  
→ Rien à faire sur ce point, c'est en place.

---

## 3. Colonnes à compléter

### Situation actuelle
| Nom | Type | Email | Téléphone | Dép. | Rec. | Actions |

### Colonnes supplémentaires à envisager

| Colonne | Source | Pertinence |
|---|---|---|
| Ville | `ville` | Utile pour distinguer des homonymes |
| Entreprise | `entreprise` | Affiché pour les contacts entreprise (raison sociale) |
| Date de naissance | `date_naissance` | Peu utile en liste, réservée au détail |

**Recommandation :** Ajouter `ville` uniquement — léger, utile pour la déduplication visuelle. `entreprise` est déjà dans `displayName()` pour les tiers de type entreprise.

### Affichage du nom selon le type

Actuellement `displayName()` retourne :
- particulier → `"Prénom Nom"`
- entreprise → `"Raison sociale"` (avec fallback sur `nom`)

Le contact (nom/prénom de la personne chez l'entreprise) n'est pas visible en liste.  
**Option :** sous le nom principal, afficher en petit le contact si renseigné :

```
🏢 ACME Corp
   Jean Dupont          ← sous-ligne grisée si nom/prénom contact non vides
```

---

## 4. Recherche et filtres

### Situation actuelle
- Champ texte libre (recherche sur `nom` et `prenom`)
- Select filtre : Tous / Dépenses / Recettes

### Lacunes
- La recherche ne couvre pas `entreprise` (raison sociale) — un tiers "ACME Corp" ne ressort pas si on tape "ACME"
- Pas de filtre sur la présence d'un `helloasso_id`

### Corrections immédiates (peu de travail)

**1. Élargir la recherche textuelle** à `entreprise`, `ville`, `email` :
```php
$query->where(function ($q) {
    $q->where('nom', 'like', "%{$this->search}%")
      ->orWhere('prenom', 'like', "%{$this->search}%")
      ->orWhere('entreprise', 'like', "%{$this->search}%")
      ->orWhere('ville', 'like', "%{$this->search}%")
      ->orWhere('email', 'like', "%{$this->search}%");
});
```

**2. Filtre HelloAsso** : checkbox "Avec HelloAsso uniquement" → `->whereNotNull('helloasso_id')`

### QBE (Query By Example) — à peser

L'écran universel transactions justifiait le QBE par le volume et la diversité des critères (date, montant, compte, catégorie, type...).

Pour les tiers, le volume est plus faible et les critères moins nombreux. **Verdict :** les deux corrections immédiates ci-dessus couvrent 95% des besoins sans la complexité du QBE. À reconsidérer si le nombre de tiers dépasse quelques centaines post-import HelloAsso.

---

## 5. Tri des colonnes

### Situation actuelle
Pas de tri cliquable sur la liste tiers (trié par `nom` ASC en dur).

### Proposition
Tri côté serveur (pattern déjà utilisé sur d'autres écrans) sur :
- Nom / raison sociale (`displayName` est calculé, donc tri sur `nom` + `entreprise`)
- Ville
- Type

Note : tri sur `displayName()` impossible côté SQL (méthode PHP). Pour les entreprises, trier sur `entreprise ?? nom` nécessite un `COALESCE` ou un tri applicatif — à considérer.

---

## 6. Récapitulatif des chantiers par ordre de priorité

| # | Chantier | Effort | Impact |
|---|---|---|---|
| 1 | Icônes à la place des badges type | XS | Cohérence UI |
| 2 | Élargir recherche à `entreprise`/`ville`/`email` | XS | Fonctionnel immédiat |
| 3 | Filtre "HelloAsso uniquement" | XS | Prépare import |
| 4 | Afficher sous-ligne contact pour entreprise | S | Lisibilité |
| 5 | Colonne `ville` | XS | Déduplication visuelle |
| 6 | Tri cliquable | S | Confort |
| 7 | QBE complet | M | Probablement pas nécessaire |

