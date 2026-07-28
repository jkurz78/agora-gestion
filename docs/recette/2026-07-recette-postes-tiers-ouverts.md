# Recette — Postes tiers ouverts et règlements datés

> À exécuter sur `http://localhost` avec `admin@monasso.fr` / `password`.
> Créer des données de test identifiables et les supprimer ensuite si nécessaire.

| # | Contrôle | Résultat | Observations |
| --- | --- | --- | --- |
| 1 | Vérifier l’entrée `Comptabilité → Postes tiers ouverts`. | À exécuter | |
| 2 | Créer une recette à crédit sur le compte 411, non reçue, avec un numéro de pièce et une référence. | À exécuter | |
| 3 | Depuis l’écran des postes tiers ouverts, encaisser partiellement la créance à une date choisie. Vérifier le montant et le mode de paiement. | À exécuter | |
| 4 | Vérifier dans Transactions que le reliquat de la créance reste visible et correspond au montant non encaissé. | À exécuter | |
| 5 | Depuis Transactions, solder ce reliquat à une autre date choisie. | À exécuter | |
| 6 | Vérifier les deux T2 : montants, comptes de trésorerie, lignes 401/411 et dates réelles de règlement. | À exécuter | |
| 7 | Créer une dépense 401 payée dès la saisie, avec une date de transaction différente de la date de paiement. | À exécuter | |
| 8 | Annuler une T2 non rapprochée et vérifier la réouverture du poste tiers correspondant. | À exécuter | |
| 9 | Rapprocher une T2 puis vérifier que son annulation est refusée sans modifier les écritures. | À exécuter | |
| 10 | Vérifier le rendu desktop des postes tiers, des modales de règlement/annulation et l’absence d’erreur console. | À exécuter | |
| 11 | Vérifier le rendu mobile des mêmes écrans, actions et modales, sans erreur console. | À exécuter | |

## Journal des constats

| Date | Contrôle | Constat | Action / ticket |
| --- | --- | --- | --- |
| | | | |
