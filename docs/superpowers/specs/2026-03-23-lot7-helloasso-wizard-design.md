# Lot 7 — Assistant de synchronisation HelloAsso (wizard)

## Objectif

Séparer la configuration HelloAsso (credentials, comptes, sous-catégories) de la synchronisation opérationnelle (mapping formulaires, rapprochement tiers, lancement synchro). Transformer la partie synchronisation en un assistant 3 étapes avec un layout accordéon.

## Contexte

L'écran actuel `/parametres/helloasso` regroupe 4 composants Livewire sur une seule page :
1. `helloasso-form` — Credentials API
2. `helloasso-sync-config` — Comptes + sous-catégories + mapping formulaires
3. `helloasso-tiers-rapprochement` — Rapprochement tiers
4. `helloasso-sync` — Lancement synchronisation

Ce design sépare l'écran en deux et transforme la partie opérationnelle en wizard.

## Répartition des écrans

### Écran Paramètres — "Connexion HelloAsso"

**Route** : `/parametres/helloasso` (inchangée)
**Menu** : Paramètres → Connexion HelloAsso (inchangé)

Conserve :
- Credentials API (`helloasso-form` — inchangé)
- Configuration comptes bancaires + mapping sous-catégories (partie haute de `helloasso-sync-config` — inchangée)

Retire :
- Le tableau mapping formulaires → opérations (déplacé vers le wizard)

### Écran Synchronisation — "Synchronisation HelloAsso"

**Route** : `/banques/helloasso-sync` (nouvelle)
**Menu** : Banques, en bas du dropdown :

```
Banques
├── Rapprochement
├── Virements
├── ───── Divider ─────
├── Synchronisation HelloAsso   ← nouveau (icône bi-arrow-repeat)
├── ───── Divider ─────
└── Comptes bancaires
```

**Composant** : `HelloassoSyncWizard` — un seul composant Livewire avec propriété `$step`.

## Exercice

Pas de sélecteur d'exercice dans le wizard. L'exercice actif est celui de la session, lu via `ExerciceService::current()`, cohérent avec le reste de l'application.

## Layout : accordéon à 3 étapes

Les 3 étapes sont affichées comme des cards empilées. L'étape active est ouverte (bordure accentuée, contenu visible). Les étapes terminées sont repliées avec un résumé compact. Les étapes futures sont grisées.

Cliquer sur une étape terminée la réouvre (sans relancer l'auto-fetch — les données sont déjà chargées).

## Étape 1 — Mapping Formulaires → Opérations

### Comportement

1. **Auto-fetch** au mount du composant : appel API `chargerFormulaires()` pour récupérer les formulaires HelloAsso et upsert les `HelloAssoFormMapping`
2. **Filtre** : afficher uniquement les formulaires dont `date_fin` est `null` OU `>= date début de l'exercice courant`. Les anciens formulaires avec un mapping restent en base mais ne s'affichent plus.
3. **Tableau** : titre, type, période, état, dropdown opération (valeur par défaut "Ne pas suivre")
4. **Bouton "+" créer opération** : à côté du dropdown, ouvre un formulaire inline ou modal avec les champs pré-remplis depuis le formulaire HelloAsso :
   - `nom` ← titre du formulaire
   - `date_debut` ← date début du formulaire
   - `date_fin` ← date fin du formulaire
   - `description`, `nombre_seances`, `statut` ← valeurs par défaut
   - L'opération créée est automatiquement sélectionnée dans le dropdown
5. **Bouton "Suite →"** : sauvegarde les mappings et passe à l'étape 2

### Résumé replié

Exemple : "8 formulaires, 3 mappés"

## Étape 2 — Rapprochement des Tiers

### Comportement

1. **Auto-fetch** à l'ouverture de l'étape : appel API pour récupérer les tiers HelloAsso de l'exercice
2. **Affichage** : uniquement les tiers **non liés**. La liste des tiers déjà liés est supprimée (pas de valeur ajoutée sans possibilité de délier).
3. Pour chaque tiers non lié :
   - Nom + email HelloAsso
   - Autocomplete pour lier à un tiers existant + bouton "Associer"
   - Bouton "Créer depuis HelloAsso" (crée un tiers et lie automatiquement)
4. Si tous les tiers sont liés : message "Tous les tiers HelloAsso sont déjà associés"
5. **Bouton "Lancer la synchronisation"** : passe à l'étape 3 ET déclenche la synchro

### Résumé replié

Exemple : "3 tiers à lier" ou "Tous les tiers liés"

## Étape 3 — Synchronisation

### Comportement

1. La synchro est **déclenchée automatiquement** par le bouton de l'étape 2
2. Spinner pendant l'exécution
3. Affichage du rapport :
   - Transactions créées / mises à jour
   - Lignes créées / mises à jour
   - Commandes ignorées
   - Rapprochements auto-verrouillés
   - Cashouts incomplets (filtrés par exercice courant uniquement)
   - Erreurs éventuelles

### Résumé replié

Exemple : "22 mises à jour, 1 rapprochement"

## Composants existants

### À conserver tel quel
- `HelloassoForm` — credentials API

### À modifier
- `HelloassoSyncConfig` — retirer le bloc mapping formulaires (ne garde que comptes + sous-catégories)

### À supprimer (absorbés dans le wizard)
- `HelloassoTiersRapprochement` — logique absorbée dans le wizard étape 2
- `HelloassoSync` — logique absorbée dans le wizard étape 3

### À créer
- `HelloassoSyncWizard` — nouveau composant unique pour les 3 étapes

## Navigation

- Cliquer sur une étape terminée la réouvre sans relancer les appels API
- Revenir à l'étape 1 ou 2 après la synchro est possible (pour relancer avec d'autres mappings par exemple)
- Changer d'exercice dans la barre de menu réinitialise le wizard (retour étape 1 au prochain chargement de page)
