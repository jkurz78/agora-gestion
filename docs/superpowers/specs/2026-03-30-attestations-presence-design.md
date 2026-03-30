# Attestations de présence — Design

**Date :** 2026-03-30
**Version cible :** v2.3.2

## Contexte

Permettre de générer et envoyer par email des attestations de présence aux participants d'une opération. Deux variantes : attestation pour une séance donnée, et récapitulatif de toutes les séances. Le socle email (EmailTemplate catégorie `attestation`, EmailLog, gabarit TinyMCE) est déjà en place.

---

## 1. Cachet et signature — champ sur Association

### Migration

Ajouter un champ `cachet_signature_path` (string, nullable) à la table `association`.

### Modèle

Ajouter `cachet_signature_path` au fillable et casts de `Association`.

### IHM Paramètres

Dans l'écran Paramètres > Association (existant), ajouter un champ **"Cachet et signature du président"** (upload image PNG/JPG, max 2 Mo). Même pattern que le champ logo existant (`logo_path`). Stockage dans `public/` comme le logo.

Si le cachet n'est pas configuré, le PDF est généré sans et la modale d'envoi affiche un avertissement "Cachet et signature non configuré".

---

## 2. PDF Attestation de présence

### Contrôleur

`AttestationPresencePdfController` avec deux méthodes :

- `seance(Operation, Seance)` + query param `participants=1,3,7` → PDF multi-pages, une attestation par participant
- `recap(Operation, Participant)` → PDF une page, récap de toutes les séances du participant

### Routes

```
GET /gestion/operations/{operation}/seances/{seance}/attestation-pdf?participants=1,2,3
    → AttestationPresencePdfController::seance()
    → route: gestion.operations.seances.attestation-pdf

GET /gestion/operations/{operation}/participants/{participant}/attestation-recap-pdf
    → AttestationPresencePdfController::recap()
    → route: gestion.operations.participants.attestation-recap-pdf
```

### Vue Blade unique

`resources/views/pdf/attestation-presence.blade.php` — gère les deux variantes via une variable `$mode` (`seance` ou `recap`).

**Layout :**
- En-tête : logo type opération (fallback logo association), nom de l'association, adresse
- Titre : "Attestation de présence"
- Corps variante séance : "L'association {nom} atteste que {prénom nom}, né(e) le {date naissance}, a participé à la séance n°{X} du {date} de l'opération {nom opération} ({date début} — {date fin})."
- Corps variante récap : même intro + tableau des séances (n°, date, titre) + total "{X} séance(s) sur {Y}"
- Pied : "Fait à {ville association}, le {date du jour}" + image cachet/signature (si configurée)
- Footer : "Généré le {date heure}" (comme les autres PDFs)

**Technique :** DomPDF (A4 portrait), même pattern que `ParticipantFichePdfController` pour la résolution des logos.

### Multi-pages (variante séance)

Quand plusieurs participants sont sélectionnés, le PDF contient une page par participant avec `page-break-after: always`.

---

## 3. Mailable AttestationPresenceMail

### Classe

`App\Mail\AttestationPresenceMail` — même pattern que `FormulaireInvitation`.

**Constructeur :**
- `prenomParticipant`, `nomParticipant`, `nomOperation`, `nomTypeOperation`
- `dateDebut`, `dateFin`, `nombreSeances`
- `numeroSeance` (nullable — null pour le récap), `dateSeance` (nullable)
- `customObjet`, `customCorps` (depuis EmailTemplate)
- `pdfContent` (string — le contenu binaire du PDF)
- `pdfFilename` (string — nom du fichier attaché)

**Variables de substitution :** `{prenom}`, `{nom}`, `{operation}`, `{type_operation}`, `{date_debut}`, `{date_fin}`, `{nb_seances}`, `{numero_seance}`, `{date_seance}` — déjà déclarées dans `CategorieEmail::Attestation`.

**Pièce jointe :** le PDF est attaché via `attachData($pdfContent, $pdfFilename, ['mime' => 'application/pdf'])`.

**From :** `typeOperation.email_from` / `email_from_name`.

### Logging

Chaque envoi crée un `EmailLog` avec :
- `categorie = 'attestation'`
- `tiers_id`, `participant_id`, `operation_id`
- `destinataire_email`, `destinataire_nom`, `objet`
- `statut = 'envoye'` ou `'erreur'`
- `envoye_par = auth()->id()`

---

## 4. IHM — Écran Séances (SeanceTable)

### Bouton bas de colonne — attestation par séance

Sous chaque colonne de séance dans la matrice, un bouton icône (`bi-envelope-paper`). Au clic → ouvre une **modale** :

- **Titre :** "Attestation — Séance n°{X} du {date}"
- **Avertissement** (si cachet non configuré) : alerte jaune "Cachet et signature non configuré dans les paramètres"
- **Liste** des participants présents (statut = `Present`) à cette séance, chacun avec :
  - Checkbox (tous cochés par défaut)
  - Nom, prénom
  - Email (ou mention "pas d'email" en grisé, checkbox désactivée pour l'envoi email)
- **Boutons d'action :**
  - "Envoyer par email" — envoie l'attestation PDF individuelle par email aux participants cochés qui ont un email. Crée un EmailLog par envoi. Affiche un résumé après (X envoyés, Y erreurs, Z sans email).
  - "Télécharger PDF" — génère un PDF multi-pages (une page par participant coché), téléchargement direct.

### Bouton bout de ligne — récap participant

En fin de chaque ligne participant dans la matrice, un bouton icône (`bi-file-earmark-text`). Au clic → ouvre une **modale** :

- **Titre :** "Attestation récapitulative — {prénom nom}"
- **Avertissement** cachet (idem)
- **Résumé :** "{X} séance(s) sur {Y}" avec la liste des séances présent
- **Mention** si pas d'email
- **Boutons :**
  - "Envoyer par email" — envoie le récap PDF par email
  - "Télécharger PDF" — téléchargement direct

### Visibilité dans l'historique

Les emails envoyés apparaissent automatiquement dans l'onglet Historique de `ParticipantShow` (via `email_logs` catégorie `attestation`) — aucun travail supplémentaire nécessaire.

---

## 5. Garde-fous

- **Pas d'email configuré sur TypeOperation** (`email_from` null) → bouton "Envoyer par email" désactivé avec tooltip explicatif
- **Aucune présence enregistrée** → bouton bas de colonne masqué ou désactivé
- **Participant sans aucune présence** → bouton récap masqué ou désactivé
- **Permission** : les séances ne sont visibles que si `peut_voir_donnees_sensibles` — les boutons d'attestation héritent de cette contrainte

---

## Ce qui n'est PAS dans le périmètre

- PDF/A ou protection du PDF — DomPDF standard suffit pour les attestations associatives
- Factur-X — sera traité dans un chantier facturation dédié
- Envoi automatique / semi-automatique — tout est déclenché manuellement
- Attestation de don — le cachet/signature sera réutilisé mais le chantier est séparé
