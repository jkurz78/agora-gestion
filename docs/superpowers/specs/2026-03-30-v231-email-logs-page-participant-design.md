# v2.3.1 — Table email_logs + Page participant dédiée

**Date :** 2026-03-30
**Version cible :** v2.3.1

## Contexte

Deux livrables repoussés après la v2.3.0 :
1. Traçabilité des emails envoyés — aucun historique n'existe actuellement
2. L'édition participant est un panneau plein écran dans `ParticipantTable` — le transformer en page dédiée avec sa propre URL

Ces deux fonctionnalités préparent le terrain pour une future fiche Tiers 360° (chantier ultérieur).

---

## Livrable 1 : Table `email_logs`

### Migration

```
email_logs
├── id (bigIncrements)
├── tiers_id (FK tiers, nullable, SET NULL on delete)
├── participant_id (FK participants, nullable, SET NULL on delete)
├── operation_id (FK operations, nullable, SET NULL on delete)
├── categorie (string) : 'formulaire', 'attestation', 'facture', etc.
├── email_template_id (FK email_templates, nullable, SET NULL on delete)
├── destinataire_email (string) — adresse effective au moment de l'envoi
├── destinataire_nom (string, nullable) — nom au moment de l'envoi
├── objet (string) — sujet effectif du mail envoyé
├── statut (string) : 'envoye', 'erreur'
├── erreur_message (text, nullable) — message d'erreur si échec
├── envoye_par (FK users, nullable, SET NULL on delete)
├── timestamps
```

### Choix de design

- **SET NULL** sur toutes les FK — si on supprime un tiers/participant, le log reste (traçabilité)
- **`destinataire_email` et `destinataire_nom` en dur** — le log reflète ce qui a été réellement envoyé, même si le tiers change d'email ensuite
- **`objet` stocké** — lecture rapide sans reconstituer depuis le template
- **Pas de stockage du corps HTML** — trop volumineux, peu utile en consultation
- **`categorie` en string** plutôt qu'enum PHP — extensible sans migration. Note : `EmailTemplate.categorie` utilise l'enum `CategorieEmail`, mais `email_logs.categorie` est volontairement un string libre pour pouvoir tracer des catégories futures sans migration
- **`envoye_par`** — l'utilisateur connecté qui a déclenché l'envoi
- **`Participant` n'a pas de SoftDeletes** — quand un participant est supprimé, `participant_id` passe à NULL mais le log reste avec `destinataire_email`/`destinataire_nom` en snapshot

### Modèle Eloquent

`EmailLog` avec `declare(strict_types=1)`, `final class`.

Relations :
- `tiers(): BelongsTo` (Tiers)
- `participant(): BelongsTo` (Participant)
- `operation(): BelongsTo` (Operation)
- `emailTemplate(): BelongsTo` (EmailTemplate)
- `envoyePar(): BelongsTo` (User)

Relations inverses à ajouter :
- `Tiers::emailLogs(): HasMany`
- `Participant::emailLogs(): HasMany`
- `Operation::emailLogs(): HasMany`

### Intégration immédiate

Câbler l'enregistrement dans `ParticipantTable::envoyerTokenParEmail()` :
- Après envoi réussi → créer `EmailLog` avec `statut = 'envoye'`
- En cas d'erreur → créer `EmailLog` avec `statut = 'erreur'` et `erreur_message`
- Renseigner `tiers_id`, `participant_id`, `operation_id`, `categorie = 'formulaire'`, `email_template_id`, `destinataire_email`, `destinataire_nom`, `objet`, `envoye_par = auth()->id()`

### Non-concerné

La réception du formulaire (côté participant) n'est pas loggée ici — `formulaire_tokens.rempli_at` fait déjà foi.

---

## Livrable 2 : Page participant imbriquée

### Architecture d'imbrication

Le composant `GestionOperations` gère déjà la vue opération avec ses onglets. Quand `activeTab === 'participants'`, il rend `<livewire:participant-table>`.

**Mécanisme :** `GestionOperations` reçoit un paramètre URL optionnel `participant` (via `#[Url]`). Quand ce paramètre est présent et que `activeTab === 'participants'`, le composant rend `<livewire:participant-show>` à la place de `<livewire:participant-table>`. Pas de nouvelle route ni de nouveau contrôleur — tout reste dans `GestionOperations`.

**URL résultante :** `/gestion/operations?id={operation_id}&participant={participant_id}` — utilise le mécanisme `#[Url]` existant de Livewire, cohérent avec le `#[Url(as: 'id')]` déjà en place pour `selectedOperationId`.

### Navigation

- Dans `ParticipantTable`, clic sur le nom du participant → met à jour l'URL avec `?participant={id}`, ce qui déclenche le rendu de `ParticipantShow` (remplace l'ouverture du panneau plein écran)
- La page s'affiche **dans le contenu de l'onglet Participants** de la vue opération — le header opération et les onglets principaux restent visibles
- Lien "← Retour à la liste des participants" en haut → retire le paramètre `participant` de l'URL, ce qui revient à `ParticipantTable`

### Composant Livewire

Nouveau composant `ParticipantShow` qui remplace la logique d'édition actuellement dans `ParticipantTable`.

### Onglets

1. **Coordonnées** — édition Tiers (nom, prénom, téléphone, email, adresse, CP, ville)
2. **Données personnelles** — nom jeune fille, nationalité, date naissance, sexe, taille, poids
3. **Contacts médicaux** — médecin + thérapeute (avec boutons mapping Tiers existants)
4. **Adressé par** — prescripteur (avec bouton mapping Tiers)
5. **Notes** — notes médicales chiffrées (textarea)
6. **Engagements** — droit image, mode/moyen paiement, RGPD, autorisation contact médecin (lecture seule, rempli par le formulaire)
7. **Documents** — fichiers uploadés, liens de téléchargement
8. **Historique** — **(nouveau)** timeline combinée, voir section dédiée

### Onglet Historique

Timeline chronologique inverse combinant :
- **Emails envoyés** : depuis `email_logs` filtré sur `participant_id`
- **Formulaire rempli** : depuis `formulaire_tokens.rempli_at`

Chaque entrée affiche : date, icône par catégorie, description (ex: "Formulaire envoyé à jean@example.com", "Formulaire rempli le 15/03/2026 depuis 82.123.xx.xx").

Extensible : quand on ajoutera l'envoi d'attestations/factures, ils apparaîtront automatiquement via `email_logs`.

### Sauvegarde et protection

- **Bouton "Enregistrer"** en bas de page — sauvegarde les champs modifiés de l'onglet actif
- **Détection de modifications** : Livewire track `$isDirty` sur les propriétés du formulaire
- **`beforeunload` JS** : intercepte la navigation navigateur si modifications non sauvegardées
- **Changement d'onglet opération / clic retour** : confirmation "Vous avez des modifications non sauvegardées. Quitter quand même ?"

### Simplification de ParticipantTable

Supprimer de `ParticipantTable` :
- Le panneau plein écran d'édition (`openEditModal`, `saveEdit`, toute la logique de formulaire associée)
- Les propriétés liées aux onglets d'édition
- La vue Blade du panneau

Conserver dans `ParticipantTable` :
- Liste des participants avec inline editing rapide (nom, prénom, téléphone)
- Ajout de participant (modale existante)
- Gestion des tokens (génération, envoi email)
- Export PDF/Excel

---

## Ce qui n'est PAS dans le périmètre v2.3.1

- Fiche Tiers 360° (adhésions, dons, fournisseur, vision transversale) — chantier ultérieur
- Affichage des règlements/présences dans la page participant — déjà accessible dans les onglets opération
- Envoi d'attestations de présence par email — le socle `email_logs` est prêt mais le câblage sera fait plus tard
- Stockage du corps HTML des emails
