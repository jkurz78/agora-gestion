# Stabilisation de la suite complète des règlements tiers

## Causes racines

1. Les tests de PJ/OCR créaient une écriture via `TransactionForm` sans provisionner les comptes partie double. La création d'une T1/T2 demandait donc des comptes 401/411/5112 et un 512X pour le compte bancaire, absents du contexte de test.
2. Les tests de réversion et de « T2 zombie » reposaient sur l'ancien contrat : modifier directement le montant, le mode, le compte ou la ventilation d'une T1 déjà réglée. Le modèle actuel protège intentionnellement le grand livre par `TransactionService::assertReglementTiersInvariants()` ; l'annulation doit passer par le flux dédié de règlement T2.
3. `TransactionUniverselle` ne possède plus la modale legacy « marquer reçu/payé » : elle ouvre maintenant `PosteTiersReglementModal`. Le test lisait donc une clé de vue supprimée.
4. Le statut après un encaissement est désormais dérivé du ledger : un chèque non remis est `EnMain`, et non plus la valeur legacy `EnAttente`.
5. Le test de déplacement de facture partait encore du nombre d'écritures legacy. Le workflow actuel crée une T1 et une T2 avant l'erreur de déplacement.

## Corrections

- Provisionnement explicite des comptes système et bancaires dans les contextes Livewire concernés.
- Adaptation des assertions legacy au contrat T1 ouverte / T2 datée et aux statuts dérivés.
- Conservation et couverture explicite des garde-fous : seuls les champs descriptifs d'une T1 réglée restent modifiables.
- Mise à jour du test de liste pour vérifier le retrait de la modale legacy et l'événement de règlement daté.

## Fichiers touchés

- `tests/Feature/CreanceSaisieTest.php`
- `tests/Feature/Journal/MarquerRecuCompteHelloAssoTest.php`
- `tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php`
- `tests/Feature/Transactions/LignePieceJointeTest.php`
- `tests/Livewire/TransactionFormIncomingDocumentTest.php`
- `tests/Livewire/TransactionFormSaveFromDepotFactureTest.php`
- `tests/Feature/Services/DepenseACreditMarquerPayeTest.php`
- `tests/Feature/Services/RecetteComptantT2SepareeTest.php`
- `tests/Feature/Services/ReglementOperationServiceEncaissementIdempotentTest.php`
- `tests/Feature/Services/TransactionServiceStatutDeriveTest.php`
- `tests/Feature/Services/TransactionServiceT2ZombieTest.php`
- `tests/Feature/Services/TransactionServiceUpdatePartieDoubleTest.php`

## Vérifications

- Groupe de régression demandé : 49 tests, 234 assertions, succès.
- Suite complète lancée avec `php -d memory_limit=1G ./vendor/bin/pest --compact` après correction.
- Pint et `git diff --check` exécutés avant commit.

## Commit

`fix(compta): stabiliser la suite complète des règlements tiers`.

## Retouche post-vérification

- `tests/Feature/Transactions/LignePieceJointeTest.php` dépendait encore de `BancairesSeeder::seed()` pour créer le compte 512 lié au compte bancaire du test. Dans le groupe de régression complet, cela restait fragile.
- Le test crée maintenant explicitement son compte 512 relié par `compte_bancaire_id`.
- Vérification : groupe de régression demandé vert, 49 tests / 234 assertions.
- Commit : `test(compta): stabiliser les PJ de lignes avec compte bancaire`.
