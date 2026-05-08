# Reçu fiscal de don — design

**Date** : 2026-05-07
**Statut** : Spec validée, prête pour `/plan`
**Périmètre** : MVP — émission unitaire d'un reçu fiscal par transaction de don

## 1. Objectif

Permettre à une association éligible d'émettre un **reçu fiscal légal** au format PDF/A-3 pour chaque don reçu, traçable, idempotent et probant en cas de contrôle fiscal.

Le reçu :
- est utilisable depuis le quick view Tiers (back-office) dès le MVP ;
- sera réutilisable depuis le portail Tiers en slice futur (mêmes services, mêmes garanties).

## 2. Choix structurants actés

| Décision | Choix retenu | Justification |
|---|---|---|
| Format légal | Attestation libre (modèle asso), pas le CERFA 11580*05 | BOI-IR-RICI-250-30 : modèle libre admis si mentions obligatoires présentes. Aligné sur la pratique HelloAsso. |
| Format technique | PDF standard (DomPDF), pas PDF/A-3. Probance assurée par numérotation + SHA256 + horodatage. | **Revirement post-livraison MVP (2026-05-08)** : le wrap `Atgp\FacturX\Writer` a été retiré au profit de DomPDF brut. Raison : DomPDF ne produit pas de PDF/A-3 conforme nativement (polices/colorspace), le wrap FacturX produisait un fichier qui « se prétend » PDF/A-3 mais que les lecteurs stricts (Adobe Reader, Preview) rejettent. Le besoin légal n'exige pas PDF/A-3 pour un reçu fiscal. |
| Granularité MVP | 1 reçu par transaction de don | Récap annuel agrégé = phase 2. |
| Période fiscale (phase 2) | Année civile (jamais exercice comptable) | Obligation fiscale : déclaration 2042 RICI sur année civile. |
| Stockage | PDF binaire stocké sur disque tenant + `pdf_hash` SHA256 en base | Fige le rendu (immunise contre une évolution future du template Blade). Vérification d'intégrité à chaque téléchargement. |
| Article CGI | Dérivé du `Tiers.type` (`entreprise` → 238 bis ; sinon → 200) | C'est la fiscalité du donateur qui détermine l'article, pas l'asso. |
| Forme du don | Dérivée des usages de la sous-catégorie (`AbandonCreance` → `abandon_revenus` ; sinon → `numeraire`) | Les 3 sous-cats existantes (754/756/771) sont nativement couvertes. |
| Taux de réduction | Non affiché sur le PDF | Non requis par BOFiP/CERFA. Le donateur calcule selon sa situation. |
| Doublon HelloAsso | Pas de blocage. Avertissement non bloquant à la première émission. | Décision utilisateur : l'asso a le droit d'émettre, le donateur est responsable de ne pas déduire deux fois. Cohérence avec un futur récap annuel agrégé. |
| Adresse donateur manquante | Blocage explicite | Mention obligatoire d'un reçu fiscal. Sur le portail (futur), on demandera le complément ; en back-office MVP, on bloque avec lien vers le tiers. |
| Don non encaissé (`statut ≠ Recu`) | Blocage | Pas de don, pas de reçu. |
| Don supprimé après émission | `TransactionObserver` annule auto le reçu (`annule_motif = 'Don supprimé'`) | Idempotent, cohérent avec la mécanique `don_transaction_id` déjà en place. |
| Don modifié (montant / date / tiers) après émission | Annulation auto, l'asso régénère manuellement | Une transaction modifiable est par définition non-HelloAsso et non-pointée — donc fragile, donc son reçu aussi. Champs `notes`/`libelle` ne déclenchent **pas** l'annulation. |
| Idempotence | Stricte : un don = un reçu actif à la fois | Ré-émission = annulation explicite préalable, nouveau numéro |
| Civilité du donateur sur le PDF | Absente du MVP | `Tiers` n'a pas de champ civilité. Identifié comme dette technique (sociétal/politique). |

## 3. Modèle de données

### 3.1 Migration `associations` — ajout de 7 colonnes

```php
$table->boolean('eligible_recu_fiscal')->default(false);
$table->string('regime_fiscal_don')->nullable();        // libre : "RUP", "intérêt général", "cultuelle", ...
$table->text('objet_recu_fiscal')->nullable();          // ex: "Œuvre d'intérêt général à caractère social"
$table->string('rescrit_fiscal_numero')->nullable();
$table->date('rescrit_fiscal_date')->nullable();
$table->string('signataire_nom')->nullable();
$table->string('signataire_qualite')->nullable();       // ex: "Président·e", "Trésorier·e"
```

L'asso configure ces champs dans **Paramètres → Association → Reçus fiscaux** (nouvel encart).

### 3.2 Migration nouvelle table `recus_fiscaux_emis`

```php
Schema::create('recus_fiscaux_emis', function (Blueprint $table) {
    $table->id();
    $table->foreignId('association_id')->constrained()->cascadeOnDelete();
    $table->string('numero');                                              // "2026-0001"
    $table->smallInteger('annee_civile');
    $table->foreignId('tiers_id')->constrained();
    $table->foreignId('transaction_ligne_id')->nullable()->constrained();  // null = récap annuel (phase 2)
    $table->integer('montant_centimes');
    $table->date('date_versement');
    $table->string('mode_versement');                                      // 'cheque'|'virement'|'espece'|'carte'|'autre'
    $table->string('forme_don');                                           // 'numeraire'|'abandon_revenus'
    $table->string('article_cgi');                                         // 'art_200'|'art_238_bis'
    $table->string('pdf_path');                                            // chemin tenant relatif
    $table->string('pdf_hash', 64);                                        // SHA256 hex
    $table->timestamp('emitted_at');
    $table->foreignId('emitted_by_user_id')->nullable()->constrained('users');
    $table->timestamp('annule_at')->nullable();
    $table->text('annule_motif')->nullable();
    $table->foreignId('remplace_par_id')->nullable()->constrained('recus_fiscaux_emis'); // chaînage annulé → nouveau
    $table->timestamps();

    $table->unique(['association_id', 'numero']);
    $table->index(['association_id', 'tiers_id', 'annee_civile']);
    $table->index(['association_id', 'transaction_ligne_id']);
});
```

### 3.3 Modèle `RecuFiscalEmis`

- Étend `App\Models\TenantModel` (scope global fail-closed sur `association_id`).
- Relations : `tiers()`, `transactionLigne()`, `emittedBy()`, `remplacePar()`, `remplace()`.
- Helpers : `isAnnule(): bool`, `isActif(): bool`, `pdfFullPath(): string` (résolution chemin tenant), `verifierIntegrite(): bool` (compare `sha256(file)` à `pdf_hash`).

### 3.4 Path stockage tenant

```
storage/app/associations/{association_id}/recus_fiscaux/{annee}/{numero}.pdf
```

ID numérique (immutable), sous-dossier `{annee}` pour éviter la concentration de fichiers.

## 4. Architecture service

### 4.1 `RecuFiscalService` (méthodes publiques)

```php
final class RecuFiscalService
{
    public function obtenirOuGenerer(TransactionLigne $ligne, ?User $user = null): RecuFiscalEmis;
    public function streamPdf(RecuFiscalEmis $recu): \Symfony\Component\HttpFoundation\StreamedResponse;
    public function annuler(RecuFiscalEmis $recu, string $motif, ?User $user = null): void;
    public function reemettre(RecuFiscalEmis $ancien, string $motif, ?User $user = null): RecuFiscalEmis;
    public function validerEligibilite(TransactionLigne $ligne): void; // throws RecuFiscalException
}
```

### 4.2 Logique de dérivation

```php
private function determinerArticleCgi(Tiers $donateur): string
{
    return $donateur->type === 'entreprise' ? 'art_238_bis' : 'art_200';
}

private function determinerFormeDon(SousCategorie $sc): string
{
    return $sc->hasUsage(UsageComptable::AbandonCreance)
        ? 'abandon_revenus'
        : 'numeraire';
}
```

### 4.3 Workflow `obtenirOuGenerer`

1. Recharger la transaction avec `lockForUpdate()` (`DB::transaction`).
2. Si reçu actif existe pour cette ligne → retourner directement.
3. Appeler `validerEligibilite()` — throws si KO.
4. Allouer numéro via `NumeroPieceService` étendu (séquence par `association_id` + `annee_civile`, format `{année}-{séquence:04d}`).
5. Charger les vues snapshot (asso, tiers, ligne, sous-cat).
6. `Pdf::loadView('pdf.recu-fiscal-don', $data)->setPaper('a4', 'portrait')->output()`.
7. Wrapper PDF/A-3 : `FacturXWriter::generate($pdfContent, $xmlMetadata, 'minimum', false)`.
8. Écrire le binaire sur disque tenant.
9. INSERT `recus_fiscaux_emis` (numero, paths, `pdf_hash = sha256_file($pdfPath)`, `emitted_at = now()`, etc.).
10. Retourner le modèle.

### 4.4 Workflow `annuler` / `reemettre`

- `annuler` : flag `annule_at`, `annule_motif`, `emitted_by_user_id` (acteur). Le fichier PDF reste sur disque (preuve historique).
- `reemettre` : appelle `annuler($ancien)` puis appelle `obtenirOuGenerer($ligne)` (qui crée un nouveau reçu) puis remplit `$ancien->remplace_par_id = $nouveau->id`. Une seule transaction DB.

### 4.5 Numérotation

Réutilisation du `NumeroPieceService` pattern existant, étendu pour le namespace `recu-fiscal` :
- Séquence portée par `(association_id, annee_civile)`
- Format `{année}-{séquence sur 4 chiffres}` (ex: `2026-0001`)
- Lock pessimiste sur la séquence pour éviter les collisions concurrentes

## 5. Génération PDF

### 5.1 Vue `resources/views/pdf/recu-fiscal-don.blade.php`

Structure A4, charte AgoraGestion (cohérente avec `pdf.facture` / `pdf.attestation-presence`) :

1. **En-tête asso** : logo, nom, adresse complète, SIRET (si présent), n° RNA (si présent).
2. **Titre** : « REÇU AU TITRE DES DONS À CERTAINS ORGANISMES D'INTÉRÊT GÉNÉRAL ».
3. **Numéro et date d'émission** : « Reçu n° {numero} — Émis le {emitted_at|d/m/Y} ».
4. **Bloc bénéficiaire** (l'asso) : nom, adresse, objet d'intérêt général, mention « Régime : {regime_fiscal_don} ». Si rescrit présent : « Rescrit fiscal n° {x} en date du {y} ».
5. **Bloc donateur** : nom (NOM en majuscules) + prénom (ou raison sociale), adresse complète, mention « Personne physique » ou « Personne morale » selon `Tiers.type`.
6. **Description du don** :
   - « L'association reconnaît avoir reçu de {donateur} la somme de **{montant} €** ({montant_en_lettres}) ».
   - Date du versement : `{date_versement|d/m/Y}`.
   - Mode : « Chèque / Virement / Espèces / Carte bancaire / Autre ».
   - Forme :
     - `numeraire` → « Don manuel en numéraire ».
     - `abandon_revenus` → « Le donateur renonce expressément au remboursement des frais engagés dans le cadre de son activité bénévole et entend en faire don à l'association » (formulation BOFiP).
7. **Mention légale** : « Le bénéficiaire certifie sur l'honneur que les dons et versements qu'il reçoit ouvrent droit à la réduction d'impôt prévue à l'article {200 | 238 bis} du CGI. »
8. **Signature** : « Fait à {ville_asso}, le {date} » + nom + qualité du signataire + image cachet/signature (champ existant `Association.cachet_signature`).
9. **Footer** : `PdfFooterRenderer` (réutilisé du reste de l'app).

### 5.2 Comportement d'un reçu annulé

Le PDF original n'est **jamais modifié** après émission (intégrité du `pdf_hash`, preuve historique). Le binaire reste sur disque tenant (preuve en cas de besoin futur d'audit). L'annulation est portée :
- **côté UI** : badge gris « Annulé n°X » remplace le bouton de téléchargement principal ; menu contextuel avec lien vers le reçu de remplacement s'il existe.
- **côté route** : `GET /tiers/{tiers}/dons/{ligne}/recu-fiscal` redirige automatiquement vers le reçu de remplacement actif (`remplace_par_id`) s'il existe ; sinon retourne 410 Gone avec message « Reçu annulé le {date} — motif : {motif} ».
- **téléchargement applicatif d'un PDF annulé** : non disponible dans le MVP. Le binaire reste accessible si besoin par un futur écran admin (hors-scope).

### 5.3 Montant en lettres

Dépendance Composer : **`kwn/number-to-words`** (locale `fr`, mature, multi-langues, déjà testée sur cas tordus comme 80, 100, 1234567).

Petit wrapper service `MontantEnLettresService` pour gérer les centimes :
> `1234,56 €` → `« mille deux cent trente-quatre euros et cinquante-six centimes »`.

Tests d'unité sur valeurs limites (0,01 / 80 / 100 / 1234,56 / 1 000 000).

### 5.4 XML métadonnées PDF/A-3

Génère un XML minimal (mode `'minimum'` du FacturXWriter) avec : numéro reçu, date émission, montant, donateur, asso, article CGI. Aligné sur le pattern `DocumentPrevisionnelService::genererMetadataXml()`.

## 6. Surface UX MVP

### 6.1 Quick view Tiers — onglet « Dons »

Extension de `app/Livewire/TiersQuickView.php` :
- Onglet ou panneau accordéon « Dons » filtrant les `transaction_lignes` par `UsageComptable::Don`.
- Liste tabulaire : Date | Sous-cat | Mode | Montant | Reçu fiscal.
- Action par ligne :
  - **Pas de reçu actif** → bouton primaire « Télécharger reçu fiscal » (un seul clic = `obtenirOuGenerer` + `streamPdf`).
  - **Reçu actif** → numéro affiché + menu contextuel `[⋯]` : « Retélécharger » / « Annuler et ré-émettre » (modale motif).
  - **Reçu annulé** → badge gris « Annulé n°X » + lien vers le reçu de remplacement.

### 6.2 Avertissement à la première émission

Si l'asso ou le tiers a `updated_at` plus récent que la `date_versement`, on affiche une modale non bloquante :
> *« Les coordonnées de [donateur/association] ont été modifiées depuis la date du don. Le reçu portera les coordonnées actuelles ({adresse}). [Continuer] [Annuler] »*

Si l'utilisateur continue, le PDF est figé sur le disque et ne bougera plus jusqu'à annulation explicite.

### 6.3 Avertissement HelloAsso

Si la transaction provient d'HelloAsso (`source = 'helloasso'`), modale non bloquante à la première émission :
> *« HelloAsso peut avoir déjà émis un reçu fiscal pour ce don. Le donateur ne doit pas déduire deux fois. [Continuer] [Annuler] »*

### 6.4 Paramètres → Association → Reçus fiscaux

Nouvelle section dans la page Paramètres association :
- Toggle « Émettre des reçus fiscaux »
- Champs régime fiscal, objet, rescrit (numéro + date), signataire (nom + qualité)
- Encart d'aide avec lien vers BOFiP

### 6.5 Endpoint backend

Route : `GET /tiers/{tiers}/dons/{ligne}/recu-fiscal`
- Middleware : `auth`, `BootTenantConfig`
- Policy : `RecuFiscalPolicy@download` (tenant scope + droit user)
- Action : `obtenirOuGenerer($ligne, auth()->user())` puis `streamPdf($recu)`
- Erreurs : 422 + message explicite pour chaque blocage d'éligibilité

## 7. Edge cases

| Cas | Comportement |
|---|---|
| Asso non éligible (`eligible_recu_fiscal = false`) | Bouton désactivé, tooltip + lien Paramètres |
| Adresse donateur incomplète (rue / ville / CP manquants) | Bouton désactivé, tooltip « Compléter l'adresse du tiers » + lien fiche tiers |
| Signataire non configuré (`signataire_nom` ou `signataire_qualite` null) | Blocage 422 + lien Paramètres |
| Transaction non encaissée (`statut ≠ Recu`) | Blocage 422 « Un don doit être encaissé pour donner droit à un reçu » |
| Don supprimé après reçu émis | `TransactionLigneObserver::deleting` ⇒ annulation auto (`annule_motif = 'Don supprimé'`) |
| Don modifié (`montant` / `date_operation` / `tiers_id`) | `TransactionLigneObserver::updating` ⇒ annulation auto (`annule_motif = 'Don modifié — {détail}'`). `notes` / `libelle` ne déclenchent **pas**. |
| Tiers fusionné via `TiersMergeModal` | Migration des reçus vers le tiers cible dans la transaction de fusion (FK update) |
| Don HelloAsso | Avertissement à la première émission, pas de blocage |
| Don sans sous-catégorie | Blocage 422 (cas anormal) |
| Multi-tenant — accès cross-tenant | `TenantScope` fail-closed sur `RecuFiscalEmis` ; route policy + test d'intrusion |

## 8. Tests (Pest)

### 8.1 Unit

- `RecuFiscalServiceTest` : idempotence, dérivation article CGI (PP/PM), dérivation forme (`numeraire`/`abandon_revenus`), numérotation séquentielle par tenant + année, validation éligibilité (un test par cas de blocage).
- `MontantEnLettresServiceTest` : valeurs limites (0,01 / 80 / 100 / 1234,56 / 1 000 000 / centimes).
- `RecuFiscalXmlMetadataTest` : XML valide, champs présents, encodage UTF-8.
- Tests dérivation pour les 3 sous-catégories réelles (754, 756, 771) × 2 types tiers.

### 8.2 Feature

- `RecuFiscalDownloadTest` : route back-office, get-or-create idempotent, headers PDF corrects, `pdf_hash` en base = SHA256 du fichier sur disque.
- `RecuFiscalAnnulationTest` : annulation, ré-émission, filigrane sur PDF annulé, chaînage `remplace_par_id`.
- `RecuFiscalAutoAnnulationObserverTest` : suppression de transaction ⇒ annulation, modification `montant`/`date_operation`/`tiers_id` ⇒ annulation, modification `notes`/`libelle` ⇒ aucune annulation.
- `RecuFiscalTenantIsolationTest` : asso A ne voit pas les reçus de l'asso B (intrusion via `TenantContext::boot`).
- `RecuFiscalEligibiliteTest` : 422 + message explicite pour chaque blocage (asso non éligible, adresse manquante, signataire manquant, statut non Recu, sans sous-catégorie).
- `RecuFiscalParametresAssociationTest` : sauvegarde des champs Paramètres et propagation correcte sur le PDF.

### 8.3 E2E (Playwright)

- Quick view tiers → onglet Dons → clic « Télécharger reçu » → PDF téléchargé avec bon numéro affiché.
- Annuler + ré-émettre depuis menu contextuel : badge passe en gris, nouveau reçu affiché.

## 9. Hors-scope MVP

Documentés ici pour cadrer la suite et éviter les dérives :

- **Récap annuel agrégé** (transaction_ligne_id null sur `recus_fiscaux_emis`, accumulant tous les dons d'un tiers sur année civile) — phase 2.
- **Cotisations éligibles** : ajouter `eligible_recu_fiscal` (bool) sur `sous_categories`, étendre le filtre du service au-delà de `UsageComptable::Don` — phase 2.
- **Reçu fiscal portail tiers** : auto-service, le donateur télécharge ses propres reçus depuis `/portail/mes-dons` — slice futur, mêmes services réutilisés.
- **Bouton ZIP « Tous les reçus de l'année »** sur quick view tiers — phase 2.
- **Notifications email** automatiques à émission — slice futur.
- **Écran admin de téléchargement des PDF annulés** (audit/traçabilité) — non MVP. Le binaire est conservé sur disque, accessible directement par un administrateur système si besoin.
- **Dématérialisation officielle** (signature électronique qualifiée RGS, horodatage) — non requis pour reçu fiscal, mais réservé si évolution réglementaire.

## 10. Dette technique consignée

- **Civilité** sur le modèle `Tiers` : champ absent, sujet sociétal/politique éludé. À adresser dans un slice dédié quand le besoin se précisera (PDF reçu, formulaire portail, mailings).
- **Ligne stale dans MEMORY.md** sur `project_ndf_abandon_creance` (indique « PROCHAINE SESSION » alors que le projet est livré depuis 2026-04-21 — corriger l'index post-spec).

## 11. Références légales

- BOI-IR-RICI-250-30 (modèle libre admis, mentions obligatoires)
- Article 200 du CGI (réduction d'impôt particuliers)
- Article 238 bis du CGI (mécénat entreprises)
- CERFA n°11580*05 (modèle facultatif)
- Article 1740 A du CGI (sanctions pour reçu indu — rappel : émission sans éligibilité = amende égale au montant indûment déduit)

## 12. Liens

- Pattern PDF/A-3 source : [app/Services/DocumentPrevisionnelService.php:198](app/Services/DocumentPrevisionnelService.php:198)
- Pattern numérotation : [app/Services/NumeroPieceService.php](app/Services/NumeroPieceService.php)
- Hook NDF abandon : [app/Services/NoteDeFrais/NoteDeFraisValidationService.php](app/Services/NoteDeFrais/NoteDeFraisValidationService.php)
- Footer PDF unifié : [app/Support/PdfFooterRenderer.php](app/Support/PdfFooterRenderer.php)
- Doc multi-tenant : [docs/multi-tenancy.md](docs/multi-tenancy.md)
