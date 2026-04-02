# Navigation hiérarchique — Gestion des opérations

**Date** : 2026-04-02
**Contexte** : Feedback négatif des utilisateurs (secrétaire, animateurs) sur l'ergonomie de l'écran "Gestion des opérations". L'interface actuelle (dropdown + onglets sur page unique) est trop dense et déroute les non-informaticiens.

## Problèmes identifiés

- Page blanche avec un sélecteur au démarrage → les utilisateurs pensent voir une liste vide
- Pas de vue d'ensemble des opérations
- Changement d'opération invisible (simple changement de dropdown)
- Pas de repère "où suis-je ?"
- Création/modification d'opération renvoyait vers l'espace Compta → perte de repères (menus différents)

## Principes de la refonte

- **Même fonctionnel, meilleure ergonomie** — aucune fonctionnalité ajoutée ni retirée
- **Navigation hiérarchique à 3 niveaux** avec breadcrumb cliquable
- **Zéro bascule vers l'espace Compta** — tout le CRUD opération reste dans Gestion
- **Chaque niveau a son propre écran** — l'utilisateur sait toujours où il est
- **Bouton "← Retour" explicite** sur les niveaux 2 et 3 en plus du breadcrumb cliquable — le breadcrumb n'est pas un pattern naturel pour les utilisateurs non-techniques

## Les 3 niveaux

### Niveau 1 — Liste des opérations

URL : `GET /gestion/operations`

**Titre** : "Gestion des opérations" (pas de breadcrumb, c'est la racine)

**Barre d'outils** :
- Titre à gauche
- Bouton "+ Nouvelle opération" à droite

**Filtres** :
- Sélecteur d'exercice (ex : "2025/2026")
- Sélecteur de type d'opération (ex : "Tous les types")
- Compteur d'opérations à droite

**Tableau** :

| Colonne | Contenu |
|---------|---------|
| Type | Badge coloré par **sous-catégorie** du type d'opération (2-4 couleurs max : parcours thérapeutiques, formations, sensibilisation, groupes de parole...) |
| Opération | Nom de l'opération |
| Période | Ligne 1 : date contextuelle ("Débute dans 14 jours", "En cours depuis 2 mois", "Terminée depuis 3 mois"). Ligne 2 : dates début — fin en petit gris |
| Participants | Nombre de participants inscrits (badge arrondi) |
| Actions | Icône engrenage pour les paramètres |

**Interactions** :
- Clic sur toute la ligne → ouvre le niveau 2 (détail opération)
- Clic sur l'engrenage → ouvre une modale de paramètres rapides (nom, dates, type). Lien dans la modale vers les réglages avancés (formulaire, tarifs, email)
- Lignes survolées en surbrillance (`hover`)
- Opérations au statut "Clôturée" affichées en opacité réduite (pas basé sur la date)

**Tri** : colonnes triables côté client (JS `data-sort` existant)

### Niveau 2 — Détail d'une opération

URL : `GET /gestion/operations/{operation}` (nouvelle route dédiée)

**Breadcrumb** (taille uniforme 13px) :
```
← Retour   Gestion des opérations / **Parcours Cheval Bleu**  [badge sous-catégorie]  15/09 — 30/06 · 8 participants · 10 séances    ⚙
```
- Bouton "← Retour" explicite à gauche du breadcrumb (retour niveau 1)
- "Gestion des opérations" également cliquable → retour niveau 1
- Segment courant en gras/noir
- Infos contextuelles en petit gris à droite sur la même ligne
- Engrenage à l'extrême droite

**Onglet par défaut** : Participants (c'est l'usage principal des animateurs)

**Onglets** (inchangés) :
- Participants
- Séances
- Règlements
- Compte résultat
- Résultat / séances

**Contenu des onglets** : fonctionnel identique à l'existant.

**Interaction participants** : clic sur un participant dans le tableau → ouvre le niveau 3. Les onglets de l'opération disparaissent — on entre dans le contexte de la fiche participant (onglets propres au participant). Le retour au niveau 2 (via bouton Retour ou breadcrumb) restaure les onglets de l'opération.

### Niveau 3 — Fiche participant

URL : `GET /gestion/operations/{operation}/participants/{participant}` (nouvelle route dédiée)

**Breadcrumb** (taille uniforme 13px) :
```
← Retour   Gestion des opérations / Parcours Cheval Bleu / **Marie Dupont**  06 12 34 56 78 · marie.dupont@email.fr · Plein tarif    [Enregistrer]
```
- Bouton "← Retour" explicite à gauche du breadcrumb (retour niveau 2 — liste des participants)
- Deux premiers segments également cliquables (retour niveau 1 ou 2)
- Segment courant en gras/noir
- Infos du participant en petit gris
- Bouton "Enregistrer" à l'extrême droite

**Bouton Enregistrer** : positionné en haut à droite, en face du breadcrumb. Position fixe indépendante de la hauteur de l'onglet.

**Onglets** (inchangés) :
- Coordonnées
- Données personnelles (si parcours thérapeutique)
- Contacts médicaux (si parcours thérapeutique)
- Adressé par (si prescripteur)
- Notes (si parcours thérapeutique)
- Engagements
- Documents (si documents)
- Historique

**Gestion des modifications non sauvegardées** :
- Le mécanisme Alpine.js `isDirty` existant est conservé
- Le `confirm()` natif est remplacé par une modale Bootstrap avec deux boutons : "Enregistrer" / "Abandonner les modifications"
- La modale se déclenche sur tout clic de navigation (breadcrumb, onglets du niveau 2 si on revient) quand `isDirty === true`
- Le `beforeunload` du navigateur reste en place comme filet de sécurité

## Routage

Nouvelles routes dans l'espace Gestion (les routes Compta pour le CRUD opération restent pour la comptabilité) :

```
GET  /gestion/operations                                          → Liste (niveau 1)
GET  /gestion/operations/{operation}                              → Détail (niveau 2)
GET  /gestion/operations/{operation}/participants/{participant}    → Fiche (niveau 3)
```

Le niveau 1 remplace la route existante `gestion.operations`. Les niveaux 2 et 3 sont de nouvelles routes.

## CRUD opération dans Gestion

- Le bouton "+ Nouvelle opération" ouvre une modale de création (même champs que l'écran Compta : nom, type, dates, nombre de séances)
- L'engrenage ouvre une modale de paramètres rapides
- Plus aucune redirection vers l'espace Compta pour la gestion des opérations

## Couleurs des badges par sous-catégorie

Les badges de type sont colorés selon la sous-catégorie de recettes (`pour_inscriptions = true`) :

| Sous-catégorie | Couleur suggérée |
|---------------|-----------------|
| Parcours thérapeutiques | Bleu (#e8f0fe / #1a56db) |
| Formations | Rose (#fce8f0 / #A9014F) |
| Journées de sensibilisation (futur) | Orange (#fff3e0 / #e65100) |
| Groupes de parole (futur) | Vert (#e8f5e9 / #2e7d32) |

La couleur est dérivée de `typeOperation.sousCategorie`, pas du type d'opération lui-même.

## Hors périmètre

- Gestion des droits animateurs / opérations (pas de filtrage par utilisateur)
- Pagination (une dizaine d'opérations par an)
- Modification du fonctionnel des onglets existants
- Refonte de l'espace Compta
