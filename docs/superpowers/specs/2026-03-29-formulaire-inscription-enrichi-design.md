# Formulaire d'inscription enrichi — Spec de design

**Date :** 2026-03-29
**Statut :** Validé (brainstorming)

## Contexte

Le formulaire public d'inscription (`/formulaire`) permet aujourd'hui aux participants de saisir leurs coordonnées, données de santé et d'uploader des documents. La fiche papier EQUI-STAB contient des champs supplémentaires (contacts médicaux, engagements, mutuelle, droit à l'image) que le formulaire en ligne ne couvre pas encore.

L'objectif est de remplacer complètement la fiche papier par le formulaire en ligne.

## Approche retenue

**Wizard Alpine.js côté client** — Les 7 pages sont dans une seule vue Blade, Alpine.js gère la navigation entre étapes. Les données restent dans le DOM et ne sont persistées qu'à la soumission finale (POST unique). Pas de sauvegarde intermédiaire.

Alternatives écartées :
- **Wizard serveur (multi-routes + session)** — complexité session, risque de fuite de données, purge à gérer
- **Wizard Livewire** — incohérent avec le flow public sans auth, données en session serveur
- **Moteur de formulaire dynamique** — pas de package Laravel adapté à Livewire 4 + Bootstrap 5, trop coûteux from scratch

## Sécurité

- Un token ne permet qu'une seule soumission (comportement inchangé)
- Retour arrière autorisé au sein d'une session de navigation
- Si la session est interrompue et le participant revient avec son token, le formulaire repart de zéro (pas de données conservées entre sessions)
- Les données ne sont écrites en base qu'au POST final
- Validation front (Alpine.js) par étape pour le confort + validation serveur complète au submit

## Structure du wizard (7 pages)

### Page 1 — Coordonnées

- Carte d'accueil : nom du participant + détails opération (dates, séances)
- Champs pré-remplis depuis Tiers : téléphone, email, adresse (ligne1, CP, ville)
- Nouveaux champs : nom de jeune fille (nullable), nationalité (nullable, optionnel)
- Bloc "Adressé par" (je vous suis adressé par) : nom, prénom, téléphone, email, adresse — stockage en clair sur Participant

### Page 2 — Données de santé

- Badge "données confidentielles et chiffrées"
- Date de naissance, sexe, taille (cm), poids (kg)
- Bloc Médecin traitant : nom, prénom, téléphone, email, adresse — chiffré
- Bloc Thérapeute référent : nom, prénom, téléphone, email, adresse — chiffré
- Notes médicales (allergies, traitements, etc.) — chiffré

### Page 3 — Documents

- Upload jusqu'à 3 fichiers (PDF, JPG, PNG, 5 Mo max par fichier)
- Si TypeOperation a une `attestation_medicale_path` : lien de téléchargement du document à faire remplir par le médecin

### Page 4 — Informations pratiques

- Page informative uniquement, pas de champs de saisie
- Présentation des dispositifs : coupons sport, sport-santé, prises en charge kiné/ostéo/sophro/thérapie
- Texte issu de la fiche papier existante

### Page 5 — Engagement financier

- Récap calculé automatiquement : nombre de séances, tarif unitaire, montant total
- Choix du rythme de paiement (radio) : comptant / par séance
- Choix du mode de règlement (radio) : espèces / chèque / virement

### Page 6 — Droit à l'image

- Texte explicatif complet (repris du document PDF d'autorisation existant)
- 4 choix radio :
  - Usage propre uniquement
  - Diffusion didactique et confidentielle
  - Les deux (usage propre + diffusion)
  - Refus

### Page 7 — Engagements & signature

Cases à cocher obligatoires :
- Présence à toutes les séances
- Certificat médical de non contre-indication
- Conditions de règlement (séances dues même en cas d'absence)
- RGPD / traitement électronique des données / droit à l'oubli

Case à cocher optionnelle :
- Autorisation de contact entre l'association et le médecin/thérapeute

Re-saisie du token comme signature électronique + bouton "Valider et envoyer"

### Page de remerciement (post-soumission)

- Message de confirmation
- Si l'exercice en cours a une `helloasso_url` et le type d'opération a `reserve_adherents` : affichage du lien/widget HelloAsso pour l'adhésion

## Modèle de données

### Migration : `participant_donnees_medicales` — nouveaux champs chiffrés

| Champ | Type | Chiffré |
|-------|------|---------|
| `medecin_nom` | text, nullable | oui |
| `medecin_prenom` | text, nullable | oui |
| `medecin_telephone` | text, nullable | oui |
| `medecin_email` | text, nullable | oui |
| `medecin_adresse` | text, nullable | oui |
| `therapeute_nom` | text, nullable | oui |
| `therapeute_prenom` | text, nullable | oui |
| `therapeute_telephone` | text, nullable | oui |
| `therapeute_email` | text, nullable | oui |
| `therapeute_adresse` | text, nullable | oui |

### Migration : `participants` — nouveaux champs

| Champ | Type | Notes |
|-------|------|-------|
| `nom_jeune_fille` | varchar, nullable | |
| `nationalite` | varchar, nullable | |
| `adresse_par_nom` | varchar, nullable | Stockage en clair |
| `adresse_par_prenom` | varchar, nullable | Stockage en clair |
| `adresse_par_telephone` | varchar, nullable | Stockage en clair |
| `adresse_par_email` | varchar, nullable | Stockage en clair |
| `adresse_par_adresse` | varchar, nullable | Stockage en clair |
| `droit_image` | varchar, nullable | Enum : `usage_propre`, `diffusion_didactique`, `les_deux`, `refus` |
| `mode_paiement_choisi` | varchar, nullable | `comptant` ou `par_seance` |
| `moyen_paiement_choisi` | varchar, nullable | `especes`, `cheque`, `virement` |

### Migration : `exercices` — nouveau champ

| Champ | Type | Notes |
|-------|------|-------|
| `helloasso_url` | varchar, nullable | URL campagne adhésion annuelle |

### Migration : `type_operations` — nouveau champ

| Champ | Type | Notes |
|-------|------|-------|
| `attestation_medicale_path` | varchar, nullable | Chemin vers le document téléchargeable |

### Pas de nouvelle table

## Flow technique

### Alpine.js

Composant `x-data` englobant le formulaire :
- `step: 1` — étape courante
- `nextStep()` — valide les champs requis de l'étape, avance si OK
- `prevStep()` — retour libre
- `validateStep(n)` — validation front, retourne true/false, affiche erreurs inline

Barre de progression Bootstrap en haut. Boutons "Précédent" / "Suivant" en bas, "Valider et envoyer" sur la page 7.

### Blade

Vue `formulaire/remplir.blade.php` refactorisée. Chaque page dans un partial :
- `@include('formulaire.steps.step-1')` à `@include('formulaire.steps.step-7')`
- Affichage conditionnel : `x-show="step === 1"` etc.

### FormulaireController::store()

Validation serveur complète de tous les champs. Vérification que le token re-saisi correspond au token d'accès. Écriture en base dans une transaction DB :

1. Mise à jour Tiers (merge intelligent existant — pas d'écrasement par du vide)
2. Mise à jour Participant (nom de jeune fille, nationalité, adressé par, droit image, choix paiement)
3. Upsert ParticipantDonneesMedicales (champs existants + médecin + thérapeute)
4. Stockage documents
5. Création des lignes de Règlement si aucune n'existe pour ce participant :
   - Comptant → 1 ligne avec montant total
   - Par séance → 1 ligne par séance avec tarif unitaire
   - Chaque ligne avec le moyen de paiement choisi
   - Si des règlements existent déjà : ne rien toucher
6. Marquage token comme utilisé (`rempli_at`, `rempli_ip`)

Redirect POST/GET vers `formulaire/merci` (nouvelle vue).

### Page de remerciement

Nouvelle vue `formulaire/merci.blade.php`. Affichage conditionnel du lien HelloAsso basé sur `Exercice::helloasso_url` + `TypeOperation::reserve_adherents`.

## Périmètre V1 / V2

### V1 (ce lot)

- Formulaire multi-pages (7 étapes) avec Alpine.js
- Tous les champs et sections décrits ci-dessus
- Attestation médicale téléchargeable par TypeOperation
- URL HelloAsso sur Exercice + affichage conditionnel post-soumission
- Report des choix de paiement sur les Règlements
- Sections droit à l'image et engagements codées en dur

### V2 (lot suivant)

- Flags par TypeOperation pour activer/désactiver des sections du formulaire
- Sections et cases d'engagement paramétrables par type
- Historique des envois d'emails (chantier séparé existant)
