# Batch A — Identité visuelle & Navigation — Design

**Date :** 2026-03-12
**Branche :** staging

---

## Contexte

Trois évolutions liées à l'identité visuelle et à la navigation principale :

1. **#3** — Renommer l'application : "SVS Comptabilité" → "Soigner•Vivre•Sourire" (ligne 1) + "Comptabilité" (ligne 2)
2. **#4** — Ajouter le logo de l'association
3. **#5** — Supprimer "Tableau de bord" du menu principal (le dashboard reste accessible via le logo/nom)

---

## 1. Navbar (`layouts/app.blade.php`)

### Rendu visuel

```
┌─────────────────────────────────────────────────────────────────────┐
│ [logo 45px]  Soigner•Vivre•Sourire   Dépenses  Recettes  ...   👤  │
│              Comptabilité                                            │
└─────────────────────────────────────────────────────────────────────┘
```

### Détails

- Le bloc brand Bootstrap (`<a class="navbar-brand">`) contient :
  - Image `<img src="{{ asset('images/logo.png') }}" alt="Soigner•Vivre•Sourire" height="45">`
  - Texte sur 2 lignes avec `<span class="d-inline-block lh-1">` :
    - Ligne 1 : `Soigner•Vivre•Sourire` (taille normale)
    - Ligne 2 : `Comptabilité` (légèrement plus petit, `fs-6` ou `small`)
- Le bloc brand reste un lien `href="{{ route('dashboard') }}"` → accès au tableau de bord
- `"Tableau de bord"` est **supprimé** du tableau `$navItems` (9 entrées restantes)
- `<title>` → `"Soigner•Vivre•Sourire Comptabilité"`

---

## 2. Page de connexion (`layouts/guest.blade.php`)

### Rendu visuel

```
        [logo 100px centré]
     Soigner•Vivre•Sourire       ← <h2>
           Comptabilité           ← <p class="text-muted">
    ┌──────────────────────┐
    │  Email               │
    │  Mot de passe        │
    │  [Connexion]         │
    └──────────────────────┘
```

### Détails

- Remplace l'actuelle `<h2><i class="bi bi-journal-bookmark-fill"></i> SVS Comptabilité</h2>`
- Logo centré : `<img src="{{ asset('images/logo.png') }}" alt="Soigner•Vivre•Sourire" height="100" class="mb-3">`
- Titre : `<h2 class="mb-0">Soigner•Vivre•Sourire</h2>`
- Sous-titre : `<p class="text-muted mb-4">Comptabilité</p>`
- `<title>` → `"Soigner•Vivre•Sourire Comptabilité - Connexion"`

---

## 3. Asset logo

- Chemin : `public/images/logo.png`
- À copier depuis le filesystem local avant l'implémentation
- Référencé via `asset('images/logo.png')` dans les deux layouts
- Alt text : `"Soigner•Vivre•Sourire"`

---

## Fichiers à modifier

| Action   | Fichier |
|----------|---------|
| Modifier | `resources/views/layouts/app.blade.php` |
| Modifier | `resources/views/layouts/guest.blade.php` |
| Créer    | `public/images/logo.png` (copie manuelle) |
