# Annulation de facture par avoir — Slice 2 (extourne automatique des lignes manuelles)

**Date** : 2026-05-01
**Statut** : spec PASS (consistency gate ✅), prête pour `/plan`
**Programme** : Annulation de facture & extourne — refonte de la dette technique de l'avoir
**Périmètre** : Slice 2 d'un programme à 3 slices (S0 audit signe négatif ✅, S1 primitive d'extourne + lettrage ✅, **S2 annulation de facture ← ce document**). Refond `FactureService::annuler()` pour qu'il neutralise comptablement les transactions **générées par les lignes `MontantManuel`** (cas invoice-first) en composant la primitive `TransactionExtourneService::extourner()` livrée par S1, et libère le pivot pour les transactions **référencées** par lignes `Montant`. Couvre la dette d'origine `project_avoir_transactions_dette.md`.
**Préalables** : Slices 0 et 1 livrés sur la même branche worktree `claude/funny-shamir-8661f9` (HEAD après `5f99847d`). Suite Feature verte 2844/0.
**Hors scope (slice futur ou backlog)** :
- Annulation **partielle** (avoir d'un seul article ou d'un montant inférieur)
- **Reverse d'un règlement référencé** depuis le flux d'annulation de facture — l'utilisateur le fait *séparément* via le bouton "Annuler la transaction" sur la fiche TX (S1), s'il le souhaite
- **Ré-émission corrective** dédiée (l'utilisateur crée une nouvelle facture après si besoin)
- Annulation d'**avoir** (annuler une facture déjà annulée)
- Édition du PDF de l'avoir (le PDF existant `pdf.facture` continue de servir avec son `numero_avoir`)
- Annulation d'une facture portée par un **exercice clos** (héritée de S1, l'extourne se range dans l'exercice courant)
- **Dé-lettrage manuel** (héritée de S1)

**Vocabulaire** : "Annulation" en UI / libellés / PDF, "extourne" et "avoir" en code/domain. L'avoir est l'objet de droit (numéro `AV-{exercice}-NNNN`) ; les extournes sont les écritures comptables qui le matérialisent en transactions miroirs sur les lignes `MontantManuel`.

---

## 1. Intent Description

**Quoi.** Réécrire `FactureService::annuler(Facture): void` pour qu'il neutralise comptablement les transactions **générées** par la facture annulée et libère les transactions **référencées**, en une opération atomique :

1. **Pour chaque transaction `MontantManuel` générée** (créée par `FactureService::valider()` à partir des lignes `MontantManuel` de cette facture) — la transaction est **extournée d'office et dans son entièreté** via la primitive S1 `TransactionExtourneService::extourner()`. Si la transaction n'a jamais été encaissée (`StatutReglement::EnAttente`), S1 crée le lettrage automatique et les deux transactions (origine + extourne) passent à `Pointe`. Si elle a été encaissée (`Recu` ou `Pointe`), l'extourne `EnAttente` apparaît dans la liste des transactions à pointer du compte bancaire (matérialise un remboursement à venir). **Pas de choix utilisateur** : laisser la TX origine subsister sans facture validée porteuse créerait une recette née de rien (état comptable incohérent).
2. **Pour chaque transaction `Montant` référencée** (préexistante, rattachée par `FactureLigne.transaction_ligne_id` + pivot `facture_transaction`) — **détachement du pivot uniquement**. Aucune extourne automatique. La TX redevient disponible pour rattachement à une autre facture brouillon (cas typique : "je me suis trompé de facture pour ce règlement"). Si l'utilisateur veut *aussi* rembourser ce règlement, il le fait *séparément* via le bouton "Annuler la transaction" sur la fiche TX (livré S1) — ces deux actes restent orthogonaux.
3. **Pivot `facture_transaction`** :
   - **Conservé** pour les TX `MontantManuel` (la facture annulée garde le lien vers l'origine ; la traçabilité vers l'extourne miroir est portée par la table `extournes`)
   - **Détaché** pour les TX `Montant` ref (la facture annulée ne doit plus apparaître comme porteuse d'un règlement préexistant)
4. **Mécanisme de l'avoir** existant inchangé : numéro `AV-{exerciceCourant}-NNNN`, séquence atomique via `lockForUpdate` sur l'exercice, statut `Annulee`, `date_annulation = today`.
5. **Suppression du guard** `isLockedByRapprochement()` actuel : c'est précisément le cas que S1 sait traiter (extourne `EnAttente` à pointer plus tard), inutile et trompeur de bloquer.

L'utilisateur déclenche le tout depuis le bouton "Annuler la facture" existant sur la fiche facture validée. La modale Bootstrap est enrichie en *informative* (résumé des transactions impactées et de leur destinée), sans interaction supplémentaire (pas de checkbox).

**Invariant cross-S1/S2** : toute TX dont `extournee_at` est non nul (origine d'une extourne, qu'elle vienne de S2 ou d'un appel direct S1) **ne doit jamais** être proposée dans les sélecteurs de règlement utilisés pour rattacher une transaction à une nouvelle facture brouillon. Idem pour la TX miroir (présente dans `extournes.transaction_extourne_id`). Cet invariant doit être vérifié explicitement par S2 (test dédié) et corrigé si la requête courante des "règlements disponibles" ne le filtre pas déjà.

**Pourquoi.** Aujourd'hui, `FactureService::annuler()` (v2.5.4) bascule la facture en `Annulee` + numéro avoir, **sans toucher aux transactions liées**. C'était correct dans le modèle historique transaction-first (les TX préexistaient à la facture, on ne devait pas effacer un paiement réel en annulant la facture émise après coup). Depuis v4.1.9 (S2 facture manuelle invoice-first) la TX `MontantManuel` est une **conséquence** de la facture, pas une prémisse — l'annuler laisse une TX "zombie" en `EnAttente` (créance fantôme) ou en `Recu` (recette légitime selon la base, illégitime selon la lecture économique). De plus, le guard actuel `isLockedByRapprochement()` interdit l'annulation dès qu'**une** TX liée est rapprochée banque verrouillée — alors que c'est précisément le cas que S1 sait traiter via une extourne `EnAttente` à pointer plus tard. La refonte traite les deux dettes d'un coup.

**Quoi ce n'est pas.** Pas un avoir partiel. Pas un mécanisme de remboursement de règlement référencé via la modale d'annulation (orthogonal, le user passe par S1 directement sur la TX). Pas une annulation d'extourne (extourner une extourne reste interdit, hérité S1). Pas une annulation d'avoir (annuler une `Annulee`). Pas une régénération automatique de la facture. Pas de modification du PDF.

**Périmètre Slice 2.**
- Refonte de `FactureService::annuler(Facture $facture): void` — **signature inchangée** (pas de payload supplémentaire ; la décision Q1 simplifie en supprimant les checkboxes initialement envisagées).
- Suppression du guard `isLockedByRapprochement()` actuel (remplacé par la logique S1 qui gère le cas).
- Ajout du guard `assertNotAnnulee()` pour refuser une double annulation.
- Détection des TX `MontantManuel` générées vs `Montant` référencées via deux helpers `Facture::transactionsGenereesParLignesManuelles(): Collection` et `Facture::transactionsReferencees(): Collection` (basés sur la jointure `facture_lignes.type === MontantManuel` + `transaction_ligne_id`).
- Modification de `Livewire\Factures\FactureShow` (ou équivalent — composant qui porte le bouton "Annuler la facture", à confirmer au plan) : remplacement du `wire:confirm` simple par une modale Bootstrap dédiée `AnnulerFactureModal` listant à titre informatif les transactions impactées et leur destinée (extourne forcée vs détachement seul).
- Permissions : Comptable + Admin uniquement (cohérent S1 ; via une `AnnulerFacturePolicy` dédiée).
- **Filtre des règlements disponibles** : audit de la requête utilisée par les sélecteurs "rattacher un règlement existant à une facture" — exclusion explicite des TX où `extournee_at IS NOT NULL` ET des TX présentes dans `extournes.transaction_extourne_id`. À ajouter si non déjà filtré.
- Tests Pest Feature couvrant tous les scénarios BDD §2 + tests d'intrusion multi-tenant + tests de non-régression sur les avoirs antérieurs.
- Logging `LogContext` : chaque annulation porte `association_id`, `user_id`, `facture_id`, `numero_avoir`, `transactions_extournees` (IDs des TX MontantManuel), `transactions_detachees` (IDs des TX ref).

**Cas métier dérivés** (rappel, déjà cadrés par le programme — S2 les couvre via composition S1 + détachement) :
1. Facture erronée avant règlement (lignes `MontantManuel` `EnAttente`) → extourne d'office + lettrage auto, facture annulée propre. ✅ S2.
2. Facture erronée après règlement (lignes `MontantManuel` `Pointe`) → extourne d'office en attente, à pointer remboursement banque ultérieur. ✅ S2.
3. Doublon de facturation (lignes `Montant` ref) → détachement pivot, le règlement reste légitime, on rattache à une autre facture. ✅ S2.
4. Mauvais tiers facturé sur règlement existant → détachement pivot, on recrée la facture vers le bon tiers et on rattache. ✅ S2.
5. Désistement participant après règlement référencé → S2 annule la facture (détachement), puis l'utilisateur passe sur la fiche TX et clique "Annuler la transaction" pour le remboursement. ✅ S2 + S1, **séparément**.

---

## 2. User-Facing Behavior (BDD Gherkin)

```gherkin
# language: fr
Fonctionnalité: Annulation de facture par avoir avec extourne automatique des lignes manuelles
  Pour pouvoir annuler une facture validée et neutraliser comptablement les transactions qu'elle a générées,
  tout en libérant les règlements préexistants pour ré-attachement
  En tant que comptable ou admin
  Je clique "Annuler la facture" sur la fiche, je confirme dans la modale informative,
  et le système produit l'avoir + les extournes des lignes MontantManuel + détache le pivot pour les lignes ref

  Contexte:
    Étant donné que je suis authentifié comme comptable de l'association "Asso A"
    Et que le tiers "Mr Dupont" existe dans "Asso A"
    Et que la sous-catégorie recette "Cotisations séance" existe dans "Asso A"
    Et que le compte bancaire "Caisse Épargne courant" existe dans "Asso A"

  Scénario: Annuler une facture manuelle non encaissée (1 ligne MontantManuel, EnAttente)
    Étant donné une facture validée "F-2026-0042" pour "Mr Dupont"
      avec une ligne MontantManuel "Cotisation mars" 80 € sous-catégorie "Cotisations séance"
      et la transaction recette générée associée au statut "en attente"
      sans rapprochement banque
    Quand j'annule cette facture
    Alors la facture passe au statut "annulée"
      avec numero_avoir "AV-2026-0001"
      et date_annulation = aujourd'hui
    Et la transaction d'origine est extournée (porte extournee_at = NOW)
    Et une transaction miroir -80 € a été créée à la date du jour, libellé "Annulation - Facture F-2026-0042"
    Et un rapprochement de type "lettrage" appariant origine et extourne a été créé
    Et les deux transactions sont au statut "pointé"
    Et le pivot facture_transaction conserve la transaction d'origine sur l'avoir
    Et la facture annulée n'apparaît plus dans Créances à recevoir

  Scénario: Annuler une facture manuelle déjà encaissée (1 ligne MontantManuel, Pointé banque verrouillée)
    Étant donné une facture validée "F-2026-0050" pour "Mr Dupont"
      avec une ligne MontantManuel "Inscription stage" 150 € sous-catégorie "Cotisations séance"
      et la transaction recette générée pointée au rapprochement bancaire R1 verrouillé
    Quand j'annule cette facture
    Alors la facture passe au statut "annulée" avec numero_avoir "AV-2026-NNNN"
    Et la transaction d'origine est extournée (porte extournee_at = NOW)
    Et une transaction miroir -150 € a été créée au statut "en attente"
    Et aucun lettrage automatique n'a été créé (cas encaissé)
    Et la transaction d'origine reste rattachée à R1 inchangée au statut "pointé"
    Et l'extourne -150 € apparaît dans la liste des transactions à pointer du compte "Caisse Épargne courant"

  Scénario: Annuler une facture portant une ligne Montant ref — détachement pivot uniquement, pas d'extourne
    Étant donné une transaction recette T1 préexistante de 200 € (paiement HelloAsso reçu) au statut "reçu"
    Et une facture validée "F-2026-0060" portant 1 ligne Montant ref pointant vers une ligne de T1
    Quand j'annule cette facture
    Alors la facture passe au statut "annulée" avec numero_avoir "AV-2026-NNNN"
    Et T1 reste au statut "reçu" sans extourne (extournee_at = null sur T1)
    Et le pivot facture_transaction ne lie plus T1 à F-2026-0060
    Et T1 redevient disponible pour être rattachée à une autre facture brouillon

  Scénario: La transaction Montant ref détachée est de nouveau rattachable à une nouvelle facture
    Étant donné une transaction recette T2 préexistante de 80 €
    Et une facture validée "F-2026-0061" qui référence T2
    Quand j'annule "F-2026-0061"
    Et que je crée une facture brouillon "F-brouillon" pour le même tiers
    Et que j'ouvre le sélecteur de règlements existants
    Alors T2 apparaît dans la liste des règlements disponibles
    Et je peux la rattacher à "F-brouillon"

  Scénario: Annuler une facture mixte (1 ligne MontantManuel + 1 ligne Montant ref)
    Étant donné une facture validée "F-2026-0070" portant
      une ligne MontantManuel "Stage avril" 100 € (TX générée Tg en attente)
      et une ligne Montant ref pointant vers une transaction préexistante Tref de 50 € (reçu)
    Quand j'annule cette facture
    Alors Tg est extournée d'office avec lettrage automatique (Tg passe Pointé, miroir -100 € Pointé)
    Et Tref est inchangée (extournee_at = null, statut "reçu" préservé)
    Et le pivot facture_transaction garde Tg, perd Tref
    Et la facture passe au statut "annulée" avec numero_avoir "AV-2026-NNNN"

  Scénario: Annuler une facture déjà rapprochée banque sans bloquer (changement de comportement vs v2.5.4)
    Étant donné une facture validée "F-2026-0080"
    Et la transaction MontantManuel générée pointée à un rapprochement bancaire R1 verrouillé
    Quand j'annule cette facture
    Alors aucune erreur "veuillez annuler le rapprochement" n'est levée
    Et l'extourne miroir est créée au statut "en attente"
    Et la modale a annoncé en bandeau d'avertissement que l'extourne devra être pointée plus tard

  Scénario: Une transaction extournée par S2 ne réapparaît pas dans les sélecteurs de règlements
    Étant donné une facture "F-2026-0090" annulée (TX MontantManuel Tg extournée)
    Et une nouvelle facture brouillon "F-2026-0091" pour le même tiers
    Quand j'ouvre le sélecteur "Rattacher un règlement existant"
    Alors Tg n'apparaît pas (extournee_at non nul)
    Et l'extourne miroir négative non plus (présente dans extournes.transaction_extourne_id)

  Scénario: Modale informative — affichage des transactions impactées
    Étant donné une facture validée portant 1 ligne MontantManuel (Tg 80 €) et 2 lignes Montant ref (Tref1 50 €, Tref2 30 €)
    Quand j'ouvre la modale "Annuler la facture"
    Alors la section "Transactions générées par cette facture (annulation comptable forcée)" affiche Tg avec son montant
    Et la section "Règlements référencés (seront détachés et redeviendront disponibles)" affiche Tref1 et Tref2
    Et un texte explicatif rappelle "Pour rembourser un règlement référencé, utilisez le bouton « Annuler la transaction » sur sa fiche."
    Quand je confirme l'annulation
    Alors la facture est annulée selon les règles ci-dessus

  Scénario: Modale — bandeau d'avertissement banque
    Étant donné une facture validée portant ≥ 1 transaction MontantManuel au statut "pointé"
    Quand j'ouvre la modale d'annulation
    Alors un bandeau d'avertissement est affiché : "L'extourne d'au moins une transaction devra être pointée lors d'un futur rapprochement bancaire."

  Scénario: Refus — facture déjà annulée
    Étant donné une facture au statut "annulée"
    Quand je tente d'annuler à nouveau
    Alors une erreur "Cette facture est déjà annulée." est levée
    Et aucune nouvelle extourne n'est créée
    Et le bouton "Annuler la facture" n'est pas affiché sur la fiche

  Scénario: Refus — facture brouillon
    Étant donné une facture au statut "brouillon"
    Quand je tente d'annuler
    Alors une erreur "Seule une facture validée peut être annulée." est levée

  Scénario: Refus — utilisateur Gestionnaire
    Étant donné un Gestionnaire sur "Asso A"
    Quand il consulte la fiche d'une facture validée
    Alors le bouton "Annuler la facture" n'est pas affiché
    Et un appel direct au service `FactureService::annuler` est refusé par la policy

  Scénario: Refus — TX MontantManuel déjà extournée hors flux (état incohérent improbable, ceinture-bretelles)
    Étant donné une facture validée portant 1 ligne MontantManuel
    Et que la transaction générée Tg a déjà extournee_at non nul (cas pathologique : appel service direct hors UI)
    Quand je tente d'annuler la facture
    Alors une erreur "La transaction « Facture F-XXXX » a déjà été annulée. État incohérent — contactez l'admin." est levée
    Et la facture reste au statut "validée"

  Scénario: Multi-tenant — annulation de facture d'un autre tenant interdite
    Étant donné un comptable de "Asso A" qui invoque le service avec l'ID d'une facture de "Asso B"
    Alors le scope global tenant retourne null et le service refuse avec "Facture introuvable"

  Scénario: Compte de résultat reflète l'annulation
    Étant donné une facture "F-2026-0100" 80 € validée et annulée dans le même exercice
    Quand je consulte le compte de résultat de l'exercice 2026
    Alors la sous-catégorie portée par la ligne MontantManuel affiche un total net de 0 €
    Et le détail montre +80 € (origine) et -80 € (extourne)

  Scénario: Numéro avoir séquentiel sous concurrence
    Étant donné deux factures validées en cours d'annulation simultanément
    Quand les deux flux s'exécutent en parallèle
    Alors les deux numéros d'avoir sont distincts (lockForUpdate sur l'exercice respecté)

# Hors scope MVP — listés ici pour cadrage du Slice 2 :
# - Annulation partielle (avoir d'un seul article ou montant inférieur)
# - Reverse d'un règlement référencé piloté depuis la modale (l'utilisateur passe par S1 sur la TX)
# - Annulation d'une facture à exercice clos (l'avoir se range dans exercice courant, hérité S1)
# - PDF d'avoir distinct du PDF facture (le PDF actuel suffit avec son numero_avoir)
# - Régénération automatique d'une facture corrective
```

---

## 3. Architecture Specification

### 3.1 Modèle de données

**Aucune nouvelle table.** Aucune nouvelle colonne. S2 est purement applicatif — composition de S1 (qui a déjà introduit `extournes` + `transactions.extournee_at` + `rapprochements_bancaires.type`). Le mécanisme d'avoir existant (`factures.numero_avoir`, `factures.date_annulation`, `factures.statut = Annulee`) est conservé sans changement de schéma.

### 3.2 Enums

**`StatutFacture` (existant, inchangé)** : `Brouillon`, `Validee`, `Annulee`. Pas de nouveau statut intermédiaire.

### 3.3 Services métier

**Refonte `FactureService::annuler()`** :

| Méthode | Signature | Comportement |
|---|---|---|
| `annuler(Facture $facture): void` | Inchangée par rapport à v2.5.4 (la décision Q1 simplifiée écarte l'idée d'un payload de checkboxes) | Voir flux ci-dessous |

**Flux** (dans une `DB::transaction` unique) :

```
1. Guards :
   - assertTenantOwnership($facture)
   - assertValidee($facture)            // existant, message "Seule une facture validée peut être annulée."
   - assertNotAnnulee($facture)          // nouveau (idempotence)
   - Gate::authorize('annuler', $facture) // policy AnnulerFacturePolicy : Comptable + Admin
2. Calcul numero_avoir : lockForUpdate sur factures de l'exercice courant (Validee + Annulee),
   max(seq existantes), seq+1, format 'AV-{exerciceCourant}-NNNN' (mécanisme actuel inchangé)
3. Update facture :
     - statut = Annulee
     - numero_avoir = (calculé étape 2)
     - date_annulation = today
   ← à ce stade, la primitive S1 ne sera plus bloquée par "facture validée"
4. Pour chaque TX MontantManuel détectée (helper §3.4) :
     - Vérifier extournable (assertExtournable côté primitive) — si non-extournable et != "déjà annulée",
       laisser la primitive lancer l'erreur (rollback)
     - $extourneService->extourner($tx, ExtournePayload::fromOrigine($tx, dateAnnulation: today))
     - Pivot conservé
5. Pour chaque TX Montant ref détectée (helper §3.4) :
     - $facture->transactions()->detach($tx->id)
     - Pas d'extourne
6. Log info 'facture.annulee' :
     association_id, user_id, facture_id, numero_avoir,
     transactions_extournees: [Tg.id, ...],
     transactions_detachees: [Tref.id, ...]
```

**Note sur l'ordre** : on flippe la facture en `Annulee` **avant** d'appeler la primitive S1. La primitive S1 contient un guard "Cette transaction est portée par la facture F-XXXX validée" — il ne se déclenche plus une fois la facture en `Annulee`. Couplage minimal : la primitive reste totalement agnostique du flux S2.

**Suppression du guard `isLockedByRapprochement()`** : la version actuelle contient

```php
foreach ($facture->transactions as $tx) {
    if ($tx->isLockedByRapprochement()) {
        throw new RuntimeException(...);
    }
}
```

→ supprimé en entier. La primitive S1 gère le cas (pas de lettrage, extourne `EnAttente` à pointer ailleurs).

### 3.4 Détection MontantManuel vs Montant ref

Une TX du pivot `facture_transaction` est dite **"générée par cette facture"** (`MontantManuel`) ssi :

```sql
EXISTS (
  SELECT 1 FROM facture_lignes fl
  JOIN transaction_lignes tl ON fl.transaction_ligne_id = tl.id
  WHERE fl.facture_id = :facture_id
    AND tl.transaction_id = :transaction_id
    AND fl.type = 'montant_manuel'
)
```

Côté Eloquent, deux helpers sur `App\Models\Facture` :

| Helper | Retourne |
|---|---|
| `transactionsGenereesParLignesManuelles(): Collection<Transaction>` | Les TX du pivot dont au moins une `TransactionLigne` est référencée par une `FactureLigne` de cette facture de type `MontantManuel` |
| `transactionsReferencees(): Collection<Transaction>` | Les TX du pivot qui ne sont **pas** dans le set ci-dessus (i.e. référencées par des `FactureLigne` de type `Montant`) |

**Invariant à figer en test** : les deux ensembles sont disjoints par construction. `genererTransactionDepuisLignesManuelles()` crée toujours une TX neuve dont *toutes* les `TransactionLignes` viennent de cette facture (on n'ajoute jamais une `TransactionLigne MontantManuel` à une TX existante). À vérifier explicitement par AC dédié.

### 3.5 Filtre des règlements disponibles (invariant cross-S1/S2)

**Constat vérifié par lecture du code (2026-05-01)** : la requête actuelle dans `app/Livewire/FactureEdit.php:447-458` propose comme règlements rattachables à la facture brouillon **toute** TX recette du tiers non déjà attachée à une facture Brouillon/Validée — sans aucun filtre sur `extournee_at` ni exclusion des miroirs d'extourne. Conséquence observable depuis la livraison S1 : sur une nouvelle facture brouillon, on voit à la fois les TX libres, les TX origines extournées, ET les TX miroirs négatives.

S2 corrige le seul call site identifié comme concerné par le rattachement à facture (`FactureEdit::render()`) en ajoutant :

```php
->whereNull('extournee_at')          // exclut les origines extournées
->whereNotIn('id', Extourne::query()
    ->select('transaction_extourne_id'))  // exclut les miroirs d'extourne
```

**Recommandation d'implémentation** : encapsuler les deux conditions dans un **scope Eloquent** sur `App\Models\Transaction` (ex: `scopeRattachableAFacture()`) pour réutilisabilité et lisibilité. Audit cross-app au step 6 du plan : aucun autre call site UI ne fait du rattachement à facture (vérifié via `grep "whereDoesntHave('factures'"` qui ne remonte que `FactureEdit.php`).

**Hors scope S2** : les autres listes Transaction (rapprochement bancaire, remises, dashboard, exercice clôture, etc.) ne nécessitent **pas** ce filtre — elles ont leur propre sémantique (afficher les écritures comptables réelles, y compris les extournes en tant que telles). Le filtre `rattachableAFacture` est strictement réservé au flux d'attachement à une facture brouillon.

### 3.6 UI

| Écran | Modification |
|---|---|
| **Fiche facture** (`Livewire\Factures\FactureShow` ou équivalent — à confirmer au plan) | Le bouton "Annuler la facture" reste affiché ssi `facture->statut === Validee` ET policy passante. Le `wire:confirm` natif (modale Bootstrap simple) est remplacé par une **modale dédiée `AnnulerFactureModal`** (modale informative, pas interactive). |
| **Nouvelle modale `App\Livewire\Factures\AnnulerFactureModal`** | Sections : (1) Bandeau résumé "Vous allez annuler la facture F-{numero} de {tiers} pour {montant} €. Numéro d'avoir attribué : AV-{exercice}-NNNN (calculé après confirmation)." (2) Section "Transactions générées par cette facture — annulation comptable forcée" listant chaque TX `MontantManuel` (ID, libellé, montant, statut_reglement). Mention "Une transaction miroir négative sera créée." Aucune interaction. (3) Section "Règlements référencés — seront détachés et redeviendront disponibles" listant les TX `Montant` ref. Texte explicatif : "Ces transactions ne sont pas annulées, seulement libérées de cette facture. Pour rembourser un règlement, utilisez le bouton « Annuler la transaction » sur sa fiche." (4) Bandeau d'avertissement orange si ≥ 1 TX MontantManuel a `statut_reglement = Pointe` : "L'extourne d'au moins une transaction devra être pointée lors d'un futur rapprochement bancaire." (5) Boutons "Annuler" (ferme modale) / "Confirmer l'annulation" (déclenche `FactureService::annuler`). |
| **Liste factures** | Inchangée. |
| **PDF facture/avoir** | Inchangé (rendu existant via `pdf.facture` continue de servir les avoirs avec leur `numero_avoir`). |
| **Sélecteurs de règlement** (rattachement à facture brouillon) | Filtres `extournee_at IS NULL` + exclusion `extournes.transaction_extourne_id` ajoutés (cf §3.5). |

### 3.7 Multi-tenant

- `Facture` (existant) étend `TenantModel` → scope global fail-closed actif.
- `assertTenantOwnership($facture)` (existant) reste appelé en début d'`annuler()`.
- `Extourne` (créé par S1) étend `TenantModel` → les extournes générées par S2 héritent automatiquement du tenant courant.
- Logging via `LogContext` enrichi pour S2 (cf §3.3 step 6).

### 3.8 Migrations

**Aucune migration.** S2 est purement applicatif.

### 3.9 Frontière avec l'existant

| Fonctionnalité | Impact |
|---|---|
| Saisie de transaction manuelle | Inchangé |
| Validation de facture (`FactureService::valider`) | Inchangé — la TX `MontantManuel` est toujours générée à la validation |
| Primitive `TransactionExtourneService::extourner()` | Appelée par S2, **non modifiée**. Réutilisée telle quelle. |
| Pointage / rapprochement bancaire | Inchangé. Les extournes `EnAttente` créées par S2 (cas encaissé) suivent le flux ordinaire de pointage. |
| Créances à recevoir | Inchangé (les extournes pointées via lettrage disparaissent ; les extournes `EnAttente` négatives sont filtrées par le durcissement S1 `montant > 0`) |
| Compte de résultat / Flux trésorerie | Inchangé (les extournes apparaissent comme transactions négatives, sommation correcte hérite de S0) |
| HelloAsso | Verrou existant — une TX HelloAsso `MontantManuel` n'existe pas (les TX HelloAsso ne sont jamais nées d'une facture invoice-first) ; le cas pathologique est donc impossible. Une TX HelloAsso `Montant` ref détachée par S2 reste intacte (pas d'extourne). |
| Slice 1 | **Construit dessus.** Le service S1 n'est pas modifié. |
| Slice 0 | Préalable, livré ✅ |
| `FactureAvoirTest` existant | À amender pour : (1) supprimer le test sur le guard `isLockedByRapprochement` ; (2) ajuster les assertions sur la vie des transactions liées (extourne pour MontantManuel, détachement pour ref) |

---

## 4. Acceptance Criteria

| # | Critère | Mesure |
|---|---|---|
| AC-1 | Suite Pest verte | `./vendor/bin/sail test` passe avec 100 % des tests existants + ajouts (~10-12 nouveaux tests Feature) |
| AC-2 | Tous les scénarios BDD §2 sont implémentés en tests Feature | 1 test = 1 scénario, mappage 1:1 (les 14 scénarios in-MVP) |
| AC-3 | Annulation d'une facture MontantManuel non encaissée → extourne + lettrage auto | Test : facture 80 € `MontantManuel` `EnAttente` → annulation → 1 extourne, 1 lettrage, statuts `Pointe` |
| AC-4 | Annulation d'une facture MontantManuel encaissée → extourne `EnAttente`, pas de lettrage | Test : facture 150 € `MontantManuel` `Pointe` → annulation → extourne -150 € `EnAttente`, origine reste `Pointe` |
| AC-5 | Annulation d'une facture avec ligne ref → pas d'extourne, pivot détaché | Test : TX ref `Recu` 200 € → annulation → TX inchangée, pivot vide |
| AC-6 | TX ref détachée redevient rattachable à une nouvelle facture | Test : annuler F1 → créer F2 brouillon pour même tiers → sélecteur règlements propose la TX |
| AC-7 | Annulation d'une facture mixte | Test : 1 ligne `MontantManuel` 100 € + 1 ligne ref 50 € → 1 extourne pour la générée, détachement pivot pour la ref |
| AC-8 | Suppression du guard `isLockedByRapprochement` ne casse pas la cohérence | Test : facture avec TX `MontantManuel` pointée banque verrouillée → annulation OK, extourne `EnAttente` créée |
| AC-9 | Refus double annulation | Test : facture `Annulee` → exception "déjà annulée" |
| AC-10 | Multi-tenant : aucune fuite | Test d'intrusion : tenant A annule facture de B → null/exception scope |
| AC-11 | Atomicité — crash mid-annulation | Test : forcer une exception après création de la 1ʳᵉ extourne → rollback complet, facture reste `Validee`, aucune extourne en base, pivot intact |
| AC-12 | Numero avoir séquentiel sous concurrence | Test : 2 annulations en parallèle (lockForUpdate) → 2 numéros distincts |
| AC-13 | Compte de résultat correct sur exercice avec annulation | Test : ∑ sous-catégorie après annulation = 0 € |
| AC-14 | Policy `AnnulerFacturePolicy` : Comptable + Admin only | Test : Gestionnaire refusé (UI + service) |
| AC-15 | Modale Bootstrap dédiée (pas de `confirm()` natif) | Conforme convention `wire:confirm` du projet |
| AC-16 | Bandeau d'avertissement banque dans la modale | Test Livewire : facture avec TX MontantManuel `Pointe` → bandeau présent |
| AC-17 | Pivot facture_transaction conservé pour MontantManuel | Test : après annulation, `$facture->transactions->contains($tx_montant_manuel)` vrai |
| AC-18 | Pivot facture_transaction détaché pour ref | Test : après annulation, `$facture->transactions->contains($tx_ref)` faux |
| AC-19 | Logging `LogContext` enrichi | Test sur log capturé : `facture_id`, `numero_avoir`, IDs des extournes et détachées |
| AC-20 | **Invariant filtre règlements disponibles : TX extournée exclue** | Test : créer F1 brouillon pour tiers d'une TX `MontantManuel` extournée → la TX origine n'apparaît pas dans le sélecteur |
| AC-21 | **Invariant filtre règlements disponibles : TX miroir d'extourne exclue** | Test : sélecteur ne propose jamais une TX listée dans `extournes.transaction_extourne_id` |
| AC-22 | Helpers `Facture::transactionsGenereesParLignesManuelles` et `transactionsReferencees` disjoints | Test : facture mixte → les deux collections n'ont aucune intersection, leur union = pivot |
| AC-23 | Pas de régression sur les avoirs antérieurs (factures historiques v2.5.4 sans `MontantManuel`) | Test sur fixture facture transaction-first → annulation → avoir créé, pivot des TX ref détaché, aucune extourne (car aucune `MontantManuel`) |
| AC-24 | PSR-12 / Pint vert | `./vendor/bin/pint --test` passe |
| AC-25 | `declare(strict_types=1)` + `final class` sur tous les nouveaux fichiers | Vérification grep |

---

## 5. Consistency Gate

- [x] Intent unambiguous — l'annulation de facture compose la primitive S1 selon une règle déterministe (MontantManuel extournée d'office, ref détachée seulement)
- [x] Chaque comportement de l'Intent a au moins un scénario BDD correspondant en §2 (extourne forcée, détachement seul, mixte, refus, multi-tenant, concurrence, modale informative, invariant filtre règlements)
- [x] L'architecture §3 contraint l'implémentation à la composition S1, sans dupliquer la logique d'extourne (DRY préservé)
- [x] Concepts nommés de façon cohérente avec S1 : "annulation" en UI, "avoir" pour le numéro, "extourne" pour l'écriture comptable, `MontantManuel` vs `Montant` ref pour la classification
- [x] Aucun artefact ne contredit S1 (la primitive est appelée telle quelle)
- [x] Frontière avec S0 (audit) et S1 (primitive) claire : aucune migration, aucune nouvelle table
- [x] Permissions explicites : Comptable + Admin (cohérent S1, Gestionnaire refusé)
- [x] Multi-tenant fail-closed appliqué (Facture déjà TenantModel, Extourne déjà TenantModel via S1)
- [x] Limitations MVP documentées : pas d'avoir partiel, pas de remboursement de ref piloté depuis la modale, pas d'annulation d'avoir, pas de PDF distinct, exercice clos hérité S1
- [x] **Décision tranchée Q1 (validée par humain)** : pas de checkbox — `MontantManuel` extournée d'office, `Montant` ref détachée seulement. L'utilisateur rembourse un règlement référencé séparément via S1 sur la fiche TX
- [x] **Décision tranchée Q2 (validée par humain)** : pivot conservé pour `MontantManuel`, détaché pour ref ; **invariant cross-S1/S2** ajouté en AC-20/AC-21 (TX extournée jamais proposée comme règlement disponible)
- [x] **Décision tranchée Q3 (validée par humain)** : suppression du guard `isLockedByRapprochement` — testée AC-8

**Verdict : ✅ PASS — spec validée par échange humain 2026-05-01. Q1/Q2/Q3 tranchées, invariant filtre règlements confirmé par lecture du code (1 seul call site `FactureEdit.php:447-458`). Prête pour `/plan`.**

---

## 6. Décisions actées (synthèse S2)

| Décision | Choix |
|---|---|
| Périmètre annulation | **Totale uniquement** (pas d'avoir partiel) |
| Lignes `MontantManuel` | Extourne **d'office et dans son entièreté** via primitive S1, non débrayable (la TX origine sans facture validée porteuse serait incohérente) |
| Lignes `Montant` ref | **Détachement du pivot uniquement**, pas d'extourne. Le user rembourse séparément via S1 sur la TX si besoin |
| Pivot `facture_transaction` | **Détaché** pour les TX `Montant` ref ; **conservé** pour les TX `MontantManuel` (traçabilité avoir → origine) |
| Guard `isLockedByRapprochement` | **Supprimé** — la primitive S1 gère le cas via extourne `EnAttente` à pointer ultérieurement |
| Invariant filtre règlements | TX où `extournee_at IS NOT NULL` OU TX dans `extournes.transaction_extourne_id` → **jamais** proposée comme règlement rattachable à une facture brouillon |
| Numero avoir | Mécanisme existant inchangé : `AV-{exerciceCourant}-NNNN`, lockForUpdate sur l'exercice |
| Date annulation et date des extournes générées | Today (pas de paramétrage utilisateur — signature inchangée) |
| PDF de l'avoir | Inchangé — le rendu existant `pdf.facture` continue de servir avec son `numero_avoir` |
| Permissions | Comptable + Admin (cohérent S1) |
| Modale UI | Modale Bootstrap dédiée **informative** (pas d'interaction supplémentaire), sections "extournée d'office" / "détachée seulement" / bandeau banque éventuel |
| Migration | **Aucune** — S2 purement applicatif |
| Régénération corrective | **Hors scope** — l'utilisateur recrée manuellement une nouvelle facture si besoin |
| Annulation d'avoir (facture déjà `Annulee`) | **Refusée** explicitement (idempotence) |
| Exercice clos | **Hors scope** (héritage S1, l'avoir et les extournes vont dans l'exercice courant) |
| Vocabulaire | "Annulation"/"avoir" en UI, "extourne" en code |

---

## 7. Notes pour le `/plan`

- **Découpage suggéré** (~7-8 steps TDD) :
  1. RED : test "annulation facture MontantManuel `EnAttente` → extourne + lettrage" (AC-3) ; GREEN : helper `Facture::transactionsGenereesParLignesManuelles`, refonte `annuler()` étapes 1-4 sur le cas MontantManuel
  2. RED/GREEN : cas MontantManuel encaissée (AC-4) — vérifie l'absence de lettrage et l'extourne `EnAttente`
  3. RED/GREEN : cas ref → détachement pivot uniquement (AC-5, AC-18) ; helper `Facture::transactionsReferencees`
  4. RED/GREEN : cas mixte (AC-7), ref redevient rattachable (AC-6)
  5. RED/GREEN : suppression guard `isLockedByRapprochement` + ajout `assertNotAnnulee` (AC-8, AC-9) ; refacto `FactureAvoirTest` existant
  6. RED/GREEN : invariants filtre règlements disponibles (AC-20, AC-21) — identifier la requête courante, ajouter les filtres
  7. RED/GREEN : modale Livewire `AnnulerFactureModal` informative (AC-15, AC-16) ; policy `AnnulerFacturePolicy` (AC-14)
  8. RED/GREEN : multi-tenant intrusion (AC-10), concurrence numéro (AC-12), atomicité crash (AC-11), logging (AC-19)
  9. REFACTOR + Pint + audit AC-22..AC-25 + non-régression AC-23 (fixture facture transaction-first)

- **Risques identifiés** :
  - Tests existants `FactureAvoirTest` à amender : suppression du test sur le guard banque verrouillée, ajustement des assertions sur la destinée des transactions liées
  - Identification précise du composant qui propose les "règlements disponibles" pour rattachement à brouillon — à investiguer step 6 (potentiel `App\Livewire\Factures\SelecteurReglements` ou équivalent)
  - Le helper `transactionsGenereesParLignesManuelles` doit faire un `JOIN` correct via `transaction_lignes` ; risque de dégradation perf si N+1, à charger en eager loading (à mesurer)

- **Dépendances cross-projet** :
  - Aucun changement de DB schema — pas de migration à coordonner avec staging
  - Pas de changement HelloAsso (verrou S1 préservé, et le cas pathologique HelloAsso × MontantManuel est inexistant par construction)
  - Pas de changement export PDF / Factur-X
