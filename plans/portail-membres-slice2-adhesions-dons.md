# Plan: Portail membres et participants — Slice 2 : Mes adhésions + Mes dons

**Created**: 2026-05-14
**Branch**: `feat/portail-membres-slice1-fondation-profil` (option B git actée — toutes les slices sur une seule branche, MEP groupée vers main)
**Status**: implemented (2026-05-14, 9 commits, security review PASS, fix défense URL scheme appliqué)
**Spec**: [docs/specs/2026-05-14-portail-membres-slice2-adhesions-dons.md](../docs/specs/2026-05-14-portail-membres-slice2-adhesions-dons.md)
**Slice 1 (F+A) statut** : implemented (commit `f8b18cb1` + 14 commits précédents) — fondation sidebar + Mon profil livrés et testés en local.

## Goal

Livrer les deux écrans portail « Mes adhésions » et « Mes dons » accessibles via la sidebar (slice 1), avec téléchargement à la demande des reçus de cotisation et reçus fiscaux. Ajouter 3 colonnes URL paramétrables sur la table `association` pour les liens externes de renouvellement et de nouveau don, avec fallback sur l'URL du site web. Ce slice valide de bout en bout la chaîne resolver/registry → provider visible si ≥1 ligne → composant Livewire post-auth → réutilisation des services métier existants (`TiersAdhesionTimelineService`, `TiersDonsTimelineService`, `RecuFiscalService::obtenirOuGenerer*` + `streamPdf`). Pas de nouveau service métier, pas de modification du back-office reçus, pas de notification email.

## Acceptance Criteria

- [ ] Migration ajoute 3 colonnes nullable sur `association` : `url_site_web`, `url_renouvellement_adhesion`, `url_nouveau_don`. Champs `$fillable`. Helpers `Association::urlRenouvellementAdhesion(): ?string` et `urlNouveauDon(): ?string` retournent l'URL spécifique ou le fallback `url_site_web` ou `null`.
- [ ] Sidebar : « Mes adhésions » apparaît ssi le Tiers connecté a ≥ 1 adhésion ; « Mes dons » ssi ≥ 1 don. Nouveau groupe « Ma vie de membre » entre « Espace personnel » et « Mes frais & factures ».
- [ ] `/{slug}/portail/mes-adhesions` liste les adhésions du Tiers connecté triées par `date_fin` desc, avec statut « À jour » (`date_fin >= today`) ou « Expirée ».
- [ ] CTA « Renouveler mon adhésion » : visible avec href = `urlRenouvellementAdhesion()` ; **caché** si null.
- [ ] CTA « Faire un nouveau don » : pareil avec `urlNouveauDon()`.
- [ ] Bouton « Télécharger le reçu » sur ligne adhésion : visible ssi `RecuFiscalService::validerEligibiliteAdhesion()` ne lève pas. Clic → `obtenirOuGenererPourAdhesion()` → `streamPdf()`.
- [ ] Bouton « Télécharger le reçu » sur ligne don : visible ssi `validerEligibilite(TransactionLigne)` ne lève pas. Clic → `obtenirOuGenerer()` → `streamPdf()`.
- [ ] `/{slug}/portail/mes-dons` regroupe les dons par année civile desc avec total annuel par groupe (en-tête de section).
- [ ] Génération à la demande : si `RecuFiscalEmis` n'existe pas mais éligible, un nouveau est créé en transaction et téléchargé. Si déjà existant : pas de doublon.
- [ ] Sécurité — Tiers Alice ne peut pas télécharger un reçu rattaché à un autre Tiers (même asso) — test d'intrusion via `Livewire::test(...)->call('telechargerRecuCotisation', $bobAdhesionId)` → 403/abort.
- [ ] Sécurité — Cross-tenant : Tiers asso A ne peut pas télécharger un reçu d'un Tiers asso B (TenantScope filtre, test explicite).
- [ ] Logger émet `portail.recu.cotisation.telecharge` et `portail.recu.fiscal.telecharge` avec `tiers_id` (LogContext propage `association_id`).
- [ ] Mode mono (`portail-mono.php`) expose `/portail/mes-adhesions` et `/portail/mes-dons` avec les mêmes règles + composants. Test feature mono dédié.
- [ ] Régression : sidebar slice 1 inchangée pour les Tiers sans adhésion ni don. Couvert mécaniquement par les tests slice 1 existants (`SidebarVisibiliteTest`, `RegressionBenevoleTest`) qui tournent dans la suite. **Cohabitation slice 1 + slice 2** explicitement testée dans `MesAdhesionsTest` : un Tiers avec `pour_depenses=true` ET ≥1 adhésion voit les 2 groupes (« Mes frais & factures » + « Ma vie de membre ») dans la sidebar, dans l'ordre attendu (60/70 après 30/40/50).
- [ ] Suite Pest verte (0 failure). Pint clean. Larastan baseline inchangée.

## Pré-décisions confirmées (cf. spec)

| Point | Décision |
| ----- | -------- |
| Tri adhésions | `date_fin` desc |
| Statut adhésion | « À jour » si `date_fin >= today` sinon « Expirée » |
| Champs URL Association | 3 nouvelles colonnes : `url_site_web`, `url_renouvellement_adhesion`, `url_nouveau_don` |
| Fallback URL CTA | Si URL spécifique null → `url_site_web` ; si les deux null → bouton caché |
| Reçus | « Obtient ou génère » à la demande via `RecuFiscalService` (pattern existant back-office) |
| Don anonyme | N/A (concept inexistant dans AgoraGestion) |
| Dons regroupés | Par année civile desc avec total |

## Hypothèses techniques verrouillées (cf. spec)

| Item | État |
| ---- | ---- |
| `TiersAdhesionTimelineService::forTiers(Tiers): AdhesionTimelineDTO` | ✓ existe — tri par `exercice` desc + `id` desc (pas par `date_fin`). **Décision** : pour MesAdhesions on n'utilise PAS ce service (tri différent demandé). On query direct `Adhesion::where('tiers_id', $tiers->id)->orderByDesc('date_fin')->get()` (TenantScope filtre asso). |
| `TiersDonsTimelineService::forTiers(Tiers, ?int $anneeCivile): DonsTimelineDTO` | ✓ existe — **expose déjà `peutTelecharger`, `raisonBlocage` et le groupement par année avec total**. On l'utilise tel quel pour MesDons (pas de précalcul d'éligibilité à faire). |
| **Critère ownership d'un don (TransactionLigne)** | `Transaction.tiers_id === $tiers->id` ET `type = Recette` ET `sousCategorie.usages` contient `Don`. Reproduit par `TiersDonsTimelineService` — pour le check ownership côté téléchargement, on **vérifie l'appartenance via le service** : `forTiers($tiers)->lignes` doit contenir l'id de la ligne demandée. Si oui → ownership OK. Sinon → 403. |
| **Critère ownership d'une adhésion** | `Adhesion.tiers_id === $tiers->id` (TenantScope filtre asso). Vérifier strict avec cast `(int)` des deux côtés (préférence projet). |
| `RecuFiscalService::obtenirOuGenerer(TransactionLigne, ?User): RecuFiscalEmis` | ✓ existe (DB::transaction + lockForUpdate) |
| `RecuFiscalService::obtenirOuGenererPourAdhesion(Adhesion, ?User): RecuFiscalEmis` | ✓ existe |
| `RecuFiscalService::validerEligibilite(TransactionLigne)` | ✓ existe — lève `RecuFiscalException` si non éligible |
| `RecuFiscalService::validerEligibiliteAdhesion(Adhesion)` | ✓ existe |
| `RecuFiscalService::streamPdf(RecuFiscalEmis): Response` | ✓ retourne directement une `Symfony\Response` (PDF inline) |
| Table `association` (singulier) | ✓ confirmé |

## Steps

### Step 1: Migration `add_url_fields_to_association` + helpers Association

**Complexity**: standard
**RED**:
- `tests/Unit/Models/AssociationUrlHelpersTest.php` (nouveau) — couvre :
  - `urlRenouvellementAdhesion()` retourne `url_renouvellement_adhesion` si défini
  - retourne `url_site_web` si `url_renouvellement_adhesion` null
  - retourne `null` si les deux null
  - Idem pour `urlNouveauDon()`
- Run `./vendor/bin/sail test --filter=AssociationUrlHelpers`. Failing : méthodes n'existent pas.

**GREEN**:
- Créer migration `database/migrations/2026_05_14_xxxxxx_add_url_fields_to_association.php` :
  ```php
  Schema::table('association', function (Blueprint $table) {
      $table->string('url_site_web', 255)->nullable();
      $table->string('url_renouvellement_adhesion', 255)->nullable();
      $table->string('url_nouveau_don', 255)->nullable();
  });
  ```
  (Pas de positionnement `after()` — placement en fin de table accepté, l'ordre des colonnes n'a pas d'impact fonctionnel.)
- Ajouter les 3 champs à `$fillable` dans `app/Models/Association.php`.
- Ajouter les 2 méthodes helper dans `Association` :
  ```php
  public function urlRenouvellementAdhesion(): ?string {
      return $this->url_renouvellement_adhesion ?: ($this->url_site_web ?: null);
  }
  public function urlNouveauDon(): ?string {
      return $this->url_nouveau_don ?: ($this->url_site_web ?: null);
  }
  ```
  (Utiliser `?:` plutôt que `??` pour traiter aussi la chaîne vide comme « pas configuré ».)
- Run migration `./vendor/bin/sail artisan migrate`.
- Run le test, doit passer.

**REFACTOR**: aucun.

**Files**:
- `database/migrations/2026_05_14_xxxxxx_add_url_fields_to_association.php`
- `app/Models/Association.php`
- `tests/Unit/Models/AssociationUrlHelpersTest.php`

**Commit**: `feat(portail): URLs paramétrables Association (site web, renouvellement, don)`

---

### Step 2: Provider `MesAdhesionsProvider`

**Complexity**: standard
**RED**:
- `tests/Unit/Portail/Providers/MesAdhesionsProviderTest.php` couvre :
  - Tiers avec ≥ 1 Adhésion → DTO non null avec `id="mes-adhesions"`, `label="Mes adhésions"`, `routeName="portail.mes-adhesions"`, `icon="bi-card-checklist"`, `ordre=60`, `groupe="Ma vie de membre"`
  - Tiers avec 0 Adhésion → null

**GREEN**:
- Créer `app/Services/Portail/Providers/MesAdhesionsProvider.php` (final, implements `PortailSectionProvider`).
- Query : `Adhesion::query()->where('tiers_id', $tiers->id)->exists()` (TenantScope global filtre `association_id`).
- Enregistrer dans `App\Providers\PortailServiceProvider::boot()`.

**REFACTOR**: aucun.

**Files**:
- `app/Services/Portail/Providers/MesAdhesionsProvider.php`
- `app/Providers/PortailServiceProvider.php` (registration ajoutée)
- `tests/Unit/Portail/Providers/MesAdhesionsProviderTest.php`

**Commit**: `feat(portail): provider sidebar Mes adhésions (visible si ≥1 adhésion)`

---

### Step 3: Provider `MesDonsProvider`

**Complexity**: standard
**RED**:
- `tests/Unit/Portail/Providers/MesDonsProviderTest.php` couvre :
  - Tiers avec ≥ 1 don → DTO non null (`id="mes-dons"`, `label="Mes dons"`, `routeName="portail.mes-dons"`, `icon="bi-gift"`, `ordre=70`, `groupe="Ma vie de membre"`)
  - Tiers sans don → null

**GREEN**:
- Créer `app/Services/Portail/Providers/MesDonsProvider.php`.
- Critère « a un don » : aligner avec ce qu'utilise `TiersDonsTimelineService::countTotal()` (ou méthode équivalente). Si pas de méthode `countTotal` exposée, utiliser le critère `Don::query()->where('tiers_id', $tiers->id)->exists()` ou — si la table `dons` n'existe pas en tant que telle — utiliser le critère canonical de comptage de dons défini dans `TiersDonsTimelineService` (probablement Transaction::query() avec une jointure sur sous-catégorie de type don). **À aligner précisément en build** sur la query déjà utilisée par le service Timeline pour rester cohérent (un don visible dans Timeline = un don visible côté provider).
- Enregistrer dans `PortailServiceProvider`.

**REFACTOR**: si la query est complexe, factoriser en méthode statique `TiersDonsTimelineService::tiersAUnDon(Tiers $tiers): bool` ou utiliser une méthode existante.

**Files**:
- `app/Services/Portail/Providers/MesDonsProvider.php`
- `app/Providers/PortailServiceProvider.php`
- `tests/Unit/Portail/Providers/MesDonsProviderTest.php`
- éventuellement `app/Services/Tiers/TiersDonsTimelineService.php` (ajout d'un helper boolean)

**Commit**: `feat(portail): provider sidebar Mes dons (visible si ≥1 don)`

---

### Step 4: Composant Livewire `MesAdhesions` (complet — UI + sécurité + reçu)

**Complexity**: complex (sécurité — téléchargement reçu + intrusion test + génération à la demande)

**RED**:
- `tests/Feature/Portail/MesAdhesionsTest.php` couvre :
  1. **Affichage tri + statut** : Tiers avec 3 adhésions → tri date_fin desc, badge « À jour » sur celle dont date_fin >= today, « Expirée » sur les autres.
  2. **CTA Renouveler — URL spécifique** : `url_renouvellement_adhesion` configuré → bouton avec href correct.
  3. **CTA Renouveler — fallback URL site web** : `url_renouvellement_adhesion` null + `url_site_web` configuré → bouton avec href = url_site_web.
  4. **CTA Renouveler — caché** : les deux URL null → aucun bouton dans la page.
  5. **Bouton reçu — déjà émis** : `RecuFiscalEmis` existant pour l'adhésion → clic via `Livewire::test(...)->call('telechargerRecuCotisation', $adhesionId)` → response renvoyée par `streamPdf` (Content-Type application/pdf), pas de nouveau RecuFiscalEmis créé.
  6. **Bouton reçu — génération à la demande** : adhésion éligible sans reçu → clic crée le RecuFiscalEmis en base + retourne le PDF.
  7. **Bouton reçu — caché si non éligible** : `Association::eligible_recu_fiscal = false` → aucun bouton « Télécharger le reçu » sur aucune ligne.
  8. **Bouton reçu — caché individuellement** : adhésion sans `transaction_id` (gratuite) → bouton caché sur cette ligne, présent sur les autres éligibles.
- `tests/Feature/Portail/MesAdhesionsSecurityTest.php` couvre :
  9. **Intrusion intra-asso** : Alice connectée appelle `telechargerRecuCotisation($bobAdhesionId)` (Bob = autre Tiers même asso) → `abort(403)`.
  10. **Intrusion cross-tenant** : Alice asso A connectée tente l'ID d'une adhésion asso B → 404 ou 403 (TenantScope filtre).
  11. **Logger** : appel `telechargerRecuCotisation` éligible émet `Log::info('portail.recu.cotisation.telecharge', ['adhesion_id' => ..., 'tiers_id' => ...])` (assert via `Log::spy()`).
- Run filter, paste failing output (composant n'existe pas).

**GREEN**:
- Créer `app/Livewire/Portail/MesAdhesions.php` :
  - Trait `WithPortailTenant`. Mount `Association`. Layout = `portail.layouts.authenticated`.
  - `render()` : récupère le Tiers connecté, charge les adhésions via `Adhesion::query()->where('tiers_id', $tiers->id)->orderByDesc('date_fin')->get()` (TenantScope global filtre asso). Précalcule l'éligibilité de chaque ligne via `try { app(RecuFiscalService::class)->validerEligibiliteAdhesion($a); $eligible[$a->id] = true; } catch (\Throwable) { $eligible[$a->id] = false; }`. Passe à la vue.
  - `telechargerRecuCotisation(int $adhesionId)` :
    ```php
    $tiers = Auth::guard('tiers-portail')->user();
    abort_unless($tiers, 403);
    $adhesion = Adhesion::find($adhesionId);
    abort_unless($adhesion !== null, 404);
    abort_unless($adhesion->tiers_id === (int) $tiers->id, 403);
    try {
        $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion);
    } catch (\App\Exceptions\RecuFiscalException $e) {
        $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        return;
    }
    Log::info('portail.recu.cotisation.telecharge', ['adhesion_id' => $adhesion->id, 'tiers_id' => $tiers->id]);
    return app(RecuFiscalService::class)->streamPdf($recu);
    ```
  - **Cast `(int)` strict des deux côtés** (préférence projet `feedback_int_cast_prod.md`).
- Créer vue `resources/views/livewire/portail/mes-adhesions.blade.php` :
  - H4 « Mes adhésions »
  - Bouton CTA en haut à droite si `$portailAssociation->urlRenouvellementAdhesion()` non null
  - Tableau Bootstrap (header `table-dark` + style projet) avec colonnes : Date début / Date fin / Formule / Montant / Statut (badge `bg-success` ou `bg-secondary`) / Action (`wire:click="telechargerRecuCotisation({{ $a->id }})"` si `$eligible[$a->id]`)
- Ajouter la route dans `routes/portail.php` dans le groupe post-auth :
  ```php
  Route::get('/mes-adhesions', MesAdhesions::class)->name('mes-adhesions');
  ```
- (Mode mono routes mirror = Step 6.)

**REFACTOR**: si beaucoup de logique dans `telechargerRecuCotisation`, extraire le check ownership en méthode privée. Conserver simple.

**Files**:
- `app/Livewire/Portail/MesAdhesions.php`
- `resources/views/livewire/portail/mes-adhesions.blade.php`
- `routes/portail.php` (1 ligne ajoutée)
- `tests/Feature/Portail/MesAdhesionsTest.php`
- `tests/Feature/Portail/MesAdhesionsSecurityTest.php`

**Commit**: `feat(portail): écran Mes adhésions avec téléchargement reçu cotisation à la demande`

---

### Step 5: Composant Livewire `MesDons` (complet — UI + sécurité + reçu fiscal)

**Complexity**: complex (sécurité — téléchargement reçu fiscal + intrusion + génération)

**RED**:
- `tests/Feature/Portail/MesDonsTest.php` couvre :
  1. **Regroupement par année civile desc + total** : 4 dons sur 3 années → 3 sections année avec total correct par section.
  2. **CTA Nouveau don — URL spécifique** : `url_nouveau_don` configuré → bouton avec href correct.
  3. **CTA Nouveau don — fallback url_site_web** : `url_nouveau_don` null + `url_site_web` configuré → fallback.
  4. **CTA Nouveau don — caché** : les deux URL null → bouton absent.
  5. **Bouton reçu fiscal — déjà émis** : un don a déjà un `RecuFiscalEmis` → clic stream le PDF existant, pas de doublon.
  6. **Bouton reçu fiscal — génération à la demande** : don éligible sans reçu → clic crée + stream.
  7. **Bouton reçu fiscal — caché si non éligible** : asso non éligible OU don sans signataire OU montant ≤ 0 → bouton absent sur cette ligne.
- `tests/Feature/Portail/MesDonsSecurityTest.php` couvre :
  8. **Intrusion intra-asso** : Alice tente `telechargerRecuFiscal($bobLigneId)` → 403.
  9. **Cross-tenant** : Alice asso A tente reçu d'asso B → 403/404.
  10. **Logger** : émission `portail.recu.fiscal.telecharge`.
- Run filter, paste failing.

**GREEN**:
- Créer `app/Livewire/Portail/MesDons.php` :
  - Layout `portail.layouts.authenticated`. Trait `WithPortailTenant`.
  - `render()` : récupère les dons du Tiers via `TiersDonsTimelineService::forTiers($tiers)` qui retourne déjà un DTO groupé par année (à confirmer en build via lecture du DTO). Précalcule éligibilité par ligne via `validerEligibilite(TransactionLigne)`.
  - `telechargerRecuFiscal(int $transactionLigneId)` :
    ```php
    $tiers = Auth::guard('tiers-portail')->user();
    abort_unless($tiers, 403);
    $ligne = TransactionLigne::find($transactionLigneId);
    abort_unless($ligne !== null, 404);
    // Ownership : la ligne appartient à une transaction dont le tiers_beneficiaire = $tiers (ou logique équivalente — à aligner sur le critère utilisé par TiersDonsTimelineService)
    abort_unless($this->ligneAppartientAuTiers($ligne, $tiers), 403);
    try {
        $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    } catch (\App\Exceptions\RecuFiscalException $e) {
        $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        return;
    }
    Log::info('portail.recu.fiscal.telecharge', ['ligne_id' => $ligne->id, 'tiers_id' => $tiers->id]);
    return app(RecuFiscalService::class)->streamPdf($recu);
    ```
  - Méthode privée `ligneAppartientAuTiers(TransactionLigne, Tiers): bool` — aligne sur le critère exact utilisé par `TiersDonsTimelineService` pour décider qu'une ligne est un don du tiers (probablement via la transaction parente et son `tiers_beneficiaire_id`). À déterminer en build par lecture du service.
- Créer vue `resources/views/livewire/portail/mes-dons.blade.php` :
  - H4 « Mes dons »
  - Bouton CTA si `$portailAssociation->urlNouveauDon()` non null
  - Pour chaque année (desc) : section avec heading « 20XX » + total annuel à droite, puis tableau (date / montant / sous-catégorie / action télécharger reçu si `$eligible[$ligne->id]`)
- Ajouter la route dans `routes/portail.php` :
  ```php
  Route::get('/mes-dons', MesDons::class)->name('mes-dons');
  ```

**REFACTOR**: factoriser la logique d'ownership si dupliquée avec MesAdhesions ; conserver simple sinon.

**Files**:
- `app/Livewire/Portail/MesDons.php`
- `resources/views/livewire/portail/mes-dons.blade.php`
- `routes/portail.php`
- `tests/Feature/Portail/MesDonsTest.php`
- `tests/Feature/Portail/MesDonsSecurityTest.php`

**Commit**: `feat(portail): écran Mes dons avec téléchargement reçu fiscal à la demande`

---

### Step 6: Mode mono — routes miroir + tests parité

**Complexity**: standard
**RED**:
- `tests/Feature/Portail/MonoMesAdhesionsEtDonsTest.php` couvre :
  1. Mode mono actif + Tiers connecté GET `/portail/mes-adhesions` → 200, contenu identique (même listing, même CTA logic, même boutons reçus).
  2. Mode mono actif + Tiers connecté GET `/portail/mes-dons` → 200, contenu identique.
  3. Téléchargement reçu cotisation depuis mode mono → fonctionne.
  4. Téléchargement reçu fiscal depuis mode mono → fonctionne.
- Pattern réutilisable : voir `tests/Feature/Portail/MonoTableauDeBordEtMonProfilTest.php` (slice 1).

**GREEN**:
- Modifier `routes/portail-mono.php` : ajouter dans le groupe post-auth :
  ```php
  Route::get('/mes-adhesions', MesAdhesions::class)->name('mes-adhesions');
  Route::get('/mes-dons', MesDons::class)->name('mes-dons');
  ```
  Names complets : `portail.mono.mes-adhesions`, `portail.mono.mes-dons`.

**REFACTOR**: aucun.

**Files**:
- `routes/portail-mono.php`
- `tests/Feature/Portail/MonoMesAdhesionsEtDonsTest.php`

**Commit**: `feat(portail): mode mono — parité routes mes-adhesions + mes-dons`

---

### Step 7: Documentation

**Complexity**: trivial
**RED**: N/A (doc only)
**GREEN**: Mettre à jour `docs/portail-tiers.md` :
- Nouvelle section « Slice 2 (B) — Mes adhésions + Mes dons (2026-05-14) » courte avec lien vers la spec.
- Mettre à jour le tableau des providers fondation pour inclure les 2 nouveaux (Mes adhésions ordre 60, Mes dons ordre 70, groupe « Ma vie de membre »).
- Documenter le nouveau pattern « Téléchargement reçu à la demande » (référence aux 2 méthodes Livewire `telechargerRecuCotisation` / `telechargerRecuFiscal` + service `RecuFiscalService::obtenirOuGenerer*`).
- Documenter les 3 nouveaux champs URL Association + le helper `urlRenouvellementAdhesion()` / `urlNouveauDon()` avec fallback.
- Procédure admin : « Configurer les URLs externes » (où ces champs apparaissent dans le back-office Paramètres asso — à vérifier ou flagger comme dette si pas encore exposé en UI back-office).

**REFACTOR**: aucun.

**Files**: `docs/portail-tiers.md`

**Commit**: `docs(portail): documenter slice 2 (Mes adhésions + Mes dons + URLs paramétrables)`

---

## Complexity Classification

| Step | Complexity |
|------|-----------|
| 1 — Migration + helpers Association | standard |
| 2 — Provider Mes adhésions | standard |
| 3 — Provider Mes dons | standard |
| 4 — Composant MesAdhesions (UI + sécurité + reçu) | **complex** (sécurité + génération PDF + intrusion test) |
| 5 — Composant MesDons (UI + sécurité + reçu fiscal) | **complex** (idem) |
| 6 — Mode mono | standard |
| 7 — Documentation | trivial |

## Pre-PR Quality Gate

- [ ] Suite Pest verte complète (objectif ~530+ tests Portail / 0 failure)
- [ ] `./vendor/bin/sail bin pint` clean
- [ ] Larastan baseline inchangée
- [ ] `/code-review --changed` passe sur le diff vs `main`
- [ ] Test manuel localhost (port 80) :
  - Ouvrir `/{slug}/portail/mes-adhesions` avec un Tiers ayant des adhésions → vérifier tri, statut, bouton reçu
  - Ouvrir `/{slug}/portail/mes-dons` → regroupement par année, total, bouton reçu
  - Tester le clic « Télécharger le reçu » sur une adhésion sans reçu existant → fichier PDF correct
  - Tester le rendu CTA selon configuration des 3 URLs Association (configurer / vider via tinker)
  - Vérifier qu'un Tiers sans adhésion ni don ne voit pas les 2 onglets en sidebar
  - **Performance** (perception manuelle) : first paint < 1s sur les 2 écrans, génération reçu < 3s en local
- [ ] `docs/portail-tiers.md` à jour

## Risks & Open Questions

| # | Risque / Question | Mitigation / Réponse |
| - | ----------------- | -------------------- |
| 1 | Ownership précis d'un don : la `TransactionLigne` est-elle directement attribuable à un Tiers via une colonne explicite, ou via la transaction parente et son tiers_beneficiaire_id ? Le critère doit être identique entre l'affichage (Timeline service) et le téléchargement (ownership check). | Step 5 : lire `TiersDonsTimelineService` pour identifier le critère exact, le réutiliser dans la méthode privée `ligneAppartientAuTiers()`. Test d'intrusion vérifie l'isolation. |
| 2 | Critère « Tiers a au moins 1 don » pour le provider : aligner avec le critère du Timeline service. | Step 3 : utiliser le même filtre, idéalement via méthode du service. Si pas exposé : ajouter méthode helper. |
| 3 | Position et UI des 3 nouveaux champs URL Association dans le back-office Paramètres : pas couvert ici. Si non exposé en UI back-office, l'admin devra passer par tinker. | Step 7 : flagger en doc + dette pour un mini-écran Paramètres dans un slice ultérieur si urgent. |
| 4 | `RecuFiscalException` dispatch toast : Livewire `$this->dispatch('toast', ...)` doit être écouté par un component layout. Vérifier qu'un système de toast existe déjà côté portail ou utiliser une session flash à la place. | Step 4/5 : vérifier l'existant ; si pas de toast, fallback sur `session()->flash('portail.error', ...)` qui s'affiche déjà via le layout. |
| 5 | Sécurité — la query `Adhesion::query()->where('tiers_id', $tiers->id)` côté Livewire bypasse-t-elle le mass assignment ? Non — c'est une query, pas un fill. Ownership vérifié côté `telechargerRecuCotisation`. | Step 4 : test d'intrusion explicite via `Livewire::test(...)->call('telechargerRecuCotisation', $bobId)` confirme l'isolation. |
| 6 | Pattern Pest pour assert response Livewire = streamDownload : Livewire ne retourne pas directement une `Response` sur `call()` — il faut un wrapping particulier. | Build : confirmer via doc Livewire 3 ou tester la signature. À défaut, créer un test feature classique HTTP qui fait POST à l'action via la route Livewire. |
| 7 | Cas spécial : Tiers `pour_depenses=true` cumulant adhésion + dons + NDF + factures → la sidebar peut devenir longue. Acceptable v0 ; à monitorer. | Hors scope. Pas d'action. |
| 8 | Migration sur prod : 3 colonnes nullable, no-op fonctionnel — safe à shipper. | Aucune mitigation requise. |

## Notes d'exécution

- **Mode subagent-driven** (préférence projet) — Opus planifie, Sonnet exécute.
- **Inline review checkpoints** sur les steps complex (4 et 5) : security-review minimum sur le téléchargement de reçu et l'intrusion test.
- **Branche unique** : on continue sur `feat/portail-membres-slice1-fondation-profil` (option B git actée). Pas de merge intermédiaire vers main.
- **Pas de push prod** : test local d'abord (préférence projet `feedback_test_before_push.md`).
- **Cast (int) strict des deux côtés** dans tous les `===` PK/FK (préférence projet `feedback_int_cast_prod.md`).

## Estimation

7 steps total, dont 5 « standard » (1, 2, 3, 6, 7) et 2 « complex » (4, 5). Slice plus court que slice 1 (12 steps) parce qu'on capitalise sur la fondation slice 1 et les services métier déjà existants (`TiersAdhesionTimelineService`, `RecuFiscalService`).
