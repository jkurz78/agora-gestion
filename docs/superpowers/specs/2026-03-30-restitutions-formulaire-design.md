# Restitutions données formulaire — Spec de design

**Date :** 2026-03-30
**Statut :** Validé (brainstorming)

## Contexte

Le formulaire d'inscription enrichi collecte des données (contacts médicaux, adressé par, droit à l'image, engagements, choix paiement) qui ne sont pas encore restituées dans l'interface admin ni dans les exports. Ce lot ajoute l'affichage, le mapping Tiers, et les exports PDF/Excel.

## Modale détaillée participant

La modale d'édition existante dans ParticipantTable est enrichie avec des onglets conditionnels.

### Onglets

| Onglet | Condition d'affichage | Contenu |
|--------|----------------------|---------|
| **Coordonnées** | Toujours | Nom, prénom, tel, email, adresse, CP, ville |
| **Parcours** | `formulaire_parcours_therapeutique` + permission données sensibles | NJF, nationalité, date naissance, sexe, taille, poids, notes médicales, médecin, thérapeute — chacun avec bouton mapping Tiers |
| **Adressé par** | `formulaire_prescripteur` | Établissement, nom, prénom, tel, email, adresse, CP, ville + bouton mapping Tiers |
| **Notes** | `formulaire_parcours_therapeutique` + permission données sensibles | Textarea haute (min 15 rows) pour les notes médicales, éditable. Remplace la modale notes existante. |
| **Engagements** | `formulaire_parcours_therapeutique` ou `formulaire_droit_image` | Récap des choix : présence, certificat, règlement, RGPD, autorisation contact, droit image, mode/moyen paiement, date soumission |
| **Documents** | `formulaire_parcours_therapeutique` + permission données sensibles | Liste des fichiers uploadés avec lien de téléchargement |

**Participants sans soumission de formulaire :** les onglets conditionnels affichent un message vide ("Aucune donnée collectée via le formulaire") plutôt qu'une erreur. Le code doit gérer `$participant->donneesMedicales` null avant d'accéder aux champs.

### Mapping Tiers

Même pattern que HelloAsso. Réutilise le composant `TiersAutocomplete` existant. Disponible sur les blocs "adressé par", "médecin", "thérapeute" si au moins nom + prénom sont renseignés dans les données texte (vérifier `donneesMedicales` non null avant d'accéder aux champs médecin/thérapeute).

**Deux actions :**
- **Chercher un tiers existant** — autocomplete via `TiersAutocomplete`, puis association
- **Créer un tiers** — pré-remplit la création depuis les données texte, puis association

**Stockage du mapping :**
- Adressé par → `Participant.refere_par_id` (FK existante vers Tiers)
- Médecin → nouveau champ `Participant.medecin_tiers_id` (FK nullable vers Tiers)
- Thérapeute → nouveau champ `Participant.therapeute_tiers_id` (FK nullable vers Tiers)

**Rationale placement FK :** les FK de mapping sont sur `participants` (pas sur `participant_donnees_medicales`) car le mapping est une action admin, pas une donnée patient soumise via le formulaire. Les données texte chiffrées restent dans `participant_donnees_medicales`.

**Affichage :** si un Tiers est mappé, on affiche ses données (prioritaires) avec un badge "Tiers associé". Les données texte du formulaire restent visibles en dessous comme référence. Bouton pour dissocier — la dissociation met le FK à null mais préserve les données texte.

**Dérive des données :** une fois mappé, les exports utilisent les données du Tiers (source de vérité). Si le Tiers est modifié ultérieurement (ex: le médecin déménage), les exports refléteront les données à jour du Tiers, pas celles saisies au moment du formulaire. C'est le comportement voulu.

### Pré-remplissage formulaire public

Dans `FormulaireController::show()`, ajouter `referePar` aux eager loads. Si les champs `adresse_par_*` sont vides mais `refere_par_id` est renseigné, pré-remplir les champs du formulaire depuis le Tiers référé par (nom, prénom, tel, email, adresse, CP, ville, entreprise→établissement).

## Modèle de données

### Migration `participants` — nouveaux champs

| Champ | Type | Notes |
|-------|------|-------|
| `medecin_tiers_id` | foreignId, nullable, constrained, nullOnDelete | FK vers Tiers |
| `therapeute_tiers_id` | foreignId, nullable, constrained, nullOnDelete | FK vers Tiers |

### Modèle Participant

Nouvelles relations :
- `medecinTiers()` → BelongsTo Tiers
- `therapeuteTiers()` → BelongsTo Tiers

## Exports enrichis

### Triple gate

Toutes les nouvelles colonnes sont conditionnées par trois conditions cumulatives :
1. Le **flag TypeOperation** correspondant (`formulaire_prescripteur`, `formulaire_parcours_therapeutique`, ou `formulaire_droit_image`)
2. La **case cochée** par l'utilisateur dans l'UI d'export ("Données confidentielles")
3. La **permission utilisateur** `peut_voir_donnees_sensibles`

Les trois conditions doivent être remplies pour que les colonnes apparaissent.

### Variables de gate dans les exports

Trois variables de gate indépendantes, chacune associée à son flag :

```php
$showParcours = $request->boolean('confidentiel')
    && ($typeOperation?->formulaire_parcours_therapeutique ?? false)
    && ($request->user()->peut_voir_donnees_sensibles ?? false);

$showPrescripteur = $request->boolean('confidentiel')
    && ($typeOperation?->formulaire_prescripteur ?? false)
    && ($request->user()->peut_voir_donnees_sensibles ?? false);

$showDroitImage = $request->boolean('confidentiel')
    && ($typeOperation?->formulaire_droit_image ?? false)
    && ($request->user()->peut_voir_donnees_sensibles ?? false);
```

### Excel (ParticipantExportController)

Nouvelles colonnes conditionnelles (gate `$showParcours`) :
- Nom de jeune fille
- Nationalité
- Date naissance, sexe, taille (cm), poids (kg)
- Médecin : nom, prénom, tel, email, adresse, CP, ville — **priorité Tiers mappé**, fallback données texte
- Thérapeute : nom, prénom, tel, email, adresse, CP, ville — **priorité Tiers mappé**, fallback données texte
- Notes médicales
- Autorisation contact médecin (Oui/Non)
- Mode paiement choisi, moyen paiement choisi
- Tarif (libellé + montant), montant par séance
- RGPD accepté le (date)

Nouvelles colonnes conditionnelles (gate `$showPrescripteur`) :
- Adressé par : établissement, nom, prénom, tel, email, adresse, CP, ville — **priorité Tiers mappé** (`refere_par_id`), fallback données texte

Nouvelles colonnes conditionnelles (gate `$showDroitImage`) :
- Droit à l'image (libellé du choix)

### PDF Liste (ParticipantPdfController)

Conservateur sur la largeur. On ajoute seulement en mode confidentiel :
- Médecin (nom) — gate `$showParcours`
- Thérapeute (nom) — gate `$showParcours`
- Droit image (abrégé) — gate `$showDroitImage`
- Mode paiement — gate `$showParcours`

### PDF Annuaire (ParticipantPdfController)

Les fiches individuelles s'enrichissent avec toutes les données conditionnelles (même logique que l'Excel, format carte).

## Nouveaux PDFs

### Nouveaux controllers

Deux nouveaux controllers dédiés (les routes existantes ont une signature différente) :
- `ParticipantFichePdfController` — fiche individuelle
- `DroitImagePdfController` — autorisation droit à l'image

Les deux routes sont dans le groupe `gestion` existant (même middleware auth + même protection).

### Fiche individuelle participant

**Route :** `GET /gestion/operations/{operation}/participants/{participant}/pdf`
**Controller :** `ParticipantFichePdfController`

PDF une page par participant. Sections conditionnées par les mêmes gates (flags + permission utilisateur) :
- En-tête : opération, type, dates
- Coordonnées complètes
- Adressé par (si `formulaire_prescripteur`)
- Données de santé + contacts médicaux (si `formulaire_parcours_therapeutique` + permission)
- Engagements (récap des cases cochées)
- Droit à l'image (choix + date soumission, si `formulaire_droit_image`)
- Documents uploadés (liste des noms de fichiers)

### Autorisation droit à l'image

**Route :** `GET /gestion/operations/{operation}/participants/{participant}/droit-image-pdf`
**Controller :** `DroitImagePdfController`

**Condition :** disponible uniquement si `formulaire_droit_image` est actif ET le participant a un choix `droit_image` non null.

PDF reprenant la mise en page du document papier EQUI-STAB, pré-rempli avec :
- Saison sportive (exercice)
- Nom, prénom du participant
- Le choix coché parmi les 4 options
- Mention "Signé électroniquement le {date}" — date = `formulaireToken.rempli_at` (timestamp de soumission du formulaire)
- Le qualificatif dynamique des ateliers (depuis `formulaire_qualificatif_atelier`)

## Accès aux nouveaux PDFs

Boutons ajoutés dans :
- La modale détaillée participant (bouton "Imprimer fiche" + bouton "Autorisation photo" si applicable)
- Le dropdown actions de ParticipantTable (même boutons)
