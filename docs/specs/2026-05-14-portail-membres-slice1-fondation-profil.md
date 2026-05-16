# Portail membres et participants — Slice 1 (F+A) : Fondation portail + Profil

- **Date** : 2026-05-14
- **Programme** : Portail membres et participants
- **Slice** : 1 / 5 (F + A) — Fondation navigation + Profil utilisateur
- **Branche cible (proposée)** : `feat/portail-membres-slice1-fondation-profil`

## Contexte

Le portail Tiers existant (`/{association:slug}/portail/...`, garde `tiers-portail`,
auth OTP, livré v4.x) sert aujourd'hui exclusivement les bénévoles
`pour_depenses=true` (NDF, indemnités km, factures partenaires déposées,
historique dépenses). Aucun cas d'usage pour un Tiers qui n'est qu'adhérent,
donateur ou participant à une opération.

Ce slice ne livre **aucune section métier nouvelle**. Il livre la **fondation
de navigation** qui permettra d'absorber sans rework les slices suivants
(Adhésions/Dons, Opérations, Communications/Documents) et **un seul écran
applicatif** : « Mon profil », pour qu'un Tiers non-bénévole ait au moins une
raison de se connecter dès la v0.

## Programme complet (rappel — non livré ici)

| # | Slice | Périmètre |
| - | ----- | --------- |
| **1 (F+A)** | **Fondation + Profil** | **Ce document** |
| 2 (B) | Mes communications + Mes documents | Branchement services slice 8 fiche tiers, restriction visibilité portail |
| 3 (C) | Adhésions + Dons + reçus fiscaux | Lien renouvellement / nouveau don paramétrable Association |
| 4 (D) | Opérations (1 onglet par type avec inscription) | Séances, présence, devis/factures/attestations, magic-link questionnaire |
| 5 — | Préférences communication granulaires | Si besoin se confirme post-slice C/D |

## Décisions actées en cadrage (pré-spec)

1. **Profil** : édition partielle (adresse, téléphone). Nom/email/date naissance lecture seule.
2. **Données médicales** : exclues du portail. Restent saisies via magic-link `/formulaire?token=...`, lequel vit **dans l'onglet de l'opération concernée** (livrable slice 4).
3. **Reçus de cotisation** : ajoutés à l'onglet Adhésions (slice 3).
4. **Visibilité sections** :
   - **Notes de frais** : `Tiers::pour_depenses = true` **OU** `count(NDF déposées par ce Tiers) ≥ 1`. **Modification du middleware actuel** `EnsurePourDepenses` requise pour NDF.
   - **Factures partenaires** + **Historique dépenses** : strict `Tiers::pour_depenses = true` (statu quo).
   - **Première NDF d'un participant non-`pour_depenses`** : procédure manuelle côté admin — l'asso passe le flag `pour_depenses=true` sur la fiche Tiers du participant qui signale un frais. Documenter pour les admins. Pas de bouton "Demander un remboursement" dans le portail v0.
   - Adhésions / Dons / Opérations : visibles **selon contenu 360** (≥ 1 ligne).
   - Pas de filtrage sur `pour_recettes` (champ peu fiable d'après expérience).
5. **Design sidebar** : **Option A — sidebar latérale**. Sections cachées si vides, ordre fixe, pas de "Coming soon". Sous-sections : "Espace personnel" (Tableau de bord, Mon profil) → "Mes frais & factures" (NDF / FP / Historique, selon flags) → puis sections métier dans les slices suivants.
6. **Multi-Tiers même email (parent/enfant)** : aucun modèle `representant_legal`. L'admin saisit l'email du parent sur la fiche Tiers de l'enfant — le parent verra l'enfant dans son sélecteur OTP post-login. Documenter pour les admins ; pas de code.
7. **Notifications email sortantes** (rappel adhésion, etc.) : hors scope programme.
8. **Inscription nouvelle opération depuis portail** : hors scope programme (HelloAsso ou contact asso).
9. **RGPD** :
   - Préférences = un toggle "Je n'accepte pas les emails marketing" (correspond au champ existant `Tiers::email_optout`).
   - Suppression compte / droit à l'oubli = lien "Contactez-nous" (mailto pré-rempli vers email association).
10. **Justificatifs déjà déposés** (NDF, formulaire participant) : pas exposés en consultation côté portail v0 (cohérent avec décision 2).

## Hypothèses techniques vérifiées sur le code

| Item | État | Source |
| ---- | ---- | ------ |
| Garde `tiers-portail` | ✓ existe | `config/auth.php:47` |
| Routes portail | `/{association:slug}/portail/...` | `routes/portail.php:28` (la mémoire `/portail/{slug}/...` était incorrecte) |
| Mode mono | `routes/portail-mono.php` (slug-mono livré) | À traiter en miroir |
| Champ opt-out | `Tiers::email_optout` (boolean) | `app/Models/Tiers.php:39`, migration `2026_04_12_200001` |
| Composant Home portail | `App\Livewire\Portail\Home` | `routes/portail.php:39` |
| Filtrage NDF/factures/historique | Middleware `EnsurePourDepenses` | `app/Http/Middleware/Portail/EnsurePourDepenses.php` |

---

## 1. Intent Description

**Objectif** : transformer le portail Tiers, aujourd'hui dédié aux bénévoles
`pour_depenses`, en une surface accueillante pour **n'importe quel Tiers
authentifié** (adhérent, donateur, participant, bénévole, ou cumul). En v0
de ce slice, on livre la coquille (navigation + dashboard + profil) sans
encore les sections métier — celles-ci viendront se brancher dessus dans les
slices 2 à 4.

**Pourquoi maintenant** : sans cette fondation, chaque slice métier devra
recoder sa propre intégration sidebar et inventer sa propre règle "est-ce
que cette section doit apparaître ?". On finirait avec une navigation
incohérente et des sections vides. En posant le résolveur de sections en
amont, chaque slice ultérieur n'a qu'à enregistrer sa section avec sa règle
de visibilité — la sidebar se compose toute seule.

**Frontière** : ce slice ne touche pas à l'auth OTP (livrée v4.x), aux
écrans NDF / factures partenaires / historique (livrés), ni aux services
fiche tiers 360 (livrés v4.3.1 — branchés en slice 2). Il modifie le layout
portail, ajoute deux composants Livewire (Profil + Tableau de bord) et
introduit un service de résolution de sections. Aucune migration.

## 2. User-Facing Behavior (Gherkin)

```gherkin
Fonctionnalité: Portail — sidebar adaptative et tableau de bord

Contexte:
  Étant donné une association "MonAsso" avec slug "monasso"
  Et un Tiers "Alice Martin" rattaché à cette association

Scénario: Membre simple — sidebar minimale
  Étant donné Alice est un Tiers avec pour_depenses=false, pas de NDF, pas de factures partenaires
  Quand Alice se connecte au portail (OTP) et choisit son Tiers
  Alors elle est redirigée vers "/monasso/portail/" (tableau de bord)
  Et la sidebar affiche dans cet ordre: "Tableau de bord", "Mon profil"
  Et la sidebar n'affiche pas "Notes de frais", "Factures partenaires", "Historique dépenses"

Scénario: Bénévole/fournisseur pour_depenses — sidebar complète
  Étant donné Alice est un Tiers avec pour_depenses=true
  Quand Alice se connecte au portail
  Alors la sidebar affiche dans la section "Espace personnel": "Tableau de bord", "Mon profil"
  Et la sidebar affiche dans la section "Mes frais & factures": "Notes de frais", "Factures partenaires", "Historique dépenses"

Scénario: Participant avec NDF — NDF visible mais pas factures partenaires
  Étant donné Alice est un Tiers avec pour_depenses=false
  Et Alice a au moins une NoteDeFrais déjà déposée
  Quand Alice se connecte au portail
  Alors la sidebar affiche "Notes de frais" dans "Mes frais & factures"
  Et la sidebar n'affiche PAS "Factures partenaires" ni "Historique dépenses"
  Et l'accès direct à /{slug}/portail/notes-de-frais retourne 200
  Et l'accès direct à /{slug}/portail/factures retourne 403 (ou redirige) — middleware EnsurePourDepenses intact

Scénario: Membre seul tente d'accéder à NDF en direct
  Étant donné Alice est un Tiers avec pour_depenses=false et aucune NDF déposée
  Quand Alice tape l'URL "/{slug}/portail/notes-de-frais" directement
  Alors elle reçoit une réponse 403 (ou redirige vers le tableau de bord) — middleware EnsurePeutVoirNotesDeFrais refuse

Scénario: Tableau de bord — accueil simple
  Étant donné Alice est connectée au portail
  Quand elle accède au tableau de bord
  Alors elle voit un message de bienvenue avec son prénom
  Et elle voit un bloc "Vos espaces" listant les sections accessibles depuis la sidebar
  Et chaque bloc est cliquable et la mène à la section correspondante

Scénario: Édition profil — champs autorisés
  Étant donné Alice est connectée et accède à "/monasso/portail/mon-profil"
  Quand elle modifie son adresse postale, son code postal, sa ville, son pays et son téléphone
  Et qu'elle soumet le formulaire
  Alors les nouvelles valeurs sont enregistrées sur son Tiers
  Et un toast "Profil mis à jour" s'affiche
  Et le rendu de la page reflète les nouvelles valeurs sans rechargement complet

Scénario: Édition profil — champs verrouillés
  Étant donné Alice est sur la page "Mon profil"
  Alors les champs "Nom", "Prénom", "Email", "Date de naissance" sont visibles mais non éditables (HTML disabled)
  Et un libellé d'aide indique "Pour modifier ces informations, contactez l'association"
  Et un lien "Contactez-nous" ouvre un mailto vers l'email de l'association

Scénario: Préférence communication — opt-out marketing
  Étant donné Alice a actuellement Tiers.email_optout = false
  Quand elle coche "Je ne souhaite pas recevoir les emails de communication de l'association"
  Et qu'elle enregistre
  Alors Tiers.email_optout vaut true
  Et un toast "Préférences enregistrées" s'affiche

Scénario: Droit à l'oubli — lien sortant
  Étant donné Alice est sur la page "Mon profil", section RGPD
  Quand elle clique sur "Demander la suppression de mon compte"
  Alors un mailto s'ouvre, destinataire = email de l'association, objet = "Demande de suppression de compte (Alice Martin)", corps pré-rempli

Scénario: Validation côté serveur — téléphone trop long
  Étant donné Alice modifie son téléphone avec une chaîne de 51 caractères
  Quand elle enregistre
  Alors le formulaire affiche une erreur de validation sur le champ téléphone
  Et aucune modification n'est persistée

Scénario: Multi-Tiers même email — parent voit enfant
  Étant donné l'email "parent@example.com" est saisi sur deux fiches Tiers : "Parent Martin" et "Enfant Martin"
  Quand "parent@example.com" se connecte au portail et saisit son OTP
  Alors le sélecteur de Tiers affiche les deux Tiers
  Et le parent peut choisir "Enfant Martin" et accéder au portail en tant qu'enfant

Scénario: Isolation multi-tenant
  Étant donné Alice (asso A) est connectée au portail de l'asso A
  Quand elle tente d'accéder à "/assoB/portail/mon-profil"
  Alors elle reçoit une redirection vers le login de l'asso B (ou un 403 selon la garde)
  Et aucune donnée de l'asso A n'est exposée

Scénario: Pas de régression bénévole
  Étant donné Bob est un bénévole pour_depenses=true avec NDF actives
  Quand il se connecte au portail
  Alors les pages "Notes de frais", "Factures partenaires", "Historique dépenses" restent accessibles et fonctionnelles à l'identique
  Et leur logique de visibilité (middleware EnsurePourDepenses) n'est pas modifiée
```

## 3. Architecture Specification

### Composants nouveaux

| Composant | Type | Rôle |
| --------- | ---- | ---- |
| `App\Livewire\Portail\TableauDeBord` | Livewire (fullpage) | Landing post-auth. Remplace `Home` ou cohabite (cf. décision plan) |
| `App\Livewire\Portail\MonProfil` | Livewire (fullpage) | Édition partielle Tiers + opt-out + lien RGPD |
| `App\Services\Portail\PortailSectionsResolver` | Service singleton | Pour un Tiers, retourne `Collection<PortailSectionDTO>` ordonnée |
| `App\Support\Portail\PortailSectionDTO` | DTO immuable | `id, label, route_name, icon, ordre, visible: bool, badge?: int` |

### Composants modifiés

| Fichier | Changement |
| ------- | ---------- |
| `resources/views/portail/layouts/app.blade.php` | Sidebar pilotée par `PortailSectionsResolver` au lieu de markup statique |
| `routes/portail.php` | Route `/` pointe vers `TableauDeBord` (au lieu de `Home`). Ajout `mon-profil`. |
| `routes/portail-mono.php` | Miroir des deux routes ci-dessus |
| `App\Livewire\Portail\Home` | Conservé en l'état si encore référencé ; sinon supprimé (à confirmer en phase plan) |

### Règle de visibilité des sections (résolveur)

Pour un Tiers `T` :

| Section | Visible si | Modification du middleware route ? |
| ------- | ---------- | ---------------------------------- |
| Tableau de bord | toujours | — |
| Mon profil | toujours | — |
| **Notes de frais** | `T->pour_depenses === true` **OR** `NoteDeFrais::where('tiers_id', T->id)->exists()` | **Oui** : remplacer `EnsurePourDepenses` par `EnsurePeutVoirNotesDeFrais` sur les routes NDF |
| Factures partenaires | `T->pour_depenses === true` (statu quo) | — |
| Historique dépenses | `T->pour_depenses === true` (statu quo) | — |
| _(slice 2)_ Mes documents | `count documents 360 > 0` | — |
| _(slice 2)_ Mes communications | `count email_logs > 0` | — |
| _(slice 3)_ Adhésions | `count adhesions > 0` | — |
| _(slice 3)_ Dons | `count dons > 0` | — |
| _(slice 4)_ Type opération `X` | `count participations type X > 0` (1 entrée par type avec contenu) | — |

Le résolveur sait calculer les règles "fondation" (Profil, Tableau de bord) et délègue les règles métier aux slices ultérieurs via un **registry** : chaque slice enregistre une `PortailSectionProvider` (interface à 1 méthode `resolve(Tiers): ?PortailSectionDTO`) au boot d'un service provider. En v0, on enregistre les 5 providers de la fondation (Tableau de bord, Profil, NDF, Factures partenaires, Historique dépenses) avec leurs règles respectives.

### Modifications middleware NDF (changement de règle métier portail)

Aujourd'hui : `routes/portail.php:42` applique `EnsurePourDepenses` aux 3 prefixes `notes-de-frais` / `factures` / `historique`.

Demain :
- Créer `App\Http\Middleware\Portail\EnsurePeutVoirNotesDeFrais` qui retourne `pour_depenses === true OR Tiers a au moins 1 NDF`.
- Appliquer ce nouveau middleware **uniquement** au prefix `notes-de-frais`.
- `factures` et `historique` continuent avec `EnsurePourDepenses`.

Procédure admin pour onboarder un participant qui veut faire sa première NDF : passer manuellement `pour_depenses=true` sur sa fiche Tiers (à documenter dans le runbook).

### Édition profil — champs

| Champ | Mode | Validation |
| ----- | ---- | ---------- |
| `nom` | lecture | — |
| `prenom` | lecture | — |
| `email` | lecture | — |
| `date_naissance` | lecture | — |
| `civilite` | lecture (livré v4.x récent) | — |
| `adresse_ligne1` | éditable | string max 255 nullable |
| `adresse_ligne2` | éditable | string max 255 nullable |
| `code_postal` | éditable | string max 20 nullable |
| `ville` | éditable | string max 120 nullable |
| `pays` | éditable | string max 80 nullable |
| `telephone` | éditable | string max 30 nullable, format libre |
| `email_optout` | éditable (toggle) | boolean |

⚠️ La liste exacte des champs `Tiers` à exposer est à confirmer en phase plan
contre la migration courante (le code peut avoir d'autres champs adresse —
ex `adresse` mono-ligne legacy). En cas d'écart, on aligne sur l'écran
back-office d'édition Tiers existant pour rester cohérent.

### Validation et persistance

- Composant Livewire utilise `validate()` Laravel.
- Save = `$tiers->update($validated)` dans une `DB::transaction()` minimale.
- Multi-tenant : `Tiers` étend `TenantModel` → scope global garantit isolation. Pas de check supplémentaire requis.
- Logger : `Log::info('portail.profil.updated', ['tiers_id' => $tiers->id])` (le `LogContext` ajoute automatiquement `association_id` + `user_id`).

### Mailto contact / RGPD

- Email destinataire = `$association->email` (champ existant ; sinon fallback sur l'admin principal).
- Objet : `"Demande [type] - {prenom} {nom}"`
- Corps : pré-rempli avec identité Tiers + asso pour faciliter le traitement.

### Tests

| Fichier | Type | Couvre |
| ------- | ---- | ------ |
| `tests/Feature/Portail/TableauDeBordTest.php` | Feature | Affichage post-auth, blocs sections accessibles |
| `tests/Feature/Portail/MonProfilTest.php` | Feature | Affichage, édition autorisée, édition refusée, validation, opt-out |
| `tests/Feature/Portail/SidebarVisibiliteTest.php` | Feature | 5 cas : membre seul (0 entrée frais), participant avec NDF (NDF seule), bénévole (3 entrées), cumul, anonyme (redirect login) |
| `tests/Feature/Portail/EnsurePeutVoirNotesDeFraisTest.php` | Feature | Middleware NDF : autorise pour_depenses ; autorise sans pour_depenses si ≥1 NDF ; refuse les autres |
| `tests/Feature/Portail/IsolationMultiTenantTest.php` | Intrusion | Tiers asso A ne voit/édite jamais asso B |
| `tests/Unit/Portail/PortailSectionsResolverTest.php` | Unit | Compose la collection selon les providers enregistrés |
| `tests/Feature/Portail/RegressionBenevoleTest.php` | Feature | Pages NDF / FP / Historique inchangées |

### Hors périmètre architecture (slices ultérieurs)

- Branchement services `TiersDocumentsTimelineService` / `TiersCommunicationsTimelineService` → slice 2.
- Wrapper "vue portail" pour masquer PJ comptables internes → slice 2.
- Préférences communication par canal → slice 5 si besoin.
- Page "famille" (vue agrégée parent voyant ses enfants sans switcher) → dette explicite, pas avant v3.x.

## 4. Acceptance Criteria

### Fonctionnels

- [ ] Un Tiers `pour_depenses=false` et 0 NDF voit exactement 2 entrées sidebar (Tableau de bord, Mon profil) après login.
- [ ] Un Tiers `pour_depenses=false` mais avec ≥ 1 NDF déposée voit en plus l'entrée "Notes de frais" (sans Factures partenaires ni Historique).
- [ ] Un Tiers `pour_depenses=true` voit "Notes de frais", "Factures partenaires", "Historique dépenses".
- [ ] Test d'isolation route NDF : un Tiers `pour_depenses=false` sans NDF qui tape `/{slug}/portail/notes-de-frais` reçoit 403/redirect.
- [ ] La page Mon profil persiste les modifications des 6 champs autorisés et rejette toute tentative sur les champs verrouillés (même par manipulation Livewire/DOM).
- [ ] Toggle `email_optout` persistant.
- [ ] Lien "Contactez-nous" et "Demander suppression compte" génèrent un `mailto:` correctement encodé.
- [ ] Sélecteur multi-Tiers post-OTP affiche tous les Tiers de l'asso ayant cet email (statu quo, à régression-tester).

### Non-fonctionnels

- [ ] Tableau de bord : first paint < 800ms en localhost après login.
- [ ] Édition profil : enregistrement < 500ms en localhost.
- [ ] Suite Pest verte, ≥ 1 test par scénario Gherkin (10 scénarios → ≥ 10 tests).
- [ ] Aucun warning Pint, aucune régression Larastan/PHPStan baseline.
- [ ] Aucune régression sur les écrans portail tiers existants (NDF, FP, Historique) — vérifié par `RegressionBenevoleTest`.

### Sécurité / multi-tenant

- [ ] Aucune fuite tenant : un Tiers de l'asso A ne peut accéder/modifier aucune donnée d'un Tiers de l'asso B (test d'intrusion explicite).
- [ ] Champs Tiers verrouillés : tentative de modification via Livewire `wire:model` injecté → erreur de validation, valeur non modifiée en base.
- [ ] Mode mono (`portail-mono.php`) ouvre les mêmes routes avec les mêmes règles.

### Documentation

- [ ] `docs/portail-tiers.md` mis à jour avec la nouvelle structure de sidebar et le résolveur (extension du doc existant).
- [ ] Note utilisateur (admin) : "comment permettre à un parent de voir un enfant" → email du parent dans la fiche Tiers de l'enfant. À ajouter dans le runbook portail ou dans la doc utilisateur de la fiche Tiers.

## Consistency Gate

| Item | Verdict |
| ---- | ------- |
| Intent unambigu (deux devs interprètent pareil) | ✓ — frontière scope explicite, livrables énumérés |
| Chaque comportement intent → ≥ 1 scénario Gherkin | ✓ — sidebar adaptative, profil édition partielle, RGPD, multi-Tiers, multi-tenant, régression |
| Architecture contrainte sans over-engineering | ✓ — résolveur + registry pattern justifié par les 4 slices à venir, pas de couches superflues |
| Naming consistent à travers les artifacts | ✓ — "section", "sidebar", "Tableau de bord", "Mon profil", "résolveur" employés à l'identique partout |
| Pas de contradiction entre artifacts | ✓ — décisions de cadrage référencées et reflétées dans Gherkin/Architecture/AC |

**Verdict global : PASS.**

Réserves mineures à lever en phase `/plan` (n'invalident pas le PASS) :

1. Liste exacte des champs adresse `Tiers` à confronter à la migration courante (mono-ligne legacy ou multi-lignes ?).
2. Sort de l'ancien composant `Home` portail (supprimé, redirigé, ou cohabite ?).
3. Email association destinataire RGPD (`$association->email` ou autre champ ?).

## Hors scope (parqué pour slices suivants ou plus tard)

- Sections Adhésions / Dons / Opérations / Communications / Documents (slices 2-4).
- Préférences communication granulaires par canal (newsletter vs transactionnel) — au-delà du toggle global `email_optout`.
- Notifications email sortantes (rappel adhésion, séance annulée, etc.).
- Inscription à une nouvelle opération depuis le portail.
- Modèle parent/enfant explicite (`representant_legal`).
- Bouton bouton-poussoir suppression compte (v0 = mailto).
- Saisie/consultation données médicales.
- Consultation justificatifs déjà déposés (NDF, formulaire participant).
- Bilan dashboard riche ("votre adhésion expire le X, Y séances cette semaine") — v0 dashboard = simple welcome + raccourcis sections.
- Bouton "Demander un remboursement de frais" pour les Tiers sans `pour_depenses` et sans NDF (procédure manuelle admin en v0).
- Renommage du flag `Tiers::pour_depenses` (sémantique évolue vers "prestataire/fournisseur facturé" mais nom historique conservé en v1 du programme).

## Prochaine étape

`/plan` sur ce slice quand tu valides la spec, puis `/build` (subagent-driven) sur la branche `feat/portail-membres-slice1-fondation-profil`.
