# Flags de sections formulaire — Spec de design

**Date :** 2026-03-29
**Statut :** Validé (brainstorming)

## Contexte

Le formulaire d'inscription enrichi (wizard 7 pages) a été implémenté avec toutes les sections codées en dur. Ce lot ajoute des flags par TypeOperation pour activer/désactiver les sections du formulaire, rendant le formulaire modulaire selon le type d'activité.

Ce lot remplace également le champ `confidentiel` de TypeOperation, qui n'a plus de raison d'être indépendamment des flags formulaire.

## Modèle de données

### Migration `type_operations` — nouvelles colonnes

| Champ | Type | Défaut | Notes |
|-------|------|--------|-------|
| `formulaire_actif` | boolean | false | Flag maître — active le bouton token + les 3 flags suivants |
| `formulaire_prescripteur` | boolean | false | Bloc "adressé par" sur step 1 |
| `formulaire_parcours_therapeutique` | boolean | false | Steps 2, 3, 4, 5 + step 7 complet |
| `formulaire_droit_image` | boolean | false | Step 6 |
| `formulaire_prescripteur_titre` | varchar, nullable | null | Titre personnalisé du bloc prescripteur (défaut affiché : "Je vous suis adressé(e) par") |
| `formulaire_qualificatif_atelier` | varchar, nullable | null | Qualificatif personnalisé (défaut affiché : "thérapeutique") |

### Migration de données `confidentiel` → flags

Dans la même migration :
- `confidentiel = true` → `formulaire_actif = true` + `formulaire_parcours_therapeutique = true` + `formulaire_prescripteur = true` + `formulaire_droit_image = true`
- `confidentiel = false` → tous les flags à `false`

Puis suppression de la colonne `confidentiel`.

**Note :** les types qui avaient `confidentiel = true` récupèrent tous les flags activés pour conserver le comportement identique. L'admin pourra ensuite affiner la configuration.

### Déplacement de champs

Les champs **nom de jeune fille** et **nationalité** passent du step 1 au step 2 (données thérapeutiques). Pas de migration nécessaire — changement uniquement dans les vues Blade.

## Comportement avec données existantes

Désactiver un flag ne supprime pas les données déjà collectées. Les colonnes données sensibles dans ParticipantTable et les exports restent conditionnées par `formulaire_parcours_therapeutique` : si le flag est désactivé après des soumissions, les données médicales ne sont plus visibles dans le tableau mais restent en base et accessibles via export confidentiel.

## Onglet "Formulaire" sur TypeOperation admin

Ajouté comme **4e onglet** après "Emails". Les limites `maxVisitedTab` et `nextTab()` dans `TypeOperationManager` passent de 3 à 4.

Nouvel onglet dans `TypeOperationManager` :

```
[x] Utiliser l'envoi de formulaires

    [x] Demander les coordonnées du prescripteur
        Titre du bloc : [Je vous suis adressé(e) par___________]

    [x] Récolter les informations nécessaires aux parcours thérapeutiques

    [x] Demander les autorisations photo et vidéo
        Qualificatif des ateliers : [thérapeutique___________]
```

**Comportement UI :**
- `formulaire_actif` décoché → les 3 cases en dessous sont grisées (disabled)
- `formulaire_prescripteur_titre` visible uniquement si `formulaire_prescripteur` coché, placeholder "Je vous suis adressé(e) par"
- `formulaire_qualificatif_atelier` visible uniquement si `formulaire_droit_image` coché, placeholder "thérapeutique"
- En base, null = utiliser le défaut

## Logique du wizard

### Ce que chaque flag contrôle

| Flag | Step 1 | Step 2 | Step 3 | Step 4 | Step 5 | Step 6 | Step 7 |
|------|--------|--------|--------|--------|--------|--------|--------|
| `formulaire_actif` seul | Coordonnées | - | - | - | - | - | RGPD + Envoyer |
| + `prescripteur` | + bloc adressé par | | | | | | |
| + `parcours_therapeutique` | | NJF/nat + Santé | Documents | Le saviez-vous | Financier | | Mode complet (engagements + token) |
| + `droit_image` | | | | | | Photos/vidéos | |

### Step 7 — deux modes

- **Mode complet** (`formulaire_parcours_therapeutique` coché) : engagements obligatoires (présence, certificat, règlement si tarif > 0) + RGPD + autorisation contact médecin + re-saisie token + bouton "Valider et envoyer"
- **Mode léger** (`formulaire_parcours_therapeutique` décoché) : uniquement case RGPD + bouton "Envoyer"

### Règles existantes conservées

- Step 5 (engagement financier) reste masqué si tarif à zéro, indépendamment des flags
- Case "engagement règlement" sur step 7 reste masquée si tarif à zéro

### Calcul des steps à skip

Le `skipSteps` existant (Alpine.js) est étendu. Il est calculé côté Blade et passé en JSON :

```php
$skipSteps = [];
if (!$typeOperation->formulaire_parcours_therapeutique) {
    $skipSteps = array_merge($skipSteps, [2, 3, 4, 5]);
}
if (!$typeOperation->formulaire_droit_image) {
    $skipSteps[] = 6;
}
// Règle tarif à zéro (existante)
if (!$tarif || (float) $tarif->montant <= 0) {
    $skipSteps[] = 5;
}
$skipSteps = array_unique($skipSteps);
```

Le calcul est fait dans `remplir.blade.php` et passé en JSON à Alpine via `@json($skipSteps)`.

Step 7 n'est jamais dans `skipSteps` — c'est son contenu qui change selon le mode.

### Step 7 — contenu conditionnel (Blade)

- **Mode complet** : affiche les cards engagements obligatoires + autorisation optionnelle + re-saisie token
- **Mode léger** : affiche uniquement la case RGPD + bouton "Envoyer" (les sections engagements et token sont masquées via `@if($typeOperation->formulaire_parcours_therapeutique)`)

### Barre de progression

La barre affiche le numéro d'étape parmi les étapes actives (pas le numéro absolu). Exemple : si seuls steps 1, 6, 7 sont actifs, on affiche "Étape 1/3", "Étape 2/3", "Étape 3/3".

## Textes dynamiques — Step 6 (droit à l'image)

Le qualificatif remplace les occurrences suivantes dans le texte du step 6 :

| Texte actuel | Devient |
|---|---|
| ateliers **thérapeutiques** | ateliers **{qualificatif}s** |
| cheminement **thérapeutique** | cheminement **{qualificatif}** |
| au sein de l'équipe **thérapeutique** | au sein de l'équipe **{qualificatif}** |

Valeur par défaut si `formulaire_qualificatif_atelier` est null : `thérapeutique`.

**Note :** l'admin saisit la forme singulière. Le pluriel est formé en ajoutant "s" dans le template Blade.

## Impact sur le bouton token (ParticipantTable)

Dans `ParticipantTable` et son Blade :
- Remplacer toutes les occurrences de `$operation->typeOperation?->confidentiel` par `$operation->typeOperation?->formulaire_actif` pour le bouton token / envoi email
- Remplacer `confidentiel` par `formulaire_parcours_therapeutique` pour les colonnes données sensibles (date naissance, taille, poids, notes, etc.)

## Remplacement complet de `confidentiel`

### Fichiers impactés (code applicatif)

| Fichier | Usage actuel de `confidentiel` | Remplacement |
|---------|-------------------------------|--------------|
| `app/Models/TypeOperation.php` | $fillable, casts | Supprimer, ajouter les 6 nouveaux champs |
| `app/Livewire/TypeOperationManager.php` | Property + openEdit + save + resetForm | Remplacer par les 6 nouvelles properties |
| `resources/views/livewire/type-operation-manager.blade.php` | Case à cocher "Données confidentielles" + pastille verte dans la liste | Remplacer par onglet Formulaire + pastille sur `formulaire_actif` |
| `resources/views/livewire/participant-table.blade.php` | Affichage colonnes sensibles + badge formulaire + export confidentiel | `formulaire_parcours_therapeutique` pour données sensibles, `formulaire_actif` pour badge/token |
| `app/Http/Controllers/ParticipantPdfController.php` | `$isConfidentiel` | `formulaire_parcours_therapeutique` |
| `app/Http/Controllers/ParticipantExportController.php` | `$request->boolean('confidentiel')` (paramètre URL, pas le champ modèle) | Le paramètre URL reste `?confidentiel=1`, mais vérifier aussi `$typeOperation->formulaire_parcours_therapeutique` |
| `app/Http/Controllers/SeancePdfController.php` | `$isConfidentiel` pour colonne kiné | `formulaire_parcours_therapeutique` |
| `resources/views/livewire/seance-table.blade.php` | Colonne kiné | `formulaire_parcours_therapeutique` |
| `resources/views/pdf/participants-liste.blade.php` | Colonnes sensibles + badge | `formulaire_parcours_therapeutique` |
| `resources/views/pdf/participants-annuaire.blade.php` | Colonnes sensibles + badge | `formulaire_parcours_therapeutique` |
| `resources/views/formulaire/layout.blade.php` | "Formulaire confidentiel" en footer | Supprimer ou conditionner |
| `app/Http/Controllers/FormulaireController.php` | Validation `engagement_*` + `token_confirmation` | Conditionner sur `formulaire_parcours_therapeutique` |
| `app/Livewire/ParticipantTable.php` | Eager load conditionnel `donneesMedicales` | `formulaire_parcours_therapeutique` au lieu de `confidentiel` |
| `resources/views/formulaire/remplir.blade.php` | Calcul `skipSteps` + barre de progression | Étendre avec les flags TypeOperation |
| `resources/views/formulaire/steps/step-1.blade.php` | Bloc "adressé par" toujours visible + NJF/nationalité | Conditionner sur `formulaire_prescripteur` + déplacer NJF/nat vers step 2 |
| `resources/views/formulaire/steps/step-2.blade.php` | Pas de NJF/nationalité | Ajouter NJF/nationalité en haut |
| `resources/views/formulaire/steps/step-6.blade.php` | Texte statique "thérapeutique" | Remplacer par `$qualificatif` dynamique |
| `resources/views/formulaire/steps/step-7.blade.php` | Engagements + token toujours affichés | Mode complet/léger via `@if(formulaire_parcours_therapeutique)` |

### Fichiers impactés (tests)

| Fichier | Impact |
|---------|--------|
| `tests/Feature/TypeOperationTest.php` | Remplacer `confidentiel` par les nouveaux flags |
| `tests/Feature/Livewire/ParticipantTableTest.php` | Remplacer `confidentiel` |
| `tests/Feature/Livewire/FormulaireTokenIntegrationTest.php` | Remplacer `confidentiel` par `formulaire_actif` |
| `database/seeders/TypeOperationSeeder.php` | Remplacer `confidentiel` par les nouveaux flags |
| `database/factories/TypeOperationFactory.php` | Remplacer `confidentiel` par les nouveaux flags |

### Fichiers NON impactés (faux positifs)

- `app/Enums/DroitImage.php` — `UsageConfidentiel` est une valeur d'enum, pas liée au flag
- `resources/views/formulaire/steps/step-2.blade.php` — "confidentielles et chiffrées" est un libellé UI, pas lié au flag
- `resources/views/formulaire/steps/step-6.blade.php` — "usage confidentiel" est une option droit à l'image

## Validation serveur (FormulaireController::store)

Les règles de validation existantes pour `engagement_*` deviennent conditionnelles :

```php
// Si parcours_therapeutique est actif
'engagement_presence' => $typeOperation->formulaire_parcours_therapeutique ? ['required', 'accepted'] : ['nullable'],
'engagement_certificat' => $typeOperation->formulaire_parcours_therapeutique ? ['required', 'accepted'] : ['nullable'],
'engagement_reglement' => /* règle existante conditionnelle tarif + parcours */,
'token_confirmation' => $typeOperation->formulaire_parcours_therapeutique ? ['required', ...] : ['nullable'],
```

`engagement_rgpd` reste toujours `required|accepted`.
