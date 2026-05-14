# Plan: Portail membres et participants — Slice 1 (F+A) : Fondation portail + Profil

**Created**: 2026-05-14
**Branch**: `feat/portail-membres-slice1-fondation-profil` (à créer depuis `main`)
**Status**: approved (2026-05-14, mode subagent-driven, Sonnet en exécuteurs)
**Spec**: [docs/specs/2026-05-14-portail-membres-slice1-fondation-profil.md](../docs/specs/2026-05-14-portail-membres-slice1-fondation-profil.md)

## Goal

Poser la fondation de navigation et le premier écran applicatif (Mon profil) du programme "Portail membres et participants", sans livrer encore les sections métier (Adhésions, Dons, Opérations, Communications, Documents — slices 2 à 4). À l'issue de ce slice : (a) la sidebar portail est pilotée par un résolveur extensible, (b) les Tiers non-bénévoles ont au moins une raison d'ouvrir le portail (édition de leurs coordonnées + préférences RGPD), (c) la règle de visibilité Notes de frais s'élargit aux participants ayant déjà déposé ≥1 NDF, et (d) les écrans bénévoles existants (NDF / Factures partenaires / Historique dépenses) ne régressent pas.

## Acceptance Criteria

- [ ] Un Tiers `pour_depenses=false` et 0 NDF voit exactement 2 entrées sidebar : "Tableau de bord", "Mon profil".
- [ ] Un Tiers `pour_depenses=false` avec ≥ 1 NDF voit en plus "Notes de frais" (sans Factures partenaires ni Historique). L'accès direct GET `/{slug}/portail/notes-de-frais` retourne 200 pour ce Tiers.
- [ ] Un Tiers `pour_depenses=true` voit "Notes de frais", "Factures partenaires", "Historique dépenses" dans une section "Mes frais & factures".
- [ ] La page "Mon profil" accepte la modification des 7 champs : `adresse_ligne1`, `adresse_ligne2`, `code_postal`, `ville`, `pays`, `telephone`, `email_optout` (liste exacte sous réserve de la confrontation step 9 à la migration `Tiers` actuelle — voir Pré-décisions).
- [ ] Les champs civilité, nom, prénom, email et date de naissance sont visibles mais non éditables (`disabled`), avec lien `mailto:` "Contactez-nous" et libellé d'aide "Pour modifier ces informations, contactez l'association" présent dans le DOM.
- [ ] Le lien "Demander la suppression de mon compte" génère un `mailto:` correctement pré-rempli vers l'email de l'association.
- [ ] L'accès direct à `/{slug}/portail/notes-de-frais` retourne 403 (ou redirige) pour un Tiers sans `pour_depenses` et sans aucune NDF.
- [ ] L'accès direct à `/{slug}/portail/factures` reste refusé pour un Tiers sans `pour_depenses` (statu quo non modifié).
- [ ] Mode mono (`portail-mono.php`) applique exactement les mêmes règles et expose les mêmes routes.
- [ ] Isolation multi-tenant : un Tiers de l'asso A ne peut accéder à aucune route portail de l'asso B (test d'intrusion explicite).
- [ ] Aucune régression sur Home/NDF/FP/Historique post-migration vers le nouveau layout.
- [ ] Suite Pest verte, `pint` clean, larastan baseline inchangée.

## Pré-décisions à confirmer avant Step 9

Trois trous techniques notés dans la spec, à lever en passant — non bloquants pour démarrer :

1. **Liste exacte des champs adresse `Tiers`** : confronter à la migration courante (mono-ligne legacy vs `adresse_ligne1/2`). Aligner sur l'écran back-office d'édition Tiers.
2. **Sort du composant `Portail\Home`** : remplacé par `TableauDeBord`. Si plus aucune route ne le référence, supprimer.
3. **Email association destinataire RGPD** : utiliser `$association->email` (champ existant) ; vérifier la présence et nullability avant de coder les `mailto:`.

Si l'une de ces hypothèses casse à l'exécution, escalader plutôt que contourner.

## Steps

### Step 0: Créer la branche

**Complexity**: trivial
**RED**: N/A
**GREEN**: `git checkout -b feat/portail-membres-slice1-fondation-profil` depuis `main`
**REFACTOR**: None
**Files**: —
**Commit**: aucun — point de départ uniquement

---

### Step 1: DTO `PortailSectionDTO` + interface `PortailSectionProvider`

**Complexity**: standard
**RED**: `tests/Unit/Portail/PortailSectionDTOTest.php` — tester construction, accès aux propriétés (`id`, `label`, `routeName`, `icon`, `ordre`, `visible`, `badge`), immutabilité (readonly), équivalence par valeur.
**GREEN**: Créer :
- `app/Support/Portail/PortailSectionDTO.php` (final, readonly, propriétés publiques typées)
- `app/Support/Portail/PortailSectionProvider.php` (interface, méthode `resolve(Tiers): ?PortailSectionDTO`)
**REFACTOR**: None
**Files**: `app/Support/Portail/PortailSectionDTO.php`, `app/Support/Portail/PortailSectionProvider.php`, `tests/Unit/Portail/PortailSectionDTOTest.php`
**Commit**: `feat(portail): DTO + interface pour les sections de navigation portail`

---

### Step 2: Service `PortailSectionsResolver` + registry

**Complexity**: standard
**RED**: `tests/Unit/Portail/PortailSectionsResolverTest.php` — couvre :
- Aucun provider enregistré ⇒ collection vide
- Plusieurs providers ⇒ collection ordonnée par `ordre` asc
- Provider qui retourne `null` ⇒ section absente de la collection
- Tiers null ⇒ collection vide (cas non authentifié)
**GREEN**: Créer :
- `app/Services/Portail/PortailSectionsResolver.php` : registre des providers (méthodes `register(PortailSectionProvider)` et `resolve(Tiers): Collection<PortailSectionDTO>`)
- `app/Providers/PortailServiceProvider.php` (nouveau) : binding singleton du résolveur ; sera étendu aux steps 3-4 pour enregistrer les 5 providers fondation
- Enregistrer `PortailServiceProvider` dans `bootstrap/providers.php`
**REFACTOR**: None
**Files**: `app/Services/Portail/PortailSectionsResolver.php`, `app/Providers/PortailServiceProvider.php`, `bootstrap/providers.php`, `tests/Unit/Portail/PortailSectionsResolverTest.php`
**Commit**: `feat(portail): résolveur de sections sidebar + service provider dédié`

---

### Step 3: Providers fondation (Tableau de bord, Mon profil)

**Complexity**: standard
**RED**: `tests/Unit/Portail/Providers/TableauDeBordProviderTest.php` + `MonProfilProviderTest.php` — chacun :
- Retourne un DTO avec route, label, icon attendus, `visible=true` pour n'importe quel Tiers (les 2 sections sont toujours visibles).
**GREEN**: Créer :
- `app/Services/Portail/Providers/TableauDeBordProvider.php` (implémente `PortailSectionProvider`)
- `app/Services/Portail/Providers/MonProfilProvider.php`
- Enregistrement dans `PortailServiceProvider::boot()` via le résolveur (résolveur récupéré du container)
**REFACTOR**: None
**Files**: 2 providers + 2 tests + édition `PortailServiceProvider`
**Commit**: `feat(portail): providers Tableau de bord et Mon profil`

---

### Step 4: Providers "Mes frais & factures" (NDF / FP / Historique)

**Complexity**: standard
**RED**: 3 fichiers de test couvrent les 3 cas :
- `NotesDeFraisProviderTest.php` :
  - Tiers `pour_depenses=true` ⇒ DTO non null
  - Tiers `pour_depenses=false` mais `NoteDeFrais::where('tiers_id', T->id)->exists()` ⇒ DTO non null
  - Tiers `pour_depenses=false` et 0 NDF ⇒ `null`
- `FacturesPartenairesProviderTest.php` : DTO si `pour_depenses=true`, `null` sinon
- `HistoriqueDepensesProviderTest.php` : DTO si `pour_depenses=true`, `null` sinon
**GREEN**: Créer les 3 providers + enregistrer dans `PortailServiceProvider`. Bien factoriser un libellé de "section group" (`Mes frais & factures`) si le DTO le porte.
**REFACTOR**: Si le DTO ne porte pas encore `groupe`, l'ajouter (et mettre à jour les tests step 1+2 en conséquence). Décision design : ajouter `groupe: ?string` au DTO dès le step 1 pour éviter ce refactor — **à anticiper** dans Step 1.
**Files**: 3 providers + 3 tests + édition `PortailServiceProvider`
**Commit**: `feat(portail): providers NDF (élargi), Factures partenaires, Historique`

---

### Step 5: Middleware `EnsurePeutVoirNotesDeFrais`

**Complexity**: complex (sécurité, modification d'un middleware d'accès)
**RED**: `tests/Feature/Portail/EnsurePeutVoirNotesDeFraisTest.php` couvre :
- Tiers `pour_depenses=true` ⇒ 200 sur `/{slug}/portail/notes-de-frais`
- Tiers `pour_depenses=false` mais ≥ 1 NDF déjà créée ⇒ 200
- Tiers `pour_depenses=false` et 0 NDF ⇒ 403 ou redirect vers tableau de bord
- Non-authentifié ⇒ redirect login (statu quo middleware Authenticate)
- Tiers asso A connecté tente accès `/{slug-B}/portail/notes-de-frais` ⇒ refus (isolation tenant)
**GREEN**: Créer `app/Http/Middleware/Portail/EnsurePeutVoirNotesDeFrais.php`. La query NDF doit utiliser le scope global tenant (Tiers étend TenantModel, NDF étend TenantModel — vérifier au build).
**REFACTOR**: None
**Files**: middleware + test
**Commit**: `feat(portail): middleware NDF élargi (pour_depenses OU ≥1 NDF déposée)`

---

### Step 6: Application du nouveau middleware sur les routes NDF

**Complexity**: standard
**RED**: Test feature qui appelle directement la route NDF avec un Tiers participant-NDF (pas `pour_depenses`, 1 NDF) et vérifie 200. Le test d'isolation existant pour Tiers sans NDF retourne 403.
**GREEN**: Modifier `routes/portail.php:42` et `routes/portail-mono.php:52` : remplacer `EnsurePourDepenses::class` par `EnsurePeutVoirNotesDeFrais::class` **uniquement** sur le prefix `notes-de-frais` (laisser `factures` et `historique` sous `EnsurePourDepenses`).
**REFACTOR**: None
**Files**: `routes/portail.php`, `routes/portail-mono.php`, fichier de test
**Commit**: `feat(portail): élargir l'accès NDF aux participants avec NDF existante`

---

### Step 7: Nouveau layout post-auth `portail.layouts.authenticated`

**Complexity**: complex (refonte visuelle, impact tous écrans post-auth)
**RED**: `tests/Feature/Portail/SidebarVisibiliteTest.php` — 5 scénarios depuis la spec :
- Membre seul ⇒ sidebar = [Tableau de bord, Mon profil]
- Participant-NDF ⇒ sidebar = précédent + [Notes de frais]
- Bénévole ⇒ sidebar = précédent + [Factures partenaires, Historique dépenses]
- Cumul = bénévole (les sections slices 2-4 ne sont pas encore enregistrées)
- Anonyme ⇒ redirect login (statu quo)

Test fait sur la route TableauDeBord (ou n'importe quelle route post-auth migrée step 8). Vérifie la présence des liens et leur ordre dans le DOM.

**GREEN**:
- Créer `resources/views/portail/layouts/authenticated.blade.php` (layout sidebar + content, header logo asso centré au-dessus, footer AgoraGestion sous le contenu).
- Charge le résolveur en composant `@inject('sectionsResolver', ...)` ou via `@php` avec `app()`, parcourt les sections par groupe.
- Conserve `portail.layouts.app` pour Login/OTP/ChooseTiers/erreurs publiques.
**REFACTOR**: Extraire `resources/views/portail/layouts/partials/sidebar.blade.php` si > 80 lignes inline.
**Files**: `resources/views/portail/layouts/authenticated.blade.php` (+ partial éventuel), `tests/Feature/Portail/SidebarVisibiliteTest.php`
**Commit**: `feat(portail): layout post-auth avec sidebar pilotée par résolveur`

---

### Step 8: Composant `TableauDeBord` (remplace `Home`)

**Complexity**: standard
**RED**: `tests/Feature/Portail/TableauDeBordTest.php` :
- Authentifié ⇒ vue `tableau-de-bord` rendue, message "Bonjour {prenom}", liste des blocs raccourcis correspondant aux sections visibles via résolveur.
- Persona membre seul ⇒ 1 raccourci ("Mon profil")
- Persona bénévole ⇒ 4 raccourcis (Mon profil + NDF + FP + Historique)
- La route `/` du portail (et mono) atterrit sur ce composant.
**GREEN**:
- Créer `app/Livewire/Portail/TableauDeBord.php` (charge sections via résolveur, layout = `portail.layouts.authenticated`)
- Créer vue `resources/views/livewire/portail/tableau-de-bord.blade.php`
- Modifier `routes/portail.php:39` et `routes/portail-mono.php:48` : `'/' => TableauDeBord::class` (au lieu de `Home::class`)
- Supprimer `App\Livewire\Portail\Home` et sa vue si aucune autre référence
**REFACTOR**: None
**Files**: 1 composant + 1 vue + 2 routes + 1 test + suppression Home
**Commit**: `feat(portail): tableau de bord remplace Home, sections raccourcis dynamiques`

---

### Step 9: Composant `MonProfil`

**Complexity**: complex (sécurité — verrouillage champs côté serveur ; validation ; multi-tenant)
**RED**: `tests/Feature/Portail/MonProfilTest.php` couvre :
- Affichage : tous les champs visibles, civilité/nom/prénom/email/date_naissance avec attribut `disabled`
- Édition autorisée : modification adresse + ville + code_postal + pays + téléphone + email_optout ⇒ persistés, toast confirmation
- Tentative modification email via `wire:set` injecté ⇒ valeur **non modifiée** en base (test d'intrusion Livewire)
- Tentative modification nom ⇒ idem
- Validation : téléphone > 30 caractères ⇒ erreur, rien persisté
- Lien `mailto:` "Contactez-nous" ⇒ destinataire = email de l'association, objet correctement encodé
- Lien `mailto:` "Demander suppression compte" ⇒ idem avec objet/corps RGPD
- Isolation multi-tenant : Tiers asso A connecté ne peut PUT sur Tiers asso B (test d'intrusion)
- Logger `Log::info('portail.profil.updated', ...)` émis (assert via `Log::spy()`)

**GREEN**:
- Créer `app/Livewire/Portail/MonProfil.php` (full-page Livewire, layout = `portail.layouts.authenticated`)
- Créer vue `resources/views/livewire/portail/mon-profil.blade.php` (3 sections : Identité readonly / Coordonnées éditable / Préférences / RGPD)
- **Liste autorisée explicite** des champs éditables dans le composant (propriétés Livewire publiques uniquement pour ces champs ; champs verrouillés en propriétés `protected` ou recalculés depuis `$tiers->fresh()` — la défense en profondeur est explicite)
- Ajouter route :
  - `routes/portail.php` : `Route::get('/mon-profil', MonProfil::class)->name('mon-profil')`
  - `routes/portail-mono.php` : miroir
**REFACTOR**: None
**Files**: 1 composant + 1 vue + 2 routes + 1 test
**Commit**: `feat(portail): écran Mon profil avec édition partielle et préférences RGPD`

---

### Step 10: Migration des composants existants vers le nouveau layout

**Complexity**: standard
**RED**:
- `tests/Feature/Portail/RegressionBenevoleTest.php` — smoke tests sur les 6 routes existantes (Home déjà remplacé, donc 6 = NDF Index + Form + Show + FP AtraiterIndex + FP Depot + Historique Index). Vérifie 200 + présence de la sidebar (un sélecteur DOM commun au nouveau layout).
- `tests/Feature/Portail/MultiTiersOtpSelectorTest.php` — régression du sélecteur Tiers post-OTP : 2 Tiers de la même association partageant un même email ⇒ après OTP, le sélecteur (ChooseTiers) affiche les deux, et la session post-choix est bien scopée au Tiers choisi (cf. Gherkin scénario "Multi-Tiers même email — parent voit enfant").
**GREEN**: Modifier chaque composant Livewire post-auth pour pointer `->layout('portail.layouts.authenticated')` au lieu de `'portail.layouts.app'` :
- `app/Livewire/Portail/NoteDeFrais/Index.php:60`
- `app/Livewire/Portail/NoteDeFrais/Form.php:411`
- `app/Livewire/Portail/NoteDeFrais/Show.php:60`
- `app/Livewire/Portail/FacturePartenaire/AtraiterIndex.php` (chercher la ligne `->layout(...)`)
- `app/Livewire/Portail/FacturePartenaire/Depot.php`
- `app/Livewire/Portail/HistoriqueDepenses/Index.php`
**REFACTOR**: None
**Files**: 6 composants + 2 fichiers de test (régression layout + régression OTP multi-Tiers)
**Commit**: `refactor(portail): migrer écrans existants vers layout authenticated + régression OTP multi-tiers`

---

### Step 11: Documentation

**Complexity**: trivial
**RED**: N/A (documentation pure)
**GREEN**: Mettre à jour `docs/portail-tiers.md` :
- Nouvelle section "Sidebar adaptative" avec règles de visibilité (tableau)
- Architecture du résolveur + registry des providers (comment un slice ultérieur enregistre une section)
- Procédure admin : "Comment un participant fait sa première NDF" → passer `pour_depenses=true` sur la fiche Tiers du back-office
- Note pour admins : "Comment permettre à un parent de voir un enfant" → email du parent sur la fiche Tiers de l'enfant
**REFACTOR**: None
**Files**: `docs/portail-tiers.md`
**Commit**: `docs(portail): documenter la sidebar adaptative et la procédure 1ère NDF`

---

## Complexity Classification

| Step | Complexity |
|------|-----------|
| 0 — Branche | trivial |
| 1 — DTO + interface | standard |
| 2 — Résolveur + registry | standard |
| 3 — Providers fondation | standard |
| 4 — Providers frais & factures | standard |
| 5 — Middleware NDF élargi | **complex** (sécurité) |
| 6 — Application middleware sur routes | standard |
| 7 — Layout post-auth | **complex** (refonte visuelle + impact tous écrans) |
| 8 — TableauDeBord | standard |
| 9 — MonProfil | **complex** (sécurité — défense en profondeur édition partielle) |
| 10 — Migration composants existants | standard |
| 11 — Documentation | trivial |

## Pre-PR Quality Gate

- [ ] Tous les tests Pest passent (suite complète)
- [ ] `./vendor/bin/pint --test` clean
- [ ] Larastan baseline inchangée
- [ ] `/code-review --changed` passe
- [ ] Test manuel localhost (port 80) :
  - Login OTP avec un Tiers `pour_depenses=false, 0 NDF` ⇒ sidebar à 2 entrées
  - Login OTP avec un Tiers `pour_depenses=true` ⇒ sidebar complète
  - Modification profil (adresse + opt-out) ⇒ persistance + toast
  - Tentative accès direct `/notes-de-frais` en tant que membre seul ⇒ 403
- [ ] `docs/portail-tiers.md` à jour
- [ ] Aucune migration en attente
- [ ] Branche poussée, PR ouverte sur `main` avec checklist remplie

## Risks & Open Questions

| # | Risque / Question | Mitigation / Réponse |
| - | ----------------- | -------------------- |
| 1 | Champs adresse `Tiers` (mono-ligne legacy vs `adresse_ligne1/2`) | Step 9 : aligner sur écran back-office édition Tiers ; si écart majeur, escalader avant de coder la vue. |
| 2 | Composant `Portail\Home` peut être référencé par un test ou un email transactionnel | Step 8 : grep `Portail\Home` avant suppression. Si référence, redirect plutôt que delete. |
| 3 | Email association RGPD (`$association->email` ?) | Step 9 : vérifier présence champ, nullability, fallback éventuel sur `Association::adminPrincipal()->email` |
| 4 | Refonte layout casse les écrans bénévoles existants (NDF/FP/Historique) | Step 10 explicite avec test de régression. Si CSS spécifique à `portail.layouts.app` (centré-card) doit être préservé dans certains écrans, possibilité de cohabitation des deux layouts. |
| 5 | Sécurité MonProfil — verrouillage Livewire | Step 9 : test d'intrusion explicite (modification champ email via `wire:set` injecté côté client) doit échouer en base. Pas de propriété publique pour les champs verrouillés. |
| 6 | Mode mono — duplication des routes | Tous les steps qui touchent `routes/portail.php` doivent toucher `routes/portail-mono.php` en miroir. Test régression sur les deux. |
| 7 | Performance : résolveur appelé à chaque page | Acceptable en v0 (5 providers, query NDF::exists() rapide). Cache slice 6 si besoin. |
| 8 | Renommage sémantique `pour_depenses` → `est_fournisseur` | Hors scope explicite ; dette portée par le projet, à reposer après slice 4. |

## Notes d'exécution

- **Mode subagent-driven recommandé** (préférence projet : Opus planifie, Sonnet exécute).
- **Inline review checkpoints** à chaque step `complex` (steps 5, 7, 9) : `security-review` + `arch-review` minimum.
- **Branche unique** pour tout le slice. Pas de merge intermédiaire. PR groupée vers `main` à la fin.
- **Pas de push prod** : test local d'abord (préférence projet).
