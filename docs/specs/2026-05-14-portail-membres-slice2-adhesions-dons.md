# Portail membres et participants — Slice 2 : Mes adhésions + Mes dons

- **Date** : 2026-05-14
- **Programme** : Portail membres et participants
- **Slice** : 2 / 4 — Mes adhésions + Mes dons (avec téléchargement reçus à la demande, URLs paramétrables)
- **Branche cible** : `feat/portail-membres-slice1-fondation-profil` (option B git actée — toutes les slices sur une seule branche, MEP groupée)
- **Dépend de** : Slice 1 (F+A) implemented (commit `f8b18cb1` + 14 commits précédents)

## Pivot UX vs cadrage initial

Décision actée 2026-05-14 : on **abandonne** l'idée d'un onglet « Mes documents » global. Les documents sont proposés inline dans l'onglet métier où ils sont contextuellement pertinents (reçu de cotisation dans Adhésions, reçu fiscal dans Dons, etc.). Ce slice livre cette logique pour Adhésions + Dons. Les opérations (slice 3) suivront le même pattern.

## Décisions actées en cadrage (pré-spec)

1. **Tri adhésions** : par `date_fin` desc (la date de fin fait foi pour l'expiration, plus parlant que date_debut).
2. **Statut adhésion** : enum binaire affiché à l'écran : « À jour » (date_fin >= aujourd'hui) / « Expirée » (date_fin < aujourd'hui). Pas d'état « En attente ».
3. **URLs paramétrage Association** : 3 colonnes nullable à ajouter sur la table `association` (singulier dans le schéma) :
   - `url_site_web` — site web global de l'asso (sera réutilisé en fallback ailleurs : footer email, etc.)
   - `url_renouvellement_adhesion` — URL externe (typiquement HelloAsso) pour renouveler
   - `url_nouveau_don` — URL externe pour faire un nouveau don
   - **Fallback** : si `url_renouvellement_adhesion` ou `url_nouveau_don` est null, on retombe sur `url_site_web`. Si les 3 sont null, le bouton CTA correspondant est **caché**.
4. **Reçu cotisation à la demande** : bouton de téléchargement présent sur chaque ligne adhésion **éligible**. Si le reçu n'a pas encore été émis, le clic le **génère puis le télécharge** (pas de problème métier — le service `RecuFiscalService::obtenirOuGenererPourAdhesion(Adhesion)` existe déjà et est utilisé en back-office, cf. `app/Livewire/Tiers/Onglets/Adhesion.php:83`).
5. **Reçu fiscal don** : même pattern — `RecuFiscalService::obtenirOuGenerer(TransactionLigne)`. Pas de récap annuel agrégé (parqué pour phase 2 reçu fiscal).
6. **Don anonyme** : le concept n'existe pas dans AgoraGestion. Tous les dons sont rattachés à un Tiers identifié. Question retirée.
7. **Dons regroupés par année civile desc**, total annuel affiché en en-tête de groupe.
8. **Pas d'inscription nouvelle / pas de modification** depuis le portail (consultation seule, conforme cadrage initial du programme).

## Hypothèses techniques vérifiées

| Item | État | Source |
| ---- | ---- | ------ |
| `TiersAdhesionTimelineService::forTiers($tiers)` | ✓ existe (slice 8 fiche tiers livré v4.3.1) | `app/Services/Tiers/TiersAdhesionTimelineService.php` |
| `TiersDonsTimelineService::forTiers($tiers)` | ✓ existe | `app/Services/Tiers/TiersDonsTimelineService.php` |
| `RecuFiscalService::obtenirOuGenerer(TransactionLigne)` | ✓ existe | `app/Services/RecuFiscalService.php:66` |
| `RecuFiscalService::obtenirOuGenererPourAdhesion(Adhesion)` | ✓ existe | `app/Services/RecuFiscalService.php:119` |
| `RecuFiscalService::validerEligibilite*` | ✓ existe (lève exception si non éligible — appelable à blanc pour décider d'afficher/cacher le bouton) | `app/Services/RecuFiscalService.php:24, 127` |
| `RecuFiscalService::streamPdf(RecuFiscalEmis)` | ✓ existe | `app/Services/RecuFiscalService.php:165` |
| Table `association` (singulier) | ✓ confirmé | migrations existantes |
| Aucune colonne URL existante sur `association` | ✓ confirmé (à créer) | `app/Models/Association.php` |
| Sidebar resolver/registry | ✓ existe (slice 1) — il suffit d'enregistrer 2 nouveaux providers | `app/Providers/PortailServiceProvider.php` |
| Layout `portail.layouts.authenticated` | ✓ existe (slice 1) | `resources/views/portail/layouts/authenticated.blade.php` |

---

## 1. Intent Description

**Objectif** : étendre le portail membres avec deux nouvelles sections visibles dans la sidebar — « Mes adhésions » et « Mes dons » — qui permettent à un Tiers identifié (a) de consulter l'historique de ses contributions, (b) de télécharger les reçus correspondants à la demande quand il y est éligible, et (c) d'aller renouveler son adhésion ou faire un nouveau don via les liens externes paramétrés par l'association.

**Pourquoi maintenant** : ce sont les premières « vraies » sections métier après la fondation du slice 1. Validation de bout en bout du pattern resolver/registry + intégration aux services réutilisables (`TiersAdhesionTimelineService`, `TiersDonsTimelineService`) + pattern « obtient ou génère » du `RecuFiscalService` côté portail.

**Frontière** :
- Pas de modification d'adhésion ni de don depuis le portail (consultation seule)
- Pas de récap annuel reçu fiscal agrégé (phase 2 du programme reçu fiscal)
- Pas de notification email (rappel adhésion expirante, etc. — hors scope programme)
- Pas de gestion des dons anonymes (concept inexistant)
- Pas d'évolution du back-office reçus (statu quo)

**Acceptance** : un membre identifié voit ses adhésions par ordre de date de fin desc avec leur statut (À jour / Expirée), peut télécharger le reçu de cotisation par clic (génération à la demande si pas encore émis), peut consulter ses dons par année civile avec total annuel et télécharger les reçus fiscaux unitaires. Boutons CTA externes affichés si URL configurée, masqués sinon. Sidebar n'affiche les onglets que si le Tiers a au moins 1 ligne dans le domaine.

## 2. User-Facing Behavior (Gherkin)

```gherkin
Fonctionnalité: Portail membres — Mes adhésions et Mes dons

Contexte:
  Étant donné une association "MonAsso" avec slug "monasso"
  Et un Tiers identifié "Alice Martin" rattaché à cette association

# ============================================================
# SIDEBAR — visibilité conditionnelle (resolver pattern slice 1)
# ============================================================

Scénario: Sidebar — aucune adhésion, aucun don
  Étant donné Alice n'a aucune adhésion ni aucun don
  Quand Alice se connecte au portail
  Alors la sidebar n'affiche pas "Mes adhésions" ni "Mes dons"

Scénario: Sidebar — au moins une adhésion
  Étant donné Alice a au moins une adhésion existante
  Quand Alice se connecte au portail
  Alors la sidebar affiche "Mes adhésions"

Scénario: Sidebar — au moins un don
  Étant donné Alice a au moins un don rattaché
  Quand Alice se connecte au portail
  Alors la sidebar affiche "Mes dons"

# ============================================================
# MES ADHÉSIONS
# ============================================================

Scénario: Mes adhésions — tri et statut
  Étant donné Alice a 3 adhésions :
    | formule       | date_debut | date_fin   |
    | Adhérent 2024 | 2024-09-01 | 2025-08-31 |
    | Adhérent 2025 | 2025-09-01 | 2026-08-31 |
    | Adhérent 2023 | 2023-09-01 | 2024-08-31 |
  Quand Alice ouvre /portail/mes-adhesions
  Alors les adhésions sont listées dans l'ordre :
    | 1 | Adhérent 2025 | À jour    |
    | 2 | Adhérent 2024 | Expirée   |
    | 3 | Adhérent 2023 | Expirée   |
  Et le statut "À jour" est calculé par date_fin >= today, "Expirée" sinon

Scénario: Mes adhésions — bouton renouveler avec URL configurée
  Étant donné l'association MonAsso a `url_renouvellement_adhesion = "https://helloasso.com/monasso/adhesion-2026"`
  Quand Alice ouvre /portail/mes-adhesions
  Alors un bouton CTA "Renouveler mon adhésion" pointe vers cette URL en cible blanche

Scénario: Mes adhésions — fallback URL site web
  Étant donné l'association n'a pas `url_renouvellement_adhesion` mais a `url_site_web = "https://monasso.fr"`
  Quand Alice ouvre /portail/mes-adhesions
  Alors le bouton CTA "Renouveler mon adhésion" pointe vers https://monasso.fr

Scénario: Mes adhésions — aucun bouton si aucune URL
  Étant donné l'association n'a ni `url_renouvellement_adhesion` ni `url_site_web`
  Quand Alice ouvre /portail/mes-adhesions
  Alors aucun bouton CTA "Renouveler mon adhésion" n'est visible

Scénario: Mes adhésions — bouton télécharger reçu cotisation (déjà émis)
  Étant donné Alice a une adhésion "Adhérent 2025" éligible reçu cotisation
  Et un RecuFiscalEmis existe déjà pour cette adhésion
  Quand Alice clique "Télécharger le reçu" sur cette ligne
  Alors le PDF est téléchargé via streamPdf
  Et aucun nouveau RecuFiscalEmis n'est créé en base

Scénario: Mes adhésions — bouton télécharger reçu cotisation (génération à la demande)
  Étant donné Alice a une adhésion éligible reçu cotisation
  Et aucun RecuFiscalEmis n'existe pour cette adhésion
  Quand Alice clique "Télécharger le reçu"
  Alors le service obtientOuGenererPourAdhesion crée un RecuFiscalEmis
  Et le PDF est téléchargé immédiatement

Scénario: Mes adhésions — adhésion non éligible reçu (asso non éligible)
  Étant donné l'association a `eligible_recu_fiscal = false`
  Quand Alice ouvre /portail/mes-adhesions
  Alors aucun bouton "Télécharger le reçu" n'est visible (pour aucune adhésion)

Scénario: Mes adhésions — adhésion non éligible reçu (cas individuel)
  Étant donné Alice a une adhésion qui ne passe pas validerEligibiliteAdhesion
  Quand Alice ouvre /portail/mes-adhesions
  Alors le bouton "Télécharger le reçu" n'est pas visible sur cette ligne précise
  Mais les autres adhésions éligibles conservent leur bouton

# ============================================================
# MES DONS
# ============================================================

Scénario: Mes dons — regroupement par année civile desc avec total
  Étant donné Alice a 4 dons :
    | date       | montant |
    | 2026-02-10 |   50 €  |
    | 2025-12-31 |  100 €  |
    | 2025-06-15 |   30 €  |
    | 2024-11-01 |   40 €  |
  Quand Alice ouvre /portail/mes-dons
  Alors les dons sont regroupés par année civile desc :
    | 2026 | total 50 €  | 1 ligne  |
    | 2025 | total 130 € | 2 lignes |
    | 2024 | total 40 €  | 1 ligne  |

Scénario: Mes dons — bouton télécharger reçu fiscal (déjà émis)
  Étant donné un don d'Alice a déjà un RecuFiscalEmis
  Quand Alice clique "Télécharger le reçu"
  Alors le PDF existant est streamé sans création nouvelle

Scénario: Mes dons — génération à la demande
  Étant donné un don d'Alice est éligible mais sans reçu émis
  Quand Alice clique "Télécharger le reçu"
  Alors obtenirOuGenerer crée le RecuFiscalEmis et stream le PDF

Scénario: Mes dons — CTA nouveau don avec fallback URL site web
  Étant donné `url_nouveau_don` est null mais `url_site_web` est configuré
  Quand Alice ouvre /portail/mes-dons
  Alors un bouton CTA "Faire un nouveau don" pointe vers `url_site_web`

Scénario: Mes dons — aucun bouton CTA si aucune URL
  Étant donné `url_nouveau_don` et `url_site_web` sont null
  Quand Alice ouvre /portail/mes-dons
  Alors aucun bouton CTA "Faire un nouveau don" n'est visible

# ============================================================
# SÉCURITÉ
# ============================================================

Scénario: Téléchargement reçu — Tiers ne peut accéder au reçu d'un autre Tiers
  Étant donné Alice est connectée et son Tiers a 0 don
  Et Bob (autre Tiers même asso) a un RecuFiscalEmis avec id=42
  Quand Alice tente d'accéder à /portail/mes-dons/recus/42 (URL forgée)
  Alors elle reçoit 403 ou redirect

Scénario: Isolation multi-tenant
  Étant donné Alice (asso A) est connectée
  Et un RecuFiscalEmis appartient à un Tiers de l'asso B
  Quand Alice tente l'URL forgée pour ce reçu
  Alors elle reçoit 403/404 (TenantScope)

Scénario: Mode mono — parité
  Étant donné le mode mono est actif
  Et Alice est connectée au portail
  Quand elle ouvre /portail/mes-adhesions
  Alors la page fonctionne identiquement au mode multi-tenant (même contenu, mêmes règles)

# ============================================================
# RÉGRESSION
# ============================================================

Scénario: Pas de régression slice 1
  Étant donné Alice est connectée
  Quand elle visite Tableau de bord, Mon profil, NDF (si applicable), FP (si applicable), Historique (si applicable)
  Alors toutes ces pages restent fonctionnelles à l'identique
```

## 3. Architecture Specification

### Migrations

`database/migrations/2026_05_14_xxxxxx_add_url_fields_to_association.php` :

```php
Schema::table('association', function (Blueprint $table) {
    $table->string('url_site_web', 255)->nullable()->after(...);
    $table->string('url_renouvellement_adhesion', 255)->nullable()->after('url_site_web');
    $table->string('url_nouveau_don', 255)->nullable()->after('url_renouvellement_adhesion');
});
```

(L'ordre `after(...)` à ajuster en fonction du schéma actuel — placer dans la zone des paramètres asso, pas en fin de table.)

### Modèle `Association`

- Ajouter les 3 nouveaux champs à `$fillable`
- Pas de cast nécessaire (string nullable)
- Méthode helper proposée : `urlRenouvellementAdhesion(): ?string` — retourne `$this->url_renouvellement_adhesion ?? $this->url_site_web` (ou null si les deux le sont). Idem `urlNouveauDon(): ?string`. Garde la logique de fallback hors des Blade.

### Composants Livewire (nouveaux)

| Classe | Layout | Rôle |
| ------ | ------ | ---- |
| `App\Livewire\Portail\MesAdhesions` | `portail.layouts.authenticated` | Liste adhésions desc + statut + bouton reçu inline + CTA renouveler |
| `App\Livewire\Portail\MesDons` | `portail.layouts.authenticated` | Liste dons groupés par année + total annuel + bouton reçu inline + CTA nouveau don |

Méthodes Livewire publiques :
- `MesAdhesions::telechargerRecuCotisation(int $adhesionId)` — vérifie ownership Tiers connecté, appelle `RecuFiscalService::obtenirOuGenererPourAdhesion`, retourne `streamDownload(...)`. Dispatch un `Log::info('portail.recu.cotisation.telecharge', ['adhesion_id' => ..., 'tiers_id' => ...])`.
- `MesDons::telechargerRecuFiscal(int $transactionLigneId)` — pareil avec `obtenirOuGenerer`.

### Providers sidebar (nouveaux)

| Classe | id | label | route | icon | ordre | groupe | visible si |
| ------ | -- | ----- | ----- | ---- | ----- | ------ | ---------- |
| `App\Services\Portail\Providers\MesAdhesionsProvider` | `mes-adhesions` | Mes adhésions | `portail.mes-adhesions` | `bi-card-checklist` | 60 | Ma vie de membre | ≥ 1 adhésion pour ce Tiers |
| `App\Services\Portail\Providers\MesDonsProvider` | `mes-dons` | Mes dons | `portail.mes-dons` | `bi-gift` | 70 | Ma vie de membre | ≥ 1 don pour ce Tiers (compté via `Transaction` ou `TransactionLigne` selon le critère métier déjà retenu dans `TiersDonsTimelineService::countTotal()`) |

Enregistrement dans `App\Providers\PortailServiceProvider::boot()` (à étendre).

**Nouveau groupe sidebar** : « Ma vie de membre » (entre « Espace personnel » ordre 10-20 et « Mes frais & factures » ordre 30-50). Ajustement de l'ordre des providers existants si besoin pour intercaler proprement, ou ordre 25 sur le label pour mettre la section avant les frais.

### Routes

Ajouter dans `routes/portail.php` (groupe post-auth `EnsureTiersChosen + EnforceSessionLifetime + Authenticate`) :
```php
Route::get('/mes-adhesions', MesAdhesions::class)->name('mes-adhesions');
Route::get('/mes-dons', MesDons::class)->name('mes-dons');
```

Mirror dans `routes/portail-mono.php` avec name `portail.mono.mes-adhesions` / `mes-dons`.

### Sécurité — téléchargement reçu

Le téléchargement passe par une **action Livewire** sur le composant (pas par un controller PDF dédié), ce qui simplifie la garde : le composant tourne sous la garde `tiers-portail` + `Authenticate` middleware. Vérifications dans la méthode :

```php
public function telechargerRecuCotisation(int $adhesionId)
{
    $tiers = Auth::guard('tiers-portail')->user();
    abort_unless($tiers, 403);

    $adhesion = Adhesion::findOrFail($adhesionId);

    // Defense in depth : vérifier ownership Tiers (TenantScope filtre déjà association_id)
    abort_unless($adhesion->tiers_id === $tiers->id, 403);

    try {
        $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion);
    } catch (\App\Exceptions\RecuFiscalException $e) {
        $this->dispatch('toast', message: 'Reçu non disponible : ' . $e->getMessage(), type: 'error');
        return;
    }

    Log::info('portail.recu.cotisation.telecharge', ['adhesion_id' => $adhesion->id, 'tiers_id' => $tiers->id]);

    return response()->streamDownload(
        fn () => echo file_get_contents(/* ... */),
        "recu-cotisation-{$recu->numero}.pdf",
        ['Content-Type' => 'application/pdf']
    );
}
```

Note : la méthode exacte de stream sera adaptée à `RecuFiscalService::streamPdf()` qui retourne déjà un `Response`. Si streamPdf retourne `Response`, on peut simplement `return $service->streamPdf($recu)` depuis l'action Livewire.

### Affichage du bouton « Télécharger le reçu »

Pour décider d'afficher/cacher le bouton, on précalcule l'éligibilité côté `render()` du composant :

```php
// Dans MesAdhesions::render()
$adhesionsEligibles = collect($adhesions)->mapWithKeys(function ($a) use ($service) {
    try {
        $service->validerEligibiliteAdhesion($a);
        return [$a->id => true];
    } catch (\Throwable) {
        return [$a->id => false];
    }
});
```

Ce précalcul est O(n) sans I/O lourd (validation = vérif de champs). Acceptable pour des listes < 50 lignes (cas usuel).

### Vues

`resources/views/livewire/portail/mes-adhesions.blade.php` :
- En-tête : H4 « Mes adhésions »
- CTA renouveler (si URL) en haut à droite
- Tableau (date_fin / formule / montant / statut badge / action télécharger reçu)

`resources/views/livewire/portail/mes-dons.blade.php` :
- En-tête : H4 « Mes dons »
- CTA nouveau don (si URL) en haut à droite
- Pour chaque année (desc) : section avec total + tableau des dons (date / montant / sous-catégorie / action télécharger reçu)

### Tests

| Fichier | Type | Couvre |
| ------- | ---- | ------ |
| `tests/Feature/Portail/MesAdhesionsTest.php` | Feature | Affichage tri/statut, CTA URL/fallback/aucun, bouton reçu déjà émis vs génération à la demande, asso non éligible |
| `tests/Feature/Portail/MesDonsTest.php` | Feature | Regroupement par année + total, bouton reçu, CTA, isolation |
| `tests/Feature/Portail/MesAdhesionsSecurityTest.php` | Feature/sécurité | Tiers Alice ne peut télécharger reçu d'adhésion d'un autre Tiers (asso A) ; Tiers asso A ne peut accéder à reçu asso B (TenantScope) |
| `tests/Feature/Portail/MesDonsSecurityTest.php` | Feature/sécurité | Idem pour reçu fiscal don |
| `tests/Unit/Portail/Providers/MesAdhesionsProviderTest.php` | Unit | Visible si ≥1 adhésion, null sinon |
| `tests/Unit/Portail/Providers/MesDonsProviderTest.php` | Unit | Visible si ≥1 don, null sinon |
| `tests/Feature/Portail/MonoMesAdhesionsEtDonsTest.php` | Feature | Mode mono : routes accessibles + contenu identique |
| `tests/Feature/Portail/RegressionSlice1Test.php` (extension) | Feature | Sidebar slice 1 (Tableau de bord, Mon profil, NDF, FP, Historique) intacte |

## 4. Acceptance Criteria

### Fonctionnels

- [ ] Migration `add_url_fields_to_association` ajoute 3 colonnes nullable (`url_site_web`, `url_renouvellement_adhesion`, `url_nouveau_don`).
- [ ] `Association::urlRenouvellementAdhesion()` et `urlNouveauDon()` retournent l'URL spécifique ou le fallback site web ou null.
- [ ] Sidebar : « Mes adhésions » apparaît ssi le Tiers a ≥ 1 adhésion ; « Mes dons » ssi ≥ 1 don.
- [ ] `/portail/mes-adhesions` liste les adhésions par `date_fin` desc avec statut « À jour » (date_fin >= today) ou « Expirée ».
- [ ] CTA « Renouveler mon adhésion » : visible avec href = `url_renouvellement_adhesion` ?? `url_site_web` ; **caché** si les deux sont null.
- [ ] CTA « Faire un nouveau don » : pareil avec `url_nouveau_don` ?? `url_site_web`.
- [ ] Bouton « Télécharger le reçu » sur ligne adhésion : visible ssi `validerEligibiliteAdhesion` ne lève pas. Clic → `obtenirOuGenererPourAdhesion` → stream PDF.
- [ ] Bouton « Télécharger le reçu » sur ligne don : pareil avec `validerEligibilite(TransactionLigne)` + `obtenirOuGenerer`.
- [ ] `/portail/mes-dons` regroupe les dons par année civile desc avec total annuel par groupe.
- [ ] Si aucune adhésion éligible reçu : aucun bouton de téléchargement (pas d'erreur 500).
- [ ] Génération à la demande : si `RecuFiscalEmis` n'existe pas mais éligible, un nouveau est créé et téléchargé en une transaction.
- [ ] Si `RecuFiscalEmis` existe déjà : pas de doublon créé, le PDF existant est servi.

### Sécurité

- [ ] Tiers Alice ne peut télécharger un reçu rattaché à un autre Tiers (même asso) — test d'intrusion via Livewire `call('telechargerRecuCotisation', $bobAdhesionId)` → 403.
- [ ] Tiers asso A ne peut télécharger un reçu d'un Tiers asso B — TenantScope filtre, test explicite.
- [ ] Mode mono (`portail-mono.php`) expose `/portail/mes-adhesions` et `/portail/mes-dons` avec les mêmes règles.
- [ ] Logger émet `portail.recu.cotisation.telecharge` et `portail.recu.fiscal.telecharge` avec `tiers_id` (LogContext propage `association_id`).

### Régression

- [ ] Sidebar slice 1 inchangée pour les Tiers sans adhésion ni don.
- [ ] Tableau de bord, Mon profil, NDF, FP, Historique restent fonctionnels.
- [ ] Suite Pest verte (objectif : ~530+ tests Portail / 0 failure après ce slice).

### Non-fonctionnels

- [ ] Pint clean.
- [ ] Larastan baseline inchangée.
- [ ] First paint /mes-adhesions et /mes-dons < 800ms en localhost.
- [ ] Génération reçu à la demande : streamPdf < 2s en localhost.

## Consistency Gate

| Item | Verdict |
| ---- | ------- |
| Intent unambigu (deux devs interprètent pareil) | ✓ — frontière scope explicite, livrables énumérés, fallback URL formalisé |
| Chaque comportement intent → ≥ 1 scénario Gherkin | ✓ — sidebar visibilité, tri/statut, CTA URL+fallback+absent, bouton reçu (déjà émis vs génération vs non éligible), isolation multi-tenant, mode mono, régression |
| Architecture contrainte sans over-engineering | ✓ — réutilise services slice 8 fiche tiers (TimelineService) + RecuFiscalService déjà éprouvé en back-office. Pas de nouveau service métier. Migration ciblée 3 colonnes. |
| Naming consistent (« Mes adhésions », « Mes dons », « Ma vie de membre ») | ✓ — utilisé à l'identique dans intent / Gherkin / architecture / AC |
| Pas de contradiction entre artifacts | ✓ — règles d'éligibilité reposent sur les méthodes existantes du service ; fallback URL identique partout ; ordre tri unique (date_fin desc) |

**Verdict global : PASS.**

## Hors scope (parqué)

- Récap fiscal annuel agrégé (un seul reçu pour tous les dons d'une année) — phase 2 du programme reçu fiscal.
- Notification email rappel adhésion expirante — hors scope programme portail v0.
- Bouton « Demander un nouveau reçu » si refusé pour cause d'éligibilité — non, on respecte le pattern : éligible = bouton, sinon rien.
- Édition d'une adhésion ou d'un don depuis le portail — non, consultation seule.
- Statut « En attente de paiement » sur adhésion — pas de cas métier identifié, statu quo binaire À jour / Expirée.
- Historique des règlements partiels d'une adhésion — non, ligne unique par adhésion.
- Vue « famille » (parent voit enfants en agrégé) — déjà parqué slice 1.

## Prochaine étape

`/agentic-dev-team:plan` sur ce slice quand tu valides la spec, puis `/agentic-dev-team:build` (subagent-driven Sonnet) sur la même branche `feat/portail-membres-slice1-fondation-profil`.
