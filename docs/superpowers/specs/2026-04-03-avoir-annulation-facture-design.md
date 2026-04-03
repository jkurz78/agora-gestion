# Design — Annulation de facture par avoir

**Date** : 2026-04-03
**Contexte** : Permettre l'annulation d'une facture validée via émission d'un avoir (obligation légale, pas de "dévalidation")

## Problème

Une facture validée ne peut pas être modifiée ou supprimée (obligation légale). Si elle est erronée, le seul recours est d'émettre un avoir qui l'annule, puis de créer une nouvelle facture si nécessaire.

## Solution

Ajouter un bouton "Annuler avec avoir" sur `FactureShow` pour les factures validées. L'annulation attribue un numéro d'avoir, gèle la date d'annulation, passe le statut à `annulee`, et libère les transactions associées.

## Prérequis existants

Les champs et structures nécessaires existent déjà :
- `factures.numero_avoir` (string, unique, nullable)
- `factures.date_annulation` (date, nullable)
- `StatutFacture::Annulee` (enum existant)
- `facture_lignes` — copies figées des montants (ne changent pas après annulation)

**Aucune migration nécessaire.**

## Logique métier — `FactureService::annuler()`

### Vérifications préalables

1. `statut === Validee` (sinon erreur)
2. Exercice courant ouvert (l'avoir est daté sur l'exercice courant)
3. Aucune transaction liée n'est verrouillée par un rapprochement bancaire

### Actions

1. Verrouiller les factures annulées du même exercice courant (`lockForUpdate`)
2. Attribuer `numero_avoir` : `AV-{exercice_courant}-{seq}` (séquence indépendante des factures)
3. Attribuer `date_annulation` : date du jour
4. Passer `statut` → `Annulee`
5. Les lignes (`facture_lignes`) et le pivot (`facture_transaction`) restent intacts (traçabilité)
6. Les transactions sont libérées : `isLockedByFacture()` ne les bloque plus car il filtre sur `statut = Validee`

### Numérotation avoir

- Format : `AV-{exercice}-{seq}` avec seq sur 4 chiffres (ex: `AV-2025-0001`)
- Séquence indépendante de celle des factures
- L'exercice est celui de la date d'annulation (exercice courant), pas celui de la facture d'origine

## Vérification rapprochement — `Transaction::isLockedByRapprochement()`

Nouvelle méthode sur le modèle Transaction :
- Retourne `true` si `rapprochement_id` est non null
- Utilisée par `FactureService::annuler()` pour bloquer l'annulation si au moins une transaction liée est rapprochée

## PDF Avoir

Le même template `pdf/facture.blade.php` avec des adaptations conditionnelles quand `statut === Annulee` :
- Titre : "AVOIR" au lieu de "FACTURE"
- Numéro affiché : `numero_avoir` (ex: AV-2025-0001)
- Date affichée : `date_annulation`
- Mention : "Annule et remplace la facture {numero}" sous le titre
- Montants affichés en négatif (préfixe `-`)
- Pas de section "Montant réglé / Reste dû"
- Pas de tampon "Acquittée"
- Factur-X : pas de XML embarqué pour l'avoir (PDF simple)

## UI — FactureShow

### Bouton annuler

Visible uniquement si `statut === Validee` :
```
[Annuler avec avoir]  (btn-outline-danger, avec confirmation modale)
```

Modale de confirmation : "Êtes-vous sûr de vouloir annuler cette facture ? Un avoir AV-xxxx sera émis. Les transactions associées seront libérées."

### Affichage après annulation

- Badge statut : "Annulée" (badge danger)
- Bloc info : numéro d'avoir, date d'annulation
- Bouton "Télécharger l'avoir (PDF)"
- Les transactions sont affichées mais marquées comme libérées

## UI — FactureList

- Le filtre "annulée" existant dans l'enum fonctionne déjà
- L'affichage de la ligne facture annulée montre le numéro d'avoir en complément

## Libération des transactions

Après annulation, les transactions liées :
- Ne sont plus verrouillées par `isLockedByFacture()` (car il filtre `statut = Validee`)
- Redeviennent modifiables (montants, lignes, sous-catégories)
- Peuvent être ajoutées à une nouvelle facture
- Le pivot `facture_transaction` reste en place (traçabilité de l'historique)

## Fichiers impactés

- `app/Services/FactureService.php` — ajouter `annuler()`
- `app/Models/Transaction.php` — ajouter `isLockedByRapprochement()`
- `app/Livewire/FactureShow.php` — bouton annuler + affichage avoir
- `resources/views/livewire/facture-show.blade.php` — UI annulation
- `resources/views/pdf/facture.blade.php` — mode avoir
- `app/Http/Controllers/FacturePdfController.php` — supporter le mode avoir
- Tests Pest pour la logique métier et l'UI
