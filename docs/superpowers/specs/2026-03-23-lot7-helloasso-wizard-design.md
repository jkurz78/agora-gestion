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
- Le tableau mapping formulaires → opérations (déplacé vers le wizard étape 1)
- Le composant rapprochement des tiers (déplacé vers le wizard étape 2)
- Le composant synchronisation (déplacé vers le wizard étape 3)

### Écran Synchronisation — "Synchronisation HelloAsso"

**Route** : `/banques/helloasso-sync` (nouvelle)
**Nom de route** : `banques.helloasso-sync`
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

La condition `active` du dropdown Banques dans `app.blade.php` doit inclure `request()->routeIs('banques.helloasso-sync')`.

**Composant** : `HelloassoSyncWizard` — un seul composant Livewire avec propriété `$step`.

## Exercice

Pas de sélecteur d'exercice dans le wizard. L'exercice actif est celui de la session, lu via `ExerciceService::current()`, cohérent avec le reste de l'application.

Contrairement aux composants actuels `HelloassoTiersRapprochement` et `HelloassoSync` qui affichent un sélecteur d'exercice, le wizard n'en a pas. Le sélecteur d'exercice global dans la barre de navigation contrôle l'exercice pour toute l'application.

## Pré-requis : vérification de la configuration

Au mount du composant, le wizard vérifie la configuration HelloAsso et affiche des messages adaptés. Tous les messages incluent un lien vers Paramètres → Connexion HelloAsso.

**Bloquant** (empêche le démarrage du wizard, aucun appel API tenté) :
- Credentials API absents (`client_id` null) : _"Les credentials HelloAsso ne sont pas encore configurés."_
- Compte HelloAsso non configuré (`compte_helloasso_id` null) : _"Le compte bancaire HelloAsso n'est pas configuré."_

**Avertissements** (affichés en haut du wizard, n'empêchent pas la synchro) :
- Compte de versement non configuré (`compte_versement_id` null) : _"Le compte de versement n'est pas configuré — les versements (cashouts) ne seront pas traités."_
- Sous-catégorie Dons non configurée : _"La sous-catégorie Dons n'est pas configurée — les dons ne seront pas importés."_
- Sous-catégorie Cotisations non configurée : _"La sous-catégorie Cotisations n'est pas configurée — les cotisations ne seront pas importées."_
- Sous-catégorie Inscriptions non configurée : _"La sous-catégorie Inscriptions n'est pas configurée — les inscriptions ne seront pas importées."_

## Layout : accordéon à 3 étapes

Les 3 étapes sont affichées comme des cards empilées. L'étape active est ouverte (bordure accentuée, contenu visible). Les étapes terminées sont repliées avec un résumé compact. Les étapes futures sont grisées.

Cliquer sur une étape terminée la réouvre sans relancer l'auto-fetch (les données restent en cache dans les propriétés Livewire).

## Étape 1 — Mapping Formulaires → Opérations

### Comportement

1. **Auto-fetch** au mount du composant : appel API `chargerFormulaires()` pour récupérer les formulaires HelloAsso et upsert les `HelloAssoFormMapping`. Spinner pendant le chargement.
2. **Filtre** : afficher uniquement les formulaires dont `date_fin` est `null` OU `>= date début de l'exercice courant`. Les anciens formulaires avec un mapping restent en base mais ne s'affichent plus.
3. **Tableau** : titre, type, période, état, dropdown opération (valeur par défaut "Ne pas suivre")
4. **Bouton "+" créer opération** : à côté du dropdown de chaque ligne, ouvre un formulaire inline (ligne supplémentaire dans le tableau ou section dépliable sous la ligne) avec les champs pré-remplis depuis le formulaire HelloAsso :
   - `nom` ← titre du formulaire (requis)
   - `date_debut` ← date début du formulaire
   - `date_fin` ← date fin du formulaire
   - `description` ← vide (optionnel)
   - `nombre_seances` ← null (optionnel)
   - `statut` ← "active" par défaut
   - Validation : `nom` requis, `date_debut` requise
   - L'opération créée est automatiquement sélectionnée dans le dropdown de cette ligne
5. **Bouton "Suite →"** : sauvegarde les mappings et passe à l'étape 2. Zéro formulaire mappé est un état valide (le bouton est toujours actif).

### Résumé replié

Exemple : "8 formulaires, 3 mappés"

## Étape 2 — Rapprochement des Tiers

### Comportement

1. **Auto-fetch** la première fois que l'étape devient active (garde `$tiersFetched` pour ne pas relancer à la réouverture) : appel API pour récupérer les tiers HelloAsso de l'exercice. Spinner pendant le chargement.
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

1. La synchro est **déclenchée automatiquement** par le bouton de l'étape 2. L'exécution est **synchrone** (acceptable à l'échelle de l'association : quelques centaines de transactions max).
2. Spinner pendant l'exécution
3. Affichage du rapport :
   - Transactions créées / mises à jour
   - Lignes créées / mises à jour
   - Commandes ignorées
   - Rapprochements auto-verrouillés
   - Cashouts incomplets (filtrés par exercice courant uniquement)
   - Erreurs éventuelles
4. Si la synchro est relancée (retour étape 2 → re-clic "Lancer la synchronisation"), le résultat précédent est effacé et remplacé par le nouveau.

### Résumé replié

Exemple : "22 mises à jour, 1 rapprochement"

## Composants existants

### À conserver tel quel
- `HelloassoForm` — credentials API

### À modifier
- `HelloassoSyncConfig` — retirer le bloc mapping formulaires (ne garde que comptes + sous-catégories). Le composant et sa vue continuent d'exister, seule la section formulaires est supprimée.

### À supprimer (absorbés dans le wizard)
- `HelloassoTiersRapprochement` — logique absorbée dans le wizard étape 2
- `HelloassoSync` — logique absorbée dans le wizard étape 3

### À créer
- `HelloassoSyncWizard` — nouveau composant Livewire dans `App\Livewire\Banques\HelloassoSyncWizard`, avec sa vue dans `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`

## Vue wrapper

Nouvelle vue `resources/views/banques/helloasso-sync.blade.php` :
```blade
<x-app-layout>
    <div class="container py-3">
        <h1 class="mb-4"><i class="bi bi-arrow-repeat"></i> Synchronisation HelloAsso</h1>
        <livewire:banques.helloasso-sync-wizard />
    </div>
</x-app-layout>
```

## Navigation

- Cliquer sur une étape terminée la réouvre sans relancer les appels API
- Revenir à l'étape 1 ou 2 après la synchro est possible (pour relancer avec d'autres mappings par exemple)
- Changer d'exercice dans la barre de menu réinitialise le wizard (retour étape 1 au prochain chargement de page)
