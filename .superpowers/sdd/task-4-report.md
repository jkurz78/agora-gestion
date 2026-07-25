# Task 4 — Règlement total et partiel atomique

## Livraison

- Branche : `feat/compta-v5`
- Base : `21fa0e265eb6e1b62590a9ff1ffef4410344cf22`
- Commit : `002d34b2` — `feat(compta): gérer les règlements tiers partiels`
- Fichiers du commit : les sept fichiers prévus par le brief, sans les modifications préexistantes du workspace.

## Implémentation

- Ajout du DTO immuable `PosteTiersReglementData`.
- Ajout de `PosteTiersReglementService::regler()` :
  - transaction atomique avec trois tentatives en cas de deadlock ;
  - verrouillage pessimiste de la ligne demandée puis de toutes les fractions ouvertes ;
  - validations exercice, date, montant, tenant et cohérence du poste ;
  - consolidation des fractions ouvertes ;
  - découpage en reliquat canonique et fraction payée, sans toucher aux lignes 6/7 ni aux affectations ;
  - résolution de trésorerie selon le côté débit/crédit réel ;
  - génération et lettrage de T2, avec rollback complet en cas d’échec.
- Extension de `EtatReglementResolver` :
  - priorité au solde métier restant ;
  - agrégation multi-T2 : `EnMain` si une branche est en main, `Pointe` si toutes sont rapprochées, `Recu` sinon ;
  - conservation des fallbacks historiques, extournes, règlements lumpés et abandons.
- Délégation des wrappers historiques `marquerRecu()`, `marquerPaye()` et `marquerRegle()` au nouveau service daté.
- Ordre de verrouillage harmonisé : lignes du poste avant mutation de T1.
- Mises à jour du wrapper rendues retry-safe par des données immuables et des updates query-builder.

## TDD

### RED

Commande :

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/PosteTiersReglementServiceTest.php
```

Résultat attendu obtenu : exit 2, 13 échecs, service inexistant (`Target class [App\Services\Compta\PosteTiersReglementService] does not exist.`).

### GREEN final

Commande obligatoire du brief :

```bash
php -d memory_limit=1G ./vendor/bin/pest --compact \
  tests/Feature/Services/Compta/PosteTiersReglementServiceTest.php \
  tests/Feature/Services/ReglementOperationServiceUnifieTest.php \
  tests/Feature/Services/ReglementOperationStatutDeriveTest.php
```

Résultat : exit 0, 26 tests, 110 assertions.

Régressions :

- Tous les tests `Reglement*Test.php` : exit 0, 104 tests, 353 assertions.
- Parcours AN, poste reporté, créance et dépense à crédit : exit 0, 31 tests, 189 assertions.
- Suite complète : exit 0, 14 976 assertions, 210,94 s.
- Les libellés `deprecated` du résumé Pest proviennent des dépréciations de l’environnement PHP 8.5 ; aucun échec de test.

Qualité :

- `php -l` : OK.
- Pint sur les sept fichiers : OK.
- `git diff --check` : OK.

## Revue

La revue automatisée a initialement relevé trois points importants :

1. casts explicites des racines PK/FK pour MySQL ;
2. risque d’interblocage entre fractions sœurs ;
3. ordre de verrouillage inverse entre wrappers et service direct.

Correctifs appliqués : casts `(int)`, transactions avec trois tentatives, et appel du service lignes avant toute mutation de T1. La seconde revue conclut à zéro finding critique et zéro finding important. Le finding mineur sur le modèle Eloquent mutable entre deux tentatives a également été corrigé et re-testé.

## Risques résiduels connus

- SQLite en mémoire n’exerce pas réellement `lockForUpdate()` ; la concurrence MySQL est protégée par les verrous et retries mais n’est pas couverte par un test à deux connexions.

## Correctifs de revue contrôleur

Les sept constats de la revue contrôleur ont été traités :

- validation tenant du compte bancaire avant toute mutation, y compris pour les chèques, espèces, fallbacks et wrappers historiques ;
- verrouillage canonique de la racine puis des fractions sœurs par identifiant, avec résolution retry-safe si une sœur disparaît ;
- recalcul du reliquat sous verrou à chaque tentative, sans réutiliser un `PosteTiersOuvert` périmé ;
- remplacement du `MAX + 1` du lettrage par une séquence atomique tenant/compte verrouillée en base ;
- restitution des branches lumpées et historiques avec leurs T2, priorité à `EnMain` et conservation du comportement des extournes ;
- verrouillage de l’exercice ouvert selon un ordre compatible avec la clôture et la génération des AN ;
- calculs monétaires exacts en centimes, sans nouveau passage par `float`.

Vérifications complémentaires :

- suite ciblée élargie : exit 0, 100 tests, 353 assertions ;
- test Livewire ayant provoqué l’arrêt mémoire de la suite complète : exit 0 isolément, 8 tests, 17 assertions ;
- revue indépendante finale : zéro finding critique ou important ;
- `git diff --check` et Pint : OK.

Une première exécution de la suite complète s'est arrêtée dans
`AnimateurManagerPrevisionnelTest` sur le plafond mémoire PHP de 512 Mo.
La relance contrôleur avec `php -d memory_limit=1G` est verte : exit 0,
15 009 assertions en 212,77 s.

## Correctifs de re-revue des wrappers

La re-revue indépendante a encore identifié deux chemins historiques :

- `reglerOuEncaisser()` réservait les séquences avant de verrouiller le poste
  tiers et ne verrouillait pas l'exercice ;
- `marquerRegle()` pouvait committer T2 et le lettrage avant la mise à jour du
  mode et du compte bancaire de T1.

Les deux wrappers délèguent désormais au service commun. Celui-ci verrouille
l'exercice, le lot canonique puis T1, relit le mode et le compte sous verrou,
et exécute T2, lettrage, statut et mise à jour de T1 dans une seule transaction
retryable. Un trigger sentinelle vérifie que l'échec de mise à jour de T1
annule aussi T2 et le lettrage.

Vérification locale après reprise de la session interrompue :

- 55 tests ciblés, 191 assertions, exit 0 ;
- Pint sur les cinq fichiers concernés : OK ;
- documentation du code de lettrage mise à jour pour la séquence
  alphabétique `AAAA` à `ZZZZ`.

## Correctif de filiation de la transaction source

- RED : le test mélangeant la ligne du poste A et la transaction source B
  échouait comme attendu (`DomainException not thrown`).
- GREEN : après validation sous verrou de l'ID de T1 avec
  `transactionOrigineId`, les deux suites ciblées passent : 30 tests,
  146 assertions, exit 0 ; Pint sur les deux fichiers PHP : OK.
- Commit du correctif : `d764aebc`.
