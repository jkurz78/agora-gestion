# Flux bancaires live en partie double — slice de correction (design)

**Date** : 2026-06-01
**Branche** : `feat/compta-v5` (non mergée, non poussée)
**Statut** : design validé en brainstorming, prêt pour `/plan`.
**Mémoire** : [[project-compta-v5-flux-bancaires-live-pd]], fait suite à [[project-compta-v5-journal-banque-slice1]] et au cutover [[project-compta-v5-cutover-remises-rappro]].

## Contexte

Après la slice « journal de banque » (masquage T2/T4) et le cutover partie double, un tour IHM sur clone prod frais (`COMPTA_USE_PARTIE_DOUBLE=true`) a révélé que les écrans **bancaires live** (remise, sélection de candidats, rapprochement) n'avaient **jamais été exercés en PD** — seuls le backfill et les rapports l'avaient été (smoke-test). Ces écrans raisonnent encore en granularité **legacy** : `transaction.compte_id`, `montant_total`, `statut_reglement` portés sur la transaction **opérationnelle** (T1). Or la couche PD a déplacé les **mouvements bancaires** sur les écritures de trésorerie **T2** (encaissement `5112/530/512X → 411`) et **T4** (remise `512X → 5112`), qui portent `journal=banque`, `compte_id=NULL`, et les lignes 512X/5112.

**Racine commune des 4 bugs** : décalage de granularité UI legacy (T1) ↔ mouvements bancaires PD (T2/T4).

### Chaîne comptable de référence (chèque reçu, école 411 systématique)

```
T1  411 D / 7xx C      créance opérationnelle (recette)         journal = vente
T2  5112 D / 411 C     encaissement : chèque « en main »        journal = banque   (lettre 411 ↔ T1)
T4  512X D / 5112 C    dépôt en banque (remise)                 journal = banque   (lettre 5112 ↔ T2)
```

Le **rapprochement bancaire** rapproche le **relevé**, donc le compte **512X**. Le mouvement 512X naît **au dépôt (T4)**, jamais à l'encaissement (5112 = « chèques à encaisser », pas encore en banque). Conséquence structurante : **l'objet rapprochable côté banque, c'est le mouvement 512X (T4), pas la T1 opérationnelle.**

## Intention (Why)

Rendre les écrans bancaires live cohérents avec la couche PD, **sans usine à gaz**, en préservant l'ergonomie V4 (pouvoir solder/rapprocher un chèque). Cinq correctifs, racine commune, un test déterministe par bug.

## Preuve forensique (remise #15, clone prod)

Reconstitution exacte du « pas de T4 » signalé :

| tx | rôle | lignes (compte D/C) | lettrage |
|----|------|---------------------|----------|
| 155 | T1 source | `411` D 80 (tiers 49) · `706B` C 80 | 411 = `rq34…` |
| 176 | T2 encaissement | `5112` D 80 · `411` C 80 (tiers 49) | 411 = `rq34…` · 5112 = `8VOA…` |
| 182 | T4 remise (créée à la main / tinker) | `5121` D 80 · `5112` C 80 | 5112 = `8VOA…` |

Le `411` de 155↔176 partage le même lettrage (`rq34…`) → **la créance était soldée avant la remise** : le chèque #155 n'était **pas « en attente »**, il avait déjà été **encaissé** (T2 #176, libellé « Encaissement règlement séance », au marquage du règlement séance #62 « reçu »). Le `5112` n'a été lettré (`8VOA…`) qu'à la **création de la T4**. Avant cela : #155 sans référence, 5112 non lettré, pas de T4. Or `comptabiliser()` pose la référence **et** crée la T4 **dans la même transaction atomique** → s'il avait tourné, les deux existeraient. **Donc `comptabiliser()` n'a jamais tourné via l'IHM** : c'est **Bug 1** (bouton « Comptabiliser » masqué parce que #155 était `recu`). Pas de défaut de `recreerT4`.

## Les 5 correctifs

### Bug 1 — Remise en limbo (le plus bloquant)

**Symptôme** : dès l'enregistrement du brouillon, la remise paraît comptabilisée (badge vert), le bouton « Comptabiliser » disparaît → ni référence, ni T4. Remise figée.

**Cause** : `RemiseBancaireShow::estBrouillon()` ([app/Livewire/RemiseBancaireShow.php:30](../../app/Livewire/RemiseBancaireShow.php)) et `remise-bancaire-list.blade.php:104` infèrent « comptabilisée » depuis `statut_reglement ∈ {recu, pointe}` sur les sources. Or `enregistrerBrouillon()` met déjà les sources à `recu`.

**Décision** : introduire un **état explicite** `remises_bancaires.comptabilisee_at` (nullable timestamp), plutôt qu'une inférence fragile. La référence n'est **pas** un signal fiable (chèques remisés réels en prod ont `reference = NULL` — Finding 2 du cutover), et `queryT4()->exists()` est PD-couplé + N requêtes pour la liste.

**Fix** :
- Migration : ajouter `comptabilisee_at` nullable à `remises_bancaires`. **Backfill** : `comptabilisee_at = ` la `date` de la remise (proxy historique fiable) pour toute remise dont une T4 existe (critère 512X de `queryT4`).
- `RemiseBancaireService::comptabiliser()` pose `comptabilisee_at = now()` ; `modifier()` la conserve ; `supprimer()` / passage à vide la remet à `null`.
- `estBrouillon()` ⇒ `comptabilisee_at === null`. Liste ([resources/views/livewire/remise-bancaire-list.blade.php:104](../../resources/views/livewire/remise-bancaire-list.blade.php)) : badge piloté par la colonne.

### Bug 2 — Fuite de candidats banque + T4 listé comme source

**Symptôme** : l'écran « modifier remise » propose des **T2** d'encaissement (`journal=banque`, ex. #176) comme chèques remisables ; l'écran détail liste la **T4** comme une source.

**Cause** : `RemiseBancaireSelection::buildBaseQuery()` ([app/Livewire/RemiseBancaireSelection.php:143](../../app/Livewire/RemiseBancaireSelection.php)) filtre `type=recette + mode + statut + remise_id` **sans filtre `journal`**. Et `RemiseBancaire::transactions()` (hasMany `remise_id`) renvoie aussi la T4 (qui porte `remise_id`).

**Fix** :
- `->operationnel()` (scope `journal ∈ {vente, achat}`) sur `buildBaseQuery()`.
- Écran détail (`RemiseBancaireShow::render`) : sources via `->operationnel()` (exclut la T4) ; afficher la T4 séparément comme **« dépôt »** (lecture seule).
- **Audit** des autres surfaces qui sélectionnent des recettes opérationnelles comme candidates (créances à recevoir, encaissement facture) : `ReglementTable`, `TransactionUniverselle`, `FactureShow`. Le plan vérifie chacune et pose `operationnel()` là où une recette banque pourrait fuiter.

### Bug 3 — T2/T4 en `statut_reglement = en_attente`

**Symptôme** : les écritures `journal=banque` sont toutes `en_attente` (`createTransactionHeader` ne pose pas de statut → défaut colonne).

**Décision** : `statut_reglement` est **NOT NULL** sans valeur neutre — on **n'invente pas** de statut ni de migration. Le **garde canonique est le filtre `journal`** (Bug 2). Bug 3 est donc **subsumé** : tant que toutes les surfaces de candidats-règlement filtrent `journal`, le `en_attente` des banques est inoffensif.

**Fix** : test de défense affirmant qu'**aucune écriture `journal=banque` n'apparaît** dans une surface de candidats-règlement (remise, créances, encaissement). Pas de changement de données.

### Bug 4a — Solde pointé (cas remise) : absorbé par Bug 1

**Constat** (re-tracé) : pour une **remise**, `RapprochementDetail` affiche une ligne groupée ; la pointer appelle `toggleRemise` ([app/Services/RapprochementBancaireService.php:294](../../app/Services/RapprochementBancaireService.php)), qui pose `rapprochement_id` sur **toutes** les tx `remise_id=X`, **T4 incluse** (elle porte `remise_id`). `calculerSoldePointage` (PD) somme les lignes 512X des tx pointées → la **T4 est comptée**, le solde bouge. **Donc dès que la T4 existe (Bug 1), le pointage de remise fonctionne.** Le « solde figé » de #15 venait de l'absence de T4 (Bug 1), pas d'un défaut de pointage.

**Fix** : aucun code neuf. **Test de non-régression** : remise comptabilisée → pointer la ligne remise → `calculerSoldePointage` augmente du montant du dépôt ; dépointer → revient. (Idem cas déjà-512X : virement/CB recette, chèque émis dépense, qui portent leur 512X sur la T1 — pointer la T1 suffit.)

### Bug 4b — Rapprocher un chèque en attente (ergonomie V4 préservée)

**Besoin** : pouvoir pointer un chèque **encore en attente** (encaissé en 5112, pas remis) directement sur l'écran de rapprochement, comme en V4. Aujourd'hui en PD : pointer la T1 génère/propage une T2 (`5112`), mais **aucune ligne 512X** → solde figé.

**Décision (solution 2, « sans remise formelle »)** : au pointage d'un chèque/espèces loose en attente, **fabriquer le mouvement 512X manquant** = une **écriture de dépôt** `512X D / 5112 C`, sans créer de `RemiseBancaire`. Variante mince de `pourRemiseBancaire` (1 ligne source). Réutilise `encaisserSiNonEncaisse`, le lettrage 5112, et le pattern de suppression `supprimerT4SiExiste`.

**Mécanique — pointage** (`toggleTransaction`, branche **recette** loose, mode chèque/espèces, `journal=vente`, `remise_id=NULL`, PD ; les dépenses chèque émis / espèces ne passent pas par 5112/530 vers 512X → hors 4b) :
1. `encaisserSiNonEncaisse(cheque)` → garantit la T2 (`5112 D / 411 C`).
2. Localiser la ligne 5112 **non lettrée** de la T2 (même lookup que `recreerT4` : via `trouverEncaissementT2`).
3. Générer l'écriture de dépôt `512X D / 5112 C` (`journal=banque`, `compte_id=NULL`, `remise_id=NULL`) + **lettrer la paire 5112** (T2 ↔ dépôt).
4. Poser `rapprochement_id` sur **le dépôt** (porteur du 512X) **et** sur la T1 (affichage legacy + `statut=pointe`).

**Mécanique — dépointage** (symétrique, idempotent) :
1. Retrouver le dépôt : depuis la T1 → T2 (`trouverEncaissementT2`) → ligne 5112 de la T2 → autre ligne de même `lettrage_code` → sa transaction = le dépôt.
2. Délettrer le 5112 + supprimer l'écriture de dépôt.
3. Effacer `rapprochement_id` sur la T1 ; statut → `recu`/`en_attente`. La T2 (encaissement) **subsiste** : le chèque reste « encaissé en 5112, non déposé ».

**Invisible à l'écran** : le dépôt a `compte_id=NULL` + `journal=banque` → il **n'apparaît pas** comme une ligne distincte du rapprochement, et reste masqué des listes opérationnelles. L'utilisateur coche le chèque, le 512X se fabrique dessous.

**Non-usine-à-gaz — garanties** :
- Aucun objet persistant nouveau (pas de `RemiseBancaire` fantôme, pas de flag, pas de clutter UI).
- Un seul ajout comptable : une méthode `EcritureGenerator::pourDepotRapprochement` (variante 1-ligne de `pourRemiseBancaire`).
- Réversibilité via les patterns lettrage déjà en place (`trouverEncaissementT2`, `delettrerParLigne`).
- Séparation nette par `remise_id` : un chèque **dans** une remise se pointe via la remise (4a) ; un chèque **loose** se pointe via le dépôt au vol (4b). Pas de chevauchement.

## Cas limites & risques

- **Espèces loose** : même mécanique via `530` au lieu de `5112` (le dépôt reste `512X D / 530 C`). À couvrir.
- **Virement / CB recette, chèque émis dépense** : portent déjà leur 512X sur la T1 → pointage direct, **pas** de dépôt au vol (4b ne s'applique qu'aux modes via portage 5112/530). Garde explicite sur le mode.
- **Double pointage / dépointage répété** : idempotence — re-pointer ne crée pas un 2ᵉ dépôt (garde : dépôt déjà présent pour ce chèque) ; dépointer puis re-pointer recrée proprement.
- **Verrouillage du rapprochement** : un dépôt 4b pointé puis verrouillé suit le sort des autres (verrou = `rapprochement_id` figé). Cohérent.
- **Masquage trop large** (slice journal de banque) : ne pas exclure par erreur une T1 opérationnelle des écrans — couvert par tests « une vente reste visible / une banque est masquée ».

## Critères d'acceptation (un test déterministe par bug)

1. **Bug 1** : après `enregistrerBrouillon`, `estBrouillon()` reste `true` (bouton Comptabiliser visible) ; après `comptabiliser`, `comptabilisee_at` est posé et `estBrouillon()` = `false`. Migration : une remise legacy avec T4 obtient `comptabilisee_at` au backfill.
2. **Bug 2** : `buildBaseQuery` n'inclut aucune écriture `journal=banque` (T2/T4) ; l'écran détail liste les sources sans la T4.
3. **Bug 3** : aucune écriture `journal=banque` n'apparaît dans les surfaces de candidats-règlement auditées.
4. **Bug 4a** : remise comptabilisée → pointer la ligne remise augmente `calculerSoldePointage` du montant du dépôt ; dépointer revient à l'identique.
5. **Bug 4b** : pointer un chèque en attente loose → un dépôt `512X/5112` est généré, lettré, `calculerSoldePointage` augmente ; dépointer supprime le dépôt, délettre, le solde revient, la T2 subsiste. Idempotence point/dépoint vérifiée.
6. **Suite complète** : 0 failed.

## Hors périmètre

- Numérotation des journaux (Slice 2 journal de banque).
- UI journaux visibles + bascule vocabulaire (Slice 3 journal de banque).
- Conversion des `VirementInterne` en écritures du journal de banque (dette actée Slice 3).
- Refonte structurelle du rapprochement pour piloter sur les lignes 512X (option B écartée — l'approche A + dépôt au vol suffit).

## Exécution

Subagent-driven (Opus planifie, Sonnet exécute), TDD, un test déterministe par bug. Tests manuels localhost sur le clone prod avant tout push. **Ne pas merger `feat/compta-v5`** tant que ces flux live ne sont pas validés.
