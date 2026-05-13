# Fiche tiers 360 — Slice 0+1 (squelette + onglet Dons)

**Date** : 2026-05-08
**Statut** : PASS — prêt pour /plan
**Branche cible** : `feat/fiche-tiers-360-slice0-1`

## 1. Contexte

Le `TiersQuickView` (modale flottante 560px) tasse trop d'informations dans un espace réduit : summary financier, dons, factures, NDF, devis, communications, transactions. L'utilisateur perd en lisibilité quand un tiers a beaucoup d'historique. Le panneau « Dons » ajouté avec le slice reçu fiscal a accentué ce constat.

Cette spec couvre le **premier run** d'un programme multi-slices qui transforme la modale en page full-screen `/tiers/{tiers}` avec onglets thématiques conditionnels.

## 2. Objectif du slice 0+1

Livrer la **fondation navigable** + le **premier onglet métier** :

- Page Livewire full-screen `/tiers/{tiers}` avec breadcrumb topbar `Tiers / {nom_tiers}`, sans H1.
- Bandeau d'identité minimaliste (nom, type, badges contact).
- Système d'onglets (Bootstrap `nav-tabs`) avec onglet pré-sélectionné via query string.
- **Onglet « Coordonnées »** (toujours présent, fondation read-only).
- **Onglet « Dons »** (conditionnel : présent si ≥1 don pour ce tiers, compteur entre parenthèses).
- Triggers d'arrivée : ligne cliquable + bouton « Voir » (icône `bi-eye`) sur les listes tiers existantes.
- **Coexistence** avec le quick view actuel : il reste opérationnel et est démonté lors du dernier slice du programme.

## 3. Hors scope (slices futurs)

- Onglets : Adhésion, Factures, NDF, Opérations/participations, Communications, Documents.
- Édition (stylo navigant vers `/tiers/{tiers}/edit`).
- Actions : fusion, archivage, envoi email.
- Démontage du quick view + suppression `<x-tiers-info-icon>`.
- Sélecteur d'exercice global (la spec V1 retient une granularité par onglet — voir §6).

## 4. Décisions de conception

### 4.1 Routing
- `GET /tiers/{tiers}` → composant Livewire full-page (route nommée `tiers.show`).
- Onglet via query string : `?onglet=coordonnees|dons` (Livewire `#[Url] public ?string $onglet`).
- Onglet par défaut : `coordonnees`.
- Tiers d'une autre association → 404 (multi-tenant scope `TenantModel` fail-closed).

### 4.2 Pas de H1
- Le breadcrumb topbar fait office de titre (mécanisme existant `app-sidebar.blade.php` ligne 148-185).
- `@section('title', $tiers->displayName())` alimente `$breadcrumbPage`.
- Le groupe « Tiers » est auto-déduit par `request()->routeIs('tiers.*')`.

### 4.3 Bandeau d'identité (sous le breadcrumb, dans le contenu)
- Nom en `fs-5 fw-semibold` (pas un `<h1>`, juste un `<div>`).
- Pictogramme type (`👤` / `🏢`).
- Badges Bootstrap : type, optout newsletter, abonné newsletter, donateur (déduit présence dons).
- Ligne info : email • téléphone • ville (séparateurs `•`).

### 4.4 Onglets — pattern Bootstrap natif
- `<ul class="nav nav-tabs">` → un seul composant Livewire parent qui gère la sélection.
- Chaque onglet = **composant Livewire enfant chargé en `wire:lazy`** (un seul rendu serveur à la fois, pas de N+1 sur les onglets non vus).
- Affichage conditionnel : un onglet sans données n'apparaît pas (sauf « Coordonnées » toujours présent).
- Compteur entre parenthèses sur les onglets volumétriques : « Dons (12) ».

### 4.5 Triggers d'arrivée
- **Ligne cliquable** : `<tr>` avec `style="cursor:pointer"` et `wire:click="goToTiers(id)"` ou `<a>` enrobant. À appliquer sur listes : `tiers.index`, `tiers.dons`, `tiers.cotisations`, `tiers.adherents`, `tiers.communication`.
- **Bouton « Voir »** (icône `bi-eye` info-coloré) dans la colonne actions, en plus des stylos existants.
- Les clics sur les boutons d'action existants (stylos, etc.) doivent **garder** leur comportement (`@click.stop` ou équivalent Livewire) pour ne pas déclencher la navigation ligne.
- L'icône `i` (`<x-tiers-info-icon>` qui ouvre le quick view) **reste inchangée**.

### 4.6 Granularité temporelle (rappel décision)
Pas de sélecteur d'exercice global sur la page. Chaque onglet définit sa propre maille temporelle :
- **Coordonnées** : aucune.
- **Dons** : groupé par **année civile** (cohérence fiscale IFI/CERFA), cumul global en tête, sélecteur année civile optionnel à venir.

Pour les onglets futurs (informatif) : adhésion = multi-exercice ; factures/NDF = exercice ; opérations = chronologique ; communications/documents = chronologique.

## 5. Symétrie portail tiers ↔ fiche back-office

Application de la règle [feedback_symetrie_portail_fiche_tiers.md] :

### 5.1 Onglet Coordonnées
- **État portail actuel** : pas d'écran « Mes coordonnées » dédié (le portail expose actuellement NDF, factures partenaires, indemnités km).
- **Action factorisation** : aucune dans ce slice (rien à factoriser côté portail).
- **Dette d'alignement** : à inscrire dans la mémoire portail comme « onglet Mes coordonnées à créer côté portail ».

### 5.2 Onglet Dons
- **État portail actuel** : le portail expose les reçus fiscaux téléchargeables (slice MVP reçu fiscal du 2026-05-07). La requête de récupération des dons d'un tiers vit actuellement dans `TiersQuickView::render()` lignes 148-160.
- **Action factorisation** : créer `App\Services\Tiers\TiersDonsTimelineService::forTiers(Tiers, ?int $anneeCivile = null): DonsTimelineDTO`. Le service centralise :
  - sélection des `transaction_lignes` filtrées par `usage = Don` via `usages_sous_categories`,
  - agrégation par année civile (`YEAR(tx.date)`),
  - jointure avec `recus_fiscaux_emis` actifs (non annulés),
  - calculs des alertes par ligne (`helloasso`, `donnees_modifiees`),
  - calcul du blocage par ligne (asso éligible, signataire complet, encaissé, adresse complète).
- Le `TiersQuickView::render()` est refactoré pour consommer le même service (pas de duplication pendant la cohabitation).
- **Dette d'alignement** : si le portail expose un jour un onglet « Mes dons » côté donateur (au-delà du téléchargement de reçu déjà en place), il consommera le même service.

## 6. Architecture cible

### 6.1 Arbre de fichiers (slice 0+1)

```
app/
  Livewire/
    Tiers/
      FicheTiers.php                (composant parent, route /tiers/{tiers})
      Onglets/
        Coordonnees.php             (composant enfant lazy)
        Dons.php                    (composant enfant lazy)
  Services/
    Tiers/
      TiersDonsTimelineService.php  (nouveau, partagé)
      DTO/
        DonsTimelineDTO.php         (groupes par année civile, totaux, lignes)
        DonLigneDTO.php             (don + reçu + alertes + blocage)

resources/views/livewire/
  tiers/
    fiche-tiers.blade.php           (bandeau identité + nav-tabs + slot enfant)
    onglets/
      coordonnees.blade.php
      dons.blade.php

routes/web.php                       (+ route tiers.show)

tests/
  Feature/Tiers/
    FicheTiersRoutingTest.php       (URL, breadcrumb, identité, onglet par défaut, query string)
    FicheTiersOngletsConditionnelsTest.php (onglet Dons absent si pas de dons)
    FicheTiersTriggersListesTest.php (ligne cliquable + bouton Voir sur tiers.index)
    FicheTiersTenantSafetyTest.php  (404 si tiers d'une autre asso)
    OngletDonsTest.php              (rendu, cumul, alertes, blocage, téléchargement reçu)
  Unit/Services/Tiers/
    TiersDonsTimelineServiceTest.php (groupage, calculs, alertes, blocage)
  Feature/QuickView/
    TiersQuickViewSmokeTest.php     (régression : quick view fonctionne avec service partagé)
```

### 6.2 Composant parent `FicheTiers`

```php
final class FicheTiers extends Component
{
    public Tiers $tiers;

    #[Url(as: 'onglet')]
    public ?string $onglet = null; // null = défaut "coordonnees"

    public function mount(Tiers $tiers): void { ... }
    public function render(): View { ... }   // calcule onglets disponibles + compteurs
}
```

- Onglets disponibles : tableau `[ ['key' => 'coordonnees', 'label' => 'Coordonnées', 'count' => null], ['key' => 'dons', 'label' => 'Dons', 'count' => 12] ]` (« Dons » filtré si compte = 0).
- La vue rend `<livewire:tiers.onglets.coordonnees :tiers="$tiers" wire:key="..." />` ou `<livewire:tiers.onglets.dons :tiers="$tiers" wire:key="..." />` selon `$onglet`, avec `wire:lazy` sur les enfants.

### 6.3 Composant `Onglets\Dons`

- Reçoit `$tiers` en prop.
- Appelle `TiersDonsTimelineService::forTiers($tiers, $anneeFiltre)`.
- Rend la timeline groupée par année civile.
- Reprend les actions du panneau dons actuel du quick view : télécharger reçu, modale annulation+ré-émission, modale avertissement helloasso/données modifiées (réutilisation des composants/méthodes existants — déplacer ou factoriser ce qui est utilisé par le quick view).

### 6.4 Service `TiersDonsTimelineService`

Signature publique :

```php
public function forTiers(Tiers $tiers, ?int $anneeCivile = null): DonsTimelineDTO;
```

`DonsTimelineDTO` :
- `array<int, AnneeCivileDTO> $annees` (clé = année, ex 2025)
- `int $totalCount`
- `string|float $totalMontant`
- `?string $raisonBlocageGlobal` (config asso incomplète)

`AnneeCivileDTO` :
- `int $annee`
- `int $count`
- `string|float $total`
- `array<int, DonLigneDTO> $lignes`

`DonLigneDTO` :
- transaction + sous-catégorie + montant + date,
- `?RecuFiscalEmis $recu` (actif),
- `array<string> $alertes` (`helloasso`, `donnees_modifiees`),
- `bool $peutTelecharger`,
- `?string $raisonBlocage`.

## 7. Tests (TDD)

| # | Test | Niveau | Couvre |
|---|---|---|---|
| 1 | `FicheTiersRoutingTest::it_renders_show_route` | feature | URL `/tiers/{tiers}` retourne 200, breadcrumb « Tiers / {nom} » présent, identité affichée |
| 2 | `FicheTiersRoutingTest::it_defaults_to_coordonnees_tab` | feature | Sans `?onglet=`, onglet « Coordonnées » est actif |
| 3 | `FicheTiersRoutingTest::it_selects_tab_via_query_string` | feature | `?onglet=dons` active l'onglet Dons |
| 4 | `FicheTiersOngletsConditionnelsTest::dons_tab_absent_without_dons` | feature | Tiers sans don : pas d'onglet Dons dans le DOM |
| 5 | `FicheTiersOngletsConditionnelsTest::dons_tab_shows_count` | feature | Tiers avec 3 dons : onglet « Dons (3) » |
| 6 | `FicheTiersTriggersListesTest::row_click_navigates` | feature | Sur `/tiers`, clic ligne navigue vers `/tiers/{id}` |
| 7 | `FicheTiersTriggersListesTest::voir_button_navigates` | feature | Bouton « Voir » fonctionne |
| 8 | `FicheTiersTriggersListesTest::edit_button_does_not_trigger_row_click` | feature | Clic sur stylo édition ne navigue pas vers la fiche |
| 9 | `FicheTiersTenantSafetyTest::other_asso_tiers_returns_404` | feature | Multi-tenant : tiers d'une autre asso → 404 |
| 10 | `OngletDonsTest::renders_dons_grouped_by_annee_civile` | feature | Dons 2024 + 2025 : 2 groupes |
| 11 | `OngletDonsTest::shows_recu_actif_badge` | feature | Don avec reçu actif : badge présent |
| 12 | `OngletDonsTest::blocks_telechargement_si_adresse_incomplete` | feature | Adresse tiers vide : bouton télécharger désactivé + raison |
| 13 | `OngletDonsTest::triggers_modale_avertissement_helloasso` | feature | Don helloasso : modale d'avertissement avant téléchargement |
| 14 | `TiersDonsTimelineServiceTest::groupes_par_annee_civile` | unit | 2 dons en 2024 + 1 en 2025 → 2 entrées dans `$annees`, totaux corrects |
| 15 | `TiersDonsTimelineServiceTest::filtre_annee_civile` | unit | `forTiers($t, 2024)` : seuls les dons 2024 |
| 16 | `TiersDonsTimelineServiceTest::alertes_par_ligne` | unit | helloasso + données_modifiees détectés |
| 17 | `TiersDonsTimelineServiceTest::blocage_global_signataire_absent` | unit | `raisonBlocageGlobal` rempli |
| 18 | `TiersDonsTimelineServiceTest::respecte_tenant_scope` | unit | Tiers d'une autre asso : pas remonté |
| 19 | `TiersQuickViewSmokeTest::quickview_still_renders_dons_via_service` | feature | Régression : quick view affiche les dons après refacto |

## 8. Checklist conventions projet

- [ ] `declare(strict_types=1)` + `final class` + type hints partout.
- [ ] PSR-12 via `./vendor/bin/pint`.
- [ ] Locale `fr` (labels, validation).
- [ ] Cast `(int)` des deux côtés sur comparaisons PK/FK.
- [ ] `wire:confirm` via modale Bootstrap (pas natif) — N/A ce slice (pas d'action destructive MVP).
- [ ] Tenant-scope respecté : `Tiers` étend déjà `TenantModel`.
- [ ] Pas de H1 sur la page.
- [ ] En-têtes de tableaux `table-dark` style bleu foncé si tableaux dans onglets.
- [ ] Tri colonnes JS avec `data-sort` si tableaux triables.

## 9. Risques & points d'attention

1. **Refacto `TiersQuickView`** : le quick view consomme déjà la logique dons. L'extraction vers le service partagé doit être faite **avant** l'utilisation côté page, et couverte par un test smoke pour ne pas casser l'existant.
2. **Listes multiples** : 5 listes tiers à patcher pour la navigation. Garder un patron unique (un composant Blade `<x-tiers-row-clickable>` ou similaire) pour ne pas dupliquer.
3. **Click-through stylos** : sur les listes, les stylos d'édition existants ne doivent pas déclencher la navigation ligne. À tester explicitement.
4. **`wire:lazy` Livewire** : vérifier que les onglets enfants reçoivent bien le tiers via prop et non via session/state global.
5. **Performance** : sur un tiers avec beaucoup de dons (cas plausible donateur fidèle 10+ ans), la query agrégée doit rester ≤ 2 queries (1 pour les dons + jointures, 1 pour les reçus actifs). Pas de N+1.
6. **Couleurs/style identité** : reprendre la palette `#f0e8f5` / `#4a1060` du quick view pour cohérence visuelle, ou s'aligner sur le style des autres pages full-page (à trancher visuellement en local — le user a dit qu'on testera sur localhost).

## 10. Définition de fait

- ✅ Suite Pest verte (tests existants + nouveaux).
- ✅ `./vendor/bin/pint` clean.
- ✅ Page `/tiers/{tiers}` accessible, breadcrumb correct, 2 onglets fonctionnels.
- ✅ Quick view actuel toujours fonctionnel (régression).
- ✅ Test manuel localhost : navigation depuis `/tiers` (ligne + bouton), onglets, téléchargement reçu fiscal.
- ✅ Pas de régression visuelle des listes tiers.
- ✅ Spec à jour si écart en cours d'implémentation.
