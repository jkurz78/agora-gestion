# Formulaire auto-déclaratif participants — Design

## Contexte

Les données personnelles et médicales des participants sont actuellement saisies par l'animateur dans l'onglet Participants. Pour alléger ce travail et améliorer la qualité des données, on propose un formulaire public accessible via un lien unique par participant, sans authentification. Le participant remplit lui-même ses coordonnées et données médicales.

## Périmètre

- Table `formulaire_tokens` pour stocker les jetons
- Génération de tokens courts et dictables (8 caractères)
- Page publique `/formulaire` avec saisie du token ou accès direct via `?token=XXXX-XXXX`
- Formulaire : coordonnées pré-remplies + données médicales vides
- Merge intelligent des coordonnées (pas d'écrasement par du vide)
- Suivi dans l'onglet Participants (statut par participant)
- Rate limiting sur la route publique

**Hors périmètre :**
- Envoi groupé par email (évolution future)
- Configuration des champs affichés par opération
- Purge automatique des tokens (cascade sur participant suffit)

## Modèle de données

### Table `formulaire_tokens`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigIncrements | PK |
| `participant_id` | foreignId, unique | FK → `participants`, cascadeOnDelete |
| `token` | string(9), unique | Code 8 caractères + tiret : `KM7R-4NPX` |
| `expire_at` | date | Date d'expiration |
| `rempli_at` | datetime, nullable | Date/heure de soumission |
| `rempli_ip` | string(45), nullable | IP du participant (IPv4 ou IPv6) |
| `timestamps` | | created_at, updated_at |

**Contrainte unique** sur `participant_id` — un seul token actif par participant. Regénérer un token remplace l'ancien.

### Modèle `FormulaireToken`

- `declare(strict_types=1)`, `final class`
- Pas de SoftDeletes (pas de donnée comptable)
- Cast : `expire_at` → `date`, `rempli_at` → `datetime`
- Relations : `belongsTo Participant`
- Méthodes :
  - `isExpire(): bool` — `expire_at < today()`
  - `isUtilise(): bool` — `rempli_at !== null`
  - `isValide(): bool` — `!isExpire() && !isUtilise()`

### Relation inverse

- `Participant` : ajouter `hasOne FormulaireToken`

## Génération de token

### Alphabet sans ambiguïté

Exclure les caractères confondables à l'oral ou à la lecture :

- Lettres exclues : `O` (confondu avec 0), `I` (confondu avec 1), `L` (confondu avec 1), `Z` (confondu avec 2)
- Chiffres exclus : `0` (confondu avec O), `1` (confondu avec I/L), `2` (confondu avec Z)
- Alphabet résultant (23 symboles) : `3456789ABCDEFGHJKMNPQRSTVWXY`

### Format

8 caractères en deux blocs de 4 séparés par un tiret : `KM7R-4NPX`. Le tiret est décoratif (stocké en base pour simplifier, mais accepté avec ou sans à la saisie).

### Unicité

Générer aléatoirement, vérifier l'unicité en base, re-générer si collision (probabilité négligeable avec 8G combinaisons).

## Routes

### Route publique (sans auth)

```php
Route::prefix('formulaire')->group(function () {
    Route::get('/', [FormulaireController::class, 'index'])->name('formulaire.index');
    Route::get('/remplir', [FormulaireController::class, 'show'])->name('formulaire.show');
    Route::post('/remplir', [FormulaireController::class, 'store'])->name('formulaire.store');
});
```

Middleware : `throttle:10,1` (10 tentatives par minute par IP).

### Route interne (auth, espace Gestion)

Pas de nouvelle route — la génération et le suivi se font dans le composant `ParticipantTable` existant via des actions Livewire.

## Contrôleur `FormulaireController`

### `index()` — Page d'accueil `/formulaire`

- Si `?token=XXXX-XXXX` en query string → redirige vers `show`
- Sinon → affiche un formulaire simple : champ de saisie du token + bouton "Accéder"
- Page publique, sobre, avec le logo de l'association

### `show(Request $request)` — Affichage du formulaire `/formulaire/remplir?token=XXXX-XXXX`

- Récupère le token depuis `?token=`
- Normalise (majuscules, supprime espaces, ajoute tiret si absent)
- Cherche en base → token introuvable : erreur générique "Code invalide ou expiré"
- Token expiré : même message (ne pas révéler si le code existe)
- Token déjà utilisé : message "Ce formulaire a déjà été rempli. Merci."
- Token valide :
  - Charge le participant avec son tiers
  - Affiche le formulaire avec coordonnées pré-remplies + données médicales vides
  - Le token est dans un champ hidden

### `store(Request $request)` — Soumission POST

- Revalide le token (existe, valide, non utilisé)
- Validation des champs (rules standard Laravel)
- **Coordonnées (Tiers)** — merge intelligent :
  - Pour chaque champ : si la nouvelle valeur est non vide ET différente de l'ancienne → mettre à jour
  - Un champ vidé par le participant est ignoré (on garde l'ancienne valeur)
  - Champs concernés : `telephone`, `email`, `adresse_ligne1`, `code_postal`, `ville` (le champ `pays` n'est pas exposé dans le formulaire public)
- **Données médicales** — `updateOrCreate` sur `ParticipantDonneesMedicales` :
  - Champs : `date_naissance`, `sexe`, `taille`, `poids`, `notes`
  - Les champs laissés vides restent null
  - Si le participant re-soumet via un token regénéré, les nouvelles données remplacent les anciennes
- **Validation** :
  - `date_naissance` : date, nullable, format `Y-m-d`, avant aujourd'hui
  - `sexe` : nullable, in `M,F`
  - `taille` : nullable, numeric, entre 50 et 250 (cm)
  - `poids` : nullable, numeric, entre 20 et 300 (kg)
  - `notes` : nullable, string, max 1000 caractères
  - `telephone` : nullable, string, max 30
  - `email` : nullable, email, max 255
- Marque le token : `rempli_at = now()`, `rempli_ip = $request->ip()`
- Redirige vers une page de remerciement

## Vue publique

### Layout

Page autonome (pas `<x-app-layout>`) — layout minimal avec :
- Logo de l'association (centré)
- Nom de l'association
- Contenu
- Pied de page discret

### Page d'accueil (`formulaire/index.blade.php`)

- Titre : "Formulaire participant"
- Texte d'explication : "Saisissez le code qui vous a été communiqué"
- Champ de saisie (input text, autocapitalize, placeholder `XXXX-XXXX`)
- Bouton "Accéder"
- Messages d'erreur si token invalide

### Formulaire (`formulaire/remplir.blade.php`)

- Logo de l'association (centré, comme sur la page d'accueil)
- Titre : "Bonjour {prénom} {nom}"
- Sous-titre : "Votre inscription à **{nom de l'opération}**, du {date_debut} au {date_fin}, {nombre_seances} séances."
- Si date_debut ou date_fin est null, adapter le texte (omettre les dates manquantes)

**Section Coordonnées :**
- Téléphone (pré-rempli)
- Email (pré-rempli)
- Adresse (pré-remplie)
- Code postal + Ville (pré-remplis)

**Section Données de santé :**
- Bandeau : "Ces informations sont confidentielles et chiffrées."
- Date de naissance
- Sexe (select : —, Masculin, Féminin)
- Taille (cm)
- Poids (kg)
- Informations complémentaires (textarea, ex : allergies, traitements)

**Bouton** : "Envoyer" → ouvre une modale Bootstrap de confirmation

**Modale de confirmation (JavaScript côté client) :**
- Récapitulatif des données saisies (construit en JS depuis les champs du formulaire)
- Bouton "Confirmer" → soumet le POST
- Bouton "Modifier" → ferme la modale, retour au formulaire

Pas de page séparée ni de données en session — tout se passe sur une seule page.

### Remerciement

Après soumission réussie, le contrôleur redirige vers `formulaire.index` avec un flash `success` :
- "Merci ! Vos informations ont bien été enregistrées. Vous pouvez fermer cette page."

## Intégration dans l'onglet Participants

### Génération de token

Nouvelle action dans `ParticipantTable` :

- **`genererToken(int $participantId)`** : crée ou remplace le token pour ce participant
  - Calcule l'expiration par défaut : `operation.date_debut - 1 jour` (si date_debut existe et est dans le futur), sinon 30 jours à partir d'aujourd'hui
  - Ouvre une modale avec : le lien complet, le code seul, l'expiration (éditable), bouton copier

### Colonne de suivi

Dans le tableau participants, une colonne "Formulaire" avec badge :

| État | Badge | Icône |
|------|-------|-------|
| Pas de token | — | — |
| Token créé, en attente | `En attente` (jaune) | `bi-hourglass` |
| Token expiré, non rempli | `Expiré` (gris) | `bi-clock-history` |
| Formulaire rempli | `Rempli` (vert) + date | `bi-check-circle` |

Clic sur le badge "En attente" → rouvre la modale avec le lien/code (pour recopier).

## Sécurité

| Mesure | Détail |
|--------|--------|
| Token non devinable | 8 caractères × 23 symboles = ~8 milliards de combinaisons |
| Rate limiting | 10 requêtes/min par IP sur `/formulaire/*` |
| Messages génériques | "Code invalide ou expiré" (ne révèle pas si le code existe) |
| Write-only | Le formulaire ne montre pas les données médicales existantes |
| CSRF | Token Laravel standard sur le POST |
| HTTPS | Obligatoire en production (déjà en place) |
| Chiffrement données | Les données médicales sont chiffrées en base (encrypted casts) |
| Expiration | Date configurable par l'animateur |
| Usage unique | `rempli_at` empêche la réutilisation |
| Traçabilité RGPD | IP + horodatage de la soumission stockés |
| Cascade | Suppression du participant → suppression du token |

## Tests

- **Token** : génération (format, unicité, alphabet), validation (valide, expiré, utilisé), normalisation (avec/sans tiret, casse)
- **Formulaire** : affichage avec token valide, refus token invalide/expiré/utilisé, soumission correcte, merge intelligent coordonnées, écriture données médicales
- **Rate limiting** : vérifier que le throttle bloque après N tentatives
- **Intégration** : génération depuis ParticipantTable, affichage des badges de suivi
