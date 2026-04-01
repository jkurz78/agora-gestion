# Documents prévisionnels : Devis et Pro forma

**Date :** 2026-04-01
**Version :** 1.0
**Statut :** Design validé

## Contexte et besoin

Les participants à une opération ont parfois besoin d'un document justificatif **avant** d'avoir réglé, pour obtenir une prise en charge (mutuelle, organisme social). Le système de facturation actuel ne peut émettre de facture qu'à partir de transactions existantes (recettes comptabilisées).

Deux documents répondent à ce besoin, avec des niveaux de détail différents :

| Document | Contenu | Usage |
|----------|---------|-------|
| **Devis** | 1 ligne synthétique par opération | Engagement de prix pour prise en charge |
| **Pro forma** | 1 ligne par séance avec date | Suivi détaillé du parcours prévu |

Ces documents ne sont **pas des factures**. Ils n'ont aucun impact comptable et ne modifient pas le système de facturation existant.

## Périmètre

### Inclus

- Émission de devis et pro forma depuis l'onglet règlement d'une opération
- Versionnage automatique (nouvelle version si les montants ont changé)
- Stockage en base + PDF archivé
- PDF/A-3 avec XML métadonnées embarqué
- Affichage dans la timeline de la fiche participant
- Ouverture du PDF dans un nouvel onglet navigateur

### Exclus (hors périmètre)

- Modification d'un document émis (snapshot immuable, on émet une nouvelle version)
- Suppression de documents
- Facture "à payer" ex nihilo (chantier séparé, approche "lignes découplées")
- Facture après prestation avant règlement (chantier séparé)
- Envoi par email (pourra être ajouté ultérieurement)

## Architecture : Approche A — Modèle dédié léger

Le module est **totalement indépendant** du système de facturation. Pas de modification des modèles Facture, FactureLigne, ou FactureService. Le seul point commun est le gabarit visuel PDF (partials Blade partagés).

### Modèle de données

**Table `documents_previsionnels` :**

| Champ | Type | Description |
|-------|------|-------------|
| `id` | bigint, PK | |
| `operation_id` | FK → operations | Opération source |
| `participant_id` | FK → participants | Participant concerné |
| `type` | varchar | `devis` ou `proforma` (enum) |
| `numero` | varchar, unique | `D-{exercice}-{seq}` ou `PF-{exercice}-{seq}` |
| `version` | int | Incrémenté automatiquement par (operation, participant, type) |
| `date` | date | Date d'émission |
| `montant_total` | decimal(10,2) | Somme des montants au moment de l'émission |
| `lignes_json` | json | Snapshot des lignes (voir structure ci-dessous) |
| `pdf_path` | varchar, nullable | Chemin vers le PDF stocké |
| `saisi_par` | FK → users | Utilisateur ayant émis le document |
| `exercice` | int | Exercice comptable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Contrainte unique :** `(operation_id, participant_id, type, version)`

**Numérotation :** compteur séquentiel indépendant par type et exercice, non lié à la numérotation des factures.

### Structure du JSON `lignes_json`

Les deux types de document commencent par une **ligne d'en-tête textuelle** (sans montant) décrivant le contexte de l'opération, suivie des lignes de montant.

**Devis** (en-tête + 1 ligne agrégée) :
```json
[
  {
    "type": "texte",
    "libelle": "Sophrologie du 15/09/2026 au 15/12/2026 en 10 séances :"
  },
  {
    "type": "montant",
    "libelle": "Sophrologie — 10 séances",
    "montant": 150.00
  }
]
```

**Pro forma** (en-tête + 1 ligne par séance) :
```json
[
  {
    "type": "texte",
    "libelle": "Sophrologie du 15/09/2026 au 15/12/2026 en 10 séances :"
  },
  {
    "type": "montant",
    "libelle": "Séance 1 — 15/09/2026",
    "montant": 15.00,
    "seance_id": 42
  },
  {
    "type": "montant",
    "libelle": "Séance 2 — 22/09/2026",
    "montant": 15.00,
    "seance_id": 43
  }
]
```

La ligne d'en-tête est construite depuis : `"{opération.nom} du {première séance.date} au {dernière séance.date} en {n} séance(s) :"`.

### Enum `TypeDocumentPrevisionnel`

```php
enum TypeDocumentPrevisionnel: string
{
    case Devis = 'devis';
    case Proforma = 'proforma';
}
```

## Service : `DocumentPrevisionnelService`

### `emettre(Operation $operation, Participant $participant, TypeDocumentPrevisionnel $type): DocumentPrevisionnel`

1. Vérifie que l'exercice est ouvert
2. Lit les reglements du participant pour cette opération (via séances)
3. Construit les lignes selon le type :
   - **En-tête commun** (ligne texte) : `"{opération.nom} du {première séance.date} au {dernière séance.date} en {n} séance(s) :"`
   - `devis` → 1 ligne montant : `"{opération.nom} — {n} séances"`, montant = somme des montant_prevu
   - `proforma` → 1 ligne montant par séance : `"Séance {numero} — {date}"`, montant = montant_prevu du reglement
4. Calcule `montant_total` = somme des montants
5. Détermine la version : `max(version) + 1` pour le triplet (operation, participant, type)
6. Attribue le numéro séquentiel (`D-{exercice}-{seq}` ou `PF-{exercice}-{seq}`)
7. Génère le PDF/A-3
8. Stocke le PDF sur disque (`storage/app/documents-previsionnels/{numero}.pdf`)
9. Crée l'enregistrement en base
10. Retourne le document

### `genererPdf(DocumentPrevisionnel $document): string`

Génère un PDF/A-3 avec XML métadonnées embarqué :

- **Layout** : réutilise les partials Blade de l'en-tête association (logo, nom, adresse, SIRET) et du bloc destinataire (coordonnées tiers du participant)
- **Titre** : "DEVIS" ou "PRO FORMA" selon le type
- **Numéro et version** : affichés sous le titre (ex: "Devis D-2026-003 — Version 2")
- **Tableau de lignes** : libellé + montant, même style visuel que les factures
- **Total** : montant_total en gras
- **Mention** : "Ce document n'est pas une facture"
- **XML embarqué** : métadonnées du document (type, numéro, version, montant, participant, opération) — pas du Factur-X

## Intégration UI

### Onglet règlement (`ReglementTable`)

Sur chaque ligne participant du tableau :
- Deux boutons/icônes : "Devis" et "Pro forma"
- Clic → appel `emettre()` → ouverture du PDF dans un nouvel onglet
- Si un document a déjà été émis pour ce participant/type : badge indiquant la version actuelle (ex: "v2")
- La ré-émission crée automatiquement une nouvelle version si les montants ont changé ; si les montants sont identiques à la dernière version, on ouvre le PDF existant sans créer de doublon

### Fiche participant (`ParticipantShow`)

Dans la timeline existante, nouvelle section "Documents" :
- Liste chronologique des documents émis
- Chaque entrée affiche : type (badge), numéro, version, date, montant, opération
- Clic → ouvre le PDF dans un nouvel onglet

### Route

```
GET /gestion/documents-previsionnels/{document}/pdf
```

Contrôleur `DocumentPrevisionnelPdfController` — retourne le PDF avec `Content-Disposition: inline`.

## Génération PDF/A-3

Même pipeline que les factures validées :
1. Rendu HTML → PDF via dompdf
2. Conversion PDF → PDF/A-3 via la bibliothèque Factur-X existante
3. Embedding d'un XML métadonnées (pas Factur-X, schéma propre simplifié)

Le XML embarqué contient :
```xml
<?xml version="1.0" encoding="UTF-8"?>
<DocumentPrevisionnel>
  <Type>devis</Type>
  <Numero>D-2026-003</Numero>
  <Version>2</Version>
  <Date>2026-04-01</Date>
  <MontantTotal>150.00</MontantTotal>
  <Emetteur>
    <Nom>Association SVS</Nom>
    <SIRET>123 456 789 00012</SIRET>
  </Emetteur>
  <Destinataire>
    <Nom>Jean Dupont</Nom>
  </Destinataire>
  <Operation>
    <Nom>Sophrologie</Nom>
    <NombreSeances>10</NombreSeances>
  </Operation>
</DocumentPrevisionnel>
```

## Tests

- `DocumentPrevisionnelServiceTest` : émission devis, émission proforma, versionnage, numérotation, exercice fermé
- `DocumentPrevisionnelPdfTest` : génération PDF, format PDF/A-3, contenu XML
- `ReglementTableTest` : boutons émission visibles, ouverture PDF
- `ParticipantShowTest` : affichage des documents dans la timeline

## Fichiers à créer

| Fichier | Rôle |
|---------|------|
| `database/migrations/xxxx_create_documents_previsionnels_table.php` | Migration |
| `app/Enums/TypeDocumentPrevisionnel.php` | Enum devis/proforma |
| `app/Models/DocumentPrevisionnel.php` | Modèle Eloquent |
| `app/Services/DocumentPrevisionnelService.php` | Logique métier |
| `app/Http/Controllers/DocumentPrevisionnelPdfController.php` | Contrôleur PDF |
| `resources/views/pdf/document-previsionnel.blade.php` | Template PDF |
| `resources/views/pdf/partials/entete-association.blade.php` | Partial partagé (extrait de facture) |
| `resources/views/pdf/partials/bloc-destinataire.blade.php` | Partial partagé (extrait de facture) |
| `tests/Feature/Services/DocumentPrevisionnelServiceTest.php` | Tests service |
| `tests/Feature/DocumentPrevisionnelPdfTest.php` | Tests PDF |

## Fichiers à modifier

| Fichier | Modification |
|---------|-------------|
| `app/Livewire/ReglementTable.php` | Ajout boutons émission devis/proforma par participant |
| `resources/views/livewire/reglement-table.blade.php` | UI des boutons + badges version |
| `app/Livewire/ParticipantShow.php` | Chargement des documents du participant |
| `resources/views/livewire/participant-show.blade.php` | Section timeline documents |
| `resources/views/pdf/facture.blade.php` | Extraction des partials (en-tête, destinataire) |
| `routes/web.php` | Route PDF |

## Décisions de design documentées

1. **Modèle dédié (approche A)** plutôt qu'extension du modèle Facture — isolation totale, pas de risque de régression sur la facturation
2. **JSON pour les lignes** plutôt qu'une table de lignes — snapshot naturel, pas de besoin de requêtage individuel
3. **PDF/A-3 pour tous les documents** — probant, archivable, rassurant pour les organismes destinataires
4. **Pas de mutation** — un document émis est immuable, nouvelle version si changement
5. **Numérotation indépendante** — les devis/proformas ont leur propre séquence, séparée des factures
6. **Détection de doublon** — si les montants n'ont pas changé depuis la dernière version, on réouvre le PDF existant au lieu de créer une version identique
