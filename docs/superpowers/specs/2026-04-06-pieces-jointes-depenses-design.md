# Pièces jointes sur les dépenses

**Date** : 2026-04-06
**Statut** : Spec validée

## Objectif

Permettre d'attacher un justificatif (facture fournisseur, reçu) à une transaction de type dépense. Le fichier est consultable ultérieurement depuis la transaction. Dans l'espace gestion encadrants, l'upload du justificatif est proposé comme première étape avant la saisie, avec un affichage split view (prévisualisation + formulaire).

## Périmètre

- Pièce jointe unique par transaction de type dépense
- Formats : PDF, JPG, PNG
- Taille max : 10 Mo
- Actions : uploader, remplacer, supprimer, consulter

**Hors périmètre** : OCR / analyse IA du document, multi-fichier, pièces jointes sur recettes/dons/cotisations.

## Données & Stockage

### Migration

Ajouter 3 colonnes nullable sur la table `transactions` :

| Colonne | Type | Description |
|---------|------|-------------|
| `piece_jointe_path` | `string(500)` | Chemin relatif dans le storage |
| `piece_jointe_nom` | `string(255)` | Nom original du fichier uploadé |
| `piece_jointe_mime` | `string(100)` | Type MIME (application/pdf, image/jpeg, image/png) |

### Stockage physique

- Disk : `local` (privé, pas accessible publiquement)
- Chemin : `pieces-jointes/{transaction_id}/{fichier}`
- Un seul fichier par dossier — un upload remplace l'existant (suppression de l'ancien)

### Téléchargement / Consultation

- Route : `GET /transactions/{transaction}/piece-jointe`
- Contrôleur authentifié avec vérification des droits d'accès (espace)
- Réponse : `Storage::response()` avec `Content-Disposition: inline; filename="{nom_original}"`
- `inline` permet au navigateur d'afficher le PDF/image nativement, avec option de sauvegarder

## Modèle Transaction

Ajouts au modèle `Transaction` :

- Champs ajoutés au `$fillable` : `piece_jointe_path`, `piece_jointe_nom`, `piece_jointe_mime`
- `hasPieceJointe(): bool` — retourne `true` si `piece_jointe_path` est non null
- `pieceJointeUrl(): ?string` — retourne l'URL de la route de consultation, ou null

## TransactionService

Deux nouvelles méthodes :

- `storePieceJointe(Transaction $transaction, UploadedFile $file): void`
  - Supprime l'éventuel fichier existant
  - Stocke le fichier dans `pieces-jointes/{transaction_id}/`
  - Met à jour `piece_jointe_path`, `piece_jointe_nom`, `piece_jointe_mime`
- `deletePieceJointe(Transaction $transaction): void`
  - Supprime le fichier du disque
  - Remet les 3 colonnes à null

## UX — Espace Comptable (TransactionForm)

### Formulaire de création/édition

- Utilise `WithFileUploads` de Livewire
- Propriété `$pieceJointe` pour l'upload temporaire
- Zone d'upload placée sous les notes, visible uniquement pour les transactions de type `depense`
- Bouton "Joindre un justificatif" avec icône trombone

### Fichier existant

- Affichage du nom du fichier original
- Icône œil : consulter dans un nouvel onglet (lien vers la route)
- Icône poubelle : supprimer la pièce jointe

### Comportement

- Le formulaire reste en pleine largeur (pas de split view)
- Le fichier est sauvegardé définitivement dans `save()` après la création/mise à jour de la transaction
- Un nouvel upload remplace l'ancien (suppression du fichier précédent)

## UX — Espace Gestion Encadrants (AnimateurManager)

### Nouveau workflow de création

1. **Clic sur `+` dans la matrice** → la modale s'ouvre sur le **step "upload"**
   - Zone d'upload (drag & drop ou bouton "Choisir un fichier")
   - Bouton "Ignorer" pour passer directement au formulaire
2. **Si upload** → passage au **step "form" en split view**
   - Modale élargie (`modal-xl`)
   - Gauche : prévisualisation du document
   - Droite : formulaire de saisie (inchangé)
3. **Si "Ignorer"** → passage au **step "form" classique**
   - Modale `modal-lg` comme aujourd'hui
   - Formulaire pleine largeur, pas de pièce jointe

### Prévisualisation

- PDF : `<iframe>` utilisant le viewer natif du navigateur (zoom, navigation multi-pages, scroll intégrés)
- Images (JPG, PNG) : `<img>` avec zoom +/- via boutons et CSS `transform: scale()`

### Édition d'une transaction existante

- Pas de step upload — ouverture directe du formulaire
- Si la transaction a déjà une pièce jointe : split view (`modal-xl`)
- Sinon : formulaire classique (`modal-lg`)

### Composant Livewire

- `use WithFileUploads`
- Propriété `$modalPieceJointe` pour l'upload temporaire
- Propriété `$modalStep` : `'upload'` | `'form'`
- Le fichier est sauvegardé définitivement dans `saveTransaction()` après le save de la transaction

## Liste des transactions

- Icône trombone affichée dans la liste quand `piece_jointe_path` est non null
- Clic sur l'icône → ouverture de la pièce jointe dans un nouvel onglet

## Route

```
GET /transactions/{transaction}/piece-jointe
```

- Middleware : `auth`
- Vérification : l'utilisateur a accès à l'espace de la transaction
- Réponse : `Storage::response($path, $nom_original, ['Content-Type' => $mime])`
- 404 si pas de pièce jointe

## Tests

- **TransactionService** : test upload, remplacement (l'ancien fichier est supprimé), suppression, vérification des colonnes
- **Route de consultation** : auth requise, Content-Disposition correct, 404 si pas de PJ
- **TransactionForm** : upload d'une PJ lors du save d'une dépense, pas d'upload proposé pour une recette
- **AnimateurManager** : workflow step upload → form → save avec PJ attachée ; workflow "Ignorer" → form sans PJ
