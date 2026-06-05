# Chantier 1 — Revert auto-remise + rapprochement sur critère 512X strict

**Branche** : `feat/compta-v5` (NON mergée, NON poussée). `main` reste v4.3.
**Spec roadmap** : `docs/specs/2026-06-03-roadmap-compta-v5.md` (chantier 1).
**Mémoire** : `project_compta_v5_flux_bancaires_live_pd.md`.
**Exécution** : TDD subagent-driven (Sonnet implémente, Opus review spec+qualité).

## ⚠️ Garde-fou DB (incident clone-wipe 2026-06-02)
- Tests = sqlite `:memory:` (phpunit.xml force `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`).
- **JAMAIS** `migrate:fresh`, **JAMAIS** un `sail test` non borné, **JAMAIS** de commande DB destructrice.
- Avant tout test : vérifier qu'aucun `bootstrap/cache/config.php` ne fige mysql (`config:clear` si présent). Confirmé absent au démarrage.

## Baseline (2026-06-03) : 7 fichiers rappro/remise verts, 171 assertions, 0 failed.

---

## Task 1 — Revert auto-remise (chirurgical)

Suppression du mécanisme « dépôt/remise auto au pointage d'un chèque loose » (Bug 4b, pivot Approche X). On NE touche PAS aux fix voisins à garder : `recreerT4`, `supprimerT4SiExiste`, `comptabilisee_at`, `reconstruireT4Backfill`, réversion reçu→non reçu, Fix D (`encaisserSiNonEncaisse` + propagation T2 dans `toggleTransaction`).

1. `app/Services/RapprochementBancaireService.php` : supprimer les helpers privés `genererDepotChequeLoose`, `supprimerDepotChequeLoose`, `trouverDepotChequeLoose`, `lignePortageEncaissement`, `modeNecessiteDepot` + leurs 2 appels dans `toggleTransaction` (bloc pointage : `genererDepotChequeLoose(...)` ; bloc dépointage : `supprimerDepotChequeLoose(...)`). Retirer l'import `RemiseBancaire` s'il devient inutilisé.
2. `app/Services/RemiseBancaireService.php` : supprimer `remettreAutoPourRapprochement()` + `supprimerAutoRemise()` (+ section commentée Bug 4b v2). Retirer imports `RapprochementBancaire`/`TenantContext` s'ils deviennent inutilisés.
3. `app/Models/RemiseBancaire.php` : retirer `auto_generee` de `$fillable` et `casts()` + supprimer `scopeManuelle()` (aucun appelant).
4. `database/migrations/2026_06_02_140000_add_auto_generee_to_remises_bancaires.php` : **supprimer le fichier** (prod ne l'a jamais eu, re-clone-depuis-prod cohérent ; `numero` redevient NOT NULL — correct, `creer()` attribue toujours un numéro).
5. `resources/views/livewire/remise-bancaire-list.blade.php` : retirer le bloc badge « Auto » (`@if ($remise->auto_generee) ... @endif`). `app/Livewire/RemiseBancaireList.php` : ajuster le commentaire L74 (les auto-remises n'existent plus).
6. `tests/Feature/Journal/DepotRapprochementChequeLooseTest.php` : **supprimer** (teste le mécanisme reverté).
7. `tests/Feature/Journal/RapprochementTotauxListeTest.php` `[BugC]` : **réécrire** sur une **remise manuelle** (au lieu du dépôt auto). Garder le garde-fou : `RapprochementList::render` doit afficher le total crédit exact (non doublé par la T4 banque) grâce à `->operationnel()`. Scénario : créer une remise manuelle (1 chèque source), `comptabiliser()`, pointer la remise via `toggleTransaction(..., 'remise', ...)` → T1(vente) + T4(banque) pointées → vérifier que `rapprochementTotals[$rappro->id]['credit']` = montant source (pas ×2).

**Critère de fin Task 1** : suite complète verte (0 failed).

---

## Task 2 — Critère 512X strict (rapprochement)

Le rapprochement ne liste/pointe que les écritures portant une ligne sur le **512X strictement du compte** du rapprochement (pas un 512X générique — on ne rapproche pas un mouvement de livret sur le compte courant). Aligne la liste sur `calculerSoldePointage` (qui résout déjà `resoudreCompte512X($rapprochement->compte)`).

1. `app/Services/RapprochementBancaireService.php` : exposer la résolution du 512X (rendre `resoudreCompte512X(CompteBancaire): ?Compte` **public**, ou ajouter un wrapper public mince).
2. `app/Livewire/RapprochementDetail.php` `render()` : **en mode PD uniquement** et si le 512X du compte est résolu, filtrer `$txRows` à `remise_id IS NOT NULL` **OU** `whereHas('lignes', compte_id = CE 512X)`. Mode legacy ou 512X introuvable → inchangé (dégradation gracieuse, comme `calculerSoldePointage`).
   - Effet : remise (T4) ✓, virement/CB recette (512X) ✓, dépense chèque émis (512X) ✓, chèque/espèces recette non remis (5112/530) **exclu**, mouvement d'un AUTRE compte 512X (livret) **exclu**, virements internes inchangés.
3. **Nouveau test** `tests/Feature/Livewire/RapprochementDetail512XTest.php` (composant réel, PD) :
   - recette virement comptant (512X sur T1) → **listée**
   - chèque recette loose (5112, pas de 512X) → **NON listée**
   - remise comptabilisée → **listée** (1 ligne)
   - dépense chèque émis (512X) → **listée**
   - écriture portant le 512X d'un AUTRE compte → **NON listée** dans ce rappro (version « liste » de [PD-B]/[R4])
4. `tests/Feature/Rappro/PartieDoubleEquivalenceTest.php` : mettre le helper `chargerListeRapproTx()` à parité prod (filtre 512X spécifique, gated PD). Aligner `[I1]` : tx sans 512X = visible en legacy, **exclue** en PD. `R2/R3/R4` doivent rester verts (fixtures 512X/remise).

**Critère de fin Task 2** : suite complète verte (0 failed) ; viser ~12 508 assertions.

---

## Clôture
- Pint avant chaque commit. Commits atomiques (1 par task minimum).
- **NE PAS pusher, NE PAS merger.** Recette manuelle localhost par l'utilisateur ensuite (re-clone prod + rejeu backfill).
