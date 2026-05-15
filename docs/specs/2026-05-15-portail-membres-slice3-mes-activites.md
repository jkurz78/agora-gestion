# Portail membres et participants — Slice 3 : Mes activités

- **Date** : 2026-05-15
- **Programme** : Portail membres et participants
- **Slice** : 3 / 4 — Mes activités (3 sections temporelles + alerte dashboard magic-link)
- **Branche cible** : `feat/portail-membres-slice1-fondation-profil` (option B git — toutes les slices sur une seule branche, MEP groupée)
- **Dépend de** : Slices 1 (F+A) et 2 (Mes adhésions + Mes dons) — 24 commits sur la branche.

## Pivot UX vs cadrage initial

Décisions actées 2026-05-15 :

1. **Vocabulaire** : le mot « opération » ne doit **jamais** apparaître côté UI portail. Toujours utiliser des libellés membre-friendly : « activité », « parcours de soins », « formation », « atelier », etc.
2. **Sidebar** : 1 entrée unique « Mes activités » (Option B après hésitation entre 1-par-type ou 1-unique). Les sous-types apparaissent en sous-tabs **dans l'écran**, pas dans la sidebar — plus stable, plus scalable.
3. **Structure de l'écran** : 3 sections temporelles (À venir / En cours / Terminées), pas de groupage par type au premier niveau (le type est une info affichée par carte).
4. **Pas de distinction payeur/participant** dans le portail (cf. dette parquée `project_dette_payeur_distinct_participant.md`). Tout doc rattaché à une participation est visible si la participation appartient au Tiers connecté. Le multi-tiers familial passe uniquement par le sélecteur OTP existant.
5. **Magic-link / FormulaireToken** : visible sur les opérations À venir et En cours (anomalie tolérée), masqué sur les Terminées (token n'a plus de valeur métier). Idem pour l'alerte dashboard.

## Décisions actées en cadrage (pré-spec)

| # | Décision | Détail |
| - | -------- | ------ |
| 1 | Classification temporelle | Avec séances : À venir = `min(seances.date) > today` / En cours = au moins 1 séance dans la période en cours / Terminée = `max(seances.date) < today`. Sans séance : utiliser `Operation.date_debut`/`date_fin` ; si null pour les deux, classer en « En cours » par défaut. |
| 2 | Timeline visuelle | Verticale, mobile-friendly. Pastille par séance avec couleur selon `Presence.statut` : verte (`Present`), orange (`Excuse`), rouge (`AbsenceNonJustifiee`), grise neutre (`Arret`), pastille bordée bleu vide pour séance future (pas encore renseignée). Date à droite. Tooltip au hover affiche le label complet du statut. |
| 3 | Attestations | **En cours** : 1 bouton « Attestation » par séance avec présence `Present`. **Terminées** : 1 bouton « Attestation globale » (récap toutes séances présentes). Réutilise les routes back-office existantes (`participants.attestation-recap-pdf` + `seances.attestation-pdf`) via routes portail dédiées avec auth `tiers-portail` + ownership. |
| 4 | Devis / factures | Visibles dès que la participation appartient au Tiers connecté. Pas de check payeur. Devis sur À venir + En cours. Facture en cours sur En cours (peut être en attente de paiement). Facture finale sur Terminées. |
| 5 | Alerte dashboard « Action requise » | 1 alerte par FormulaireToken actif (`expire_at > now AND rempli_at IS NULL`) attaché à un Participant du Tiers connecté **dont l'opération n'est pas Terminée**. Max 3 alertes affichées avec « +X autres » si plus. Bouton « Ouvrir le questionnaire » → `/formulaire?token=XXXX-XXXX` en nouvel onglet. |
| 6 | Opération sans séance | Pas de timeline, juste « Inscrit le X » + boutons docs/magic-link. Classification temporelle basée sur `Operation.date_debut`/`date_fin` ou En cours par défaut. |
| 7 | Magic-link affichage | Visible À venir + En cours, masqué Terminées. Sur la carte opération : code + bouton « Ouvrir le questionnaire » (target `_blank`). Aussi exposé sur le dashboard via l'alerte. |
| 8 | Sidebar | 1 entrée unique « Mes activités » (groupe « Mes activités »), visible si ≥ 1 Participation pour ce Tiers (peu importe le type). Ordre 80 (après « Ma vie de membre » 60-70). Sous-tabs par TypeOperation à l'intérieur de l'écran si le Tiers a des participations sur plusieurs types. |
| 9 | Dashboard | 1 tuile « Mes activités » dans le cadre « Mes activités » (cohérent sidebar). Pas de multiplication par type. Tuiles existantes (Mon profil, Mes adhésions, etc.) inchangées. |

## Hypothèses techniques verrouillées

| Item | État |
| ---- | ---- |
| `TiersOperationsTimelineService::forTiers(Tiers): ParticipationsTimelineDTO` | ✓ existe — liste plate des Participations du Tiers |
| `ParticipationLigneDTO` expose `participant`, `operation`, `typeOperation`, `dateInscription`, `estHelloasso`, `montantPaye` | ✓ |
| `Operation` a `date_debut`, `date_fin`, `nombre_seances` (cast date) | ✓ |
| `Seance` a `date` (cast date) + relation `presences` | ✓ |
| `Presence` a `statut` (cast `encrypted` → enum `App\Enums\StatutPresence`) | ✓ |
| `StatutPresence` enum : `Present`, `Excuse`, `AbsenceNonJustifiee`, `Arret` | ✓ |
| `FormulaireToken` a `participant_id`, `expire_at`, `rempli_at` (à confirmer), méthodes `isExpire()`, `isValide()`, `isUtilise()` | ✓ partiel — `rempli_at` à confirmer en build |
| `AttestationPresencePdfController` (back-office) — 2 endpoints `seance($op, $seance)` et `recap($op, $participant)` | ✓ |
| `AttestationPresenceLigneDTO` (slice 8 fiche tiers) déjà disponible | ✓ |
| `TypeOperation` n'a **pas** de `slug` ni `getRouteKeyName()` custom (utiliser `id` dans les URLs sub-tabs) | ✓ |
| `DocumentPrevisionnel` (devis/pro forma) rattaché à `participant_id` | ✓ |
| Pattern PortailSectionsResolver / Provider du slice 1 utilisable tel quel (pas de provider 0..N nécessaire grâce à Option B) | ✓ |

---

## 1. Intent Description

**Objectif** : étendre le portail membres avec un écran « Mes activités » qui rend vivante la consultation des participations à des opérations (parcours de soins, formations, ateliers, etc.). L'écran organise le contenu par **horizon temporel** (À venir / En cours / Terminées) plutôt que par type, parce que le membre veut savoir « qu'est-ce qui m'attend, qu'est-ce que je vis, qu'est-ce qui s'est passé », pas « quel type de chose est-ce que je suis ». Sous-tabs par type seulement si le Tiers a des participations sur plusieurs types — pas un onglet par type qui pollue la sidebar.

**Pourquoi maintenant** : c'est le dernier slice métier du programme portail v0 (le slice 4 « Mes communications » est plus simple, peut suivre rapidement). C'est aussi le slice où le pattern « génération à la demande » (déjà éprouvé sur les reçus en slice 2) est étendu aux attestations. Et c'est le slice où le portail prend sa dimension « pratique » : l'utilisateur trouve ses attestations, ses factures, ses questionnaires — il n'a plus besoin de demander à l'asso.

**Frontière** :
- Pas de modification de Participation, Présence, Séance depuis le portail (consultation seule)
- Pas de distinction payeur ≠ participant (cf. dette parquée)
- Pas de remplissage du formulaire participant **dans** le portail — le magic-link reste le mécanisme de saisie (ouverture en nouvel onglet)
- Pas de notification email rappel inscription / fin de parcours (hors scope programme)
- Vocabulaire : jamais « opération » côté UI ; toujours « activité » / nom du TypeOperation

**Acceptance** : un Tiers connecté ouvre « Mes activités » et voit en un coup d'œil ses futurs parcours, ce qui se passe maintenant (avec timeline présence + actions docs), et son historique. Si une URL magique est en attente, il est alerté dès le dashboard. Toutes les attestations et factures se téléchargent en nouvel onglet inline (pattern slice 2). Aucune participation d'un autre Tiers ne fuit. Aucune mention du mot « opération » dans le rendu HTML.

## 2. User-Facing Behavior (Gherkin)

```gherkin
Fonctionnalité: Portail membres — Mes activités

Contexte:
  Étant donné une association "MonAsso" avec slug "monasso"
  Et un Tiers identifié "Alice Martin" rattaché à cette association
  Et 3 TypeOperation existants : "Parcours de soins", "Formations", "Ateliers"

# ============================================================
# SIDEBAR + TABLEAU DE BORD
# ============================================================

Scénario: Sidebar — aucune participation
  Étant donné Alice n'a aucune Participation à aucune opération
  Quand Alice se connecte au portail
  Alors la sidebar n'affiche pas "Mes activités"
  Et le tableau de bord n'affiche pas la tuile "Mes activités"

Scénario: Sidebar — au moins une participation
  Étant donné Alice a au moins une Participation existante (peu importe le type)
  Quand Alice se connecte au portail
  Alors la sidebar affiche "Mes activités" dans le groupe "Mes activités"
  Et la tuile "Mes activités" apparaît sur le tableau de bord

Scénario: Vocabulaire — pas le mot "opération"
  Étant donné Alice est connectée et a des participations
  Quand elle ouvre /portail/mes-activites
  Alors le rendu HTML ne contient nulle part le mot "opération" (insensible à la casse, hors attributs HTML internes type data-*)

# ============================================================
# ALERTE DASHBOARD — MAGIC-LINK
# ============================================================

Scénario: Alerte dashboard — un magic-link en attente
  Étant donné Alice a un FormulaireToken actif (expire_at > now, rempli_at NULL) attaché à un Participant
  Et l'opération de ce Participant n'est pas Terminée
  Quand Alice ouvre le tableau de bord
  Alors une alerte "Action requise" est affichée tout en haut
  Et l'alerte mentionne le nom du TypeOperation et le nom de l'opération
  Et un bouton "Ouvrir le questionnaire" pointe vers /formulaire?token=XXXX-XXXX en target="_blank"

Scénario: Alerte dashboard — magic-link sur opération Terminée masqué
  Étant donné Alice a un FormulaireToken actif attaché à un Participant
  Et l'opération est Terminée (toutes les séances passées)
  Quand Alice ouvre le tableau de bord
  Alors aucune alerte n'est affichée pour ce token

Scénario: Alerte dashboard — plusieurs magic-links
  Étant donné Alice a 5 FormulaireToken actifs sur des opérations non Terminées
  Quand Alice ouvre le tableau de bord
  Alors 3 alertes sont affichées au maximum
  Et un message "+ 2 autre(s) action(s) en attente, voir Mes activités" complète

# ============================================================
# ÉCRAN "MES ACTIVITÉS" — STRUCTURE 3 SECTIONS
# ============================================================

Scénario: Section À venir — affichage minimal
  Étant donné Alice a une Participation à "Cycle 2026" du TypeOperation "Parcours de soins"
  Et toutes les séances de cette opération ont une date > today
  Quand Alice ouvre /portail/mes-activites
  Alors la section "À venir" contient une carte avec :
    - Type "Parcours de soins"
    - Nom "Cycle 2026"
    - Date de début (date de la 1ère séance ou Operation.date_debut)
    - Nombre de séances
  Et la section "En cours" et "Terminées" ne contiennent pas cette opération

Scénario: Section En cours — timeline présence + actions inline
  Étant donné Alice a une Participation à "Module avancé" du type "Formations"
  Et l'opération a 6 séances dont 3 dans le passé et 3 dans le futur
  Et Alice a `Presence.statut = Present` sur les 2 premières séances passées
  Et Alice a `Presence.statut = AbsenceNonJustifiee` sur la 3ème séance passée
  Quand Alice ouvre /portail/mes-activites
  Alors la section "En cours" contient une carte avec :
    - Type "Formations"
    - Nom "Module avancé"
    - Une timeline verticale de 6 pastilles dans l'ordre chronologique
    - Pastilles 1-2 vertes (Present)
    - Pastille 3 rouge (AbsenceNonJustifiee)
    - Pastilles 4-6 bordées bleu (futures, pas encore renseignées)
    - Un bouton "Voir attestation" sur les pastilles 1 et 2 (Present uniquement)
    - Pas de bouton sur les autres pastilles
    - Si un devis ou facture en cours est rattaché : bouton "Voir le devis" / "Voir la facture en cours"

Scénario: Section Terminées — affichage condensé
  Étant donné Alice a une Participation à "Cycle 2024" du type "Parcours de soins"
  Et toutes les séances ont une date < today
  Quand Alice ouvre /portail/mes-activites
  Alors la section "Terminées" contient une carte condensée avec :
    - Type "Parcours de soins"
    - Nom "Cycle 2024"
    - Période "Du 1 sept au 30 juin"
    - Nombre de séances
    - 1 bouton "Voir attestation globale" (recap)
    - 1 bouton "Voir la facture finale" (si facture finale émise)

Scénario: Sous-tabs par type — affichés si plusieurs types
  Étant donné Alice a des participations dans 2 TypeOperation distincts ("Parcours de soins" + "Formations")
  Quand Alice ouvre /portail/mes-activites
  Alors une barre de sous-tabs (nav-pills) en haut affiche "Parcours de soins" et "Formations"
  Et le tab actif par défaut est le premier (alphabétique)
  Et changer de tab filtre les 3 sections temporelles sur le type sélectionné

Scénario: Sous-tabs par type — non affichés si un seul type
  Étant donné Alice n'a que des participations dans "Parcours de soins"
  Quand Alice ouvre /portail/mes-activites
  Alors aucune barre de sous-tabs n'est affichée
  Et les 3 sections temporelles affichent directement les opérations de ce type unique

Scénario: Magic-link sur carte opération — visible et utilisable
  Étant donné Alice a un FormulaireToken actif sur une participation En cours
  Quand Alice ouvre /portail/mes-activites
  Alors la carte de l'opération concernée affiche le code "XXXX-XXXX"
  Et un bouton "Ouvrir le questionnaire" pointe vers /formulaire?token=XXXX-XXXX en target="_blank"

Scénario: Magic-link sur opération Terminée — masqué
  Étant donné Alice a un FormulaireToken actif (non utilisé, non expiré) attaché à un Participant
  Et l'opération est Terminée
  Quand Alice ouvre /portail/mes-activites
  Alors la carte de cette opération (section Terminées) ne mentionne pas le code ni le bouton questionnaire

Scénario: Téléchargement attestation par séance — ouverture en nouvel onglet inline
  Étant donné Alice a une Présence "Present" sur une séance d'une opération En cours
  Quand Alice clique "Voir attestation" sur cette séance
  Alors un nouvel onglet s'ouvre avec le PDF inline (Content-Type: application/pdf, Content-Disposition: inline)
  Et le filename respecte la convention "{slug-asso}-attestation-{nom-op}-{date-seance}.pdf" (ou similaire — à fixer en build)

Scénario: Téléchargement attestation globale — opération Terminée
  Étant donné Alice a une Participation à une opération Terminée avec ≥1 séance présente
  Quand Alice clique "Voir attestation globale"
  Alors un nouvel onglet s'ouvre avec le PDF récap inline (route portail dédiée avec ownership check)

# ============================================================
# OPÉRATION SANS SÉANCE
# ============================================================

Scénario: Opération sans séance — classification par dates Operation
  Étant donné Alice a une Participation à une opération qui n'a aucune Séance créée
  Et `Operation.date_debut` = 2026-09-01, `date_fin` = 2026-09-30
  Quand Alice ouvre /portail/mes-activites
  Alors selon la date du jour, l'opération apparaît dans la section À venir / En cours / Terminées (basé sur Operation.date_debut/date_fin)
  Et la carte n'affiche pas de timeline (pas de pastilles séance)
  Et le contenu se limite à : Type, Nom, Période, "Inscrit le X" (date_inscription)

Scénario: Opération sans séance ni dates Operation — défaut En cours
  Étant donné Alice a une Participation à une opération sans Séance et sans Operation.date_debut/date_fin
  Quand Alice ouvre /portail/mes-activites
  Alors l'opération apparaît dans la section "En cours" (défaut conservateur)

# ============================================================
# SÉCURITÉ — OWNERSHIP + MULTI-TENANT
# ============================================================

Scénario: Téléchargement attestation — Tiers ne peut accéder à celle d'un autre Tiers
  Étant donné Alice est connectée
  Et Bob est un autre Tiers de la même asso avec une participation à une opération
  Quand Alice tente d'accéder à l'URL d'attestation forgée pour le Participant de Bob
  Alors elle reçoit 403 ou 404 (ownership check)

Scénario: Téléchargement attestation — cross-tenant
  Étant donné Alice (asso A) est connectée
  Et un Participant existe en asso B
  Quand Alice tente l'URL d'attestation forgée pour ce Participant
  Alors elle reçoit 404 (TenantScope filtre)

Scénario: Pas d'autres données dans le DOM
  Étant donné Alice est connectée et a 1 participation
  Quand Alice ouvre /portail/mes-activites
  Alors le HTML rendu ne contient AUCUNE donnée d'autres participants (ni autres Tiers même asso, ni cross-tenant)

# ============================================================
# RÉGRESSION
# ============================================================

Scénario: Pas de régression slices 1+2
  Étant donné Alice est connectée
  Quand elle visite Tableau de bord, Mon profil, Mes adhésions, Mes dons, NDF (si applicable), FP (si applicable), Historique (si applicable)
  Alors toutes ces pages restent fonctionnelles à l'identique
  Et la sidebar liste correctement les 3 groupes selon les rôles du Tiers
```

## 3. Architecture Specification

### Composant Livewire

`App\Livewire\Portail\MesActivites` (final, layout `portail.layouts.authenticated`) :

- Mount `Association`. Trait `WithPortailTenant`.
- État Livewire :
  - `public ?int $typeOperationId = null` (sélection sub-tab, null = premier type alphabétique par défaut)
- `render()` :
  - Récupère les Participations du Tiers connecté via une query dédiée (pas le service Timeline plat car on a besoin de plus de données : séances, présences, devis, factures).
  - Calcule la liste des `TypeOperation` ayant ≥ 1 Participation pour ce Tiers (alphabétique).
  - Si `$typeOperationId` null et plusieurs types, sélectionne le premier alphabétique.
  - Filtre les Participations sur le type sélectionné.
  - Pour chaque Participation, calcule sa **classification temporelle** :
    - Si l'opération a ≥ 1 Séance : utiliser `min(date)` et `max(date)` des séances
      - À venir si `min > today`
      - En cours si `min <= today AND max >= today`
      - Terminée si `max < today`
    - Sinon, utiliser `Operation.date_debut` / `date_fin` :
      - Si non null pour les deux : même logique
      - Si null pour les deux : En cours (défaut)
  - Charge pour chaque participation : séances, présences, FormulaireToken actif, devis (DocumentPrevisionnel), facture (à clarifier en build — relation depuis transactions du règlement).
  - Passe au Blade : 3 collections (`aVenir`, `enCours`, `terminees`) chacune ordonnée chronologiquement.

### Vue Blade

`resources/views/livewire/portail/mes-activites.blade.php` :

- H4 « Mes activités »
- Si plusieurs `TypeOperation` actifs : barre de sous-tabs Bootstrap nav-pills, `wire:click` change `$typeOperationId`.
- 3 sections empilées dans l'ordre : À venir → En cours → Terminées (chacune avec un titre `H5`).
- Chaque section : si vide, message muted (« Aucune activité dans cette catégorie »). Sinon liste des cartes.

**Carte À venir** (compacte) :
- Type · Nom
- Date de début + nombre de séances
- Bloc magic-link si applicable (code + bouton « Ouvrir le questionnaire »)

**Carte En cours** (riche) :
- Type · Nom + statut « En cours »
- Timeline verticale des séances avec pastilles colorées (cf. CSS dans la section UI)
- Liste des actions inline : 1 bouton « Voir attestation » par séance présente + bouton devis / facture en cours si applicable
- Bloc magic-link si applicable

**Carte Terminée** (condensée) :
- Type · Nom
- Période « Du X au Y » + Nombre de séances
- Bouton « Voir attestation globale »
- Bouton « Voir facture finale » si applicable

### Timeline visuelle (Blade + CSS inline)

```html
<div class="seance-timeline">
  @foreach($seances as $seance)
    <div class="seance-item">
      <span class="pastille {{ $statutClass[$seance->id] ?? 'future' }}"
            title="{{ $statutLabel[$seance->id] ?? 'À venir' }}"></span>
      <span class="date">{{ $seance->date->format('d/m/Y') }}</span>
      @if($peutTelechargerAttestation[$seance->id] ?? false)
        <a class="btn btn-sm btn-outline-secondary"
           href="{{ \App\Support\PortailRoute::to('attestations.seance', $portailAssociation, [
              'operation' => $seance->operation_id,
              'seance' => $seance->id,
           ]) }}"
           target="_blank" rel="noopener">Voir attestation</a>
      @endif
    </div>
  @endforeach
</div>
```

CSS minimal : pastille = `width:14px; height:14px; border-radius:50%;` avec couleurs `bg-success`, `bg-warning`, `bg-danger`, `bg-secondary`, et bord bleu pour future. Disposition verticale via flex-direction column ou ligne verticale connectée par `border-left` sur le conteneur.

### Provider sidebar

`App\Services\Portail\Providers\MesActivitesProvider` (final, implements `PortailSectionProvider`) :

- Visible si `Participant::query()->where('tiers_id', $tiers->id)->exists()` (TenantScope filtre asso).
- DTO :
  - `id` = `"mes-activites"`
  - `label` = `"Mes activités"`
  - `routeName` = `"portail.mes-activites"`
  - `icon` = `"bi-calendar-event"` (à valider visuellement, ou `bi-activity`)
  - `ordre` = 80
  - `groupe` = `"Mes activités"`
  - `visible` = true, `badge` = null

Enregistré dans `App\Providers\PortailServiceProvider::boot()`.

### Routes (multi + mono)

`routes/portail.php` (groupe post-auth, à côté de `mes-adhesions` / `mes-dons`) :

```php
use App\Http\Controllers\Portail\AttestationPortailController;

Route::get('/mes-activites', MesActivites::class)->name('mes-activites');
Route::get('/attestations/seance/{operation}/{seance}',
    [AttestationPortailController::class, 'seance']
)->name('attestations.seance');
Route::get('/attestations/recap/{operation}/{participant}',
    [AttestationPortailController::class, 'recap']
)->name('attestations.recap');
```

`routes/portail-mono.php` mirror (full names `portail.mono.*`).

⚠️ Pattern route binding : aligner sur `RecuPortailController` (commit `d733fcd7`) qui résout manuellement `$request->route('xxx')` car `SubstituteBindings` tourne avant `BootTenantFromSlug`.

### Nouveau controller

`app/Http/Controllers/Portail/AttestationPortailController.php` :

Deux méthodes `seance(Request)` et `recap(Request)` qui :
1. Auth `tiers-portail` (déjà via middleware route group)
2. Résolvent `Operation`, `Seance`/`Participant` depuis les params route (avec `withoutGlobalScopes()` + check tenant manuel comme dans `RecuPortailController`)
3. Vérifient ownership : pour `seance`, le Tiers connecté doit avoir un `Participant` pour cette opération avec une `Presence::statut === Present` sur cette séance ; pour `recap`, le `Participant` doit appartenir au Tiers connecté
4. Délèguent au service de génération PDF (réutilisation logique du `AttestationPresencePdfController` back-office — soit appel direct, soit extraction d'un service partagé `AttestationPresencePdfService` à créer si la duplication est trop forte)
5. Retournent `Response` avec `Content-Type: application/pdf`, `Content-Disposition: inline; filename="..."` (cf. pattern slice 2 hotfix `RecuPortailController`)

**Convention filename** : `{slug-asso}-attestation-{slug-op}-{date}.pdf` ou `{slug-asso}-attestation-globale-{slug-op}.pdf`. À aligner sur la convention reçus (slice 2 hotfix) en proposant une méthode `pdfFilename()` sur un objet pivot ou directement dans le controller.

### Alerte dashboard

Modifier `App\Livewire\Portail\TableauDeBord::render()` pour calculer la liste des `FormulaireToken` actifs liés au Tiers connecté avec opération non-Terminée :

```php
$tokensActifs = FormulaireToken::query()
    ->whereHas('participant', fn($q) => $q->where('tiers_id', $tiers->id))
    ->where('expire_at', '>', now())
    ->whereNull('rempli_at')
    ->with(['participant.operation.typeOperation'])
    ->get()
    ->filter(fn($token) => /* opération non Terminée — utiliser le helper de classification */)
    ->take(3); // limiter à 3 alertes affichées
```

Passer à la vue `tableau-de-bord.blade.php` qui affiche les alertes en haut du contenu (avant les cadres groupes).

Helper de classification : extraire dans une classe dédiée `App\Services\Portail\ClassificationTemporelle` (statique) qui prend une `Participation`/`Operation` et retourne `enum HorizonTemporel { AVenir, EnCours, Terminee }`. Réutilisé par `MesActivites::render()` et `TableauDeBord::render()`.

### Tests

| Fichier | Type | Couvre |
| ------- | ---- | ------ |
| `tests/Unit/Services/Portail/ClassificationTemporelleTest.php` | Unit | 6 cas : avec séances (à venir / en cours / terminée), sans séances avec dates Operation (3 cas), sans séances ni dates (en cours par défaut) |
| `tests/Feature/Portail/MesActivitesTest.php` | Feature | Affichage 3 sections, sous-tabs (présents si N types, absents si 1), vocabulaire (assertNoSee `opération`), classification correcte, magic-link visible/masqué selon section |
| `tests/Feature/Portail/MesActivitesSecurityTest.php` | Feature/sécurité | Pas de fuite cross-Tiers / cross-tenant dans le rendu |
| `tests/Feature/Portail/AttestationPortailControllerTest.php` | Feature | Téléchargement attestation séance (200 + Content-Disposition inline + signature `%PDF-`) ; téléchargement attestation récap ; ownership intra-asso (403) + cross-tenant (404) |
| `tests/Feature/Portail/TableauDeBordAlertesMagicLinkTest.php` | Feature | Alerte affichée si token actif ; masquée si Terminée ; max 3 ; mention `+X autre(s)` |
| `tests/Unit/Portail/Providers/MesActivitesProviderTest.php` | Unit | Visible si ≥ 1 Participation, null sinon |
| `tests/Feature/Portail/MonoMesActivitesTest.php` | Feature | Mode mono : routes accessibles + contenu identique |

## 4. Acceptance Criteria

### Fonctionnels

- [ ] `MesActivitesProvider` enregistré, visible ssi Tiers a ≥ 1 Participation. Sidebar et tuile dashboard apparaissent.
- [ ] Vocabulaire : le mot « opération » n'apparaît **jamais** dans le rendu HTML public de `/portail/mes-activites` ni du dashboard (test assertNoSee).
- [ ] Classification temporelle calculée correctement (6 cas couverts par `ClassificationTemporelleTest`).
- [ ] Section À venir : carte compacte avec Type · Nom · Date début · Nombre de séances.
- [ ] Section En cours : carte avec timeline verticale séances + pastilles couleurs selon `Presence.statut` + bouton attestation par séance présente.
- [ ] Section Terminées : carte condensée avec période + bouton attestation globale + bouton facture finale (si applicable).
- [ ] Sous-tabs par TypeOperation : affichés ssi le Tiers a des participations sur ≥ 2 types ; sinon directement les sections temporelles.
- [ ] Magic-link visible sur cartes À venir + En cours, masqué sur Terminées.
- [ ] Alerte dashboard : 1 par token actif lié à participation non-Terminée, max 3 affichées, mention « +X autre(s) » si plus.
- [ ] Téléchargement attestation séance (route `portail.attestations.seance`) : 200, PDF inline, signature `%PDF-`, filename respect convention.
- [ ] Téléchargement attestation récap (route `portail.attestations.recap`) : idem pour récap globale.
- [ ] Mode mono (`portail-mono.php`) : routes mirror identiques.

### Sécurité

- [ ] Tiers ne peut télécharger une attestation rattachée à un Participant d'un autre Tiers (même asso) — test 403.
- [ ] Cross-tenant : Tiers asso A ne peut accéder à aucune attestation/donnée d'asso B — test 404 via TenantScope.
- [ ] Aucune donnée d'autres participants n'apparaît dans le DOM rendu de `/portail/mes-activites`.
- [ ] Logger émet `portail.attestation.seance.telecharge` et `portail.attestation.recap.telecharge` avec `participant_id` + `tiers_id`.

### Régression

- [ ] Slices 1+2 inchangés (sidebar, dashboard, MonProfil, MesAdhesions, MesDons, NDF/FP/Historique pour Tiers `pour_depenses`).
- [ ] Suite Pest verte (objectif 0 failure après ce slice).

### Non-fonctionnels

- [ ] Pint clean.
- [ ] Larastan baseline inchangée.
- [ ] Test manuel : first paint /mes-activites < 1s en localhost (perception manuelle, pas de test automatique).

## Consistency Gate

| Item | Verdict |
| ---- | ------- |
| Intent unambigu (deux devs interprètent pareil) | ✓ — frontière scope explicite, vocabulaire imposé, structure 3 sections détaillée |
| Chaque comportement intent → ≥ 1 scénario Gherkin | ✓ — 18 scénarios couvrent sidebar, dashboard, alerte magic-link, 3 sections, sous-tabs, opération sans séance, sécurité, régression |
| Architecture contrainte sans over-engineering | ✓ — capitalise sur services existants (`TiersOperationsTimelineService`, `AttestationPresencePdfController`, helpers slice 1) ; nouveau controller portail réutilise pattern slice 2 hotfix ; helper de classification factorisé pour double usage |
| Naming consistent (« Mes activités », « À venir / En cours / Terminées », types d'opération) | ✓ |
| Pas de contradiction entre artifacts | ✓ — règles magic-link cohérentes (alerte dashboard + carte cartouche) ; classification cohérente ; vocabulaire respecté |

**Verdict global : PASS.**

Réserves mineures à lever en `/plan` (n'invalident pas le PASS) :
1. Convention exacte du filename attestation (à figer dans le plan en référence à la convention reçus slice 2)
2. Comment relier une `Participation` à sa `Facture` finale (lien indirect via les `Transaction` issues des `Reglement` — à auditer en build et capturer dans une méthode helper)
3. `FormulaireToken.rempli_at` à confirmer comme champ existant (sinon adapter la query alerte dashboard)

## Hors scope (parqué)

- Distinction payeur ≠ participant (cf. dette parquée `project_dette_payeur_distinct_participant.md`)
- Modification d'une participation depuis le portail (consultation seule)
- Remplissage du formulaire participant directement dans le portail (le magic-link reste le mécanisme de saisie)
- Notifications email rappel inscription / fin de parcours
- Compteur dynamique « 3 séances cette semaine » sur la sidebar
- Animation timeline (statique en v0)
- 1 entrée sidebar par type d'opération (Option A initialement envisagée — abandonnée au profit de l'Option B unique)
- Provider 0..N sections sur le résolveur (pas nécessaire avec Option B)
- Renommage interne `Operation` → `Activite` dans le modèle (vocabulaire UI seulement, le modèle reste tel quel)

## Prochaine étape

`/agentic-dev-team:plan` sur ce slice quand tu valides la spec, puis `/agentic-dev-team:build` (subagent-driven Sonnet) sur la même branche `feat/portail-membres-slice1-fondation-profil`.
