# Gardes du parcours comptable — tranche 1

| Champ | Valeur |
|---|---|
| Date | 2026-07-30 |
| Branche | `feat/compta-v5` |
| Origine | Recette préprod du 2026-07-29 : clôture acceptée sans reprise initiale, à-nouveaux faux et silencieux |
| Portée | Permanente et par association — pas un outillage de migration jetable |
| Tranche 2 (hors périmètre) | Écran IHM de reprise initiale, façade sur `BootstrapANouveauService` |

## 1. Le problème

Faire passer une association en comptabilité v5 exige quatre gestes dans un ordre précis : convertir les écritures legacy, reprendre les soldes historiques, réconcilier les statuts de règlement, puis clôturer. Cet ordre n'est écrit nulle part dans l'application. Il vit dans des scripts shell de déploiement, et rien n'empêche de l'enfreindre.

Constaté en recette le 2026-07-29 sur un clone prod frais, après une bascule pourtant réussie techniquement — 28 migrations, backfill de 186 transactions sans erreur, smoke-test à 0,00 € :

- La clôture de l'exercice 2024 a été acceptée **sans reprise initiale**.
- L'à-nouveau produit contenait deux lignes — 5121 pour 130 €, résultat 130 € — au lieu des soldes réels.
- Le Livret Épargne, 24 010 €, était purement absent du bilan d'ouverture.
- **Aucune garde, aucun invariant, aucun test ne l'a signalé.**

Deux raisons à ce silence. La garde « Soldes d'ouverture » de l'assistant sort au vert dès que l'exercice précédent n'existe pas — cas de toute première clôture. Et la garde « Aperçu des à-nouveaux » ne teste que l'équilibre débit/crédit : un à-nouveau amputé de 26 k€ reste parfaitement équilibré.

Ce n'est pas un cas limite. Toute association qui arrive sur AgoraGestion avec des soldes bancaires à reprendre emprunte exactement ce chemin : premier exercice, aucun précédent, garde au vert.

## 2. Décisions de cadrage

**Aucune orchestration.** Pas de commande qui enchaîne la bascule. L'opérateur garde la main sur chaque geste ; l'application refuse les enchaînements incorrects et nomme l'étape légitime suivante. Une bascule comptable qui se déroule seule est précisément ce qui a fabriqué des à-nouveaux faux en silence.

**État dérivé, jamais stocké.** Les quatre défauts trouvés le 2026-07-29 ont tous la même forme : une seconde source de vérité qui dérive de la première — la copie figée de `solde_initial` dans le plan comptable, le miroir `statut_reglement` face au grand livre, le drapeau dans `.env` face à l'environnement du conteneur. Une table d'étapes de bascule en fabriquerait une quatrième. La donnée dit déjà la vérité.

Corollaire opérationnel : l'état est diagnosticable sur n'importe quelle association, sans rien avoir enregistré au préalable — prod actuelle, préprod, tenant créé dans deux ans.

**Réutilisation stricte des critères existants.** Chaque règle de détection reprend le critère déjà en place plutôt que d'en écrire un second. Deux logiques qui prétendent mesurer la même chose finissent par diverger.

## 3. Composants

Trois objets, chacun testable isolément.

### `App\Enums\EtapeCompta`

Énumération ordonnée des étapes du parcours comptable d'une association :

| Cas | Valeur | Libellé |
|---|---|---|
| `BackfillRequis` | `backfill_requis` | Écritures comptables incomplètes |
| `RepriseInitialeRequise` | `reprise_initiale_requise` | Soldes d'ouverture non repris |
| `ReconciliationRequise` | `reconciliation_requise` | Statuts de règlement à mettre à jour |
| `Operationnel` | `operationnel` | Opérationnel |

Chaque cas porte **son libellé français, et rien d'autre**. Pas de commande : le remède appartient à la couche qui connaît le support et le tenant (voir § 6).

Les libellés évitent deux pièges. « Conversion » et « backfill » décrivent une opération de migration que le trésorier n'a pas déclenchée et ne peut pas se représenter. Et « réconciliation » désigne en français comptable la même chose que « rapprochement » — or la checklist de clôture contient déjà « Rapprochements en cours », qui parle de banque et non de statuts.

### `App\Services\Compta\EtatCompta`

Objet-valeur immuable, `final readonly`, **avec un seul champ** :

- `blocages` — les conditions bloquantes détectées, **indexées par la valeur de l'étape** qu'elles concernent, chacune décrite en français sans commande. Le constructeur rejette toute clé qui n'est pas une valeur d'`EtapeCompta`, ainsi que `Operationnel` ;
- `etape(): EtapeCompta` — **déduite** : le premier blocage dans l'ordre de déclaration de l'énumération, ou `Operationnel` ;
- `estOperationnel(): bool` — vrai exactement quand il n'y a aucun blocage ;
- `exige(EtapeCompta $condition): bool` — cette condition précise fait-elle partie des blocages ? Permet à une garde d'exprimer son intention (« le backfill est-il requis ? ») sans dépendre de l'étape courante, qui ne révèle que le premier blocage ;
- `causes(): string` — les causes concaténées, prêtes à afficher sur n'importe quel support.

**Révisé le 2026-07-30 après la revue de la task 2.** La première version stockait `etape` à côté de `blocages`. Or l'étape s'en déduit entièrement : la stocker rendait représentable un état qui se contredit — `estOperationnel()` vrai et `exige()` vrai simultanément — soit précisément le défaut de seconde source de vérité que ce chantier corrige. Un objet dont la première ligne annonce « dérivé, jamais stocké » ne peut pas stocker un dérivé.

Deux bénéfices en découlent. La promesse d'ordre de l'énumération devient vraie : elle repose désormais sur l'ordre de déclaration, et non sur l'ordre d'évaluation des règles du résolveur, que rien n'imposait. Et la validation des clés ferme un contournement muet : sans elle, une clé mal orthographiée était affichée par le diagnostic mais invisible d'`exige()`, si bien qu'une garde laissait passer l'opération sans rien signaler.

### `App\Services\Compta\EtatComptaResolver`

La déduction. Une méthode publique unique : `pourTenantCourant(): EtatCompta`.

**Le résolveur ne prend pas d'association en paramètre.** Tout modèle tenant-scopé étant protégé par un scope global fail-closed sur `association_id`, une méthode `pour(Association $x)` inviterait à des requêtes qui traversent la frontière ou qui retournent `WHERE 1 = 0` selon le contexte booté. Le résolveur lit le tenant courant, un point ; c'est à l'appelant de booter `TenantContext` — exactement ce que font déjà `compta:check-integrity` et `compta:reconcilier-statuts` en itérant sur les associations.

Le résolveur est en lecture seule. Il n'écrit rien, ne corrige rien, ne journalise rien d'autre que du debug.

## 4. Règles de détection

Les quatre règles sont évaluées dans l'ordre ci-dessous ; la première condition remplie détermine l'étape.

### Backfill requis

Il reste des transactions non converties en partie double.

**Critère** : celui de `compta:assert-pd-complete`, réutilisé tel quel — y compris son exclusion des transactions HelloAsso restées legacy par construction. Sur la préprod, ce sont le « ℹ 1 transaction HelloAsso non enrichie » que la commande sort en information et non en erreur : le résolveur hérite de ce jugement, il n'en formule pas un second.

**Geste prescrit** : `compta:backfill-partie-double --all`.

### Reprise initiale requise

Des soldes historiques existent et ne sont pas entrés dans le grand livre.

**Critère** : au moins un `CompteBancaire` du tenant porte un `solde_initial` non nul, **et** aucune `ANouveauGeneration` d'origine `reprise_initiale` au statut `active` n'existe.

Lecture sur `comptes_bancaires`, la source de vérité — comme `BootstrapANouveauService`, jamais sur la copie de `comptes`, que rien ne relit et qui peut être périmée.

Cas nominal à respecter : une association qui démarre à zéro ne porte aucun solde non nul et **traverse cette étape sans rien faire**. C'est correct, pas une exception à contourner.

**Geste prescrit** : `compta:bootstrap-an` (tranche 2 : l'écran de reprise).

### Réconciliation requise

Le miroir `statut_reglement` diverge du grand livre.

**Critère** : au moins une divergence entre `transactions.statut_reglement` et le statut dérivé par `EtatReglementResolver`, **sur le périmètre métier** — journaux `vente` et `achat`, via `Transaction::scopeOperationnel()`. Même périmètre que `compta:reconcilier-statuts`, pour que les deux ne puissent pas se contredire.

Cette étape existe parce que le backfill laisse systématiquement le miroir périmé sur toute la population legacy, et que la réconciliation était une ligne de script qu'on oublie. En faire une étape franchissable la rend visible.

**Geste prescrit** : `compta:reconcilier-statuts`.

### Opérationnel

Aucune des trois conditions ci-dessus. Clôtures et saisie normales.

## 5. Points d'accroche

### Garde de clôture — le défaut du 2026-07-29

`ClotureCheckService` reçoit une garde nouvelle, **« Soldes historiques repris »**, alimentée par le résolveur : elle est rouge tant que l'association n'est pas opérationnelle, et son message nomme le geste manquant.

`checkOuverturePrecedente` n'est **pas** modifiée. Son court-circuit sur l'absence d'exercice précédent n'était nocif que parce que rien ne couvrait les soldes historiques ; une fois la garde nouvelle en place, il redevient inoffensif. Le laisser tranquille évite de perturber son comportement et ses tests existants.

### `ExerciceService::cloturer` — défense en profondeur

`ExerciceService::cloturer()` ne consulte pas `ClotureCheckService` : les gardes de l'assistant sont **consultatives**. Un appel direct au service — un test, un futur bouton, une requête forgée — clôture sans elles.

Le service refuse donc lui-même, en levant `EtapeComptaRequiseException` quand l'association n'est pas opérationnelle. Le contrôle est posé après le verrou sur l'exercice, au même endroit que le refus existant sur l'exercice cible déjà clôturé. Un utilisateur normal ne verra jamais cette exception : l'assistant l'aura arrêté avant.

### `compta:bootstrap-an`

Refuse tant que le backfill n'est pas terminé. Aujourd'hui la commande ne refuse qu'une génération en doublon.

### `compta:etat` — commande nouvelle

Lecture seule. Par défaut, itère sur **toutes** les associations en bootant `TenantContext` pour chacune — même mécanique que `compta:check-integrity`. Affiche pour chacune : l'étape courante, les blocages avec leur geste prescrit, la prochaine étape.

Affiche également la **valeur effective de `compta.use_partie_double` telle que l'application la lit** — pas le contenu du `.env`. C'est l'écart passé inaperçu le 2026-07-29, avec `true` dans le fichier et `false` dans le conteneur, faute de recréation de celui-ci.

Option `--check` : code de sortie non nul si l'association n'est pas opérationnelle. Même convention que `check-integrity`, `assert-pd-complete` et `reconcilier-statuts`, donc utilisable en fin de déploiement.

Option `--association=ID` : restreint à un tenant.

### Le drapeau `COMPTA_USE_PARTIE_DOUBLE`

Le résolveur l'ignore : sur v5 la génération partie double est inconditionnelle, le drapeau ne pilote plus que des chemins de lecture. `compta:etat` le rend seulement **visible**.

Son retrait complet reste un chantier distinct — il gate encore des lectures dans `RapportService`, `RapprochementBancaireService`, `ExerciceService`, `ClotureCheckService`, `RapprochementDetail` et `ClotureWizard`.

## 6. Refus — la cause est partagée, le remède ne l'est pas

**Révisé le 2026-07-30 après la revue de la task 1.** La première version de cette section demandait que la console et l'écran rendent « le même texte, pris à la même source ». Bonne intention, mauvais objet : appliquée au remède, elle faisait remonter `php artisan compta:backfill-partie-double --all` dans l'assistant de clôture — une commande que le trésorier ne peut pas exécuter, qu'il ne *doit* pas exécuter puisqu'elle traiterait tous les tenants, et qui contrevient à la règle « pas de jargon technique dans l'IHM ».

La règle corrigée : **la cause est partagée, le remède est propre à chaque support.**

**La cause** est indépendante du support et du tenant : « 2 compte(s) bancaire(s) portent un solde historique jamais entré dans le grand livre. » Elle vit dans `EtatCompta::causes()` et ne contient jamais de commande.

**Le remède** dépend du support *et* du tenant. Il est composé par la couche qui connaît les deux :

- **CLI** : `compta:etat` et les commandes qui refusent composent la commande complète, avec le bon `--asso=` ou `--association=`. Deux des trois gestes sont en effet scopables par association — `compta:backfill-partie-double` via `--asso`, `compta:bootstrap-an` via `--association` (obligatoire) — tandis que `compta:reconcilier-statuts` est nécessairement global, faute d'option.
- **IHM** : la garde de clôture affiche la cause et le fait que ces préalables doivent être traités avant la clôture. Aucune commande. La tranche 2 y ajoutera le lien vers l'écran de reprise.

`App\Exceptions\Compta\EtapeComptaRequiseException` porte l'`EtatCompta` et un message composé du libellé d'étape et des causes — sans commande. L'appelant CLI y ajoute son remède.

Aucun refus n'est muet : chacun nomme sa cause. Aucun refus ne prescrit un geste que son destinataire ne peut pas accomplir.

## 7. Tests

Reproduire les situations réelles du 2026-07-29, pas des cas d'école.

**Résolveur** — un test par transition :

- transactions legacy présentes → `BackfillRequis` ;
- une transaction HelloAsso legacy seule → **pas** `BackfillRequis` (hérite du jugement d'`assert-pd-complete`) ;
- soldes bancaires non nuls sans génération `reprise_initiale` → `RepriseInitialeRequise` ;
- tous les soldes bancaires à zéro → l'étape se traverse ;
- génération `reprise_initiale` active → étape franchie ;
- divergence miroir sur le journal `vente` → `ReconciliationRequise` ;
- divergence sur une écriture technique du journal `banque` → **pas** `ReconciliationRequise` (périmètre métier) ;
- rien de tout ça → `Operationnel`.

**Régression préprod** — le test qui aurait épargné la journée du 2026-07-29, écrit avec ses chiffres :

> Exercice 2024 sans exercice 2023, comptes 5121 et 5122 portant 2 388,82 € et 24 010,00 € non repris, aucune génération `reprise_initiale` → la clôture est refusée, et le message désigne la reprise. Après création d'une génération `reprise_initiale`, la clôture est autorisée.

**Gardes** :

- `bootstrap-an` refuse avant la fin du backfill ;
- `compta:etat --check` sort 0 quand l'association est opérationnelle, non nul sinon.

## 8. Hors périmètre

Explicitement exclus de cette tranche :

- l'écran IHM de reprise initiale (tranche 2) ;
- le retrait du drapeau `COMPTA_USE_PARTIE_DOUBLE` ;
- toute modification de `checkOuverturePrecedente` ;
- toute table nouvelle ;
- toute correction des données de la préprod, qui relève d'un geste d'exploitation.

## 9. Références

- [Runbook reprise initiale](../runbooks/2026-07-22-reprise-initiale-a-nouveaux.md)
- [Cutover et rollback](../compta-partie-double.md) § 8
- [Journal de recette v5](../recette/2026-07-recette-fonctionnelle-v5.md)
