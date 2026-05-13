# Fiche tiers slice 4 — Reçus fiscaux pour cotisations déductibles

**Date** : 2026-05-10
**Statut** : Spec en attente de validation
**Périmètre** : extension du programme reçu fiscal (MVP livré v4.3.0) aux **cotisations** snapshot=déductible. Émission unitaire, mêmes garanties (idempotence / numérotation / PDF figé / hash SHA256 / annulation auto).

## 1. Objectif

Permettre à une association éligible d'émettre un **reçu fiscal légal** pour une **cotisation déductible**, en réutilisant intégralement les briques du programme reçu fiscal (service, modèle, numérotation, stockage, observers, policy, controller).

Source de l'éligibilité : `adhesions.deductible_fiscal` (snapshot fiscal posé à la création de l'adhésion par slice 3d). Hérité automatiquement :
- HelloAsso → `formules_adhesion.deductible_fiscal` (lui-même snapshot du `tier.isEligibleTaxReceipt` de l'API)
- Manuelles → case "Déductible fiscal" cochée sur la formule au paramétrage

## 2. Choix structurants actés

| Décision | Choix retenu | Justification |
|---|---|---|
| Source de vérité | `adhesions.deductible_fiscal` (snapshot, figé à la création) | Slice 3d a posé cette source unique. Modifier la formule catalogue ne casse pas les reçus déjà émis. |
| Numérotation | **Compteur unique partagé** don + cotisation, séquence annuelle `2026-0001` par tenant | Conforme CGI (un seul fil de numérotation par asso). Aucune migration : la séquence existante absorbe les cotisations. |
| Granularité | 1 reçu par adhésion (donc 1 ligne de transaction cotisation) | Récap annuel agrégé = phase 2 (hors MVP slice 4). |
| Adhésions gratuites | Pas de reçu (pas de transaction, pas de ligne) | Logique : pas de paiement, pas de reçu. UI : bouton non affiché. |
| Adhésions HelloAsso | Soumises au même flag `adhesions.deductible_fiscal` | Snapshot HelloAsso = source unique. |
| Wording PDF | « Cotisation » au lieu de « Don » + dérivation `forme_versement = 'cotisation'` | Le donateur reste éligible art. 200 / 238 bis CGI sur cotisation. |
| Annulation auto | Suppression d'adhésion → annulation reçu (motif "Adhésion supprimée") | Symétrique à la suppression de don. La modification du montant de la transaction sous-jacente est déjà couverte par `TransactionRecuFiscalObserver`. |
| Schéma `recus_fiscaux_emis` | **Aucune migration** — `transaction_ligne_id` existante référence indifféremment une ligne don ou cotisation | DRY. Le wording cotisation/don est dérivé de la sous-cat usage à la génération du PDF. |
| Identification de la ligne cotisation | Résolution dynamique via `(transaction.lignes ↔ formule_adhesion.helloasso_tier_id ou sous_categorie_id)` | Pas de migration `transaction_ligne_id` sur `adhesions`. La résolution est triviale (1 ligne pour les manuelles ; helloasso_tier_id pour HelloAsso). |
| Récap annuel | **Hors scope MVP slice 4** | Reporté, idem don. |

## 3. Modèle de données

**Aucune migration nécessaire.** Le modèle slice 3d (`adhesions.deductible_fiscal`) + le modèle MVP recu fiscal (`recus_fiscaux_emis`) couvrent les besoins.

## 4. Architecture service

### 4.1 Extension de `RecuFiscalService`

Nouvelle méthode publique :

```php
public function obtenirOuGenererPourAdhesion(Adhesion $adhesion, ?User $user = null): RecuFiscalEmis
```

Implémentation : résout la `TransactionLigne` correspondante (cf. 4.3) puis délègue au `obtenirOuGenerer(TransactionLigne $ligne)` existant. Le primitive reste inchangé.

Ajout à la validation d'éligibilité existante (`validerEligibilite(TransactionLigne $ligne)`) : si la ligne est rattachée à une `Adhesion`, vérifier que `adhesion.deductible_fiscal === true`. Sinon → `RecuFiscalException` ("adhésion non déductible").

### 4.2 Dérivation du wording cotisation/don

```php
private function determinerObjetRecu(SousCategorie $sc): string
{
    return $sc->hasUsage(UsageComptable::Cotisation) ? 'cotisation' : 'don';
}
```

La vue PDF `pdf.recu-fiscal-don` est étendue (ou splittée) pour adapter le wording :
- En-tête : "REÇU AU TITRE D'UN DON" → "REÇU AU TITRE D'UNE COTISATION"
- Corps : "Don" → "Cotisation" partout
- Forme : "numéraire" reste valable (cotisation = versement numéraire). `abandon_revenus` ne s'applique pas aux cotisations.

Décision technique : **garder une seule vue Blade** avec un `@if($objet === 'cotisation')` localisé sur les libellés, plutôt que dupliquer. Réduit la dette si évolution future (loi Coluche, IFI, etc.).

### 4.3 Résolution `Adhesion → TransactionLigne`

```php
private function resoudreLigneCotisation(Adhesion $adhesion): TransactionLigne
{
    if ($adhesion->transaction_id === null) {
        throw new RecuFiscalException('Adhésion gratuite : pas de reçu possible.');
    }

    $lignes = $adhesion->transaction->lignes()->get();

    // HelloAsso : la ligne porte helloasso_tier_id correspondant à la formule
    if ($adhesion->formuleAdhesion?->est_helloasso) {
        $tierId = $adhesion->formuleAdhesion->helloasso_tier_id;
        $ligne = $lignes->firstWhere('helloasso_tier_id', $tierId);
        if ($ligne !== null) return $ligne;
    }

    // Manuel : 1 transaction = 1 ligne (créée par AdhesionService::creerDepuisWizard)
    if ($lignes->count() === 1) return $lignes->first();

    // Multi-lignes manuel (jamais émis aujourd'hui mais défensif) :
    // matcher par sous_categorie_id de la formule
    if ($adhesion->formuleAdhesion !== null) {
        $ligne = $lignes->firstWhere('sous_categorie_id', $adhesion->formuleAdhesion->sous_categorie_id);
        if ($ligne !== null) return $ligne;
    }

    throw new RecuFiscalException('Impossible d\'identifier la ligne cotisation de cette adhésion.');
}
```

### 4.4 Annulation auto sur suppression d'adhésion

Nouvel observer `AdhesionRecuFiscalObserver` :
- `deleted(Adhesion $a)` → si reçu actif existe sur la ligne résolue → annuler avec motif "Adhésion supprimée".

Les autres déclencheurs (modif montant, date, tiers de la transaction sous-jacente) sont **déjà couverts** par les observers MVP (`TransactionLigneRecuFiscalObserver` + `TransactionRecuFiscalObserver`) qui agissent au niveau ligne/transaction. Le snapshot `adhesion.deductible_fiscal` est immuable par design slice 3d → pas besoin d'observer côté adhésion sur ce champ.

### 4.5 Numérotation

**Inchangée.** Le `NumeroPieceService` étendu pour `recu-fiscal` partage la séquence annuelle quel que soit le type d'objet (don ou cotisation). Cohérent CGI.

## 5. UI

### 5.1 Onglet Adhésion fiche tiers (`livewire/tiers/onglets/adhesion.blade.php`)

Sur chaque ligne d'adhésion **payée et déductible** :
- Si reçu actif : badge cliquable "Reçu n° 2026-XXXX" → ouvre PDF
- Sinon : bouton "Émettre un reçu fiscal" → POST vers nouvelle action

Conditions d'affichage du bouton :
- `adhesion.transaction_id !== null` (pas gratuite)
- `adhesion.deductible_fiscal === true` (snapshot)
- L'asso est éligible (`association.eligible_recu_fiscal === true`)

### 5.2 Action Livewire

Nouvelle méthode sur le composant `Tiers\Onglets\Adhesion` (ou une action dédiée) :

```php
public function emettreRecuFiscalAdhesion(int $adhesionId): void
{
    $adhesion = Adhesion::findOrFail($adhesionId);
    $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion, auth()->user());
    $this->redirect(route('recu-fiscal.download', ['recu' => $recu]));
}
```

Réutilise le route MVP `recu-fiscal.download` (controller existant). Pas de nouveau controller.

### 5.3 Annulation / ré-émission

Réutilise l'UX existante MVP : menu ⋯ sur le badge → "Annuler" / "Ré-émettre" via les méthodes existantes du service.

## 6. PDF

### 6.1 Vue partagée (don ou cotisation)

`resources/views/pdf/recu-fiscal-don.blade.php` — étendre avec une variable `$objet` ('don' | 'cotisation') passée par `RecuFiscalService::genererPdf`. Adaptations :

- Titre : "REÇU AU TITRE D'UN {{ strtoupper($objet) }}" → "REÇU AU TITRE D'UN DON" / "REÇU AU TITRE D'UNE COTISATION"
- Article CGI : inchangé (200 ou 238 bis selon Tiers.type)
- Bloc "DÉCLARATION DU BÉNÉFICIAIRE" : "Le bénéficiaire reconnaît avoir reçu à titre de don..." → "à titre de cotisation déductible..."
- Bloc "FORME DU VERSEMENT" : pour cotisation, forcer "numéraire" (l'abandon de créance n'a pas de sens sur une cotisation)
- Loi Coluche / IFI : inchangées (l'asso peut être éligible, ça reste valide)

Décision : factoriser via un partial `pdf/partials/recu-fiscal-corps.blade.php` qui prend `$objet` en paramètre, et 2 wrapper minces (`recu-fiscal-don.blade.php` et `recu-fiscal-cotisation.blade.php`) qui passent l'objet — OU garder UNE seule vue paramétrée (plus simple pour MVP).

**Choix MVP slice 4** : une seule vue paramétrée. Si la divergence devient trop forte plus tard, on splitte.

## 7. Tests

| Cas | Type test |
|---|---|
| `obtenirOuGenererPourAdhesion` génère un reçu actif sur adhésion payée + déductible | Feature (avec PDF stub) |
| Lance `RecuFiscalException` sur adhésion gratuite (`transaction_id = null`) | Unit |
| Lance `RecuFiscalException` sur adhésion non déductible (`deductible_fiscal = false`) | Unit |
| Idempotence : 2 appels successifs retournent le même `RecuFiscalEmis` | Feature |
| Suppression de l'adhésion → reçu annulé avec motif "Adhésion supprimée" | Feature (observer) |
| HelloAsso : ligne identifiée par `helloasso_tier_id` | Unit (`resoudreLigneCotisation`) |
| Manuel mono-ligne : ligne identifiée par `lignes()->first()` | Unit |
| Numérotation séquentielle partagée don/cotisation (séquence ne se réinitialise pas) | Feature |
| PDF objet=cotisation : titre + corps adaptés (assertions sur le HTML rendu) | Snapshot ou regex |
| Bouton "Émettre" présent uniquement si `deductible_fiscal=true` ET asso éligible | Livewire |
| Bouton non présent sur adhésion gratuite | Livewire |

## 8. Hors scope (rappel)

- **Récap annuel** (1 reçu cumulant tous les dons + cotisations d'un tiers sur l'année civile) — phase ultérieure
- Reçu fiscal pour cotisation depuis le portail tiers — slice futur, mêmes services réutilisés
- Notification email automatique à l'émission
- Bouton ZIP "Tous les reçus de l'année"

## 9. Risques & vigilances

- **Cohérence avec MVP don** : tout changement futur sur `RecuFiscalService` (article CGI, forme, etc.) doit rester cohérent pour les 2 cas. Tests unitaires sur la dérivation.
- **Doublon HelloAsso** : pour les cotisations HelloAsso, HelloAsso peut **déjà** émettre son propre reçu (selon la config de l'asso côté HelloAsso). Risque de doublon donateur. Symétrique au cas don (déjà décidé : avertissement non bloquant). On reproduit le même avertissement à la première émission cotisation HelloAsso.
- **Performance** : la résolution `Adhesion → ligne` charge `transaction.lignes` à chaque émission. Acceptable (1-3 lignes typiques). Eager-loading déjà présent côté `TiersAdhesionTimelineService`.

## 10. Branche & MEP

- Branche : `feat/fiche-tiers-slice4-recus-cotisations` créée à partir de `feat/fiche-tiers-slice3-formules-adhesions` (continuité — slice 4 dépend du snapshot fiscal slice 3d).
- MEP groupée slice 3 + slice 4 (décision utilisateur 2026-05-10 — raison : éviter MEP intermédiaire après les ajustements UX/sync HelloAsso de slice 3).

## 11. Procédure post-MEP (rappel cumulé slice 3+4)

1. (Slice 3) Configurer formules manuelles dans Paramètres → Adhésions
2. (Slice 3) Configurer fallback Don dans Paramètres → HelloAsso
3. (Slice 3) Wizard sync HelloAsso étape 1 : configurer chaque form (action + sous-cat ou opération)
4. (Slice 3) Wizard sync HelloAsso étape 3 : sync (auto-création formules + enrichissement transactions)
5. (Slice 3) `php artisan adhesions:backfill` → crée Adhesion à partir des transactions cotisations existantes (snapshot fiscal correct)
6. (Slice 4) Vérifier que les adhésions HelloAsso éligibles ont bien `deductible_fiscal=true` (résultat du backfill via `formules_adhesion.deductible_fiscal` héritée du `tier.isEligibleTaxReceipt`)
7. (Slice 4) Optionnel : ré-émettre les reçus fiscaux des cotisations historiques d'années en cours via la nouvelle UI de l'onglet Adhésion fiche tiers
