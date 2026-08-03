# Spec — Cutover compta-v5 : encaissement au premier événement, reconstruction des remises au backfill, survie des rapprochements

**Date** : 2026-05-31
**Branche** : `feat/compta-v5` (commits uniquement ici — **NE PAS merger main, NE PAS pusher** sans demande explicite)
**Statut** : PASS — prête pour `/plan` puis exécution subagent-driven (Opus planifie, Sonnet exécute)
**Contexte amont** : sous-slices 1a→1d livrées (voir MEMORY). Recette de validation « process opération (séance/règlement) » en cours sur un clone prod localhost. 4 défauts + 1 trou de backfill identifiés.

---

## 1. Objectif

Rendre le cycle partie double **fidèle et complet** sur trois plans, avant re-clone prod + re-backfill de cutover :

1. **Backfill** : reconstruire les **remises bancaires** (écritures T4 `512x→5112`) que la reprise actuelle ne génère pas, et router la conversion sur le **statut d'encaissement réel** et non sur le mode de paiement seul.
2. **Rapprochements** : garantir leur « survie » dans le modèle PD — la ligne 512x (du T4 pour un chèque remisé, ou de la transaction elle-même pour un encaissement direct) doit être l'ancre comptée par `calculerSoldePointage`.
3. **Ergonomie live** : préserver le geste actuel — pouvoir remettre en banque / pointer une transaction « en attente » **sans prérequis manuel** — en générant le T2 d'encaissement **implicitement** (idempotent) au moment de la remise / du pointage.

---

## 2. Terminologie (à respecter dans le code et les tests)

Le cycle PD d'un règlement de séance comporte **trois** transactions (il n'y a **pas de T3** — la numérotation historique saute) :

| Nom | Écriture | Rôle |
|-----|----------|------|
| **T1** | `706/751 → 411` (411 D tiers / 7x C) | Créance constatée. Statut initial `en_attente`. Porte une `reference` quand elle est source de remise. |
| **T2** | `5112/530/512x → 411` | Encaissement : la créance 411 est soldée (paire 411 lettrée) ; l'argent atterrit sur le compte de portage selon le mode (chèque→5112, espèces→530, virement/CB→512x). |
| **T4** | `512x → 5112` (ou `512x → 530`) | Remise bancaire : **un** débit 512x = total (sans tiers), crédite chaque ligne de portage source + auto-lettrage. `remise_id` posé sur le T4, `reference = null`. |

---

## 3. Modèle cible

### 3.1 Principe directeur — « encaissement au premier événement »

Le T2 (encaissement) est généré par **le premier** des événements qui confirme que l'argent a bougé :

- `marquerReçu` explicite (déjà OK : `ReglementOperationService::marquerRecu`), **OU**
- mise en **remise** bancaire (`RemiseBancaireService::comptabiliser` / `modifier`), **OU**
- **pointage** sur un rapprochement (`RapprochementBancaireService::toggleTransaction`).

Les trois appellent le **même** générateur idempotent (`EcritureGenerator::pourEncaissementCreance`, via le helper `encaisserPartieDouble`). Idempotence garantie par la garde existante : skip si la ligne 411 est absente **ou déjà lettrée**. Donc déclencher le T2 plusieurs fois est sans effet après le premier.

Conséquence : **zéro régression d'ergonomie**. « Marquer reçu » devient une *conséquence* implicite de remettre/pointer, jamais un prérequis.

### 3.2 Table de routage de la conversion (backfill) — recettes

Le routage se décide sur le **triplet `(statut_reglement, remise_id, rapprochement_id)`**, pas sur `mode_paiement` seul.

| Prédicat | Cible comptable | Cas | Volume clone (réf.) |
|----------|-----------------|-----|---------------------|
| `statut = en_attente` | `706→411` **seul** (créance) | 3 | 2 (virements) |
| `remise_id ≠ null` | `706→411` + portage **5112** (chèque) / **530** (espèces) ; le T4 sera bâti en phase 2 | 2 | 21 chèques |
| `remise_id = null` ET `rappro_id ≠ null` | `706→411` + encaissement **direct 512x** | 1 | 6 chèques pointés |
| `remise_id = null` ET `rappro_id = null` ET reçu | `706→411` + portage **5112** (reste sur transit) | 4 | 1 (#155) |

Modes hors chèque : **espèces → 530**, **virement/CB/prélèvement → 512x direct** (inchangé).
Prédicat-clé du cas 1 : *« la banque a vu ce règlement individuellement (rapproché, sans remise) → il est sur 512x »*.

> ⚠️ **À vérifier par l'implémenteur** (voir §8) : le mécanisme exact par lequel un chèque pointé sans remise atterrit sur 512x (les 6 du clone sont déjà sur 5121 dans la reprise actuelle — mécanisme à confirmer, ne pas présumer).

### 3.3 Reconstruction des remises (backfill, phase 2) + propagation rappro (phase 3)

Pour chaque `RemiseBancaire` de l'exercice traité :

1. Réutiliser **`RemiseBancaireService::recreerT4($remise, $transactionIds)`** (qui appelle `EcritureGenerator::pourRemiseBancaire`) → génère le T4 `512x→5112` + auto-lettrage. `recreerT4` **cherche** une ligne de portage 5112/530 non lettrée, sans tiers, en débit sur les transactions sources → d'où l'ordre **phase 1 (pose le 5112) AVANT phase 2**.
2. **Propager le `rapprochement_id`** : par remise, prendre l'**unique** `rapprochement_id` des transactions sources (vérifié cohérent : une seule valeur ou NULL par remise) ; s'il est non-null, le poser sur le T4 reconstruit. → la ligne 512x du T4 devient l'ancre du rapprochement.

### 3.4 Survie du rapprochement (mode PD)

`RapprochementBancaireService::calculerSoldePointage` (mode PD) somme les **lignes 512x** des transactions portant le `rapprochement_id`. Donc :

- **Cas 1** (pointé sans remise) : la ligne 512x est sur la transaction elle-même → `rapprochement_id` déjà au bon endroit. ✓ rien à faire au backfill.
- **Cas 2** (remisé) : la ligne 512x est sur le **T4** → le backfill doit y reporter le `rapprochement_id` (phase 3). Cohérent avec le live (`toggleRemise` pointe sources + T4).

---

## 4. Preuves issues du clone prod (asso #1, exercice courant)

(État après la reprise **actuelle**, défectueuse — sert de référence de non-régression.)

- Chèques : `en_attente` 0 · `recu` (16 avec remise + 1 sans = 17) · `pointe` (5 avec remise + 6 sans = 11). Le « 11 » = **total pointés**, scindé **6 sans remise / 5 avec remise**.
- Virements en attente : 2 (ex. **#138** — actuellement le **cycle comptant complet à tort** : 4 lignes dont 5121 + 411 lettré → preuve du Bug A).
- **#113** (chèque reçu, `remise_id=10`) : **bloqué sur 5112**, aucun T4 → preuve que le backfill ne reconstruit pas les remises.
- **6 chèques pointés sans remise** (#65,67,68,69,77,78 — réfs `IMP-…`) : tous `rapprochement_id` non-null, déjà sur 5121.
- **#155** (reçu chèque sans remise) : `rappro = null` → cible 5112 transit (cas 4).
- Remises : `rapprochement_id` **cohérent par remise** — #6→22, #7→23 (seules rapprochées), #8–14 → NULL.
- Contrôle : chèques `sans_remise + rapprochés` = 6 ; `avec_remise + rapprochés` = 5.

> Requêtes de vérif (tinker, tenant à booter) :
> `TenantContext::boot(Association::first());` puis filtrer `Transaction` sur `mode_paiement` / `statut_reglement` / `remise_id` / `rapprochement_id`. Colonne montant = `montant_total` (pas `montant`). Sans boot tenant, le scope renvoie `WHERE 1=0`.

---

## 5. Périmètre des travaux

### Bug A — `app/Services/Compta/TransactionConverter.php` (`convertir`, ~L130-215)
Router sur le **triplet** (§3.2) au lieu de `mode_paiement !== null`. En attente → `pourRecette/DepenseACredit` (créance seule) **quel que soit le mode**. Reçu/pointé → cycle d'encaissement vers le bon compte de portage selon le cas. Conserver `existingTransaction: $tx` et `equilibree = true`.

### Bug B — `app/Livewire/TransactionUniverselle.php` (`marquerRecu`, L333-343)
Remplacer le toggle nu par `app(\App\Services\ReglementOperationService::class)->marquerRecu($tx);`. Les gardes sont **identiques** (EnAttente + `isLockedByRapprochement` + `isLockedByFacture`). Le service génère le T2 + lettre la paire 411.

### Fix C — `app/Services/RemiseBancaireService.php` (`comptabiliser` L75-120, `modifier` L125-185)
Avant `recreerT4`, garantir que chaque source en attente porte sa ligne de portage : générer le T2 idempotent (réutiliser le helper d'encaissement, p.ex. via `ReglementOperationService` ou en factorisant `encaisserPartieDouble`). Idempotent → no-op si T2 déjà présent. Puis `recreerT4` trouve la ligne 5112/530.

### Fix D — `app/Services/RapprochementBancaireService.php` (`toggleTransaction` L201-262, branche `depense`/`recette`)
Au **pointage** (passage en `Pointe`) d'une transaction `en_attente`, générer le T2 idempotent **avant/avec** le flip de statut, pour que la ligne 512x existe et soit comptée par `calculerSoldePointage`. Concerne surtout virements/CB/espèces (les chèques passent par les remises, branche `toggleRemise`). Ne rien casser au dé-pointage.

### Backfill — `app/Console/Commands/BackfillPartieDoubleCommand.php`
- **Phase 1** : conversion via `convertir` corrigé (Bug A).
- **Phase 2** (NOUVELLE) : après conversion, boucler les `RemiseBancaire` de l'exercice/asso traité → `recreerT4`.
- **Phase 3** (dans la phase 2) : propager le `rapprochement_id` unique des sources sur le T4.
- Respecter les options existantes (`--exercice`, `--all`, `--asso`, `--dry-run`, `--force`) et les resets (`resetExercice`, `resetLettrageAuditExercice`). La reconstruction des remises doit être **idempotente / rejouable** (garde `queryT4`).

---

## 6. Critères d'acceptation (testables)

1. **Bug A** : une recette `en_attente` avec mode posé → conversion = **2 lignes** (`706→411`), aucune ligne classe 5, 411 **non lettré**. (Non-régression #138.)
2. **Bug A / cas 2** : un chèque `recu`/`pointe` avec `remise_id` → après phase 1, porte une ligne **5112** débit non lettrée, sans tiers ; 411 lettré avec sa paire.
3. **Backfill phase 2** : pour chaque remise, un **T4** existe (`512x` D total / `5112` C par source), auto-lettré, `remise_id` posé, `reference = null`. Le 5112 des sources est **soldé** (lettré).
4. **Backfill phase 3** : le T4 d'une remise rapprochée porte le `rapprochement_id` des sources ; `calculerSoldePointage` (PD) compte la ligne 512x du T4. (#113 et la remise #6/#7 → soldes cohérents.)
5. **Cas 1** : chèque `pointe` sans remise → ligne **512x** sur la transaction, `rapprochement_id` conservé, comptée au solde. **Pas** de passage par 5112, **pas** de remise synthétique.
6. **Cas 4** : chèque `recu` sans remise et non rapproché (#155) → reste sur **5112**.
7. **Fix C** : remettre en banque un chèque **en attente** → T2 (5112) généré puis T4 ; **sans** « marquer reçu » préalable. Rejouer `comptabiliser` n'ajoute pas de second T2 (idempotence).
8. **Fix D** : pointer une transaction (virement) **en attente** → T2 (512x) généré, statut `Pointe`, solde de pointage déplacé du bon montant. Dé-pointer revient proprement à l'état antérieur.
9. **Bug B** : `marquerRecu` Livewire génère le T2 (paire 411 lettrée), identique au flux séance.
10. **Idempotence backfill** : relancer le backfill sur le même exercice (avec `--force`/reset) reproduit le même état (pas de doublons de T4, pas de double lettrage).
11. **Multi-tenant** : tous les chemins respectent le scope fail-closed (T4, audit lettrage portent le bon `association_id`).

---

## 7. Stratégie de test (TDD, Pest, SQLite in-memory)

- RED → GREEN → REFACTOR par item. Fixtures « legacy » créées via `Transaction::create` + `TransactionLigne::create` avec `statut_reglement` / `remise_id` / `rapprochement_id` positionnés (cf. patterns `BackfillPartieDoubleCommandTest` et `…EndToEndTest`, helpers `setupBackfillFixture…`, `creerFixtureE2E`, `simulerEtatLegacyE2E`).
- Couvrir les **4 cas** de la table + Fix C + Fix D + Bug B + idempotence.
- Tests d'intégration backfill via `$this->artisan('compta:backfill-partie-double', ['--exercice'=>…, '--asso'=>…])`.
- Bruit à ignorer : dépréciations PHP 8.5, erreurs Ghostscript. `timeout` indisponible sur macOS.
- Lancer ciblé d'abord (`sail artisan test --filter=…`), suite complète avant de clore chaque vague.

---

## 8. Points à vérifier AVANT/PENDANT l'implémentation (ne pas présumer)

1. **Cas 1 — mécanisme 512x** : pourquoi les 6 chèques pointés sans remise sont sur 5121 et non 5112 dans la reprise actuelle ? Confirmer comment router *cheque + pointe + sans remise* vers 512x (resolver trésorerie vs `resoudreComptePortage` qui force 5112 pour chèque). En déduire l'implémentation correcte du cas 1.
2. **Structure lumped vs T2 séparé** : la reprise actuelle produit un cycle « lumped » (`pourRecetteComptant`, créance+encaissement dans **une** transaction) alors que le live produit T1 + T2 **séparés**. Décider (piloté par tests) si le backfill conserve le lumped (suffit si comptes/lettrage/soldes corrects) ou reproduit le T2 séparé (meilleure fidélité au live). Les AC §6 priment ; viser la fidélité au live si peu coûteux.
3. **Source de remise & ligne 5112** : confirmer quelle transaction porte `remise_id` **et** la ligne 5112 que `recreerT4` doit retrouver (dans le clone lumped, c'est la même ; en live à séparer, vérifier).
4. **`modifier()` remise** : appliquer la même garantie T2-idempotent que `comptabiliser` (retrait/ajout de sources).
5. **Factorisation C/D** : éviter la duplication — un point d'entrée idempotent unique d'encaissement réutilisé par `marquerRecu`, `comptabiliser`/`modifier`, `toggleTransaction`.

---

## 9. Contraintes & Definition of Done

- **Branche** `feat/compta-v5` uniquement. Pas de merge main, pas de push remote sans demande explicite.
- Convention commit : `type(v5): description` + `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.
- Cast `(int)` des deux côtés des `===` PK/FK. `declare(strict_types=1)` + `final` + type hints. Pint vert.
- **DoD** : les 11 AC verts ; suite complète verte (référence ~12 171+ / 0 failed) ; backfill idempotent ; doc/CLAUDE/MEMORY mises à jour si pertinent.
- **Après merge de la branche dev → cutover** : l'utilisateur **re-clone prod + re-backfille** (scripts ops gitignored). La spec ne touche pas aux scripts de clone/deploy.

---

## 10. Ordre d'exécution recommandé (vagues)

1. **Vague 1 — Encaissement idempotent partagé + Bug B** : point d'entrée unique, brancher `TransactionUniverselle::marquerRecu`. (Petit, sécurise la base.)
2. **Vague 2 — Bug A** : routage `convertir` sur le triplet (4 cas) + AC 1,2,5,6.
3. **Vague 3 — Backfill phases 2 & 3** : reconstruction remises + propagation rappro + idempotence (AC 3,4,10,11).
4. **Vague 4 — Fix C & D** : remise/pointage d'« en attente » (AC 7,8).
5. **Vague 5 — Suite complète + revue + finishing-a-development-branch.**
