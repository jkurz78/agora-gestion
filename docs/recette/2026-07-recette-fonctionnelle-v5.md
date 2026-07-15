# Recette fonctionnelle compta-v5 — Phase 2 (localhost)

> Branche `feat/compta-v5` — schéma final (dissolution sous_categories → comptes livrée).
> Stratégie complète : Phase 1 (rejeu cutover à blanc) → **Phase 2 (ce document)** → Phase 3 (préprod NAS) → Phase 4 (GO/NO-GO).

## Mode d'emploi

- Dérouler les blocs dans l'ordre : chaque bloc s'appuie sur les données créées par les précédents.
- **Après chaque bloc**, lancer les invariants (≈ 30 s) :

  ```bash
  ./vendor/bin/sail artisan compta:check-integrity
  ./vendor/bin/sail artisan compta:assert-pd-complete --check
  ```

  Les deux doivent sortir en code 0. Un écart = STOP, consigner le constat (§ Journal des constats) avant de continuer.
- Tout constat (bug, affichage, comportement inattendu) va dans le journal en bas de ce fichier, même mineur.
- Environnement : clone prod frais recommandé (Phase 1 rejouée juste avant), sinon la base locale courante en le notant dans le journal.

---

## Bloc 1 — Cycle vente (devis → facture → encaissement → avoir)

- [ ] Créer un devis manuel (2-3 lignes sur des comptes 7x différents), le transformer en facture
- [x] Créer une facture manuelle directe (invoice-first), la valider — *vérifié in-app 2026-07-15 : facture F-2025-0002 pour Pauline FAURE, ligne 707 « Ventes de produits » 150 € (sélecteur compte-first « 707 — Ventes de produits »), tx 2025-2026:00394 (Claude, navigateur)*
  - [x] La transaction générée existe, journal `vente`, équilibrée, lignes 7x + contrepartie 411 lettrable — *vérifié SQL : 411 Clients D 150 (tiers Pauline) / 707 Ventes C 150, equilibree=1*
  - [x] La transaction est verrouillée (montants/comptes non éditables) et **chaque ligne affiche famille / intitulé du compte** (constat R-2) — *vérifié in-app 2026-07-15 sur tx 2025-2026:00046 (Claude, navigateur)*
  - [x] La facture apparaît en créance à recevoir — *statut_reglement=en_attente, reste dû 150 €*
- [x] Encaisser la facture (virement) depuis la fiche facture — *vérifié in-app*
  - [x] T2 générée (512X débit / 411 crédit), lettrage 411 fermé, `statut_reglement` passe à reçu/pointé — *vérifié SQL : T2 tx 00395 = 5121 Compte Courant D 150 / 411 C 150, lettrage AADT partagé T1↔T2, T1 passe à `recu`*
- [ ] Encaisser une 2ᵉ facture par chèque → vérifier portage 5112 (pas 512X) tant que non remis/pointé
- [x] Annuler une facture par avoir — *vérifié in-app : avoir AV-2025-0001 émis, facture annulée*
  - [x] Écritures miroir générées, lettrage cohérent, la créance disparaît des créances en cours — *vérifié SQL : miroir tx 00396 = 707 D 150 / 411 C 150 (inverse exact), T1 marquée extournée, montant miroir −150 (brèche du signe)*
  - [ ] PDF avoir généré, mentions correctes
- [x] Invariants (check-integrity + assert-pd-complete) ✅ — *grand livre équilibré 137 118,83 = 137 118,83, écart 0,00*

## Bloc 2 — Saisie directe & chaîne bancaire

- [x] Dépense comptant (virement) avec tiers — *vérifié 2026-07-15 (form + reproduction serveur)*
  - [x] Écritures 6x débit / 401 crédit + T2 401/512X lettrées — *vérifié SQL : 606 Fournitures D 80 / 401 Fournisseurs C 80 (tiers, lettrage AADG), T2 = 401 D 80 / 5121 C 80, equilibree=1*
  - [x] N-3 CORRIGÉ : le tiers est désormais **obligatoire** pour toute recette/dépense (le 401/411 porte la contrepartie). Sans lui, l'écriture ne pouvait pas s'équilibrer — le formulaire rejette maintenant la saisie. (voir Journal N-3)
- [ ] Recette comptant (espèces) : vérifier portage caisse (530)
- [ ] Recette en attente (créance) puis « Marquer reçu » → T2 générée à l'encaissement, date correcte
- [ ] Recette chèque → remise en banque **multi-source** (au moins 2 chèques + espèces)
  - [ ] T4 de remise : une seule ligne 512X débit du total, contreparties 5112/530 soldées
  - [ ] Modifier puis supprimer un brouillon de remise : les statuts des transactions sources reviennent à « en main »
- [ ] Rapprochement bancaire du mois courant
  - [ ] Pointer les transactions du bloc (dont la remise) ; solde rapproché = solde relevé
  - [ ] Verrouiller le rapprochement : les transactions pointées ne sont plus modifiables
- [ ] Virement interne 512→512 entre deux comptes bancaires (si saisi via l'app) : pas de double comptage au CR
- [ ] Modifier une transaction non verrouillée (montant + ventilation) → écritures régénérées proprement
- [ ] Supprimer une transaction de test → lignes purgées, lettrage nettoyé
- [ ] Invariants ✅

## Bloc 3 — Tiers : cotisations, dons, HelloAsso

- [ ] Adhésion avec formule (tarif + compte associé) → transaction cotisation générée, compte à usage Cotisation
- [ ] Don manuel → transaction sur compte à usage Don
  - [ ] Émettre le reçu fiscal PDF/A-3, vérifier montant/numérotation/coordonnées
- [ ] HelloAsso : déclencher une sync (webhook ou import)
  - [ ] Transactions HelloAsso créées PD-complètes, verrouillées à la saisie
  - [ ] Chèque/espèces HelloAsso routés vers créances (pattern v2.10)
- [ ] Fiche tiers 360 : onglet Opérations cohérent (les transactions des blocs 1-3 y figurent)
- [ ] Invariants ✅

## Bloc 4 — NDF portail

- [ ] Déposer une NDF depuis le portail (OTP), avec justificatif
- [ ] Ajouter une ligne indemnités kilométriques via le wizard
- [ ] Valider la NDF en back-office → transaction dépense générée (comptes 6x, contrepartie 401)
- [ ] Rembourser (T2) puis, sur une 2ᵉ NDF, faire un **abandon de créance**
  - [ ] Écriture d'abandon sur le compte à usage AbandonCreance, lettrage 401 fermé
  - [ ] Reçu fiscal d'abandon émis si applicable
- [ ] Invariants ✅

## Bloc 5 — Plan comptable & familles

- [ ] Créer un compte 7x avec un préfixe nouveau → famille orpheline auto-créée (nom = code)
- [ ] Renommer la famille depuis l'en-tête de groupe
- [x] **Renuméroter un compte vierge** (clic sur le numéro — constat R-1) — *vérifié in-app 2026-07-15 : 706Z→706Y→706Z, famille inchangée, base contrôlée en SQL (Claude, navigateur)*
  - [x] Refus si numéro en doublon, si changement de classe (7x→6x) — *vérifié in-app : messages « Ce numéro de compte existe déjà. » et « Le numéro doit rester en classe 7. », base intacte. Minuscules non testables via l'UI (uppercase automatique côté client), couvert par test Livewire*
  - [x] Le compte porteur d'écritures et le compte système restent non renumérables (pas de curseur d'édition) — *vérifié in-app : clic sur 706B (66 écritures) inerte, 681 badgé système sans action*
- [ ] Supprimer un compte propre ✅ / tenter la suppression d'un compte utilisé → refus explicite
- [ ] Créer un compte à la volée depuis l'autocomplete d'une saisie (modale) → numéro requis, famille OK
- [ ] Paramètres → Usages comptables : déplacer un usage (ex. FraisKm) vers un autre compte, vérifier l'effet sur le wizard km
- [ ] Invariants ✅

## Bloc 6 — Rapports & exports

À faire après les blocs 1-5 pour avoir de la matière :

- [ ] Compte de résultat : totaux par famille cohérents avec les saisies ; toggles N-1 et budget fonctionnels ; barres budget direction-aware
- [ ] CR par tiers : raisons sociales correctes
- [ ] Analyse financière ventilée (pivot) : la source plate contient les transactions des blocs, signes corrects
- [ ] Dashboard : réalisé/prévu par groupe aligné avec le CR
- [ ] Exports XLSX + PDF du CR : mêmes chiffres que l'écran, colonnes N-1/budget respectées
- [ ] Rapport CERFA : les comptes à usage Don sortent avec le bon numéro (numero_pcg, ex-code_cerfa)
- [ ] Balance / grand livre (écrans P1 si présents) : débits = crédits par compte
- [ ] Comparaison au centime avec la prod v4 sur l'exercice clos le plus récent (CR par famille + soldes bancaires)
- [ ] Invariants ✅

## Bloc 7 — Clôture, extourne, provisions

- [ ] Saisir une provision FNP et une PCA sur l'exercice courant → visibles au CR, écritures 68x/78x équilibrées
- [ ] Extourner une transaction → miroir généré, lignes lettrées auto-délettrées, **affichage famille sur le miroir** (même rendu que R-2)
- [ ] Dérouler le wizard de clôture d'exercice jusqu'aux gates (sans clôturer si données réelles) : toutes les gates s'évaluent sans erreur
- [ ] `./vendor/bin/sail artisan compta:smoke-test-v5 --detail` ✅
- [ ] `./vendor/bin/sail artisan compta:reconcilier-statuts` : zéro divergence
- [ ] Invariants ✅

---

## Fin de recette

- [ ] Suite complète verte : `./vendor/bin/sail exec -T laravel.test php -d memory_limit=1G ./vendor/bin/pest --compact`
- [ ] Journal des constats vidé (tout corrigé ou explicitement accepté)
- [ ] GO Phase 3 (préprod NAS)

## Journal des constats

| # | Date | Bloc | Constat | Statut | Commit |
|---|------|------|---------|--------|--------|
| R-1 | 2026-07-15 | 5 | Numéro de compte non modifiable même sans écriture (écart design D3) | ✅ Corrigé | `792c9fed` |
| R-2 | 2026-07-15 | 1 | Transaction verrouillée par facture : compte affiché sans famille (tx 2025-2026:00046) | ✅ Corrigé | `d0bcd3c3` |
| R-3 | 2026-07-15 | 5 | Après un refus de renumérotation, la cellule affichait la valeur refusée (état Alpine local) — l'utilisateur croyait la modification acceptée. Découvert lors de la vérification in-app | ✅ Corrigé | `ca8639b6` |
| N-1 | 2026-07-15 | 5 | Note (pas un bug) : familles « 68 — 68 » et « 78 — 78 » jamais nommées (orphelines auto-créées) — à renommer via l'édition d'en-tête de groupe | 📝 À faire (choix de libellé utilisateur) | — |
| N-2 | 2026-07-15 | 5 | Note UX mineure : le message de refus s'affiche en haut du composant — hors viewport quand on est scrollé en bas d'un long plan comptable. Piste : toast ou scroll-to-alert | 📋 Parqué | — |
| N-3 | 2026-07-15 | 2 | Une recette/dépense **sans tiers** produisait une transaction PD-incomplète (ventilation 6x/7x seule, pas de contrepartie 401/411, equilibree=false). `TransactionService::enrichirPartieDouble` skippe si `tiers_id` null, et le formulaire autorisait `tiers_id` nullable. **0 cas légitime dans le clone prod** (seule la tx de repro 420 « sans tiers »). Décision : **interdire** — tiers rendu obligatoire à la saisie recette/dépense (comptant et créance). | ✅ Corrigé | (commit ci-dessous) |
