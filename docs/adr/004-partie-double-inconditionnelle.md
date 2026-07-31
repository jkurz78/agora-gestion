# ADR-004 : La partie double devient inconditionnelle — suppression du flag `use_partie_double`

**Statut :** Accepté, 2026-07-31. Supersede le volet *feature flag* d'ADR-003.
**Date :** 2026-07-31
**Auteurs :** Jurgen Kurz, Claude
**Dernière revue :** 2026-07-31

---

## Contexte

ADR-003 (2026-05-27) actait le passage à un modèle de partie double uniforme pour toutes les associations, mais conservait `config('compta.use_partie_double')` (`COMPTA_USE_PARTIE_DOUBLE=false` par défaut) comme interrupteur de bascule : l'idée était d'activer le flag association par association, une fois le backfill d'un exercice terminé, sans faire dépendre le cutover prod de la stabilisation complète du chantier.

La génération des écritures est devenue inconditionnelle dès le 2026-06-18 : toute transaction produit ses lignes partie double, flag ou pas. Depuis cette date, le flag ne gouvernait plus que des bifurcations résiduelles de lecture et de clôture :

- **Clôture** (`ExerciceService`, `ClotureCheckService`, `ClotureWizard`) : à `false`, la clôture ne génère aucun à-nouveau et quatre contrôles pré-clôture renvoient un `CheckItem` vert de complaisance au lieu de vérifier quoi que ce soit.
- **Compte de résultat et flux de trésorerie** (`RapportService`, `FluxTresorerieBuilder`) : à `false`, les provisions sont ajoutées en surcouche au résultat — un double comptage, puisqu'elles sont déjà portées par les écritures 68/78 des classes 6 et 7 en partie double.
- **Rapprochement bancaire** (`RapprochementBancaireService`, `RapprochementDetail`) : à `false`, le solde de pointage et le filtre de la liste pointable retombent sur l'entête (`montant_total`) au lieu des lignes du compte 512X.

Un flag qui ne pilote plus la génération des écritures depuis six semaines, et qui ne gouverne plus que ces trois bifurcations résiduelles, ne correspond plus à ce que son nom promet. Le laisser en l'état produit des incohérences plutôt que de la sécurité : une clôture peut passer au vert sans que les préalables réels (soldes d'ouverture, aperçu des à-nouveaux) soient satisfaits.

## Décision

La compta V5 devient le comportement unique du produit, quelle que soit l'association. `config/compta.php` est supprimé, avec la variable d'environnement `COMPTA_USE_PARTIE_DOUBLE` :

- Comptes et familles de comptes, écritures sur les journaux T1/T2/T4, lettrage, balance, grand livre et état des journaux sont actifs sans condition — c'était déjà le cas pour la génération des écritures depuis le 2026-06-18, ça l'est maintenant aussi pour la lecture.
- La clôture génère systématiquement les à-nouveaux, et les quatre contrôles pré-clôture (soldes d'ouverture, exercice cible, aperçu AN, mouvements sur l'exercice suivant) s'exécutent réellement. Une clôture dont les préalables ne sont pas remplis est refusée, pas approuvée par défaut.
- Le compte de résultat n'ajoute plus les provisions en surcouche : dotations et reprises restent visibles, à leur place naturelle dans la ventilation des classes 6 et 7.
- Le rapprochement bancaire calcule toujours son solde de pointage et filtre toujours sa liste pointable depuis les lignes du compte 512X.

## Périmètre différé

La saisie manuelle experte — saisie d'OD, retouche de la pièce d'à-nouveaux, écritures libres dans les journaux — reste à construire. Elle introduira, le moment venu, son propre flag, nommé pour ce qu'il fait plutôt que pour l'infrastructure comptable sous-jacente.

## Conséquences

**La notion de cutover disparaît.** Il n'y a plus de bascule de configuration entre un mode legacy et un mode partie double : la partie double est le seul mode. `compta:smoke-test-v5` perd ses deux volets de comparaison (compte de résultat, solde de pointage legacy vs PD) et conserve l'invariant d'équilibre `∑débit = ∑crédit` par transaction ainsi que le diagnostic des transactions ventilées en classes 6/7 dépourvues d'écriture partie double.

**Le seul retour arrière possible est la restauration d'une sauvegarde de base.** Il n'existe plus de chemin de code legacy à réactiver en repassant une variable d'environnement.

**Changement visible par les utilisateurs.** Le compte de résultat, à l'écran et en PDF, perd sa section « Provisions / Extournes / Résultat brut → Résultat net » et n'affiche plus qu'un résultat unique. À valider en recette locale avant toute diffusion.

**Tests.** `tests/Feature/Rappro/PartieDoubleEquivalenceTest.php` est supprimé : il prouvait une équivalence entre deux chemins dont il ne reste qu'un. Une clôture dont les préalables ne sont pas remplis, jusqu'ici contournable en mode historique, devient un cas d'échec attendu et testé comme tel.

## Liens

- **Spec** : `docs/superpowers/specs/2026-07-31-suppression-flag-partie-double-design.md`
- **Plan** : `docs/superpowers/plans/2026-07-31-suppression-flag-partie-double.md`
- **Documentation moteur** : `docs/compta-partie-double.md`
- **ADR connexe** : `003-passage-partie-double.md` — le volet feature flag de cet ADR est supersédé par le présent document ; le reste conserve sa valeur d'enregistrement historique.
