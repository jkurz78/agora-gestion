# Facturation Factur-X — Spécification complète

> Date : 2026-03-31
> Statut : Validé (brainstorm + revue)
> Périmètre : V1 (facturation complète + Factur-X) + V2 (avoirs, email, enrichissements)

---

## 1. Contexte et objectif

L'association SVS a besoin de facturer ses prestations (séances, parcours) aux tiers. Le module de facturation suit le même pattern que les remises bancaires : sélection de transactions existantes → regroupement → validation → document PDF.

### Principes directeurs

- Une facture est un **document légal immuable** une fois validée
- Les lignes de facture sont une **copie figée** des données comptables au moment de la validation
- La numérotation est **séquentielle et chronologique** (CGI art. 242 nonies A)
- Le PDF est au format **Factur-X** (profil MINIMUM) dès la V1
- L'annulation passe par un **avoir** (V2)

### Choix structurants

- On facture des **transactions entières**, pas des lignes isolées au sein d'une transaction
- Les lignes de facture sont stockées dans une **table dédiée** `facture_lignes` (pas de génération dynamique)
- Le `montant_regle` est **calculé à la volée** (pas stocké) : une transaction est considérée réglée si son `remise_id` est non null (la remise bancaire est la source de vérité pour le paiement)
- Les préfixes de numérotation sont **fixés en dur** : `F` (factures), `AV` (avoirs)
- La numérotation est **par exercice** : `F-2025-0001` (exercice sept 2025 → août 2026)

---

## 2. Modèle de données

### 2.1 Table `factures`

| Champ | Type | Notes |
|-------|------|-------|
| `id` | bigint PK | |
| `numero` | string, unique, nullable | Attribué à la validation, null en brouillon. Format : `F-{exercice}-{seq}` |
| `date` | date | Date d'émission. Contrôle chronologique à la validation |
| `statut` | string (enum) | `brouillon`, `validee`, `annulee` |
| `tiers_id` | FK → tiers | Client facturé |
| `compte_bancaire_id` | FK → comptes_bancaires, nullable | Coordonnées bancaires affichées sur le PDF |
| `conditions_reglement` | string, nullable | Pré-rempli depuis paramètres, modifiable sur le brouillon |
| `mentions_legales` | text, nullable | Pré-rempli depuis paramètres, modifiable sur le brouillon |
| `montant_total` | decimal(10,2) | Calculé et figé à la validation |
| `numero_avoir` | string, unique, nullable | Attribué à l'annulation (V2). Format : `AV-{exercice}-{seq}` |
| `date_annulation` | date, nullable | Date de l'avoir, sur l'exercice courant (V2) |
| `notes` | text, nullable | Notes internes (non affichées sur le PDF) |
| `saisi_par` | FK → users | |
| `exercice` | integer | Année de l'exercice |
| `timestamps` | | |

Note : pas de SoftDeletes sur `factures`. Un brouillon est supprimé en dur (pas de valeur légale). Une facture validée ne peut jamais être supprimée, uniquement annulée via avoir (V2). Le statut `annulee` conserve l'enregistrement intact.

### 2.2 Table `facture_lignes`

| Champ | Type | Notes |
|-------|------|-------|
| `id` | bigint PK | |
| `facture_id` | FK → factures | CASCADE on delete |
| `transaction_ligne_id` | FK → transaction_lignes, nullable | Null pour les lignes de texte |
| `type` | string (enum) | `montant` ou `texte` |
| `libelle` | string | Personnalisable en brouillon, figé à la validation |
| `montant` | decimal(10,2), nullable | Null pour les lignes de texte |
| `ordre` | integer | Position d'affichage, géré par flèches haut/bas |

### 2.3 Table pivot `facture_transaction`

| Champ | Type | Notes |
|-------|------|-------|
| `facture_id` | FK → factures | |
| `transaction_id` | FK → transactions | |
| | index unique (facture_id, transaction_id) | |

Sert aux requêtes rapides : "cette transaction est-elle facturée ?", "quelles transactions sont disponibles ?". Conservée après annulation pour traçabilité.

### 2.4 Enrichissement table `comptes_bancaires`

| Champ | Type | Notes |
|-------|------|-------|
| `bic` | string, nullable | Code BIC/SWIFT |
| `domiciliation` | string, nullable | Nom de la banque |

### 2.5 Enrichissement table `association`

| Champ | Type | Notes |
|-------|------|-------|
| `siret` | string, nullable | SIRET (ou RNA si pas de SIRET) |
| `forme_juridique` | string, nullable | Défaut : "Association loi 1901" |
| `facture_conditions_reglement` | string, nullable | Défaut : "Payable à réception" |
| `facture_mentions_legales` | text, nullable | Défaut : "TVA non applicable, art. 261-7-1° du CGI\nPas d'escompte pour paiement anticipé" |
| `facture_mentions_penalites` | text, nullable | Défaut : "En cas de retard de paiement, pénalités au taux de 3× le taux d'intérêt légal. Indemnité forfaitaire de recouvrement : 40 € (art. D441-5 C.Com)." |
| `facture_compte_bancaire_id` | FK → comptes_bancaires, nullable | Compte par défaut pour IBAN sur les factures |

### 2.6 Enum `StatutFacture`

```php
enum StatutFacture: string {
    case Brouillon = 'brouillon';
    case Validee = 'validee';
    case Annulee = 'annulee';
}
```

### 2.7 Enum `TypeLigneFacture`

```php
enum TypeLigneFacture: string {
    case Montant = 'montant';
    case Texte = 'texte';
}
```

---

## 3. Verrouillage — Matrice complète

Le verrouillage n'est pas une hiérarchie mais une **matrice** : chaque mécanisme protège des invariants différents. Une transaction peut être verrouillée simultanément par plusieurs sources.

| Action sur Transaction | Rapprochement | Remise bancaire | Facture validée |
|------------------------|:---:|:---:|:---:|
| Modifier date | Bloqué | Bloqué | Autorisé |
| Modifier compte | Bloqué | Bloqué | Autorisé |
| Modifier montant total | Bloqué | Bloqué | Bloqué |
| Modifier nb lignes | Bloqué | Bloqué | Bloqué |
| Modifier montant ligne | Bloqué | Bloqué | Bloqué |
| Modifier sous-catégorie | Autorisé | Bloqué | Bloqué |
| Modifier opération/séance | Autorisé | Bloqué | Bloqué |
| Modifier notes ligne | Autorisé | Bloqué | Autorisé |
| Modifier libellé tx | Autorisé | Bloqué | Autorisé |
| Reventilation (affectations) | Autorisé | Bloqué | Bloqué |
| Suppression | Bloqué | Bloqué | Bloqué |

### Méthode sur Transaction

```php
public function isLockedByFacture(): bool
{
    return $this->factures()
        ->where('statut', StatutFacture::Validee)
        ->exists();
}
```

### Invariants protégés par le verrou facture dans TransactionService

`assertLockedByFactureInvariants($transaction, $data, $lignes)` — appelé dans `update()` si `isLockedByFacture()` :

**Bloqué :**
- Changement de `montant_total`
- Changement du nombre de lignes
- Changement de `montant` d'une ligne
- Changement de `sous_categorie_id` d'une ligne
- Changement de `operation_id` ou `seance` d'une ligne

**Autorisé :**
- Changement de `date`, `compte_id`, `libelle`, `reference`, `mode_paiement`, `notes`
- Changement de `notes` sur une ligne

`affecterLigne()` et `supprimerAffectations()` doivent également vérifier `isLockedByFacture()` et bloquer si vrai.

`delete()` doit vérifier `isLockedByFacture()` et bloquer si vrai.

### Requête "transactions disponibles pour facturation"

```php
Transaction::where('type', TypeTransaction::Recette)
    ->where('tiers_id', $tiersId)
    ->whereDoesntHave('factures', fn ($q) =>
        $q->whereIn('statut', [StatutFacture::Brouillon, StatutFacture::Validee])
    )
    ->get();
```

Note : les transactions de tous exercices sont affichées (cas d'un avoir sur exercice antérieur qui libère des lignes).

---

## 4. Workflow

### 4.1 Création (→ brouillon)

1. Utilisateur clique "Nouvelle facture" sur FactureList
2. Sélection d'un tiers dans un dropdown
3. `FactureService::creer($tiersId)` :
   - Vérifie que l'exercice courant est ouvert (`ExerciceService::assertOuvert()`)
   - Crée une facture en statut `brouillon`
   - `numero` = null
   - `date` = date du jour
   - `exercice` = exercice courant
   - Pré-remplit `conditions_reglement`, `mentions_legales`, `compte_bancaire_id` depuis la table `association`
4. Redirection vers FactureEdit

### 4.2 Édition du brouillon (FactureEdit)

**Panneau haut — Sélection des transactions :**
- Liste des transactions `recette` du tiers, non facturées (tous exercices)
- Cases à cocher pour sélectionner/désélectionner
- À chaque ajout/retrait : mise à jour du pivot `facture_transaction` + génération/suppression des `facture_lignes` correspondantes

**Panneau bas — Lignes de facture :**
- Affichage des `facture_lignes` ordonnées par `ordre`
- Pour chaque ligne de type `montant` :
  - Libellé avec choix du mode : auto-généré / note de la ligne / saisie libre
  - Montant (lecture seule, issu de `transaction_ligne.montant`)
  - Flèches haut/bas pour réordonner
- Pour chaque ligne de type `texte` :
  - Libellé éditable (séparateur/titre de section)
  - Pas de montant
  - Flèches haut/bas
- Bouton "Ajouter une ligne de texte"

**Libellé auto-généré :**
Format : `{Sous-catégorie} — {Opération} — Séance {n} du {date_séance}`
- La date de séance est résolue via `Seance::where('operation_id', x)->where('numero', n)->value('date')`
- Si pas de séance : `{Sous-catégorie} — {Opération}`
- Si pas d'opération : `{Sous-catégorie}`

**Métadonnées éditables :**
- Date de facture
- Conditions de règlement
- Mentions légales
- Compte bancaire (select)

**Actions :**
- "Enregistrer" — sauvegarde le brouillon
- "Valider la facture" — avec modal de confirmation
- "Supprimer le brouillon" — avec modal de confirmation

### 4.3 Validation (brouillon → validée)

`FactureService::valider($facture)` :
1. Vérifie statut = brouillon
2. Vérifie que l'exercice est ouvert (`ExerciceService::assertOuvert()`)
3. Vérifie au moins une ligne de type `montant`
4. Vérifie contrainte chronologique : `date >= date de la dernière facture validée du même exercice`
   - Si non respecté : erreur "La date doit être postérieure ou égale au {date} (dernière facture validée {numero})"
5. Attribue le numéro séquentiel avec verrou pessimiste (`lockForUpdate` sur la table `factures` filtrée par exercice) pour éviter les doublons en cas de validation concurrente. Format : `F-{exercice}-{seq}` (seq = max+1)
6. Fige `montant_total` = somme des `facture_lignes` de type `montant`
7. Passe statut à `validee`
8. Les transactions liées sont désormais verrouillées par `isLockedByFacture()`

### 4.4 Consultation (FactureShow)

- Lecture seule pour les factures validées
- Affiche toutes les lignes, métadonnées, montant total
- **Montant réglé** (calculé dynamiquement) : somme des `montant_total` des transactions liées ayant un `remise_id` non null
- Badge "Acquittée" si `montantRegle() >= montant_total`
- Actions : "Télécharger PDF", "Annuler (avoir)" (V2)

### 4.5 Suppression de brouillon

`FactureService::supprimerBrouillon($facture)` :
1. Vérifie statut = brouillon
2. Supprime les `facture_lignes`
3. Supprime les entrées pivot `facture_transaction`
4. Supprime la facture

### 4.6 Annulation / Avoir (V2)

`FactureService::annuler($facture)` :
1. Vérifie statut = `validee`
2. Vérifie exercice **courant** ouvert (l'avoir est daté sur l'exercice courant, pas celui de la facture)
3. Vérifie qu'aucune transaction liée n'est verrouillée par rapprochement bancaire
4. Attribue `numero_avoir` : `AV-{exercice_courant}-{seq}`
5. Attribue `date_annulation` = date du jour
6. Passe statut à `annulee`
7. Les `facture_lignes` et le pivot restent intacts (document d'avoir + traçabilité)
8. Les transactions sont libérées (à nouveau modifiables et facturables)

---

## 5. Valeurs dérivées (accesseurs sur le modèle Facture)

```php
// Montant réglé — calculé à la volée
public function montantRegle(): float
{
    return (float) $this->transactions()
        ->whereNotNull('remise_id')
        ->sum('montant_total');
}

// Facture acquittée — état dérivé
public function isAcquittee(): bool
{
    return $this->statut === StatutFacture::Validee
        && $this->montantRegle() >= (float) $this->montant_total;
}
```

---

## 6. PDF Factur-X

### 6.1 Format

Factur-X profil MINIMUM : PDF/A-3 avec fichier XML embarqué (`factur-x.xml`).

L'association n'est pas assujettie à la TVA (art. 261-7-1° CGI) et n'est pas concernée par la réforme e-invoicing, mais adopte volontairement Factur-X pour l'exemplarité et la conformité.

**Pipeline PDF/A-3** : génération en deux étapes :
1. **dompdf** génère le PDF visuel depuis le template Blade (aucun changement aux templates existants)
2. **`atgp/factur-x`** post-traite le PDF : embarque le XML Factur-X, réécrit la structure PDF (profil ICC, XMP, fichier attaché) et produit un **PDF/A-3 conforme**

La lib `atgp/factur-x` (828K+ downloads) gère la conversion PDF/A-3 en interne via FPDI — le PDF d'entrée n'a pas besoin d'être déjà PDF/A-3.

### 6.2 Bibliothèque

`atgp/factur-x` (PHP) — génère le XML et l'embarque dans le PDF.

### 6.3 Contenu du PDF

**En-tête :**
- Logo de l'association
- Nom de l'association + forme juridique
- Adresse, email, téléphone
- SIRET (si renseigné)
- Titre : "FACTURE" (ou "AVOIR" en V2)
- Numéro : F-2025-0001 (ou AV-2025-0001 en V2)
- Date d'émission

**Coordonnées client :**
- Nom / raison sociale (depuis Tiers)
- Adresse complète

**Tableau des lignes :**
- Colonnes : Désignation | Montant
- Lignes de type `texte` : libellé en gras, pas de montant
- Lignes de type `montant` : libellé + montant aligné à droite
- **Total** en bas du tableau

**Mention "Acquittée" :**
- Si `isAcquittee()` : mention "ACQUITTÉE" visible sur le PDF

**Coordonnées bancaires :**
- Nom du compte, IBAN, BIC, domiciliation (si `compte_bancaire_id` renseigné)

**Pied de facture :**
- Conditions de règlement
- Mentions légales (TVA non applicable, pas d'escompte...)
- **Si tiers.type === 'entreprise'** : mentions pénalités de retard + indemnité 40 €

### 6.4 Mode avoir (V2)

Même vue `pdf/facture.blade.php` avec :
- Titre "AVOIR" au lieu de "FACTURE"
- Numéro AV-xxxx
- Date = `date_annulation`
- Mention "Annule la facture F-2025-XXXX"
- Montants affichés en négatif

### 6.5 Champs XML Factur-X (profil MINIMUM)

| Champ BT | Donnée |
|----------|--------|
| BT-1 | `numero` |
| BT-2 | `date` |
| BT-3 | 380 (facture) ou 381 (avoir) |
| BT-5 | EUR |
| BT-9 | Date d'échéance (déduite des conditions de règlement) |
| BT-27 | Nom association |
| BT-30 | SIRET (schemeID=0002) |
| BT-44 | Nom du tiers |
| BT-109 | `montant_total` (HT = TTC car pas de TVA) |
| BT-112 | `montant_total` |
| BT-115 | Montant dû (`montant_total - montantRegle()`) |

---

## 7. Mentions légales obligatoires

### Source légale

- CGI art. 242 nonies A (mentions obligatoires sur factures)
- Code de Commerce art. L441-9 (relations commerciales)
- CGI art. 261-7-1° (exonération TVA organismes non lucratifs)
- Code de Commerce art. D441-5 (indemnité forfaitaire de recouvrement)

### Mentions sur toute facture

- `TVA non applicable, art. 261-7-1° du CGI`
- `Pas d'escompte pour paiement anticipé`
- Conditions de règlement avec date d'échéance
- Modalités de paiement

### Mentions conditionnelles (tiers.type === 'entreprise')

- Taux de pénalités de retard (minimum 3× taux d'intérêt légal)
- `Indemnité forfaitaire pour frais de recouvrement : 40 € (art. D441-5 C.Com)`

---

## 8. Paramètres de facturation

Stockés directement sur la table `association` (colonnes dédiées, cohérent avec le pattern existant — pas de table clé/valeur générique). Accessibles via un onglet dédié dans l'écran Paramètres.

| Champ sur `association` | Valeur par défaut | Widget |
|------------------------|------------------|--------|
| `facture_conditions_reglement` | `Payable à réception` | textarea |
| `facture_mentions_legales` | `TVA non applicable, art. 261-7-1° du CGI\nPas d'escompte pour paiement anticipé` | textarea |
| `facture_mentions_penalites` | `En cas de retard de paiement, pénalités au taux de 3× le taux d'intérêt légal. Indemnité forfaitaire de recouvrement : 40 € (art. D441-5 C.Com).` | textarea |
| `facture_compte_bancaire_id` | null | select (comptes bancaires non-système) |

---

## 9. Écrans Livewire

### 9.1 FactureList

- Liste paginée des factures de l'exercice courant (avec sélecteur d'exercice)
- Colonnes : numéro, date, tiers, montant total, montant réglé, statut
- Badges : `brouillon` (gris), `validée` (bleu), `validée acquittée` (vert), `annulée` (rouge)
- Filtres : statut, recherche tiers
- Bouton "Nouvelle facture" → dropdown tiers → crée brouillon → redirige FactureEdit
- Clic sur une ligne : FactureEdit (si brouillon) ou FactureShow (si validée/annulée)

### 9.2 FactureEdit (brouillon uniquement)

**Panneau haut — Transactions disponibles :**
- Transactions recette du tiers, non facturées, tous exercices
- Colonnes : date, référence, libellé, montant, nb lignes
- Cases à cocher : ajouter/retirer de la facture
- Total sélectionné affiché

**Panneau bas — Lignes de facture :**
- Liste ordonnée des `facture_lignes`
- Chaque ligne : libellé (éditable) + montant (lecture seule) + flèches ↑↓ + supprimer
- Lignes de texte : libellé éditable, pas de montant
- Bouton "Ajouter une ligne de texte"

**Métadonnées :**
- Date, conditions de règlement, mentions légales, compte bancaire

**Actions :**
- Enregistrer le brouillon
- Valider la facture (modal de confirmation)
- Supprimer le brouillon (modal de confirmation)
- Prévisualiser PDF

### 9.3 FactureShow (validée ou annulée)

- Lecture seule
- Toutes les informations de la facture
- Montant réglé (dynamique) + badge acquittée
- Actions V1 : "Télécharger PDF"
- Actions V2 : "Envoyer par email", "Annuler (émettre un avoir)"
- Si annulée : numéro d'avoir, date d'annulation, bouton "Télécharger l'avoir PDF"

### 9.4 Onglet Paramètres Facturation

- Conditions de règlement (textarea)
- Mentions légales (textarea)
- Mentions pénalités B2B (textarea)
- Compte bancaire par défaut (select)

---

## 10. Routes

```
/gestion/factures                       → FactureList
/gestion/factures/{facture}/edit        → FactureEdit (brouillon)
/gestion/factures/{facture}             → FactureShow (validée/annulée)
/gestion/factures/{facture}/pdf         → FacturePdfController
```

---

## 11. FactureService — API complète

### V1

| Méthode | Description |
|---------|-------------|
| `creer(int $tiersId): Facture` | Crée un brouillon vide avec valeurs par défaut |
| `ajouterTransactions(Facture $f, array $txIds): void` | Ajoute des transactions + génère facture_lignes |
| `retirerTransaction(Facture $f, int $txId): void` | Retire une transaction + supprime ses facture_lignes |
| `valider(Facture $f): void` | Attribue numéro, fige montant, verrouille transactions |
| `supprimerBrouillon(Facture $f): void` | Supprime brouillon + lignes + pivot |
| `majOrdre(Facture $f, int $ligneId, string $dir): void` | Monte/descend une ligne |
| `majLibelle(Facture $f, int $ligneId, string $libelle): void` | Modifie le libellé d'une ligne |
| `ajouterLigneTexte(Facture $f, string $texte): void` | Ajoute un séparateur |
| `supprimerLigne(Facture $f, int $ligneId): void` | Supprime une ligne de texte uniquement (vérifie type = texte) |
| `genererPdf(Facture $f): \Barryvdh\DomPDF\PDF` | Génère le PDF Factur-X |

### V2

| Méthode | Description |
|---------|-------------|
| `annuler(Facture $f): void` | Émet l'avoir, libère les transactions |
| `genererPdfAvoir(Facture $f): \Barryvdh\DomPDF\PDF` | Génère le PDF avoir |
| `envoyerParEmail(Facture $f): void` | Envoie le PDF par email au tiers |

Toutes les méthodes sont enveloppées dans `DB::transaction()`.

---

## 12. Périmètre V1 — Facturation complète

### Modèle de données
- [x] Migration `create_factures_table`
- [x] Migration `create_facture_lignes_table`
- [x] Migration `create_facture_transaction_table` (pivot)
- [x] Migration `add_bic_domiciliation_to_comptes_bancaires`
- [x] Migration `add_siret_forme_juridique_to_association`
- [x] Modèle `Facture` avec relations, accesseurs (`montantRegle`, `isAcquittee`)
- [x] Modèle `FactureLigne`
- [x] Enums `StatutFacture`, `TypeLigneFacture`
- [x] Relation `factures()` sur `Transaction` (belongsToMany via pivot)
- [x] `isLockedByFacture()` sur `Transaction`
- [x] Intégration du verrou facture dans `TransactionService::update()` et `delete()`

### Service
- [x] `FactureService` — toutes les méthodes V1

### Écrans Livewire
- [x] `FactureList` — liste + création brouillon
- [x] `FactureEdit` — sélection transactions + édition lignes + validation
- [x] `FactureShow` — consultation lecture seule

### PDF Factur-X
- [x] Vue Blade `pdf/facture.blade.php`
- [x] Contrôleur `FacturePdfController`
- [x] Intégration `atgp/factur-x` pour XML embarqué
- [x] Mentions légales conditionnelles (B2B)

### Paramètres
- [x] 4 colonnes facture_* sur la table `association`
- [x] Onglet Facturation dans l'écran Paramètres (AssociationForm)
- [x] Valeurs par défaut dans la migration ou le seeder

### Tests
- [x] Tests unitaires `FactureService` (création, ajout/retrait tx, validation, suppression)
- [x] Tests verrouillage (`isLockedByFacture`, intégration avec TransactionService)
- [x] Tests contrainte chronologique
- [x] Tests Livewire (FactureList, FactureEdit, FactureShow)

---

## 13. Périmètre V2 — Avoirs et enrichissements

### Annulation / Avoir
- [ ] `FactureService::annuler()` — changement statut, numéro avoir, date annulation, libération transactions
- [ ] PDF avoir (même vue Blade, mode avoir)
- [ ] Vérification rapprochement avant annulation
- [ ] Gestion cross-exercice (avoir sur exercice courant, facture sur exercice antérieur)
- [ ] Bouton "Annuler" sur FactureShow avec modal de confirmation

### Envoi par email
- [ ] Réutilisation CategorieEmail::Facture + template seedé existant
- [ ] PDF en pièce jointe
- [ ] Variables : `{numero_facture}`, `{date_facture}`, `{prenom}`, `{nom}`, `{operation}`...
- [ ] Logging dans `email_logs`

### Création de recettes au vol
- [ ] Ajout paramètre `forcedTiersId` sur `TransactionForm::openForm()`
- [ ] Champ tiers pré-rempli et read-only
- [ ] Événement `transaction-saved` écouté par FactureEdit pour rafraîchir la liste

### Duplication de brouillon
- [ ] Créer un nouveau brouillon depuis une facture existante (copie lignes + métadonnées, nouveau tiers optionnel)

### Drag & drop
- [ ] SortableJS (CDN) pour le réordonnancement des lignes dans FactureEdit
- [ ] En complément des flèches haut/bas (V1)

---

## 14. Dépendances techniques

### Packages à installer
- `atgp/factur-x` — génération Factur-X (XML + embedding PDF/A-3)

### Packages existants réutilisés
- `barryvdh/laravel-dompdf` — génération PDF de base
- Templates email (CategorieEmail::Facture déjà seedé)

---

## 15. Points d'attention

1. **Chronologie des numéros** : à la validation, vérifier `date >= date dernière facture validée du même exercice`
2. **Verrou pessimiste** : utiliser `lockForUpdate()` lors de l'attribution du numéro séquentiel pour éviter les doublons en validation concurrente
3. **Pivot après annulation** : toujours filtrer sur `statut` dans les requêtes (ne jamais oublier d'exclure `annulee` pour les transactions disponibles)
4. **Transactions tous exercices** : l'écran de sélection affiche les recettes non facturées de tous les exercices
5. **Factur-X + dompdf** : pipeline en deux étapes — dompdf génère le PDF visuel, `atgp/factur-x` le convertit en PDF/A-3 avec XML embarqué
6. **Mentions pénalités conditionnelles** : afficher si `tiers.type !== 'particulier'` (couvre entreprises et associations facturées)
7. **Exercice ouvert** : vérifier `ExerciceService::assertOuvert()` à la création du brouillon et à la validation
8. **Libellé auto-généré** : résolution de la date de séance via requête `Seance::where(...)` — batch les requêtes pour éviter N+1 lors de la génération de plusieurs lignes
9. **Statut `annulee` en V1** : le statut existe dans l'enum mais aucun code V1 ne doit le produire. Seul le code V2 (`annuler()`) y accède
10. **Email Facture (V2)** : les variables `CategorieEmail::Facture` existantes sont orientées participant/opération — à adapter pour être orientées tiers/facture lors de l'implémentation V2
