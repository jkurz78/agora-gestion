# Remises en banque — Design

## Contexte

Quand l'association reçoit des chèques ou des espèces, elle les regroupe en une **remise en banque** (bordereau de dépôt) avant de les déposer à la banque. Sur le relevé bancaire, une seule ligne apparaît pour le montant total de la remise.

Côté comptabilité, on a besoin d'une transaction individuelle par chèque/espèces pour le suivi analytique (tiers, opération, séance, sous-catégorie). Pour concilier ces deux réalités, on utilise un **compte bancaire intermédiaire** dédié (pattern identique à HelloAsso) :

1. Une transaction recette par règlement sélectionné → sur le compte intermédiaire
2. Un virement interne du compte intermédiaire vers le compte bancaire cible → une seule ligne à rapprocher

## Périmètre

- Table `remises_bancaires` et modèle Eloquent
- Compte bancaire système "Remises en banque" (créé par migration, masqué des formulaires)
- Service `RemiseBancaireService` pour la logique métier
- Composants Livewire : liste, sélection, validation, consultation
- Génération PDF du bordereau
- Menu dans l'espace Gestion
- Routes dédiées

**Hors périmètre :**
- Masquer/refactorer le compte HelloAsso existant (amélioration future)
- Approche "création anticipée" (transactions créées dès la saisie du règlement)

## Modèle de données

### Table `remises_bancaires`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigIncrements | PK |
| `numero` | unsignedInteger, unique | Numéro séquentiel global (1, 2, 3...) |
| `date` | date | Date du bordereau |
| `mode_paiement` | string | `cheque` ou `especes` (enum ModePaiement) |
| `compte_cible_id` | foreignId | FK → `comptes_bancaires` (banque de dépôt) |
| `virement_id` | foreignId, nullable | FK → `virements_internes` (créé à la comptabilisation) |
| `libelle` | string | Ex : "Remise chèques n°3" |
| `saisi_par` | foreignId | FK → `users` |
| `timestamps` | | created_at, updated_at |
| `deleted_at` | softDeletes | Pour la suppression en cascade |

**Contraintes :**
- `numero` unique global (pas par exercice — la numérotation est continue)
- `mode_paiement` restreint à `cheque` et `especes`

### Modèle `RemiseBancaire`

- `declare(strict_types=1)`, `final class`, `SoftDeletes`
- Relations : `belongsTo CompteBancaire` (compte_cible), `belongsTo VirementInterne`, `belongsTo User` (saisi_par), `hasMany Reglement`, `hasMany Transaction`
- Cast `mode_paiement` → `ModePaiement`, `date` → `date`
- Méthode `isVerrouillee(): bool` — true si le virement est pointé dans un rapprochement verrouillé
- Méthode `montantTotal(): float` — somme des montants des règlements liés
- Méthode `referencePrefix(): string` — `RBC` pour chèque, `RBE` pour espèces

### Modifications sur les modèles existants

**`Transaction`** — ajout de `remise_id` (foreignId, nullable) pointant vers `remises_bancaires`. Permet de retrouver toutes les transactions d'une remise.

**`Reglement`** — le champ `remise_id` existant pointe désormais vers `remises_bancaires` avec une vraie contrainte FK (nullOnDelete pour permettre la suppression d'une remise sans casser les règlements).

**`CompteBancaire`** — ajout d'un champ `est_systeme` (boolean, default false). Les comptes systèmes sont exclus des sélecteurs de formulaire mais visibles dans les listes de consultation (transactions, historique par compte).

### Compte intermédiaire

Créé par migration :
```php
CompteBancaire::create([
    'nom' => 'Remises en banque',
    'iban' => '',
    'solde_initial' => 0,
    'date_solde_initial' => now(),
    'actif_recettes_depenses' => false,
    'actif_dons_cotisations' => false,
    'est_systeme' => true,
]);
```

Le flag `est_systeme` sert à :
- **Exclure** le compte des sélecteurs dans les formulaires transaction et virement
- **Inclure** le compte dans les listes de consultation (écran transactions par compte)
- Être réutilisable à terme pour le compte HelloAsso

### Numérotation

**Numéro de remise** : séquence globale auto-incrémentée, stockée dans `remises_bancaires.numero`. On prend `max(numero) + 1` à la création (dans une transaction DB pour éviter les doublons).

**Références générées** :

| Objet | Format | Exemple |
|-------|--------|---------|
| Transaction (chèque n°2 de la remise 3) | `{prefix}-{numero remise paddé 3}-{index paddé 2}` | `RBC-003-02` |
| Virement interne | `{prefix}-{numero remise paddé 3}` | `RBC-003` |

Préfixes : `RBC` = Remise Banque Chèques, `RBE` = Remise Banque Espèces.

**Libellés générés** :

| Objet | Format | Exemple |
|-------|--------|---------|
| Transaction | `Règlement {Nom Tiers} - {Nom Opération} S{numéro séance}` | `Règlement Dupont Jean - Gym Seniors S5` |
| Virement | `Remise {chèques\|espèces} n°{numero}` | `Remise chèques n°3` |

**Numéro de pièce** : chaque transaction et le virement reçoivent un `numero_piece` via le `NumeroPieceService` existant (séquence par exercice).

### Sous-catégorie

Même logique que HelloAsso : on utilise la `sous_categorie_id` de l'opération liée au règlement. Si l'opération n'a pas de sous-catégorie, il faudra un fallback configurable (hors scope — on lève une erreur pour l'instant).

## Service `RemiseBancaireService`

### `creer(array $data): RemiseBancaire`

Crée une remise (sans comptabilisation). Paramètres : `date`, `mode_paiement`, `compte_cible_id`. Attribue le prochain numéro et génère le libellé.

### `comptabiliser(RemiseBancaire $remise, array $reglementIds): void`

Dans une `DB::transaction()` :

1. Vérifie que la remise n'est pas déjà comptabilisée (`virement_id` null)
2. Vérifie que tous les règlements sont disponibles (`remise_id` null, bon `mode_paiement`)
3. Récupère le compte intermédiaire (`est_systeme` = true, nom = "Remises en banque")
4. Pour chaque règlement (trié par un ordre stable) :
   - Charge le participant → tiers, la séance, l'opération
   - Crée une transaction recette via `TransactionService::create()` :
     - `type` = recette
     - `date` = date de la remise
     - `libelle` = `Règlement {tiers.nom} - {opération.nom} S{séance.numero}`
     - `montant_total` = `reglement.montant_prevu`
     - `mode_paiement` = mode de la remise
     - `tiers_id` = `participant.tiers_id`
     - `reference` = `RBC-003-01` (indexé)
     - `compte_id` = compte intermédiaire
     - `remise_id` = id de la remise
   - Ligne de transaction :
     - `sous_categorie_id` = `operation.sous_categorie_id` (erreur si null)
     - `operation_id` = `operation.id`
     - `seance` = `seance.numero`
     - `montant` = `reglement.montant_prevu`
   - Met à jour `reglement.remise_id`
5. Crée le virement interne via `VirementInterneService::create()` :
   - `date` = date de la remise
   - `montant` = somme des montants
   - `compte_source_id` = compte intermédiaire
   - `compte_destination_id` = `remise.compte_cible_id`
   - `reference` = `RBC-003`
   - `notes` = libellé de la remise
6. Met à jour `remise.virement_id`

### `modifier(RemiseBancaire $remise, array $reglementIds): void`

Permet d'ajouter/retirer des règlements. Précondition : `!remise.isVerrouillee()`.

Dans une `DB::transaction()` :

1. Identifie les règlements retirés (actuellement liés mais absents de `$reglementIds`) et les ajoutés (présents mais pas encore liés)
2. Pour les retirés : supprime la transaction correspondante, remet `reglement.remise_id = null`
3. Pour les ajoutés : crée la transaction (même logique que `comptabiliser`)
4. Met à jour le montant du virement interne
5. Si la liste finale est vide, équivaut à une suppression (appel `supprimer`)

### `supprimer(RemiseBancaire $remise): void`

Précondition : `!remise.isVerrouillee()`.

Dans une `DB::transaction()` :

1. Remet `remise_id = null` sur tous les règlements liés
2. Supprime (soft delete) toutes les transactions liées
3. Supprime (soft delete) le virement interne
4. Supprime (soft delete) la remise

## Composants Livewire

### `RemiseBancaireList`

**Route** : `/gestion/remises-bancaires`
**Vue** : tableau paginé des remises

| Colonne | Contenu |
|---------|---------|
| N° | Numéro formaté (ex: `003`) |
| Date | Format dd/mm/yyyy |
| Type | Chèques / Espèces |
| Banque | Nom du compte cible |
| Nb règlements | Nombre de règlements inclus |
| Montant | Total formaté |
| Statut | Badge : "Brouillon" (pas encore comptabilisée), "Comptabilisée", "Verrouillée" (rapprochée) |
| Actions | Voir, Modifier (si non verrouillée), Supprimer (si non verrouillée), PDF |

**Bouton "+"** : ouvre un formulaire de création (date, banque cible, type CHQ/ESP). À la validation, crée la remise et redirige vers l'écran de sélection.

### `RemiseBancaireSelection`

**Route** : `/gestion/remises-bancaires/{remise}/selection`
**Vue** : écran de sélection des règlements (similaire au rapprochement bancaire)

**En-tête** : rappel date, type, banque cible.

**Tableau des règlements disponibles** (ceux avec `remise_id = null` et `mode_paiement` correspondant) :

| Colonne | Contenu |
|---------|---------|
| ☑ | Case à cocher |
| Participant | Nom du tiers via participant |
| Opération | Nom de l'opération via séance |
| Séance | Numéro et titre de la séance |
| Montant | montant_prevu formaté |

**Filtres/tri** : par opération (select), par tiers (recherche texte). Tri cliquable sur chaque colonne.

**Bandeau bas (sticky)** : nombre de règlements sélectionnés, montant total. Bouton "Valider la sélection" → redirige vers l'écran de validation.

**Si la remise est déjà comptabilisée** (modification) : les règlements déjà inclus sont pré-cochés. On peut décocher pour retirer, cocher pour ajouter.

### `RemiseBancaireValidation`

**Route** : `/gestion/remises-bancaires/{remise}/validation`
**Vue** : écran de confirmation avant comptabilisation

**Contenu** :
- Récapitulatif : date, banque cible, type (Chèques/Espèces), nombre de règlements, montant total
- Tableau récapitulatif des règlements sélectionnés (même colonnes que la sélection, sans case à cocher)
- Bouton "Comptabiliser" → appelle `RemiseBancaireService::comptabiliser()`, redirige vers la liste
- Bouton "Retour" → retour à l'écran de sélection

### `RemiseBancaireShow`

**Route** : `/gestion/remises-bancaires/{remise}`
**Vue** : consultation d'une remise comptabilisée

**Contenu** :
- En-tête : n°, date, type, banque cible, montant total, statut
- Tableau des règlements/transactions créées (participant, opération, séance, montant, référence, n° pièce)
- Référence du virement interne
- Boutons : PDF, Modifier (si non verrouillée), Supprimer (si non verrouillée)

## PDF Bordereau

Généré via le même mécanisme que les PDF existants (Blade + CSS print ou dompdf).

**Contenu** :
- En-tête : nom de l'association (depuis les paramètres), "Bordereau de remise en banque"
- Informations : date, banque cible, type (Chèques/Espèces), numéro de remise
- Tableau :

| N° | Tireur | Opération | Séance | Montant |
|----|--------|-----------|--------|---------|
| 1 | Dupont Jean | Gym Seniors | S5 | 30,00 |
| 2 | Martin Sophie | Yoga | S3 | 25,00 |

- Pied : nombre de pièces, montant total
- Zone de signature (vide, pour signature manuelle)

**Route** : `/gestion/remises-bancaires/{remise}/pdf`

## Navigation

Nouvel item dans le menu Gestion (après Opérations, avant Sync HelloAsso) :
```html
<a class="nav-link" href="{{ route('gestion.remises-bancaires') }}">
    <i class="bi bi-bank"></i> Remises en banque
</a>
```

## Routes

```php
// Gestion > Remises en banque
Route::prefix('gestion/remises-bancaires')->name('gestion.remises-bancaires')->group(function () {
    Route::get('/', RemiseBancaireList::class);
    Route::get('/{remise}/selection', RemiseBancaireSelection::class)->name('.selection');
    Route::get('/{remise}/validation', RemiseBancaireValidation::class)->name('.validation');
    Route::get('/{remise}', RemiseBancaireShow::class)->name('.show');
    Route::get('/{remise}/pdf', [RemiseBancairePdfController::class, 'show'])->name('.pdf');
});
```

## Verrouillage et intégrité

| Condition | Modifiable | Supprimable |
|-----------|------------|-------------|
| Remise brouillon (pas encore comptabilisée) | Oui | Oui (simple suppression) |
| Remise comptabilisée, virement non rapproché | Oui (ajout/retrait de règlements) | Oui (cascade) |
| Remise comptabilisée, virement rapproché (verrouillé) | Non | Non |

**Transactions créées par une remise** : non éditables directement par l'utilisateur dans les écrans de saisie de transaction. Le champ `remise_id` sur la transaction identifie qu'elle est gérée par le mécanisme de remise.

**Suppression en cascade** : remet `remise_id = null` sur les règlements, soft-delete des transactions et du virement, soft-delete de la remise.

## Visibilité du compte intermédiaire

| Écran | Visible |
|-------|---------|
| Liste des transactions / historique par compte | Oui |
| Détail d'une transaction | Oui |
| Sélecteur de compte dans formulaire transaction | Non |
| Sélecteur de compte dans formulaire virement | Non |
| Liste des comptes bancaires (paramètres) | Non |

Le filtrage se fait via `where('est_systeme', false)` dans les requêtes des sélecteurs.

## Migrations nécessaires

1. **Ajouter `est_systeme` sur `comptes_bancaires`** : boolean, default false
2. **Créer le compte intermédiaire** : dans la même migration, insérer le compte "Remises en banque" avec `est_systeme = true`
3. **Créer la table `remises_bancaires`** : structure décrite ci-dessus
4. **Ajouter `remise_id` sur `transactions`** : foreignId nullable, constrained → `remises_bancaires`, nullOnDelete
5. **Ajouter la contrainte FK sur `reglements.remise_id`** : constrained → `remises_bancaires`, nullOnDelete (le champ existe déjà mais sans FK)

## Tests

- **Service** : création, comptabilisation (transactions + virement créés), modification (ajout/retrait), suppression (cascade), verrouillage (refuse modification si rapproché)
- **Livewire** : navigation entre écrans, sélection de règlements, comptabilisation, affichage correct des statuts
- **Intégrité** : le compte intermédiaire a un solde zéro après comptabilisation, les numéros de pièce sont attribués, les références suivent le format attendu
