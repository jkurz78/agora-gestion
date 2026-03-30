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
| **Engagements** | `formulaire_parcours_therapeutique` ou `formulaire_droit_image` | Récap des choix : présence, certificat, règlement, RGPD, autorisation contact, droit image, mode/moyen paiement, date soumission |
| **Documents** | `formulaire_parcours_therapeutique` + permission données sensibles | Liste des fichiers uploadés avec lien de téléchargement |

### Mapping Tiers

Même pattern que HelloAsso. Disponible sur les blocs "adressé par", "médecin", "thérapeute" si au moins nom + prénom sont renseignés.

**Deux actions :**
- **Chercher un tiers existant** — autocomplete, puis association
- **Créer un tiers** — pré-remplit la création depuis les données texte, puis association

**Stockage du mapping :**
- Adressé par → `Participant.refere_par_id` (FK existante vers Tiers)
- Médecin → nouveau champ `Participant.medecin_tiers_id` (FK nullable vers Tiers)
- Thérapeute → nouveau champ `Participant.therapeute_tiers_id` (FK nullable vers Tiers)

**Affichage :** si un Tiers est mappé, on affiche ses données (prioritaires) avec un badge "Tiers associé". Les données texte du formulaire restent visibles en dessous comme référence. Bouton pour dissocier.

### Pré-remplissage formulaire public

Dans `FormulaireController::show()`, si les champs `adresse_par_*` sont vides mais `refere_par_id` est renseigné, pré-remplir les champs du formulaire depuis le Tiers référé par (nom, prénom, tel, email, adresse, CP, ville, entreprise→établissement).

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

### Double gate

Toutes les nouvelles colonnes sont conditionnées par :
1. Le flag TypeOperation (`formulaire_prescripteur`, `formulaire_parcours_therapeutique`, `formulaire_droit_image`)
2. La case cochée par l'utilisateur dans l'UI d'export ("Données confidentielles")

Les deux conditions doivent être remplies pour que les colonnes apparaissent.

### Excel (ParticipantExportController)

Nouvelles colonnes conditionnelles (gate `formulaire_parcours_therapeutique` + case UI) :
- Nom de jeune fille
- Nationalité
- Date naissance, sexe, taille (cm), poids (kg)
- Médecin : nom, prénom, tel, email, adresse, CP, ville — **priorité Tiers mappé**, fallback données texte
- Thérapeute : nom, prénom, tel, email, adresse, CP, ville — **priorité Tiers mappé**, fallback données texte
- Notes médicales
- Autorisation contact médecin (Oui/Non)
- Droit à l'image (libellé)
- Mode paiement choisi, moyen paiement choisi
- Tarif (libellé + montant), montant par séance
- RGPD accepté le (date)

Nouvelles colonnes conditionnelles (gate `formulaire_prescripteur` + case UI) :
- Adressé par : établissement, nom, prénom, tel, email, adresse, CP, ville — **priorité Tiers mappé** (`refere_par_id`), fallback données texte

### PDF Liste (ParticipantPdfController)

Conservateur sur la largeur. On ajoute seulement en mode confidentiel :
- Médecin (nom)
- Thérapeute (nom)
- Droit image (abrégé)
- Mode paiement

### PDF Annuaire (ParticipantPdfController)

Les fiches individuelles s'enrichissent avec toutes les données conditionnelles (même logique que l'Excel, format carte).

## Nouveaux PDFs

### Fiche individuelle participant

**Route :** `GET /gestion/operations/{operation}/participants/{participant}/pdf`

PDF une page par participant avec toutes les données, double gate (flags + case UI). Sections :
- En-tête : opération, type, dates
- Coordonnées complètes
- Adressé par (si flag prescripteur)
- Données de santé + contacts médicaux (si flag parcours + case UI)
- Engagements (récap des cases cochées)
- Droit à l'image (choix + date soumission)
- Documents uploadés (liste des noms de fichiers)

### Autorisation droit à l'image

**Route :** `GET /gestion/operations/{operation}/participants/{participant}/droit-image-pdf`

**Condition :** disponible uniquement si `formulaire_droit_image` est actif ET le participant a soumis un choix.

PDF reprenant la mise en page du document papier EQUI-STAB, pré-rempli avec :
- Saison sportive (exercice)
- Nom, prénom du participant
- Le choix coché parmi les 4 options
- Mention "Signé électroniquement le {date soumission du formulaire}" à la place de la signature manuscrite
- Le qualificatif dynamique des ateliers (depuis `formulaire_qualificatif_atelier`)

## Accès aux nouveaux PDFs

Boutons ajoutés dans :
- La modale détaillée participant (bouton "Imprimer fiche" + bouton "Autorisation photo" si applicable)
- Le dropdown actions de ParticipantTable (même boutons)
