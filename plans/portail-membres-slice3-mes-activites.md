# Plan: Portail membres et participants — Slice 3 : Mes activités

**Created**: 2026-05-15
**Branch**: `feat/portail-membres-slice1-fondation-profil` (option B git — toutes les slices sur une seule branche, MEP groupée)
**Status**: implemented (2026-05-15, 13 commits, 619 tests Portail / 0 failure, security PASS, spec compliance 22/22 PASS après gaps comblés)
**Spec**: [docs/specs/2026-05-15-portail-membres-slice3-mes-activites.md](../docs/specs/2026-05-15-portail-membres-slice3-mes-activites.md)
**Slices 1+2 statut** : implemented, validés en local. 24 commits sur la branche.

## Goal

Livrer l'écran portail « Mes activités » (3 sections temporelles À venir / En cours / Terminées avec sous-tabs par TypeOperation si plusieurs) et l'alerte dashboard « Action requise » pour les FormulaireToken en attente. Le membre y trouve ses participations passées, présentes et à venir avec timeline présence par séance, attestations téléchargeables (par séance + globale), devis et factures, et le code/lien magique pour remplir son questionnaire si applicable. **Aucun mot « opération » dans l'UI** — toujours « activité » + nom du TypeOperation. Aucune fuite cross-Tiers ni cross-tenant.

## Acceptance Criteria

- [ ] Helper `App\Services\Portail\ClassificationTemporelle` (statique) retourne l'enum `HorizonTemporel` (AVenir / EnCours / Terminee) selon les dates des séances ou à défaut `Operation.date_debut`/`date_fin` ; défaut « En cours » si tout est null. 6+ cas couverts en unit tests.
- [ ] Provider `MesActivitesProvider` enregistré dans `PortailServiceProvider`, visible ssi `Participant::query()->where('tiers_id', $tiers->id)->exists()`. Section sidebar « Mes activités » groupe « Mes activités », ordre 80, icône `bi-calendar-event`. Tuile dashboard correspondante apparaît.
- [ ] Vocabulaire portail : le mot « opération » (insensible à la casse) **n'apparaît jamais** dans le rendu HTML public de `/portail/mes-activites` ni du dashboard. Couvert par `assertDontSee('opération', false)` (case-insensitive).
- [ ] `/{slug}/portail/mes-activites` rend les 3 sections (À venir / En cours / Terminées). Chaque carte affiche Type · Nom + contenu spécifique à la section.
- [ ] Sous-tabs par TypeOperation : affichés ssi le Tiers a des participations sur ≥ 2 types ; tab actif par défaut = premier type alphabétique.
- [ ] Section En cours : timeline verticale des séances avec pastilles colorées par `Presence.statut` (verte=Present, orange=Excuse, rouge=AbsenceNonJustifiee, grise=Arret) + bordée bleu vide pour séance future.
- [ ] Bouton « Voir attestation » sur chaque pastille `Present` d'une opération En cours (route portail `attestations.seance`, ouvre PDF inline en nouvel onglet).
- [ ] Bouton « Voir attestation globale » sur chaque carte d'opération Terminée (route portail `attestations.recap`).
- [ ] Bouton « Voir le devis » sur les cartes À venir + En cours si DocumentPrevisionnel rattaché à la participation.
- [ ] Bouton « Voir la facture en cours » sur les cartes En cours et « Voir la facture finale » sur Terminées si Facture rattachée à la participation (lien indirect via Reglement → Transaction → TransactionLigne → FactureLigne → Facture).
- [ ] Magic-link visible (code + bouton « Ouvrir le questionnaire ») sur les cartes À venir + En cours uniquement, si FormulaireToken actif (`expire_at >= today AND rempli_at IS NULL`).
- [ ] Alerte dashboard « Action requise » : 1 alerte par FormulaireToken actif lié à une participation **non Terminée**, max 3 affichées, mention « +X autre(s) action(s) en attente, voir Mes activités » si plus.
- [ ] Opération sans séance : pas de timeline, juste « Inscrit le X » + boutons docs/magic-link. Classification basée sur `Operation.date_debut`/`date_fin` ou En cours par défaut.
- [ ] Sécurité — Tiers ne peut télécharger une attestation rattachée à un autre Tiers (test 403 intra-asso).
- [ ] Sécurité — Cross-tenant : Tiers asso A ne peut accéder à aucune attestation/donnée d'asso B (test 404).
- [ ] Sécurité — Aucune donnée d'autres participants n'apparaît dans le DOM rendu de `/portail/mes-activites`.
- [ ] Logger émet `portail.attestation.seance.telecharge` et `portail.attestation.recap.telecharge` avec `participant_id` + `tiers_id`.
- [ ] Convention filename attestation, alignée sur slice 2 reçus : `{slug-asso}-attestation-seance-{seance_id}.pdf` (route séance) et `{slug-asso}-attestation-recap-{participant_id}.pdf` (route recap). Méthode helper centralisée dans le controller.
- [ ] Mode mono : routes mirror identiques (`portail.mono.mes-activites`, `portail.mono.attestations.*`).
- [ ] Régression : sidebar slices 1+2 inchangée pour les Tiers sans Participation. Tableau de bord, MonProfil, MesAdhesions, MesDons, NDF, FP, Historique restent fonctionnels.
- [ ] Suite Pest verte (0 failure). Pint clean. Larastan baseline inchangée.

## Pré-décisions actées (cf. spec)

| Point | Décision |
| ----- | -------- |
| Vocabulaire | Jamais « opération » côté UI — toujours « activité » + nom TypeOperation |
| Sidebar | 1 seule entrée « Mes activités » (Option B) |
| Structure écran | 3 sections temporelles, sous-tabs par TypeOperation si plusieurs |
| Distinction payeur/participant | Pas de check (cf. dette parquée) |
| Magic-link | Visible À venir + En cours, masqué Terminées |
| Alerte dashboard | Max 3, exclut tokens des opérations Terminées |
| Attestations | Par séance (En cours) + globale (Terminées) |
| Timeline | Verticale, pastilles couleurs selon `Presence.statut` |
| Opération sans séance | Pas de timeline, juste « Inscrit le X » |

## Hypothèses techniques verrouillées

| Item | État |
| ---- | ---- |
| `App\Models\FormulaireToken` champs : `participant_id`, `token`, `expire_at` (date), `rempli_at` (datetime) | ✓ confirmé |
| Critère token actif : `expire_at >= today AND rempli_at IS NULL` (NB : `expire_at` date, comparer à `today()`) | ✓ |
| `App\Models\Operation` : `date_debut`, `date_fin`, `nombre_seances` (cast date / int) | ✓ |
| `App\Models\Seance` : `date` (cast date) | ✓ |
| `App\Models\Presence` : `statut` (cast `encrypted` → `App\Enums\StatutPresence` à filtrer en PHP) | ✓ |
| `App\Enums\StatutPresence` : `Present`, `Excuse`, `AbsenceNonJustifiee`, `Arret` | ✓ |
| `App\Http\Controllers\AttestationPresencePdfController` (back-office) avec méthodes `seance($op, $seance)` + `recap($op, $participant)` | ✓ |
| `App\Models\Facture` lien indirect : `Facture` → `FactureLigne.transaction_ligne_id` → `Transaction.reglement_id` → `Reglement.participant_id` | ✓ confirmé |
| `App\Models\TypeOperation` n'a pas de slug (utiliser `id` dans les sub-tabs) | ✓ |
| `App\Models\DocumentPrevisionnel` rattaché à `participant_id` | ✓ |
| Pattern PortailSectionsResolver / Provider du slice 1 utilisable tel quel | ✓ |
| Pattern HTTP route inline + nouvel onglet (slice 2 hotfix `RecuPortailController`) à reproduire | ✓ |

## Steps

### Step 1: Enum `HorizonTemporel` + Helper `ClassificationTemporelle`

**Complexity**: standard

**RED**: `tests/Unit/Services/Portail/ClassificationTemporelleTest.php` — 6 cas :
1. Opération avec ≥1 séance, toutes futures → `AVenir`
2. Opération avec séances chevauchant aujourd'hui → `EnCours`
3. Opération avec ≥1 séance, toutes passées → `Terminee`
4. Opération sans séance, `date_debut > today` → `AVenir`
5. Opération sans séance, `date_debut <= today AND date_fin >= today` → `EnCours`
6. Opération sans séance, `date_fin < today` → `Terminee`
7. Opération sans séance ni dates → `EnCours` (défaut)

**GREEN** :
- Créer `app/Enums/HorizonTemporel.php` (cases AVenir/EnCours/Terminee + label)
- Créer `app/Services/Portail/ClassificationTemporelle.php` final avec méthode statique `pour(Operation $op): HorizonTemporel`
- Logique : si `$op->seances->isNotEmpty()` → utiliser min/max des dates séances ; sinon utiliser `date_debut`/`date_fin` ; sinon défaut `EnCours`

**REFACTOR** : aucun.

**Files**: `app/Enums/HorizonTemporel.php`, `app/Services/Portail/ClassificationTemporelle.php`, `tests/Unit/Services/Portail/ClassificationTemporelleTest.php`

**Commit**: `feat(portail): helper de classification temporelle des opérations (AVenir/EnCours/Terminee)`

---

### Step 2: Provider `MesActivitesProvider`

**Complexity**: standard

**RED**: `tests/Unit/Portail/Providers/MesActivitesProviderTest.php` — 2 cas :
1. Tiers avec ≥1 Participation → DTO non null avec id/label/route/icon/ordre/groupe attendus
2. Tiers sans Participation → null

**GREEN** :
- Créer `app/Services/Portail/Providers/MesActivitesProvider.php`
- DTO : `id="mes-activites"`, `label="Mes activités"`, `routeName="portail.mes-activites"`, `icon="bi-calendar-event"`, `ordre=80`, `groupe="Mes activités"`
- Critère : `Participant::query()->where('tiers_id', $tiers->id)->exists()` (TenantScope filtre asso)
- Enregistrer dans `App\Providers\PortailServiceProvider::boot()`

**REFACTOR** : aucun.

**Files**: provider + test + édition `PortailServiceProvider`

**Commit**: `feat(portail): provider sidebar Mes activités (visible si ≥1 participation)`

---

### Step 3: Composant Livewire `MesActivites` — squelette + 3 sections

**Complexity**: complex (nouvel écran, UI structurée, vocabulaire à respecter)

**RED**: 2 fichiers de test :

`tests/Feature/Portail/MesActivitesTest.php` — premières assertions :
1. Authentifié + 1 Participation à venir → page rend H4 « Mes activités », section À venir contient nom de l'opération, sections En cours/Terminées vides
2. Pareil avec En cours, et avec Terminée (3 cas)
3. Vocabulaire : `assertDontSee('opération', false)` ET `assertDontSee('Operation', false)` sur le rendu HTML
4. Sans participation → 200 mais sections toutes vides avec message muted

`tests/Feature/Portail/MesActivitesSecurityTest.php` — sécurité DOM (Gherkin "Pas d'autres données dans le DOM") :
5. Alice connectée + 2 Participations Alice + 3 Participations Bob (autre Tiers même asso) → la réponse de `/portail/mes-activites` ne contient AUCUN nom d'opération de Bob (assertDontSee sur les libellés des opérations de Bob)
6. Cross-tenant : Alice asso A + Participation existant en asso B → la réponse ne contient pas le libellé de l'opération asso B (TenantScope filtre, vérification explicite)

**GREEN** :
- Créer `app/Livewire/Portail/MesActivites.php` (full-page, layout `portail.layouts.authenticated`, trait `WithPortailTenant`)
- `render()` :
  - Récupère Participations du Tiers via `Participant::query()->where('tiers_id', $tiers->id)->with(['operation.typeOperation', 'operation.seances'])->get()`
  - Pour chaque, calcule horizon via `ClassificationTemporelle::pour($participant->operation)`
  - Groupe en 3 collections `aVenir`, `enCours`, `terminees` (ordonnées chronologiquement)
- Créer `resources/views/livewire/portail/mes-activites.blade.php` :
  - H4 « Mes activités »
  - 3 sections H5 + cartes minimales (juste Type · Nom + date début pour chaque)
  - Vocabulaire strict : `Type` libellé via `$participant->operation->typeOperation->nom`, jamais le mot littéral « opération »
- Ajouter route dans `routes/portail.php` (post-auth) : `Route::get('/mes-activites', MesActivites::class)->name('mes-activites')`

**REFACTOR** : extraire les 3 sections en partials Blade si > 100 lignes inline.

**Files**: composant + vue + route + test

**Commit**: `feat(portail): écran Mes activités — squelette 3 sections temporelles`

---

### Step 4: Sous-tabs par TypeOperation

**Complexity**: standard

**RED**: extension `MesActivitesTest.php` — 3 cas :
1. Tiers avec participations sur 2 types distincts → barre de sub-tabs visible (assertSee « Parcours de soins » et « Formations »)
2. Tiers avec participations sur 1 seul type → barre de sub-tabs absente
3. Tiers change de tab via `wire:click` → sections filtrées sur le type sélectionné

**GREEN** :
- Ajouter état Livewire `public ?int $typeOperationId = null`
- `render()` calcule les `TypeOperation` distincts (alphabétique) ; si `$typeOperationId` null et plusieurs types, sélectionne le premier
- Filtre les Participations sur le type sélectionné avant le calcul horizon
- Vue : ajouter une `<nav class="nav nav-pills">` avec `wire:click="$set('typeOperationId', X)"` par type, classe `.active` si sélectionné

**REFACTOR** : aucun.

**Files**: composant + vue + test

**Commit**: `feat(portail): sous-tabs par type d'activité dans Mes activités`

---

### Step 5: Timeline visuelle (section En cours)

**Complexity**: standard (CSS custom + logique présence)

**RED**: `tests/Feature/Portail/MesActivitesTimelineTest.php` :
1. Opération En cours avec 6 séances (3 passées + 3 futures), Tiers a `Presence(Present)` sur 1+2, `Presence(AbsenceNonJustifiee)` sur 3 → timeline rend 6 pastilles dans l'ordre chronologique avec classes CSS attendues : `.bg-success` ×2, `.bg-danger` ×1, `.bg-future` (ou équivalent vide bordé) ×3
2. Statut `Excuse` → classe `.bg-warning`
3. Statut `Arret` → classe `.bg-secondary`

**GREEN** :
- Étendre le composant pour précharger `Presence` par participant (`with('presences')`)
- Construire un map `[seance_id => statut]` par participation
- Vue : extraire un partial `_timeline-seances.blade.php` avec pastilles + dates + tooltip label
- CSS inline minimal (pastille = `width:14px height:14px border-radius:50%`, ligne verticale via border-left du conteneur, gap entre items)

**REFACTOR** : si CSS > 30 lignes, extraire dans `resources/views/portail/layouts/partials/timeline.css.blade.php` chargé une fois.

**Files**: composant + vue + partial + test

**Commit**: `feat(portail): timeline verticale séances avec pastilles colorées par statut présence`

---

### Step 6: Magic-link sur cartes (À venir + En cours)

**Complexity**: standard (logique conditionnelle + lien externe)

**RED**: `tests/Feature/Portail/MesActivitesMagicLinkTest.php` :
1. FormulaireToken actif sur participation À venir → carte affiche le code « XXXX-XXXX » + bouton avec `href="/formulaire?token=..."` `target="_blank"`
2. FormulaireToken actif sur participation En cours → idem
3. FormulaireToken actif sur participation Terminée → ni code ni bouton sur la carte
4. FormulaireToken expiré (`expire_at < today`) → masqué
5. FormulaireToken `rempli_at` non null → masqué

**GREEN** :
- Étendre `render()` pour précharger `formulaireToken` par participant (`with('formulaireToken')`)
- Helper local `tokenActifEtUtilisable(Participant): ?FormulaireToken` qui retourne le token si actif sinon null
- Vue : ajouter un bloc magic-link sur cartes À venir + En cours uniquement
- L'URL du formulaire utilise la route existante (probablement `/formulaire?token=XXXX-XXXX` — à vérifier en build, déjà existant)

**REFACTOR** : aucun.

**Files**: composant + vue + test

**Commit**: `feat(portail): bloc magic-link questionnaire sur cartes Mes activités`

---

### Step 7: Liens devis + factures inline

**Complexity**: complex (lien indirect Facture-Participation + UI factures conditionnelles)

**RED**: `tests/Feature/Portail/MesActivitesDocumentsTest.php` :
1. Participation À venir avec DocumentPrevisionnel (devis) → bouton « Voir le devis » présent
2. Participation En cours avec Facture en cours rattachée (via Reglement→Transaction→TransactionLigne→FactureLigne→Facture) → bouton « Voir la facture en cours »
3. Participation Terminée avec Facture finale → bouton « Voir la facture finale »
4. Sans devis / sans facture → boutons absents

Note : la création d'une facture liée à une participation pour les tests requiert d'enchaîner les modèles. Si trop coûteux, mocker via factory states ou créer un helper de test.

**GREEN** :
- Helper sur `Participant` : `factureRattachee(): ?Facture` qui fait la query `Facture::query()->whereHas('lignes.transactionLigne.transaction.reglement', fn($q) => $q->where('participant_id', $this->id))->latest('id')->first()` (à valider en build : la chaîne de relations existe-t-elle bien ? sinon adapter)
- Helper sur `Participant` : `devisRattaches(): Collection<DocumentPrevisionnel>` (filtre type devis si applicable)
- Vue : afficher les boutons inline dans chaque carte selon section
- Les boutons pointent vers les routes existantes (back-office ou nouvelles routes portail à créer en Step 8 si besoin pour devis/factures côté membre — sinon réutiliser routes existantes avec auth `tiers-portail` ?)

**Important** : il faut vérifier en build si une route portail dédiée est nécessaire pour les factures et devis (vs réutilisation back-office). Si oui, ajouter au step 8 ou faire un step séparé. Les attestations sont en step 8 ; on peut grouper si elles utilisent le même controller.

**REFACTOR** : aucun.

**Files**: helpers Participant + vue + test

**Commit**: `feat(portail): liens devis et factures inline dans Mes activités`

---

### Step 8: Controller `AttestationPortailController` + 2 routes (séance + récap)

**Complexity**: complex (sécurité — ownership intrusion + multi-tenant + génération PDF)

**RED**: `tests/Feature/Portail/AttestationPortailControllerTest.php` :
1. **Téléchargement attestation séance** : participation Tiers + Présence Present sur séance → GET 200, Content-Type application/pdf, Content-Disposition contient `inline`, body commence par `%PDF-`
2. **Téléchargement attestation récap** : participation Tiers Terminée → GET 200, idem
3. **Intrusion intra-asso** : Alice connectée tente l'URL d'attestation séance pour le Participant de Bob (autre Tiers même asso) → 403
4. **Intrusion cross-tenant** : Alice asso A tente URL en asso B → 404 (TenantScope)
5. **Filename** : Content-Disposition mentionne `{slug-asso}-attestation-...`
6. **Logger** : `Log::spy()` reçoit `portail.attestation.seance.telecharge` ou `portail.attestation.recap.telecharge` avec `participant_id` + `tiers_id`

**GREEN** :
- Créer `app/Http/Controllers/Portail/AttestationPortailController.php` avec 2 méthodes `seance(Request)` et `recap(Request)` :
  - Auth : `Auth::guard('tiers-portail')->user()` (déjà via middleware route group)
  - Résolution manuelle des params (pattern slice 2 hotfix car SubstituteBindings tourne avant BootTenantFromSlug) :
    - `seance` : `$opId = $request->route('operation')` + `$seanceId = $request->route('seance')` ; `Operation::find($opId)` (TenantScope) ; `Seance::find($seanceId)` (n'extends pas TenantModel — guard manuel sur `operation_id`)
    - `recap` : `$opId = $request->route('operation')` + `$participantId = $request->route('participant')` ; idem
  - Ownership :
    - `seance` : un Participant existe pour `$tiers->id + $opId` ET il a `Presence(Present)` sur `$seanceId`
    - `recap` : Participant `id=$participantId` existe ET `tiers_id === $tiers->id`
  - Délégation génération PDF : extraire la logique de `AttestationPresencePdfController` (back-office) dans un service partagé `App\Services\AttestationPresencePdfService` avec 2 méthodes `seance($op, $seance, $participantIds): string` (binary content) et `recap($op, $participant): string`. Le back-office et le portail appellent ce service.
  - Retourne `response($contents, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="..."', 'Content-Length' => strlen($contents), 'Cache-Control' => 'private, no-cache, no-store, must-revalidate'])`
  - Filename helper aligné slice 2 : `{$asso->slug}-attestation-seance-{$seance->id}.pdf` ou `{$asso->slug}-attestation-recap-{$participant->id}.pdf` (pas de numéro CERFA car pas pertinent ici).
  - Logger après génération : `Log::info('portail.attestation.seance.telecharge', [...])`

- Routes dans `routes/portail.php` (post-auth) :
```php
Route::get('/attestations/seance/{operation}/{seance}', [AttestationPortailController::class, 'seance'])->name('attestations.seance');
Route::get('/attestations/recap/{operation}/{participant}', [AttestationPortailController::class, 'recap'])->name('attestations.recap');
```

**REFACTOR** : si le service `AttestationPresencePdfService` clarifie le code des 2 controllers, refactor le back-office `AttestationPresencePdfController` pour s'appuyer dessus aussi (DRY).

**Files**: controller + service partagé + 2 routes + test feature

**Commit**: `feat(portail): controller AttestationPortail (séance + récap) avec ownership strict + service partagé`

---

### Step 9: Modification `TableauDeBord` — alerte « Action requise » magic-link

**Complexity**: standard

**RED**: `tests/Feature/Portail/TableauDeBordAlertesMagicLinkTest.php` :
1. Tiers avec 1 FormulaireToken actif sur participation En cours → 1 alerte affichée avec mention nom opération + bouton « Ouvrir le questionnaire »
2. Tiers avec FormulaireToken sur participation Terminée → aucune alerte
3. Tiers avec 5 FormulaireToken actifs sur participations non Terminées → 3 alertes + mention « + 2 autre(s) action(s) en attente, voir Mes activités »
4. Tiers sans aucun FormulaireToken → aucune alerte

**GREEN** :
- Étendre `TableauDeBord::render()` pour calculer la liste des tokens actifs filtrés sur opération non-Terminée :
```php
$tokens = FormulaireToken::query()
    ->whereHas('participant', fn($q) => $q->where('tiers_id', $tiers->id))
    ->where('expire_at', '>=', today())
    ->whereNull('rempli_at')
    ->with(['participant.operation.typeOperation', 'participant.operation.seances'])
    ->get()
    ->reject(fn($t) => ClassificationTemporelle::pour($t->participant->operation) === HorizonTemporel::Terminee)
    ->values();

$alertes = $tokens->take(3);
$autres = max(0, $tokens->count() - 3);
```
- Passer à la vue `tableau-de-bord.blade.php` les 2 vars `$alertes` et `$autres`
- Vue : ajouter en haut (avant les cadres groupes) un bloc d'alertes Bootstrap (`.alert-warning` ou `.alert-info`) — 1 par token avec mention du TypeOperation + nom opération + bouton lien `/formulaire?token=XXXX-XXXX` `target="_blank"`. Si `$autres > 0`, ajouter une note discrète après les 3 alertes.

**REFACTOR** : extraire les alertes en partial `resources/views/livewire/portail/_alertes-magic-link.blade.php` si propre.

**Files**: TableauDeBord + tableau-de-bord.blade.php + test

**Commit**: `feat(portail): alerte dashboard "Action requise" pour magic-links en attente`

---

### Step 10: Mode mono — routes miroir + tests parité

**Complexity**: standard

**RED**: `tests/Feature/Portail/MonoMesActivitesTest.php` :
1. Mode mono actif + Tiers connecté GET `/portail/mes-activites` → 200, contenu identique
2. Téléchargement attestation séance depuis mode mono → 200 PDF
3. Téléchargement attestation récap depuis mode mono → 200 PDF

Pattern : voir `tests/Feature/Portail/MonoMesAdhesionsEtDonsTest.php` pour la mise en place mono.

**GREEN** :
- Modifier `routes/portail-mono.php` : ajouter les 3 routes mirror (`mes-activites`, `attestations.seance`, `attestations.recap`) avec les imports nécessaires
- Names complets : `portail.mono.mes-activites`, `portail.mono.attestations.seance`, `portail.mono.attestations.recap`

**REFACTOR** : aucun.

**Files**: routes/portail-mono.php + test feature

**Commit**: `feat(portail): mode mono — parité routes mes-activites + attestations`

---

### Step 11: Documentation portail

**Complexity**: trivial (doc only)

**RED**: N/A

**GREEN** : Mettre à jour `docs/portail-tiers.md` :
- Nouvelle section « Slice 3 (D) — Mes activités (2026-05-15) » courte avec liens spec + plan
- Compléter la table des providers fondation pour inclure `MesActivitesProvider` (ordre 80)
- Documenter le pattern « Classification temporelle » (helper réutilisé Mes activités + alerte dashboard)
- Documenter la convention filename attestation : `{slug-asso}-attestation-{type}-{numero}.pdf`
- Documenter la règle vocabulaire : interdire le mot « opération » côté UI portail
- Documenter le critère token actif : `expire_at >= today AND rempli_at IS NULL` (NB : `expire_at` est un `date`, comparer à `today()` pas `now()`)

**REFACTOR** : aucun.

**Files**: docs/portail-tiers.md

**Commit**: `docs(portail): documenter slice 3 (Mes activités + alerte magic-link + classification temporelle)`

---

## Complexity Classification

| Step | Complexity |
|------|-----------|
| 1 — Helper ClassificationTemporelle | standard |
| 2 — Provider MesActivites | standard |
| 3 — Composant squelette 3 sections | **complex** (UI structurée + vocabulaire) |
| 4 — Sous-tabs par TypeOperation | standard |
| 5 — Timeline visuelle séances | standard |
| 6 — Magic-link sur cartes | standard |
| 7 — Liens devis + factures inline | **complex** (lien indirect Facture, query chaînée) |
| 8 — AttestationPortailController + service partagé | **complex** (sécurité + génération PDF + extraction service) |
| 9 — Alerte dashboard | standard |
| 10 — Mode mono | standard |
| 11 — Documentation | trivial |

## Pre-PR Quality Gate

- [ ] Suite Pest verte (objectif ~590+ tests Portail / 0 failure)
- [ ] `./vendor/bin/sail bin pint` clean
- [ ] Larastan baseline inchangée
- [ ] `/code-review --changed` passe sur le diff vs `main`
- [ ] Test manuel localhost (port 80) :
  - Ouvrir `/{slug}/portail/mes-activites` avec un Tiers ayant des participations diverses → vérifier 3 sections + sous-tabs + timeline
  - Tester sur opération sans séance (pas de timeline)
  - Téléchargement attestation par séance + récap → ouverture nouvel onglet, PDF inline lisible Acrobat
  - Téléchargement devis et facture → idem
  - Magic-link visible sur À venir + En cours, masqué Terminée
  - Alerte dashboard apparaît si token actif sur op non Terminée
  - Vérifier l'absence du mot « opération » dans le DOM (Ctrl+F)
- [ ] `docs/portail-tiers.md` à jour

## Risks & Open Questions

| # | Risque / Question | Mitigation / Réponse |
| - | ----------------- | -------------------- |
| 1 | Lien indirect `Participation → Facture` chaîne complexe (5 niveaux). La relation `Transaction → reglement` existe-t-elle ou faut-il `whereHas('reglement')` via `Reglement.transaction_id` ? | Step 7 : auditer `app/Models/Reglement.php` ligne 51 (`hasOne(Transaction::class, 'reglement_id')`) et `Transaction` réciproque. Adapter la query `whereHas` selon. |
| 2 | Convention exacte filename attestation : `{slug}-attestation-seance-{seance_id}.pdf` vs `{slug}-attestation-{date}-{participant}.pdf` | Step 8 : trancher en build, pencher pour la 1ère (court et suffisant). |
| 3 | Service partagé `AttestationPresencePdfService` : extraction depuis `AttestationPresencePdfController` peut casser le back-office si la signature change | Step 8 : extraire avec signature stable, refactor le back-office pour appeler le service, valider que les tests existants back-office passent. |
| 4 | Le DOM doit ne pas contenir « opération ». Mais une URL/route route name peut contenir le mot (ex `route('participants.attestation-recap-pdf')`). Le test `assertDontSee('opération', false)` doit ignorer les attributs HTML internes ? | Step 3 : la route name n'apparaît pas dans le DOM rendu (c'est une string PHP). Test sur le rendu HTML uniquement. Si un libellé interne contient « opération », ce sera détecté → on adapte ou on l'élimine. |
| 5 | `Operation.date_debut`/`date_fin` peut être null. Si c'est le cas et qu'il y a au moins 1 séance, on utilise les séances. Si null+pas de séance → En cours par défaut. Bien testé en step 1. | Step 1 : tests unit couvrent les 7 cas. |
| 6 | TypeOperation n'a pas de slug → on utilise l'ID dans `wire:click="$set('typeOperationId', X)"`. OK pour Livewire mais peu sympathique en URL. Pas d'impact UX si l'état est uniquement sidebar internal. | Step 4 : pas d'URL dédiée par tab, juste un état Livewire. OK. |
| 7 | Si un Tiers a beaucoup de Participations (50+), précharger toutes les séances + présences peut être lourd. | v0 : pas d'optimisation. À monitorer. |
| 8 | Magic-link `/formulaire?token=...` route existe (cf. spec). Vérifier qu'elle est bien publique et fonctionnelle. | Step 6 : grep `route('formulaire')` ou similaire en build. |
| 9 | Refactor service `AttestationPresencePdfService` est dans le scope back-office aussi. Risque de régression. | Step 8 : couvrir avec les tests back-office existants si présents, sinon passer en revue manuelle. |

## Notes d'exécution

- **Mode subagent-driven** (préférence projet) — Opus planifie, Sonnet exécute.
- **Inline review checkpoints** sur les steps complex (3, 7, 8) : security-review minimum sur step 8 (téléchargement PDF + ownership).
- **Branche unique** : on continue sur `feat/portail-membres-slice1-fondation-profil`. Pas de merge intermédiaire vers main.
- **Pas de push prod** : test local d'abord (préférence projet `feedback_test_before_push.md`).
- **Cast (int) strict des deux côtés** dans tous les `===` PK/FK (préférence projet `feedback_int_cast_prod.md`).
- **Vocabulaire** : le mot « opération » est interdit côté UI portail. Le subagent doit relire ses libellés Blade avant commit.

## Estimation

11 steps total : 3 complex (3, 7, 8), 7 standard, 1 trivial. Slice plus gros que slice 2 (7 steps) car nouvelles surfaces (timeline, attestations, alerte dashboard) et lien indirect facture. Mais capitalise lourdement sur slices 1+2 (resolver, layout, pattern HTTP inline, helpers Association).
