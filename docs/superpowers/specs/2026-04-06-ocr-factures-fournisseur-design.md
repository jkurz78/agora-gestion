# OCR Factures Fournisseur — Analyse IA

**Date** : 2026-04-06
**Statut** : Spec validée
**Prérequis** : Pièces jointes sur les dépenses (spec `2026-04-06-pieces-jointes-depenses-design.md`, déjà implémentée)

## Objectif

Analyser automatiquement les factures fournisseur uploadées (PDF/JPG/PNG) via l'API Claude Vision pour pré-remplir les champs de la transaction dépense : date, référence, tiers, lignes (sous-catégories, montants). L'analyse se déclenche automatiquement dès l'upload terminé. Tous les champs pré-remplis sont modifiables.

## Périmètre

- Analyse IA via Claude Vision (claude-sonnet-4-20250514)
- Deux workflows : espace comptable (nouveau bouton) et espace encadrants (enrichissement du step upload existant)
- Pré-remplissage automatique, tout modifiable
- Alertes de cohérence en mode encadrants (tiers, date, opération, séance)
- Clé API stockée dans la configuration de l'application (table `associations`), chiffrée
- Gestion d'erreur : réessayer + ignorer

**Hors périmètre** : indicateurs de confiance par champ, OCR multi-pages complexe, reconnaissance de logos.

## Configuration — Clé API

### Migration

Ajouter une colonne sur la table `associations` :

| Colonne | Type | Description |
|---------|------|-------------|
| `anthropic_api_key` | `string(255)`, nullable | Clé API Anthropic, stockée chiffrée |

### Modèle Association

- Ajout au `$fillable`
- Cast `encrypted` pour chiffrement transparent en base

### Écran Paramètres > Association

- Nouveau champ "Clé API Anthropic (OCR factures)" : `type="password"`
- Texte d'aide : "Renseignez une clé API Anthropic pour activer l'analyse automatique des factures fournisseur."

### Helper

`InvoiceOcrService::isConfigured(): bool` — retourne `true` si la clé API est non-null dans la config association. Utilisé dans les vues pour afficher/masquer les fonctionnalités OCR.

## InvoiceOcrService

### Classe

`App\Services\InvoiceOcrService`

### Méthode principale

```
analyze(UploadedFile $file, ?array $context = null): InvoiceOcrResult
```

- `$file` : le fichier uploadé (PDF, JPG, PNG)
- `$context` : optionnel, pour le workflow encadrants. Contient `tiers_attendu`, `operation_attendue`, `seance_attendue`
- Récupère la clé API depuis `Association::first()->anthropic_api_key`
- Si clé absente → throw `OcrNotConfiguredException`
- Charge dynamiquement depuis la base : tiers (pour_depenses), sous-catégories dépense, opérations en cours
- Encode le fichier en base64
- Construit le prompt avec le contexte métier
- Appelle l'API Claude (`claude-sonnet-4-20250514`) via HTTP
- Parse le JSON de la réponse en `InvoiceOcrResult`
- En cas d'erreur (timeout, JSON invalide, erreur API) → throw `OcrAnalysisException` avec message explicite

### DTO InvoiceOcrResult

```php
final class InvoiceOcrResult
{
    public function __construct(
        public readonly ?string $date,          // YYYY-MM-DD
        public readonly ?string $reference,     // numéro de facture
        public readonly ?int $tiers_id,         // ID du tiers matché, ou null
        public readonly ?string $tiers_nom,     // nom tel qu'il apparaît sur la facture
        public readonly ?float $montant_total,
        public readonly array $lignes,          // array<InvoiceOcrLigne>
        public readonly array $warnings,        // alertes de cohérence
    ) {}
}
```

### DTO InvoiceOcrLigne

```php
final class InvoiceOcrLigne
{
    public function __construct(
        public readonly ?string $description,
        public readonly ?int $sous_categorie_id,
        public readonly ?int $operation_id,
        public readonly ?int $seance,
        public readonly float $montant,
    ) {}
}
```

### Prompt

Structuré en sections :

1. **Rôle** : "Tu es un assistant d'extraction de factures fournisseur pour une association."
2. **Format** : JSON strict avec le schéma imposé (date, reference, tiers_id, tiers_nom, montant_total, lignes[], warnings[])
3. **Consigne métier** : "Respecte les lignes telles qu'elles apparaissent sur la facture. Si la facture indique quantité 2 à 70€ pour un montant de 140€, c'est une seule ligne à 140€. Ne ventile pas."
4. **Contexte dynamique** : listes des tiers, sous-catégories dépense, opérations en cours avec leurs IDs
5. **Mode encadrant** (si `$context` fourni) : "Le contexte attendu est : tiers = X, opération = Y, séance = Z. Si la facture ne correspond pas à ces valeurs, ajoute un warning dans le champ warnings. Exemples : 'Le tiers sur la facture (A) ne correspond pas au tiers sélectionné (B)', 'L'opération détectée (X) ne correspond pas à l'opération sélectionnée (Y)', 'La séance détectée (N) ne correspond pas à la séance sélectionnée (M)'"
6. **Consigne finale** : "Réponds UNIQUEMENT avec le JSON, sans commentaire ni bloc markdown."

### Modèle IA

`claude-sonnet-4-20250514` — bon rapport qualité/coût pour l'extraction structurée. Coût estimé ~0.01€ par facture (~2500 tokens input, ~300 tokens output).

## Workflow — Espace Comptable (TransactionUniverselle + TransactionForm)

### Nouveau bouton

Dans `TransactionUniverselle`, à côté du bouton/dropdown "Nouvelle dépense" : bouton "Nouvelle dépense depuis facture" (icône `bi-file-earmark-text`). Visible uniquement si `InvoiceOcrService::isConfigured()` retourne `true`.

### Flow

1. **Clic** → file picker natif s'ouvre directement
2. **Fichier sélectionné** → le formulaire `TransactionForm` s'ouvre avec :
   - Spinner "Analyse de la facture en cours..." au-dessus du formulaire
   - Le fichier est uploadé vers Livewire
3. **Upload terminé** → appel `InvoiceOcrService::analyze()` côté serveur (~2-3s)
4. **Succès** → les champs se pré-remplissent (date, référence, tiers via l'autocomplete, lignes)
5. **Erreur** → message d'erreur + boutons "Réessayer" / "Ignorer"
6. L'utilisateur vérifie, corrige si besoin, et enregistre normalement

### Layout split horizontal

Quand le formulaire est ouvert via "Nouvelle dépense depuis facture" (PJ présente + mode OCR) :

- **Haut** : formulaire pleine largeur. Le champ "Justificatif" (nom du fichier + boutons) est déplacé sur la même ligne que le champ calculé "Montant total"
- **Bas** : prévisualisation du document en pleine largeur, hauteur ~40vh (iframe PDF avec `#navpanes=0` / img pour images)

Le split horizontal ne s'active que pour ce workflow. L'upload simple d'un justificatif sur une dépense classique reste sans prévisualisation (comportement actuel inchangé).

## Workflow — Espace Encadrants (AnimateurManager)

### Enrichissement du step upload

Quand l'OCR est configuré et qu'un fichier est uploadé au step 1 :

1. **Upload terminé** → spinner "Analyse en cours..." dans la modale
2. **Appel API** avec le contexte encadrant :
   ```php
   $context = [
       'tiers_attendu' => $tiers->displayName(),
       'operation_attendue' => $this->operation->nom,
       'seance_attendue' => $seanceNum,
   ];
   ```
3. **Succès** → passage au step form (split view existant) avec pré-remplissage :
   - **Date** : date extraite de la facture
   - **Référence** : numéro de facture extrait
   - **Mode paiement / Compte bancaire** : inchangés (pré-remplis depuis la dernière transaction du tiers)
   - **Lignes** : sous-catégories et montants extraits. Opération et séance restent ceux du contexte matrice
   - **Warnings** : affichés en `alert alert-warning` au-dessus du formulaire
4. **Erreur API** → "Réessayer" / "Ignorer" (ignorer = formulaire classique sans pré-remplissage)

### Warnings encadrants

Alertes non-bloquantes affichées en haut du formulaire. Exemples :
- "⚠️ Le tiers sur la facture (Anne KURZ-VAN DER HOEVEN) ne correspond pas au tiers sélectionné (Jürgen KURZ)"
- "⚠️ La date de facture (22/11/2025) est antérieure à l'exercice en cours"
- "⚠️ L'opération détectée (EQUI-THE 2025/2026) ne correspond pas à l'opération sélectionnée (Equistab avril 2026)"
- "⚠️ La séance détectée (4) ne correspond pas à la séance sélectionnée (3)"

Si pas d'OCR configuré : le step upload fonctionne comme actuellement (upload → split view sans pré-remplissage).

## Sécurité

- Clé API stockée chiffrée en base (`encrypted` cast Laravel)
- Appel API exclusivement côté serveur, jamais exposé au client
- Fichier validé (mimes + MIME réel) avant envoi à l'API
- Le contenu du fichier est envoyé en base64 à l'API Anthropic — pas de stockage intermédiaire supplémentaire
- Pas de rate limiting pour le v1 (usage interne, volume faible)

## Tests

- **InvoiceOcrService (unitaire)** : mock HTTP de l'appel API Claude. Vérifie le parsing du JSON en DTOs, la gestion d'erreur (timeout, JSON invalide, clé absente → exception), le prompt construit avec le bon contexte
- **InvoiceOcrService (intégration)** : test avec vrai appel API, marqué `@group external` (pas lancé en CI, exécution manuelle)
- **Configuration** : le helper `isConfigured()` retourne false si clé absente, true si présente
- **Workflow comptable** : le bouton "Nouvelle dépense depuis facture" n'apparaît que si OCR configuré
- **Workflow encadrant** : les warnings sont générés quand le contexte passé ne correspond pas à la réponse IA
