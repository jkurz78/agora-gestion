# Audit signe négatif — Slice 0

**Date** : 2026-04-30
**Branche** : claude/funny-shamir-8661f9
**Spec** : docs/specs/2026-04-30-audit-signe-negatif-s0.md
**Plan** : plans/audit-signe-negatif-s0.md
**Statut** : en cours (sera "Slice 0 prêt" en step 10)

## 1. Contexte

(2-3 phrases : pourquoi cet audit, ce qu'introduit S1, sources signées préexistantes via ProvisionService)

## 2. Cibles d'audit

### 2.1 Builders de rapports (Step 2)

- [ ] CompteResultatBuilder (`app/Services/Rapports/CompteResultatBuilder.php`) — verdict :
- [ ] FluxTresorerieBuilder (`app/Services/Rapports/FluxTresorerieBuilder.php`) — verdict :
- [ ] Dashboard KPIs (`app/Livewire/Dashboard.php`) — verdict :
- [ ] Super-admin KPIs (`app/Livewire/SuperAdmin/Dashboard/*` — à identifier) — verdict :
- [ ] ClotureWizard (`app/Livewire/Exercices/ClotureWizard.php`) — verdict :
- [ ] RapprochementBancaireService (`app/Services/RapprochementBancaireService.php`) — verdict :
- [ ] RapportCompteResultat + RapportCompteResultatOperations (`app/Livewire/`) — verdict :
- [ ] RapportExportController (`app/Http/Controllers/RapportExportController.php`) — verdict :
- [ ] **Cas croisé transactions négatives + provisions PCA** — verdict :

### 2.2 Exports (Step 3)

- [ ] Exports Excel `app/Exports/*` (à identifier) — verdict :
- [ ] PDF compte de résultat — verdict :
- [ ] PDF flux trésorerie — verdict :

### 2.3 Robustesse écrans (Step 4)

- [ ] TransactionUniverselle — verdict :
- [ ] TransactionCompteList — verdict :
- [ ] TiersTransactions — verdict :
- [ ] Vue Créances à recevoir + filtre `montant > 0` — verdict :
- [ ] Dashboard rendering — verdict :
- [ ] Liste Rapprochements bancaires — verdict :

### 2.4 Validations de saisie (Steps 5, 6, 7, 8)

Trait commun : `app/Livewire/Concerns/RefusesMontantNegatif.php` (créé Step 5)

- [ ] TransactionForm (Step 5)
- [ ] TransactionUniverselle (Step 5)
- [ ] FactureEdit (Step 5)
- [ ] ReglementTable (Step 6)
- [ ] BackOffice/NoteDeFrais (Step 6)
- [ ] VirementInterneForm (Step 6)
- [ ] RemiseBancaireList (Step 7)
- [ ] Portail/NoteDeFrais (Step 7)
- [ ] CsvImportService (Step 8) — refus avec log

### 2.5 Affichage (Step 9)

- [ ] Helper formatMontant (à identifier ou créer) — gestion signe
- [ ] Tri data-sort sur colonnes montants — vérification numérique

## 3. Patches apportés

(Liste à remplir au fil des steps : pour chaque site qui a nécessité un patch, lien fichier:ligne, nature du patch, pourquoi.)

## 4. Précédent dans le code : extournes de provisions

`ProvisionService::extournesExercice()` (livré v2.10.0) gère déjà des montants signés et des extournes virtuelles N→N+1 des PCA/FNP. Voir `Provision::montantSigne()` (peut retourner négatif pour PCA). Notre Slice 1 introduit un mécanisme distinct (extourne **matérielle** de Transaction réelle) avec service nommé `TransactionExtourneService` pour désambiguïser.

## 5. Conclusion (Step 10)

(À remplir au step 10 : récap patches, sites OK sans patch, recommandation explicite "Slice 1 peut démarrer".)
