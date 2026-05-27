# ADR-003 : Révision stratégie comptable — Cash basis enrichie → Partie double uniforme

**Statut :** Adopté 2026-05-27
**Date :** 2026-05-27
**Auteurs :** Jurgen Kurz, Claude
**Dernière revue :** 2026-05-27

---

## Contexte

### Décision initiale (cadrage 2026-05-02)

Le programme `project_compta_partie_double.md` (cadrage 2026-05-02) posait une approche *cash basis enrichie* :

- Les associations **non fiscalisées** (95 % du marché cible) continueraient sur le modèle simplifié existant (`transactions.montant_total`, `transaction_lignes.montant`, `sous_categorie_id`).
- Les associations **fiscalisées** (dépassant 78 596 €/an de recettes commerciales accessoires, seuil d'obligation FEC) basculeraient seules sur un modèle partie double.
- La frontière serait activée par un flag tenant.

Cette approche visait à minimiser l'investissement initial et à ne servir que le sous-marché fiscalisé pour la comptabilité avancée.

### Trou identifié au démarrage du slice 1

En préparant le branchement des services métier (sous-slice 1b, 2026-05-22), deux constats ont invalidé l'hypothèse de départ :

1. **Coût marginal concentré sur le schéma, pas sur les services** : une fois les colonnes `debit`, `credit`, `compte_id`, `tiers_id`, `lettrage_code` posées sur `transaction_lignes`, le coût d'enrichissement des services métier est le même qu'on serve 5 % ou 100 % des associations.

2. **Divergence FEC non réparable sans partie double** : la norme BOI-CF-IOR-60-40-20 réserve le champ `CompAuxNum` (tiers comptable) à la classe 4 (411/401). L'ancien modèle plaçait le tiers sur les lignes de classe 5 (compte bancaire) — non conforme FEC. La correction nécessite l'école 411 systématique, qui implique structurellement la partie double pour toutes les transactions.

### Conclusion

L'approche cash basis enrichie avec un schéma hybride n'est pas viable : le coût de maintenir deux chemins (legacy simplifié + partie double) sur le long terme dépasse le coût de passer directement au modèle complet. La bifurcation par tenant fiscalisé ajoute une complexité opérationnelle (deux états de base de données, deux codes de résolution, deux jeux de tests) sans bénéfice significatif.

---

## Décision

**Partie double uniforme pour toutes les associations, fiscalisées ou non.**

Toutes les transactions nouvelles et backfillées suivent le modèle :

- Table `comptes` (plan comptable PCG, classes 1-7)
- Table `transaction_lignes` enrichie : colonnes `debit`, `credit`, `compte_id`, `tiers_id`, `lettrage_code`
- Table `lettrage_audit` (traçabilité complète)
- Invariant `transactions.equilibree` : `∑ debit = ∑ credit` vérifié par observer XOR
- École 411 systématique : tiers exclusivement sur comptes 411/401

Cette décision est effective depuis le slice 1 (`feat/compta-v5`), qui couvre les Steps 1-39.

---

## Justification

### 1. Coût marginal concentré dans le slice 1

Les Steps 1-11 (sous-slice 1a) posent le schéma cible : tables, colonnes, modèles, seeds. Ce travail est fait une seule fois. Le surcoût de brancher les services sur le moteur partie double pour 100 % des associations (vs 5 %) est négligeable — c'est le même `EcritureGenerator`, les mêmes méthodes `pour*`, les mêmes tests.

À l'inverse, maintenir deux chemins aurait nécessité : deux branches conditionnelles dans chaque service, deux jeux de tests d'équivalence, deux états de migration possibles, une documentation duale.

### 2. Ouvre la voie sans réécriture ultérieure

Le schéma partie double permet d'ajouter les modules suivants sans toucher au modèle de données :

- **TVA** : lignes 44571 (TVA collectée) / 44566 (TVA déductible) — simplement des comptes 4xx supplémentaires
- **Immobilisations** : comptes de classe 2, dotations aux amortissements (681/68)
- **Bilan comptable** : agrégation par classe depuis `transaction_lignes`
- **Production FEC** : export conforme à BOI-CF-IOR-60-40-20 depuis `transaction_lignes` avec `tiers_id` sur classe 4

Ces modules constituent les *slices suivants* : ils ne livrent que des **vues et exports** sur des données déjà en partie double.

### 3. Alignement avec les pratiques professionnelles

Les logiciels comptables professionnels (Sage, Cegid, Pennylane, EBP Pro) utilisent tous la partie double uniforme, quel que soit le régime fiscal de l'association. AgoraGestion s'aligne sur ce standard, ce qui facilite :

- L'import/export avec les experts-comptables
- La production de rapports conformes aux livrables attendus en AG
- La transition vers un logiciel certifié si l'association devient fiscalisée

### 4. Aucun changement fonctionnel utilisateur dans le slice 1

La double écriture est transparente : les formulaires, les listes, les PDF ne changent pas. Le moteur enrichit silencieusement chaque transaction en base. L'utilisateur ne voit la partie double que si les modules avancés (bilan, TVA, FEC) sont activés dans un slice futur.

---

## Conséquences

### Conséquences immédiates (slice 1 livré)

- **Opérationnel** : modèle `App\Models\Compte` fonctionnel, seeder PCG pour chaque tenant, 6 services métier rebranchés sur `EcritureGenerator`.
- **Tests** : suite `feat/compta-v5` : 12 151 assertions / 0 failed (Step 39, 2026-05-27). 7 fichiers de tests dédiés partie double, 2 tests d'équivalence (CR + rappro, tolérance 0€), 1 test backfill end-to-end.
- **Feature flag** : `COMPTA_USE_PARTIE_DOUBLE=false` par défaut. Les rapports (CR, rappro) continuent en mode legacy jusqu'à activation post-backfill.

### Dette acceptée (différée)

| Artéfact | Raison du maintien | Prochaine étape |
|---|---|---|
| Modèle `SousCategorie` | FK `sous_categorie_id` dans 6 tables (budget_lines, formules_adhesion, facture_lignes, note_de_frais_lignes, devis_lignes, usages_sous_categorie) | Programme dédié post-cutover |
| Colonnes legacy sur `transaction_lignes` (`sous_categorie_id`, `montant`) | Lues par les services en mode `use_partie_double=false` | Drop Step 40, PR séparée post-stabilité prod |
| Colonnes legacy sur `transactions` (`type`, `compte_id` entête) | Utilisées par les 47 tests rappro legacy et certains rapports | Drop Step 40, PR séparée |
| Transactions avec `montant < 0` (miroirs d'extourne) | `EcritureGenerator` ne supporte pas les montants négatifs | Branche dédiée dans `TransactionConverter` — post-cutover |

### Ce que le schéma partie double ne résout pas

- **Compatibilité FEC certifiée** : la production FEC est techniquement possible depuis `transaction_lignes`, mais la certification DGFIP (`décret 2014-1044`) requiert des tests complémentaires et potentiellement un audit externe. Hors scope slice 1.
- **Module TVA** : comptes 4457x non seedés ni utilisés. Aucun calcul de TVA dans slice 1.
- **Module immobilisations** : comptes de classe 2 non seedés. Aucun plan d'amortissement dans slice 1.
- **Bilan comptable** : vue agrégée classes 1-5 non implémentée. Slice futur.

---

## Décisions notables liées (slice 1)

Ces décisions découlent directement du choix de la partie double uniforme et sont documentées plus en détail dans `docs/compta-partie-double.md`.

| Décision | Détail |
|---|---|
| **École 411 systématique** | Tiers exclusivement sur 411/401, jamais sur classe 5. Conformité FEC `CompAuxNum`. |
| **Asymétrie chèque dépense** | Dépense chèque → 512X direct. Pas de 5112 miroir. Recette chèque → 5112 (portage). |
| **Feature flag** | `config('compta.use_partie_double')` contrôle la lecture (rapports + rappro). La double écriture est toujours active. |
| **Auto-délettrage avant modification destructive** | `LettrageService::autoDelettrerLignesDe` appelé avant `forceDelete` (update) et avant `creerTransactionMiroir` (extourne). |
| **Backfill idempotent** | `compta:backfill-partie-double --dry-run` safe en prod. `--force` interdit en prod. |

---

## Liens

- **Spec** : `docs/specs/2026-05-19-fondations-partie-double-slice1.md`
- **Plan** : `plans/fondations-partie-double-slice1.md`
- **Documentation moteur** : `docs/compta-partie-double.md`
- **Mémoires** :
  - `project_compta_partie_double.md` (cadrage initial — approche révisée par cet ADR)
  - `project_compta_v5_sous_slice_1a.md` (Data layer)
  - `project_compta_v5_sous_slice_1b.md` (EcritureGenerator + LettrageService)
  - `project_compta_v5_sous_slice_1c.md` (Branchements services)
  - `project_compta_v5_sous_slice_1d.md` (Backfill + cleanup)
- **ADR connexes** : `001-remise-bancaire-statuts-vs-comptes.md`

---

## Note sur la numérotation

L'ADR-002 est occupé par `ADR-002-facture-libre-invoice-first.md` (format de nommage incohérent avec le 001). Cet ADR utilise le numéro 003 (prochain disponible). La numérotation séquentielle continue à partir de 004 pour les futurs ADR.
