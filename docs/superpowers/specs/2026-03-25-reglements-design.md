# Onglet Règlements — Design

## Contexte

L'onglet Règlements permet à l'animateur de planifier les règlements à recevoir par participant et par séance au sein d'une opération. Il se présente sous forme d'une grille similaire à l'onglet Séances, avec les participants en lignes et les séances en colonnes.

## Périmètre de ce lot

- Table `reglements` et modèle Eloquent
- Composant Livewire `ReglementTable` avec grille d'édition inline
- Intégration comme nouvel onglet dans `GestionOperations`
- Totaux prévu / réalisé / écart par participant et par séance

**Hors périmètre (lots suivants) :**
- Exports PDF et Excel
- Écran bordereau de remise en banque (chèques / espèces)
- Détail du réalisé par sous-catégorie (dépliable ou infobulle)

## Modèle de données

### Table `reglements`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigIncrements | PK |
| `participant_id` | foreignId, cascadeOnDelete | FK → participants |
| `seance_id` | foreignId, cascadeOnDelete | FK → seances |
| `mode_paiement` | string, nullable | Enum ModePaiement (virement, cheque, especes) |
| `montant_prevu` | decimal(10,2), default 0 | Montant attendu |
| `remise_id` | foreignId, nullable | FK → remises (future table, pas de contrainte FK pour l'instant) |
| `timestamps` | | created_at, updated_at |

**Contrainte unique** : `[participant_id, seance_id]` — une cellule par croisement.

### Modèle `Reglement`

- `declare(strict_types=1)`, `final class`
- Pas de SoftDeletes (planification, pas un objet comptable)
- Pas de chiffrement (données non sensibles)
- Cast `mode_paiement` → `ModePaiement` enum (réutilise l'enum existante)
- Cast `montant_prevu` → `decimal:2`
- Relations : `belongsTo Participant`, `belongsTo Seance`
- Fillable : `participant_id`, `seance_id`, `mode_paiement`, `montant_prevu`, `remise_id`

### Relations inverses

- `Participant` : ajouter `hasMany Reglement`
- `Seance` : ajouter `hasMany Reglement`

## Composant Livewire `ReglementTable`

### Propriétés

- `Operation $operation`
- Séances chargées depuis `$operation->seances` (lecture seule)
- Participants chargés depuis `$operation->participants` avec `tiers`
- Map des règlements indexée par `{participant_id}-{seance_id}`, chargée en une seule requête

### Actions

- **`cycleModePaiement(int $participantId, int $seanceId)`** — Cycle : null → CHQ → VMT → ESP → null → CHQ. Sur une cellule vierge, le premier clic met CHQ. Seuls 3 des 5 valeurs de `ModePaiement` sont proposées dans le cycle (CB et Prélèvement exclus). Crée le `Reglement` (upsert) si inexistant. Refuse si `remise_id` non null.
- **`updateMontant(int $participantId, int $seanceId, string $montant)`** — Met à jour `montant_prevu`. Parse la virgule décimale française (`str_replace(',', '.', $montant)`) avant conversion. Crée le `Reglement` (upsert) si inexistant. Refuse si `remise_id` non null.
- **`copierLigne(int $participantId)`** — Copie le mode de paiement et le montant de la première séance (plus petit `numero`) sur toutes les autres séances de la ligne. Ignore les cellules verrouillées (`remise_id` non null).

### Montant réalisé (lecture seule)

Somme des `transaction_lignes.montant` pour les recettes, via le chemin de jointure : `transaction_lignes.transaction_id` → `transactions` (where `type = 'recette'` and `tiers_id` = `participant.tiers_id`), filtrée par `transaction_lignes.operation_id` = `operation.id` et `transaction_lignes.seance` = `seances.numero` (attention : `transaction_lignes.seance` est un entier correspondant au numéro de séance, pas une FK vers `seances.id`).

Renvoyé comme array indexé `{participant_id}-{seance_id}` → montant. Les critères de filtrage pourront être affinés ultérieurement (détail par sous-catégorie).

### Totaux

Calculés en PHP à partir des collections :
- Par ligne (participant) : prévu, réalisé, écart
- Par colonne (séance) : prévu, réalisé, écart
- Grand total : prévu, réalisé, écart

## Vue Blade `reglement-table.blade.php`

### En-têtes (3 lignes, lecture seule — esclaves de l'onglet Séances)

| Ligne | Contenu |
|-------|---------|
| 1 | "Participant" (sticky left) — S1, S2, S3... — "Total" |
| 2 | Titres des séances |
| 3 | Dates des séances (format dd/mm) |

### Corps (2 lignes par participant)

**Ligne principale :**
- Colonne sticky : nom du participant + bouton `→` (recopier S1 sur la ligne)
- Par séance : trigramme cliquable + montant inline editable
- Colonne total : prévu / réalisé / écart

**Ligne secondaire :**
- Vide sous le nom
- Par séance : montant réalisé en lecture seule (vert si encaissé, rouge si 0 alors que prévu > 0, gris si prévu = 0)

### Trigrammes et cycle

| Trigramme | Mode | Couleur |
|-----------|------|---------|
| CHQ | Chèque | Bleu (#e7f1ff / #0d6efd) |
| VMT | Virement | Vert (#d4edda / #155724) |
| ESP | Espèces | Jaune (#fff3cd / #856404) |
| — | Aucun | Gris (#f0f0f0 / #adb5bd) |

Cycle au clic : CHQ → VMT → ESP → — → CHQ

### Montant inline editable

- Affiché comme du texte normal (pas d'input visible)
- Contour léger au survol, éditable au clic via Alpine.js (`x-on:blur` → `$wire.updateMontant(...)`)
- Blur pour valider
- Pas de symbole € (implicite)
- Format : nombres avec virgule décimale française (30,00)

### Cellule verrouillée (`remise_id` non null)

- Trigramme et montant affichés mais non éditables
- Petit cadenas visible dans la cellule
- Bouton `→` ignore ces cellules lors de la recopie

### Pied de tableau (3 lignes)

| Ligne | Contenu |
|-------|---------|
| Total prévu | Somme des montants prévus par séance + grand total |
| Total réalisé | Somme des montants réalisés par séance + grand total |
| Écart | Différence (rouge si négatif) |

### Comportement responsive

Scroll horizontal avec colonne participant sticky à gauche (identique à la grille séances).

## Intégration dans GestionOperations

- Nouvel onglet **"Règlements"** entre Séances et Compte résultat
- Visible par tous les utilisateurs (pas de restriction `peut_voir_donnees_sensibles` — les règlements sont des données financières de planification, pas des données médicales sensibles)
- Pour les utilisateurs sans `peut_voir_donnees_sensibles`, l'onglet apparaît après Participants (Séances étant masqué)
- Contenu : `<livewire:reglement-table :operation="$selectedOperation" :key="'rt-'.$selectedOperation->id" />`
- Pas de routes supplémentaires pour ce lot

### États vides

- Pas de participants : message "Aucun participant inscrit à cette opération"
- Pas de séances : message "Aucune séance définie pour cette opération"
