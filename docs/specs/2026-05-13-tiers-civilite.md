# Spec — Tiers : civilité

**Date** : 2026-05-13
**Branche** : `feat/tiers-civilite`

## Décision validée

Un seul champ `civilite` nullable sur `tiers`, enum à 2 valeurs (`M.`, `Mme`) + null.

- Pas de "Mlle" (circulaire 2012)
- Pas de Mx / Autre / Non-binaire (pas d'équivalent administratif FR, le null gère cet usage)
- Pas de champ `genre` ou `sexe` séparé (pas d'usage métier pour une asso, donnée sensible RGPD)
- Pas d'inférence depuis le prénom

## Schéma DB

```sql
ALTER TABLE tiers ADD COLUMN civilite VARCHAR(5) NULL AFTER prenom;
-- Valeurs autorisées (validées côté Eloquent enum cast) : 'M.', 'Mme'
```

## Code

### Enum

`App\Enums\Civilite` :
- `M` = `'M.'`
- `Mme` = `'Mme'`
- `label()` retourne le nom long pour les selects ("Monsieur" / "Madame")

### Modèle `Tiers`

Nouveau cast `civilite => Civilite::class`.

Trois accesseurs dérivés :

| Accesseur | `civilite = M.` | `civilite = Mme` | `civilite = null` |
|---|---|---|---|
| `adresse_polie` | Monsieur Dupont | Madame Kurz | Anne Kurz |
| `salutation` | Monsieur | Madame | Madame, Monsieur |
| `civilite_label` | Monsieur | Madame | (vide) |

Pour `adresse_polie`, sur un tiers entreprise (sans civilité applicable), retourner la raison sociale.

### Form fiche tiers

`TiersForm` : ajouter propriété publique `public ?string $civilite = null;` + `<select>` 3 options (vide / M. / Mme) entre les champs prénom et email.

Affichage conditionnel : caché si `type = 'entreprise'`.

### Import CSV tiers

`ImportCsvTiers` : reconnaît une colonne optionnelle `civilite` (ou `civilité`). Valeurs acceptées (insensibles à la casse, accents normalisés) :
- `m.`, `m`, `monsieur` → `M.`
- `mme`, `madame` → `Mme`
- vide, autre → `null` (silencieux, pas d'erreur)

### Templates email — variables disponibles

`CategorieEmail::variables()` : ajouter dans les catégories `Message`, `Attestation`, `Document`, `Communication` :
- `{civilite}` → label long (Monsieur / Madame / vide)
- `{adresse_polie}` → Madame Kurz / Monsieur Dupont / Anne Kurz
- `{salutation}` → Madame / Monsieur / Madame, Monsieur

Pas dans `Formulaire` (variables différentes côté participant prospect).

## Hors scope

- Mise à jour automatique des PDFs existants (attestations, reçus, factures) : pas touché. Les variables sont disponibles, l'utilisateur les insère dans ses gabarits TinyMCE au fil de l'eau. Refonte spécifique d'un PDF = slice séparé.
- Backfill des civilités existantes : aucun.
- Statistiques par civilité : non.

## Critères d'acceptation

- ✅ Champ `civilite` éditable sur la fiche tiers (particulier uniquement).
- ✅ Import CSV reconnaît la colonne.
- ✅ Les 3 variables apparaissent dans le picker TinyMCE pour les catégories appropriées.
- ✅ Un tiers sans civilité ne casse aucun template (fallback gracieux partout).
- ✅ Pest 100% vert, Pint clean.
- ✅ Pas de modification des PDFs existants.
