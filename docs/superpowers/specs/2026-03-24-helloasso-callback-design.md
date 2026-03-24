# Design — Callback HelloAsso léger

## Contexte

L'intégration HelloAsso actuelle est 100% pull (synchronisation manuelle via wizard).
On ajoute un callback léger qui ne fait qu'enregistrer des notifications pour signaler
à l'utilisateur qu'une nouvelle synchronisation est nécessaire.

## Composants

### 1. Migration : table `helloasso_notifications`

| Colonne          | Type       | Description                                      |
|------------------|------------|--------------------------------------------------|
| `id`             | bigint PK  |                                                  |
| `association_id` | FK         |                                                  |
| `event_type`     | string     | Type brut HelloAsso (`Order`, `Payment`…)        |
| `libelle`        | string     | Résumé humain ("Nouvelle cotisation de Jean Dupont") |
| `payload`        | json       | JSON complet reçu                                |
| `created_at`     | timestamp  | Date de réception                                |

Pas de `updated_at` — lignes en lecture seule puis purgées.

### 2. Migration : colonne `callback_token` sur `helloasso_parametres`

Token de 64 caractères hex, généré automatiquement à la sauvegarde des paramètres HelloAsso.

### 3. Modèle `HelloAssoNotification`

Eloquent simple, pas de SoftDeletes (purge en dur).

### 4. Route API

`POST /api/helloasso/callback/{token}` — sans auth Laravel, sans CSRF.

### 5. Controller `HelloAssoCallbackController`

- Vérifie que le token correspond à un `HelloAssoParametres`
- Parse le JSON pour extraire un libellé lisible (type + nom si disponible)
- Insère dans `helloasso_notifications`
- Retourne 200 OK

### 6. Écran Paramètres / Connexion HelloAsso

Sous le formulaire existant, nouveau bloc :
- Affiche l'URL de callback complète (avec token) + bouton "Copier"
- Instruction : "Collez cette URL dans Paramètres API → Notification → Mon URL de callback sur HelloAsso"
- Bouton pour régénérer le token (avec confirmation)
- N'apparaît que si les paramètres HelloAsso sont déjà sauvegardés (token existant)

### 7. Bandeau global dans le layout (`app.blade.php`)

Composant Livewire sous la navbar :
- Requête count des notifications non purgées pour l'association courante
- Si > 0 : bandeau warning
  - Texte : "Attention, les données HelloAsso ne sont pas à jour. X notification(s) reçue(s)."
  - Lien "Voir les détails" qui déplie la liste des notifications (libellé + date)
  - Bouton "Lancer la synchronisation" → `/banques/helloasso-sync`

### 8. Purge au lancement de la synchro

Dans `HelloassoSyncWizard::mount()` : supprimer toutes les `helloasso_notifications`
de l'association. Le bandeau disparaît automatiquement.

### 9. Commande artisan `helloasso:simulate-callback`

Forge un appel HTTP local au endpoint callback avec un payload réaliste.
Permet de tester toute la chaîne en dev/préprod (environnements non accessibles
depuis internet par HelloAsso).

## Sécurité

- HelloAsso ne signe pas ses webhooks (pas de HMAC, pas d'IP fixe)
- Protection par token secret dans l'URL (64 chars hex, non devinable)
- Risque résiduel minime : le callback ne fait qu'enregistrer une notification,
  aucune donnée n'est modifiée automatiquement
- Token régénérable depuis les paramètres

## Contraintes de test

- Dev/préprod non accessibles depuis internet → commande artisan pour simuler
- Prod : HelloAsso appellera le vrai endpoint
