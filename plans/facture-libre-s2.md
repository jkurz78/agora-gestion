# Plan: Facture manuelle — Slice 2 (transformation devis → facture, lignes manuelles, génération transaction)

**Created**: 2026-04-28
**Branch**: feat/facture-libre-s2 (à créer ; main aujourd'hui)
**Status**: implemented
**Spec**: [docs/specs/2026-04-28-facture-libre-s2.md](../docs/specs/2026-04-28-facture-libre-s2.md) — gate ✅ PASS

## Goal

Étendre le module Facture pour qu'il porte trois types de lignes (`Montant` ref, `MontantManuel` libre, `Texte` info) et qu'à la validation d'une facture portant des lignes manuelles, **une `Transaction` recette + N `TransactionLignes`** soient générées automatiquement (statut "à recevoir", mode = `facture.mode_paiement_prevu`). Permettre la transformation d'un devis manuel accepté en facture brouillon, et la création d'une facture manuelle directe sans devis source. Embarquer le fix d'un bug PDF préexistant sur les lignes `Texte` (devis + facture). Pivot architectural invoice-first, traçé dans un ADR-002.

## Acceptance Criteria

- [ ] Un devis manuel `accepté` peut être transformé en facture brouillon par bouton dédié, lignes recopiées (`MontantManuel`/`Texte`), `factures.devis_id` renseigné. Bouton désactivé après transformation.
- [ ] Une facture manuelle vierge peut être créée directement depuis la liste, sans devis source.
- [ ] L'éditeur de facture brouillon permet d'ajouter des lignes des trois types (`Montant` ref via picker, `MontantManuel` libre, `Texte` info), avec recalcul live du total.
- [ ] Le bloc "Conditions de règlement" expose un champ `mode_paiement_prevu` (énuméré `ModePaiement`), visible et requis ssi la facture porte ≥ 1 ligne `MontantManuel`.
- [ ] À la validation d'une facture portant ≥ 1 ligne `MontantManuel` : guards `mode_paiement_prevu` requis + `sous_categorie_id` requise sur chaque `MontantManuel` ; **1 `Transaction` recette + N `TransactionLignes`** créées dans une transaction DB ; `facture_lignes.transaction_ligne_id` set sur chaque ligne manuelle ; pivot `facture_transaction` mis à jour.
- [ ] L'encaissement existant (flow Créances v2.4.3) traite la nouvelle transaction comme n'importe quelle créance — bouton "Encaisser" passe à "payé".
- [ ] Les transactions générées par les lignes manuelles sont verrouillées par la facture validée (`isLockedByFacture()` existant).
- [ ] PDF facture : option α appliquée — `Montant` ref affiche libellé+montant total (PU/Qté vides), `MontantManuel` affiche libellé+PU+Qté+montant total, `Texte` affiche libellé seul (PU/Qté/Montant vides).
- [ ] PDF devis manuel : lignes `Texte` n'affichent plus `0,00 €` dans les colonnes PU/Qté/Montant (bug fix embarqué).
- [ ] Multi-tenant : facture manuelle + transaction générée invisibles depuis une autre association — assertions sur la liste, la vue 360°, la recherche, et l'accès direct par ID (HTTP 403 ou 404).
- [ ] Race transformation devis (parallèle) : pas de double facture ; race validation facture : pas de double transaction générée. Stratégie : pattern projet existant (cf S6) — `lockForUpdate` + 2 transactions DB séquentielles dans le même test, assertion `count() = 1` après le second appel.
- [ ] Décision actée : `prix_unitaire > 0` et `quantite > 0` strictement (positifs). Les montants négatifs ne sont pas supportés en S2 ; les remises par montant négatif sont une évolution future possible.
- [ ] Logs `facture.valide` et `devis.transforme_en_facture` émis avec `association_id` + `user_id` (via `LogContext` existant) ; assertions via `Log::spy()` dans les tests Steps 7 et 8.
- [ ] Suite Pest globale 0 failed, 0 errored. ≥ 2 tests intrusion + 2 tests races (transformation, validation).
- [ ] ADR-002 rédigé (`docs/adr/ADR-002-facture-libre-invoice-first.md`).
- [ ] Mémoire projet `project_facture_libre_s2.md` et `project_devis_libre.md` mises à jour ; CHANGELOG entrée.

## Steps

### Step 1: Migrations DB — `factures.devis_id` + `factures.mode_paiement_prevu` + colonnes libres sur `facture_lignes`

**Complexity**: standard
**RED**: test (Pest, RefreshDatabase) qui assert le schéma cible :
- `factures` a `devis_id` (FK nullable, RESTRICT), `mode_paiement_prevu` (string nullable), index `(association_id, devis_id)`
- `facture_lignes` a `prix_unitaire` decimal(12,2) nullable, `quantite` decimal(10,3) nullable, `sous_categorie_id` FK nullable, `operation_id` FK nullable, `seance` int nullable
- `facture_lignes.type`, `transaction_ligne_id`, `libelle`, `montant`, `ordre` inchangés
- aucune ligne existante n'a été modifiée (fixtures représentatives chargées via seeders avant la migration : test rollback puis re-up doit conserver les données)
**GREEN**: 2 migrations Laravel sous `database/migrations/` — `add_devis_id_and_mode_paiement_prevu_to_factures` + `add_libre_columns_to_facture_lignes`. Up + down réversibles (drop foreign + drop column dans le bon ordre).
**REFACTOR**: None needed.
**Files**: `database/migrations/2026_04_28_*_add_devis_id_and_mode_paiement_prevu_to_factures.php`, `database/migrations/2026_04_28_*_add_libre_columns_to_facture_lignes.php`, `tests/Feature/Migration/FactureLibreSchemaTest.php`
**Commit**: `feat(facture-libre): migrations devis_id + mode_paiement_prevu + colonnes libres facture_lignes`

### Step 2: Enum `TypeLigneFacture` — 3e valeur `MontantManuel` + helpers

**Complexity**: trivial
**RED**: test enum unitaire — 3 cases présentes (`Montant`, `MontantManuel`, `Texte`) ; `genereTransactionLigne()` true uniquement pour `MontantManuel` ; `aImpactComptable()` true pour `Montant` et `MontantManuel`, false pour `Texte`.
**GREEN**: ajouter `case MontantManuel = 'montant_manuel';` + 2 méthodes helper.
**REFACTOR**: None needed.
**Files**: `app/Enums/TypeLigneFacture.php`, `tests/Unit/Enums/TypeLigneFactureTest.php`
**Commit**: `feat(facture-libre): enum TypeLigneFacture étendu (MontantManuel + helpers)`

### Step 3: Modèles — `Facture`, `FactureLigne`, `Devis` enrichis

**Complexity**: standard
**RED**: tests modèles :
- `Facture::devis()` belongsTo Devis ; `Facture::$casts['mode_paiement_prevu']` = `ModePaiement::class`
- `FactureLigne::$fillable` accepte `prix_unitaire`, `quantite`, `sous_categorie_id`, `operation_id`, `seance` ; casts numériques sur `prix_unitaire` (decimal:2) et `quantite` (decimal:3) ; relations `sousCategorie()`, `operation()`
- `Devis::factures()` ou `Devis::facture()` (préférer `hasOne` car cardinalité 1:1 MVP) ; helper `aDejaUneFacture(): bool`
**GREEN**: étendre les 3 modèles. Audit sécurité : `mode_paiement_prevu` et `devis_id` dans `$fillable` de Facture, ou via setter dédié pour matérialiser les transitions (cohérent avec pattern S1 sur Devis).
**REFACTOR**: factoriser le helper `aDejaUneFacture` si patron répété.
**Files**: `app/Models/Facture.php`, `app/Models/FactureLigne.php`, `app/Models/Devis.php`, `tests/Unit/Models/FactureTest.php`, `tests/Unit/Models/FactureLigneTest.php`, `tests/Unit/Models/DevisFactureTest.php`
**Commit**: `feat(facture-libre): modèles Facture/FactureLigne/Devis enrichis (devis_id, mode_paiement_prevu, lignes manuelles)`

### Step 4: `FactureService::creerLibreVierge` — création facture manuelle directe

**Complexity**: standard
**RED**: test feature — `creerLibreVierge(int $tiersId)` retourne une `Facture` brouillon, `tiers_id` correct, `devis_id = null`, association courante via `TenantContext`, `montant_total = 0`, pas de lignes. Test multi-tenant : un tiers d'une autre asso lève une exception (`guardAssociation`).
**GREEN**: méthode dans `FactureService` en `DB::transaction` + `guardAssociation` ; pas d'attribution de numéro (statut brouillon).
**REFACTOR**: None needed.
**Files**: `app/Services/FactureService.php`, `tests/Feature/FactureLibre/CreerLibreViergeTest.php`
**Commit**: `feat(facture-libre): FactureService::creerLibreVierge (facture manuelle directe brouillon)`

### Step 5: `FactureService::ajouterLigneLibre[Montant|Texte]` + recalcul total

**Complexity**: standard
**RED**: tests :
- `ajouterLigneLibreMontant(facture, ['libelle'=>..., 'prix_unitaire'=>800, 'quantite'=>3, 'sous_categorie_id'=>...])` crée FactureLigne `MontantManuel`, `montant = 2400`, `transaction_ligne_id = null`, `operation_id = null`, `seance = null` (sauf si fournis), ordre incrémental, `Facture.montant_total` recalculé
- `ajouterLigneLibreTexte(facture, 'libellé')` crée ligne `Texte`, `montant = null`, `prix_unitaire = null`, `quantite = null`, `sous_categorie_id = null`, `operation_id = null`, `seance = null`, total inchangé
- guards : refus si facture pas brouillon, refus si association_id ≠ tenant courant
- **guard montants positifs stricts** : `prix_unitaire ≤ 0` ou `quantite ≤ 0` lève une exception ciblée (S2 ne supporte pas les montants négatifs ; les remises par montant négatif sont une évolution future)
**GREEN**: 2 méthodes + helper privé `recalculerMontantTotal(Facture)` (somme `montant` toutes lignes non-null). Validation `prix_unitaire > 0 && quantite > 0` côté service.
**REFACTOR**: extraire helper si déjà code de recalcul ailleurs ; sinon laisser local.
**Files**: `app/Services/FactureService.php`, `tests/Feature/FactureLibre/AjouterLigneLibreTest.php`
**Commit**: `feat(facture-libre): FactureService::ajouterLigneLibre{Montant,Texte} + recalcul total`

### Step 6: `FactureService::valider` — guards mode_paiement_prevu + sous_categorie

**Complexity**: standard
**RED**: tests guards — sans génération encore :
- facture avec ≥ 1 `MontantManuel` et `mode_paiement_prevu = null` → exception ciblée à la validation
- facture avec ≥ 1 `MontantManuel` sans `sous_categorie_id` → exception ciblée
- facture sans `MontantManuel` (que des `Montant` ref ou `Texte`) → validation OK même si `mode_paiement_prevu = null` (régression : les factures classiques continuent à fonctionner)
**GREEN**: étendre `valider()` avec les 2 guards en début de méthode (avant l'attribution numéro existante).
**REFACTOR**: None needed.
**Files**: `app/Services/FactureService.php`, `tests/Feature/FactureLibre/ValiderGuardsTest.php`
**Commit**: `feat(facture-libre): guards validation facture (mode_paiement_prevu + sous_categorie)`

### Step 7: `FactureService::valider` — génération `Transaction` + `TransactionLignes` + set `transaction_ligne_id`

**Complexity**: complex
**RED**: tests scénarios BDD §validation :
- 1 facture brouillon, 2 `MontantManuel` 1200€/200€ + mode "virement" → 1 Transaction recette créée (montant_total=1400, statut_reglement="à recevoir", mode_paiement="virement", `libelle = "Facture {$facture->numero}"` où `$numero` est attribué dans la même `DB::transaction` AVANT la génération), 2 TransactionLignes (montants/sous_cat/operation/seance recopiés, notes=libellé facture_ligne), pivot `facture_transaction` peuplé, chaque facture_ligne manuelle a son `transaction_ligne_id` setté
- **assertion verrou** : la nouvelle Transaction `isLockedByFacture()` retourne `true`
- **assertion encaissement** : appel du flow Créances existant (méthode d'encaissement de la facture) → la transaction générée passe de `statut_reglement = "à recevoir"` à `"payé"` (régression : flow existant doit traiter la nouvelle transaction sans surprise)
- **assertion logs** : `Log::info('facture.valide', […])` est émis, et le contexte contient automatiquement `association_id` + `user_id` (via `LogContext` + middleware existant — vérifier via `Log::spy()` que l'event est émis avec une payload incluant `facture_id` et `transaction_id_generee`)
- mix `Montant` ref + `MontantManuel` : pivot porte les transactions ref + la nouvelle, total facture cohérent
- facture sans `MontantManuel` → aucune nouvelle transaction créée
- race : 2 jobs parallèles tentent valider la même facture → un seul gagne (lockForUpdate), pas de double transaction. **Stratégie test** : pattern projet (cf S6 multi-tenancy) — deux `DB::transaction` séquentielles dans le même test process avec `lockForUpdate` ; le second `valider` doit lever ou être no-op, pas créer de doublon (assertion `Transaction::count() = 1`)
**GREEN**: dans `valider()`, après guards et attribution numéro, si lignes `MontantManuel` existent :
1. Créer `Transaction` (type=recette, tiers, date=today, libelle, montant_total=somme, mode_paiement=mode_prevu, statut_reglement="à recevoir")
2. Pour chaque `MontantManuel` : créer `TransactionLigne` (transaction_id, sous_cat, operation, seance, montant, notes=libelle)
3. Update `facture_lignes.transaction_ligne_id` sur chaque ligne manuelle
4. Attacher Transaction au pivot `facture_transaction`
Le tout dans `DB::transaction()` + `lockForUpdate` sur la facture, scope tenant respecté.
**REFACTOR**: extraire en méthode privée `genererTransactionDepuisLignesLibres(Facture)` pour clarifier.
**Files**: `app/Services/FactureService.php`, `tests/Feature/FactureLibre/ValiderGenereTransactionTest.php`, `tests/Feature/FactureLibre/ValiderRaceTest.php`
**Commit**: `feat(facture-libre): génération Transaction+TransactionLignes à la validation facture`

### Step 8: `DevisService::transformerEnFacture` — passage devis accepté → facture brouillon

**Complexity**: standard
**RED**: tests scénarios BDD §transformation :
- devis "accepté" avec 2 lignes montant + 1 ligne texte → `Facture` brouillon créée, `tiers_id` cohérent, `devis_id = devis.id`, lignes recopiées avec **conversion explicite de type** : `DevisLigne` type `Montant` → `FactureLigne` type **`MontantManuel`** (assertion `factureLigne->type === TypeLigneFacture::MontantManuel`), `DevisLigne` type `Texte` → `FactureLigne` type `Texte`, montant_total cohérent
- guard : devis non-accepté → exception
- guard : devis déjà transformé (`Devis::aDejaUneFacture()`) → exception
- race : 2 transformations parallèles → une seule facture créée, l'autre lève l'exception (lockForUpdate, même stratégie test que Step 7)
- multi-tenant : transformation respecte le scope (cross-tenant tiers/devis lève exception)
- **assertion logs** : `Log::info('devis.transforme_en_facture', […])` est émis avec `devis_id` + `facture_id` ; contexte automatique `association_id` + `user_id` via `LogContext`
**GREEN**: méthode dans `DevisService` en `DB::transaction` + `lockForUpdate` sur le devis + `guardAssociation` ; mapping ligne par ligne.
**REFACTOR**: factoriser le mapper de ligne devis→facture si > 10 LOC.
**Files**: `app/Services/DevisService.php`, `tests/Feature/FactureLibre/TransformerDevisEnFactureTest.php`, `tests/Feature/FactureLibre/TransformerDevisRaceTest.php`
**Commit**: `feat(facture-libre): DevisService::transformerEnFacture (devis accepté → facture brouillon)`

### Step 9: UI Devis — bouton "Transformer en facture" sur DevisEdit

**Complexity**: standard
**RED**: test Livewire `DevisEdit` :
- **table de visibilité du bouton** sur les 5 statuts (assertions inline) :

  | statut    | bouton "Transformer en facture" |
  |---|---|
  | brouillon | absent du DOM |
  | validé    | absent du DOM |
  | accepté   | présent et `enabled` |
  | refusé    | absent du DOM |
  | annulé    | absent du DOM |

- bouton rendu avec attribut HTML `disabled` (jamais masqué via CSS) si `aDejaUneFacture() === true` ; **tooltip exact** : `"Une facture issue de ce devis existe déjà"`
- click sur bouton actif → appelle `DevisService::transformerEnFacture` + redirige vers la fiche facture brouillon créée (URL `/factures/{id}`)
- `wire:confirm` via modale Bootstrap (jamais natif)
**GREEN**: bouton + action Livewire dans `DevisEdit.php` ; modale Bootstrap reuse pattern ; redirection.
**REFACTOR**: None needed.
**Files**: `app/Livewire/DevisLibre/DevisEdit.php`, `resources/views/livewire/devis-libre/devis-edit.blade.php`, `tests/Feature/Livewire/DevisLibre/TransformerEnFactureBoutonTest.php`
**Commit**: `feat(facture-libre): bouton 'Transformer en facture' sur DevisEdit`

### Step 10: UI FactureList — bouton "Nouvelle facture manuelle"

**Complexity**: standard
**RED**: test Livewire `FactureList` — bouton "Nouvelle facture manuelle" présent à côté de "Nouvelle facture" ; ouvre modale `TiersAutocomplete` ; sélection tiers → appelle `FactureService::creerLibreVierge` + redirige vers FactureEdit du brouillon.
**GREEN**: bouton + action Livewire ; modale `TiersAutocomplete` réutilisée (pattern S1).
**REFACTOR**: None needed.
**Files**: `app/Livewire/FactureList.php`, `resources/views/livewire/facture-list.blade.php`, `tests/Feature/Livewire/FactureLibre/NouvelleFactureLibreBoutonTest.php`
**Commit**: `feat(facture-libre): bouton 'Nouvelle facture manuelle' sur FactureList`

### Step 11: UI FactureEdit — éditeur 3 types lignes + bloc Conditions de règlement

**Complexity**: complex
**RED**: tests Livewire `FactureEdit` :
- éditeur permet d'ajouter une ligne `Montant` (picker transaction existant, comportement actuel inchangé)
- éditeur permet d'ajouter une ligne `MontantManuel` (form libellé/PU/qté/sous_cat/opération/séance) → service appelé, total recalculé live
- éditeur permet d'ajouter une ligne `Texte` (libellé seul) → service appelé
- bloc "Conditions de règlement" affiche `mode_paiement_prevu` (select `ModePaiement`) ssi la facture porte ≥ 1 `MontantManuel` ; persiste sur changement
- édition refusée si facture validée (régression : pattern existant)
- mention discrète "Issue du devis {numero}" si `devis_id` renseigné
**GREEN**: étendre `FactureEdit` Livewire + blade. Réutiliser pattern de saisie de ligne existant pour `Montant` ; ajouter section dédiée pour `MontantManuel`/`Texte`.
**REFACTOR**: extraire un partial blade par type de ligne si la blade dépasse 250 lignes.
**Files**: `app/Livewire/FactureEdit.php`, `resources/views/livewire/facture-edit.blade.php`, partials, `tests/Feature/Livewire/FactureLibre/FactureEditLignesLibresTest.php`, `tests/Feature/Livewire/FactureLibre/FactureEditModePaiementPrevuTest.php`
**Commit**: `feat(facture-libre): éditeur FactureEdit étendu (3 types lignes + mode_paiement_prevu)`

### Step 12: PDF devis manuel — fix bug lignes `Texte`

**Complexity**: trivial
**RED**: test rendu PDF devis (Pest, snapshot ou parsing texte) — devis avec une ligne `Texte` : le rendu n'affiche pas `0,00 €` ni `0` dans les colonnes PU/Qté/Montant ; le libellé apparaît.
**GREEN**: blade `pdf/devis-libre.blade.php` — `@if($ligne->type === TypeLigneFacture::Texte)` (ou équivalent côté DevisLigne) → cellules vides au lieu de format monétaire de zéro.
**REFACTOR**: None needed.
**Files**: `resources/views/pdf/devis-libre.blade.php`, `tests/Feature/Pdf/DevisLibrePdfLigneTexteTest.php`
**Commit**: `fix(devis-libre): lignes Texte n'affichent plus 0,00 € sur le PDF`

### Step 13: PDF facture — option α (asymétrie honnête) + lignes `Texte` vides

**Complexity**: standard
**RED**: tests rendu PDF facture :
- ligne `Montant` ref → libellé + montant total rendus, PU et Qté vides
- ligne `MontantManuel` → libellé + PU + Qté + montant total rendus
- ligne `Texte` → libellé seul, PU/Qté/Montant vides (cohérent avec Step 12)
- bloc "Conditions de règlement" affiche `mode_paiement_prevu` si renseigné
- pas de mention "Issue du devis ..." sur le PDF (la facture est juridiquement autonome)
**GREEN**: blade `pdf/facture.blade.php` — switch sur `$ligne->type` pour rendre les cellules selon la table d'option α (cf §3.5 spec).
**REFACTOR**: extraire une partial `pdf.partials.ligne-facture` réutilisable si pertinent.
**Files**: `resources/views/pdf/facture.blade.php`, `tests/Feature/Pdf/FacturePdfOptionAlphaTest.php`
**Commit**: `feat(facture-libre): PDF facture option α (asymétrie honnête par type de ligne)`

### Step 14: Tests intrusion multi-tenant + tests races

**Complexity**: standard
**RED**: tests dédiés (peuvent regrouper si déjà couverts par steps précédents — vérifier coverage et compléter) :
- intrusion : facture manuelle + transaction générée invisibles depuis une autre association (liste, recherche, vue 360°, accès direct par ID)
- race transformation devis (test parallèle) : 2 workers tentent de transformer le même devis → un seul gagne
- race validation facture (test parallèle) : 2 workers tentent de valider la même facture → une seule transaction générée
**GREEN**: si gaps de coverage, ajouter guards manquants ou tests ; sinon ces tests confirment les implémentations précédentes.
**REFACTOR**: None needed.
**Files**: `tests/Feature/FactureLibre/IntrusionMultiTenantTest.php`, `tests/Feature/FactureLibre/RaceConditionsTest.php`
**Commit**: `test(facture-libre): tests intrusion multi-tenant + races transformation/validation`

### Step 15: Documentation — ADR-002 + Changelog + mémoire + seeders dev

**Complexity**: standard
**RED**: aucun test code. Critères : ADR rédigé, CHANGELOG mis à jour, seeders de démo permettent de voir la fonctionnalité en local (≥ 1 devis transformé en facture, ≥ 1 facture manuelle directe validée avec transaction générée, ≥ 1 facture manuelle brouillon).
**GREEN**: 
- `docs/adr/ADR-002-facture-libre-invoice-first.md` (Contexte / Options envisagées / Décision / Conséquences ; ~1 page)
- `CHANGELOG.md` entrée S2 + version
- `database/seeders/FactureLibreSeeder.php` (ou extension du DemoSeeder)
- mise à jour `project_facture_libre_s2.md` (statut livré) et `project_devis_libre.md` (S2 livrée, S3 fusionnée)
- ticket dette "Avoir n'annule pas transactions issues de lignes manuelles" créé (issue GitHub)
**REFACTOR**: None needed.
**Files**: `docs/adr/ADR-002-facture-libre-invoice-first.md`, `CHANGELOG.md`, `database/seeders/...`, fichiers mémoire
**Commit**: `docs(facture-libre): ADR-002 + changelog + seeders dev + mémoire projet`

## Complexity Classification

| Rating | Criteria | Review depth |
|--------|----------|--------------|
| `trivial` | Single-file rename, config change, typo fix, documentation-only | Skip inline review; covered by final `/code-review --changed` |
| `standard` | New function, test, module, or behavioral change within existing patterns | Spec-compliance + relevant quality agents |
| `complex` | Architectural change, security-sensitive, cross-cutting concern, new abstraction | Full agent suite including opus-tier agents |

**Steps `complex`** : Step 7 (génération transaction — cœur métier, race-prone, sécurité multi-tenant), Step 11 (UI éditeur — gros chantier UX, source d'erreurs).
**Steps `trivial`** : Step 2 (enum), Step 12 (fix bug PDF devis ciblé).
**Reste** : `standard`.

## Pre-PR Quality Gate

- [ ] Suite Pest globale verte (0 failed, 0 errored), ≥ ~3050 tests
- [ ] `vendor/bin/pint` clean
- [ ] `/code-review --changed` passes
- [ ] Régression manuelle parcours séances → règlement → comptabilisation → facture (flux historique inchangé)
- [ ] Recette manuelle parcours devis → transformation facture → validation → encaissement
- [ ] Recette manuelle parcours facture manuelle directe → validation → encaissement
- [ ] Recette manuelle PDF devis (lignes Texte vides) + PDF facture (option α)
- [ ] CHANGELOG + ADR-002 + mémoires à jour
- [ ] Tag + release GitHub `vX.Y.0` (numéro à confirmer avec l'utilisateur)

## Risks & Open Questions

- **Couplage `FactureEdit` existant** : la blade actuelle peut nécessiter une refonte non-triviale pour porter 3 types de lignes. Mitigation : Step 11 classé `complex`, prévoir partials par type ; au pire, splitter le step en 11a (saisie MontantManuel/Texte) + 11b (mode_paiement_prevu).
- **Comportement édition facture validée** : la spec dit "modification refusée" ; vérifier au build que `FactureEdit` actuel a déjà ce verrou et qu'il s'étend naturellement aux nouveaux champs.
- **Seeders dev** : nécessitent un tiers + une sous-catégorie recette + (optionnel) une opération/séance. Réutiliser fixtures S1.
- **Numérotation facture lors de transformation devis** : la facture créée est brouillon (pas de numéro), la numérotation se fait à la validation (cycle existant inchangé). À confirmer en build.
- **Verrou édition facture brouillon issue d'un devis transformé** : si l'utilisateur modifie/supprime des lignes recopiées du devis sur la facture brouillon, c'est autorisé (la facture est éditable jusqu'à validation, le devis source reste figé). Cohérent avec spec.
- **Déclassement des transactions ref existantes** : si une ligne `Montant` ref pointe vers une transaction déjà payée, la facture peut être validée. Le bouton "Encaisser" ne touche que les transactions à recevoir. À valider sur des fixtures représentatives au Step 14.
- **Estimation taille de PR** : ~25-35 commits attendus, suite Pest + ~80-120 tests ajoutés. Branche dédiée `feat/facture-libre-s2` recommandée.

---

**Approve this plan to begin implementation, or suggest changes?**
