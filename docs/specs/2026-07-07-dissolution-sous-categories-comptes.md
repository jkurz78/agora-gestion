# Spec — Dissolution `sous_categories` → `comptes` + familles dérivées

Statut : **validée** (design challengé et approuvé en session le 2026-07-07).
Branche : `feat/compta-v5` (chantier fondation de la vision cible réconciliée du 2026-06-22).
Référence : `docs/specs/2026-06-03-roadmap-compta-v5.md` § « Vision cible » — décision 2026-06-22.

## Contexte

La table `comptes` est déjà la source de vérité du ledger PD (toutes classes 1-7) et la
matérialisation `sous_categories` → `comptes` (classes 6/7) est complète et entretenue par
`SousCategorieCompteObserver` — qui se décrit lui-même comme un échafaudage transitoire.
Les données prod sont propres : 100 % des sous-catégories ont un `code_cerfa`, mapping 1:1
vers `numero_pcg` (validé sur clone prod le 2026-07-07, smoke-test vert).

Ce chantier supprime la notion de sous-catégorie au profit du **compte**, et remplace la
notion de catégorie par la **famille** (nom donné aux 2 premiers caractères du numéro).

## Décisions structurantes

### D1 — Vocabulaire : « compte » partout, numéro discret hors compta pure

La frontière d'affichage du numéro est **« qui regarde »**, pas « quel écran » :

- **Tiers / adhérent / public** (PDF facture, devis, reçus, portail) : libellé seul
  (« Formations »).
- **Saisie et analyse** (formulaire transaction, budget, lignes facture back-office,
  écrans Paramètres, rapports financiers, exports comptables) : « 706A — Formations ».
  Un sélecteur de ventilation est un écran comptable, même utilisé par un bénévole.

Le mot « sous-catégorie » disparaît de l'IHM, du code et de la doc. « Catégorie »
(au sens comptable) disparaît au profit de « famille ».

### D2 — Famille = dérivée de `LEFT(numero_pcg, 2)`, table pour les noms seulement

- **Pas de FK** : le regroupement compte → famille est calculé par préfixe (2 premiers
  caractères du numéro). `comptes.categorie_id` est supprimé.
- Nouvelle table **`familles`** : `(association_id, code CHAR(2), nom)`, unique
  `(association_id, code)`. Elle ne porte que le *nom* du regroupement.
- **Typage par construction** : 6x = dépense, 7x = recette. Pas de colonne `type` sur
  `familles`. (Extension future absorbée sans schéma : 86/87 contributions volontaires.)
- **Famille orpheline** : un compte créé avec un préfixe sans famille nommée →
  auto-création de la famille avec `nom = code` (éditable ensuite). Jamais bloquant,
  jamais silencieux.
- **Migration** : les `categories` actuelles s'appellent « 70 - Ventes et prestations »
  → parse `^(\d{2})\s*-\s*(.+)$` → `familles(code, nom)`. Fallback si le nom ne matche
  pas : code = préfixe majoritaire des comptes rattachés, nom = nom actuel tel quel.
- Effet auto-réparant acquis : 681/781 (aujourd'hui `categorie_id = NULL`) tombent
  d'office dans « 68 » / « 78 ».

### D3 — Le numéro est l'identité du compte

- **Requis**, **unique par association** (index `comptes_asso_numero_pcg_unique` déjà
  en place), premier champ du formulaire de création ; le libellé vient en rang 2.
- **Validation en mode simplifié** : commence par `6` ou `7`, alphanumérique majuscule,
  3 à 6 caractères (`^[67][0-9A-Z]{2,5}$`). Les numéros existants (706A, 611B…) passent.
- **Immuable dès la première écriture** : un compte porteur de lignes
  (`transaction_lignes.compte_id`) ne peut plus être renuméroté. Le libellé reste
  librement modifiable. Renuméroter = créer un nouveau compte (+ virement de solde en
  mode OD, plus tard) ; en mode simplifié : interdit.
- Le rapport CERFA lit `numero_pcg` là où il lisait `code_cerfa` — à l'identique.

### D4 — IHM mode simplifié : classes 6 et 7 uniquement

- L'écran « Sous-catégories » devient **« Plan comptable »** et ne liste que les
  classes 6/7 tant que le mode comptabilité complète n'est pas activé.
- Les comptes 6/7 **système** (681, 781 — provisions) apparaissent avec un badge
  « système » : non supprimables, numéro et libellé verrouillés (`est_systeme`).
- Les classes 1-5 (411, 401, 512X, 5112, 530, 467, 486, 487…) restent invisibles en
  mode simplifié. Elles arrivent à l'écran avec le mode complet (hors périmètre ici).
- Nom du mode complet : à trancher plus tard (piste : « Comptabilité simplifiée » /
  « Comptabilité complète »). Ne bloque pas ce chantier.

## Cible modèle

- **`comptes`** : source de vérité unique. Drop `categorie_id`.
- **`familles`** : nouvelle table (noms des regroupements).
- **Suppression** : table `sous_categories`, table `categories`, modèles `SousCategorie`
  et `Categorie`, `SousCategorieCompteObserver`, `CompteVentilationResolver` (devient
  identité puis disparaît), garde `AuditGuard` du backfill (plus de code_cerfa à
  vérifier — le numéro EST le compte), écran `SousCategorieList`,
  `SousCategorieAutocomplete`.

### Les 11 FKs `sous_categorie_id` → `compte_id`

Liste exhaustive vérifiée sur le schéma (information_schema, 2026-07-07) :

| Table | Note |
|---|---|
| `transaction_lignes` | déjà dual (`compte_id` existe, backfillé) — drop `sous_categorie_id` seulement |
| `budget_lines` | + écrans budget |
| `facture_lignes` | + FactureEdit |
| `devis_lignes` | + DevisEdit |
| `formules_adhesion` | formules d'adhésion |
| `type_operations` | défauts de saisie par type |
| `helloasso_form_mappings` | mapping formulaires HelloAsso |
| `provisions` | provisions FNP/PCA |
| `usages_sous_categories` | devient `usages_comptes` (écran Usages comptables) |
| `notes_de_frais_lignes` | NDF portail + back-office |
| `encadrement_previsions` | prévisionnel Encadrants (futures écritures prévisionnelles — vision cible) |

Backfill trivial pour chacune : `compte_id = comptes.id WHERE numero_pcg = code_cerfa
AND association_id`.

## Découpage en slices

1. **Familles** : table + migration de données depuis `categories` + helper de
   dérivation (`Compte::famille()`, `Famille::pourPrefixe()`) + auto-création orpheline.
2. **Schéma + backfill** : `compte_id` sur les 8 tables restantes, backfill, double
   écriture à la création (les écrans écrivent encore `sous_categorie_id`).
3. **Bascule des lecteurs** : services et rapports lisent `compte_id` / `familles`
   (CR, budget, exports, CERFA, HelloAsso, NDF, provisions, usages).
4. **Écrans + vocabulaire** : « Plan comptable » (création numéro-d'abord, validation
   D3, badge système), sélecteurs « 706A — Libellé » groupés par famille, renommage
   des libellés partout (D1), onboarding wizard, seeds, snapshot démo.
5. **Drop** : colonnes `sous_categorie_id`, tables `sous_categories` + `categories`,
   modèles, observer, resolver, AuditGuard, écrans morts. Factories et 266 fichiers de
   tests basculés au fil des slices 2-4, purge finale ici.

## Hors périmètre (YAGNI)

- Mode comptabilité complète (réglage par association, saisie d'OD, classes 1-5 à
  l'écran) — chantier suivant.
- Renumérotation avec virement de solde.
- Comptes 86/87 (contributions volontaires).
- Hiérarchie de comptes (`parent_compte_id` existe mais n'est pas exploité ici).

## Critères d'acceptation

- **AC1** : plus aucune référence à `SousCategorie`/`sous_categorie` dans `app/` ni
  `resources/views/` en fin de chantier.
- **AC2** : l'écran Plan comptable crée un compte par son numéro (validation D3),
  famille auto-créée si préfixe inconnu.
- **AC3** : un compte porteur d'écritures ne peut pas être renuméroté ; son libellé oui.
- **AC4** : les sélecteurs de ventilation affichent « numéro — libellé » groupés par
  famille ; les PDF tiers n'affichent que le libellé.
- **AC5** : compte de résultat, budget, exports, CERFA, HelloAsso, NDF, provisions,
  usages : iso-comportement avant/après (smoke-test vert sur clone prod).
- **AC6** : 681/781 badgés système, non supprimables, non renumérotables.
- **AC7** : onboarding wizard et snapshot démo créent des comptes + familles (plus de
  sous-catégories).
- **AC8** : suite complète verte + pint.
