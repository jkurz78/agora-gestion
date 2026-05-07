# Newsletter — Import buffer → Tiers (slice 2)

**Date** : 2026-05-06
**Statut** : spec en revue
**Programme** : Newsletter publique — finalisation du flux côté back-office
**Périmètre** : nouvel écran Livewire `/newsletter/inscriptions` (2 onglets : Inscriptions à traiter / Désinscriptions à traiter), 4ᵉ entrée du dropdown topbar Boîte de réception, services métier et tests Pest.
**Préalables** : slice 1 mergé en prod (v4.2.5) — la table `newsletter_subscription_requests` est peuplée par les inscriptions confirmées, mais aucune IHM ne la lit côté back-office. Multi-tenant v4.0.0. Pattern `TiersMergeModal` existant (v2.5.1).

---

## 1. Intent Description

**Quoi.** Donner aux administrateurs/comptables d'une asso une IHM unique pour traiter les évènements newsletter qui requièrent une décision humaine :

1. **File "Inscriptions à traiter"** : demandes confirmées en attente d'import vers la table `tiers`. Pour chacune, l'admin choisit : *Créer un nouveau Tiers*, *Fusionner avec un Tiers existant* (via `TiersMergeModal`), ou *Ignorer* (cas spam confirmé, ex-membre qui ne veut pas être réimporté).
2. **File "Désinscriptions à traiter"** : abonnés précédemment importés qui ont cliqué le lien de désinscription. Pour chacun, l'admin choisit : *Désabonner le Tiers* (set `email_optout=true`), *Supprimer le Tiers* (si Tiers orphelin sans dépendances), ou *Acter sans rien faire* (cas où le Tiers a une autre raison d'exister, ex. un fournisseur).

**Pourquoi.** La spec de slice 1 (`docs/specs/2026-05-02-newsletter-public-api.md`) a livré l'API publique et le buffer, mais a explicitement reporté l'import vers `tiers` à une PR ultérieure (cf. §5 "Hors-scope"). En prod, la table buffer se remplit sans qu'on puisse la lire — c'est un trou fonctionnel. Symétriquement, les désinscriptions traversent la même file invisible et risquent de générer des envois "non sollicités" si jamais l'admin lance une campagne sur le module Communication Tiers existant en s'appuyant sur des Tiers importés via la newsletter.

**Pourquoi maintenant.** Le formulaire d'inscription est déjà branché en prod sur le site `soigner-vivre-sourire.fr`. La file buffer accumule. La désinscription réelle doit pouvoir être traitée avant le premier envoi de newsletter — c'est un prérequis RGPD opérationnel pour basculer en mode actif.

**Quoi ce n'est pas.**
- Pas un module d'envoi de newsletter (toujours hors scope, repose sur Communication Tiers v2.12.0 quand on l'utilisera dans ce contexte).
- Pas une UI pour les autres formulaires SVS (contact, pré-inscription équithérapie) — chacun aura sa propre PR avec son propre buffer.
- Pas une vue d'historique des imports — uniquement les files actives. L'historique se reconstruit a posteriori si besoin via une 3ᵉ requête (`tiers_id IS NOT NULL` ou `ignored_at IS NOT NULL`).
- Pas une dépendance sur `feat/boite-de-reception` (branche aggregate-view non mergée) : on ajoute un écran autonome sur le pattern existant des 3 sources déjà en main (NDF, factures fournisseurs, documents reçus).

---

## 2. User-Facing Behavior (BDD Gherkin)

```gherkin
# language: fr
Fonctionnalité: Import des inscriptions newsletter vers la table Tiers
  Pour qu'un administrateur traite proprement les évènements newsletter
  En tant qu'Admin ou Comptable connecté à mon association
  Je vois les inscriptions confirmées en attente et les désinscriptions à traiter,
  et je décide au cas par cas

  Contexte:
    Étant donné que je suis connecté avec le rôle Admin sur l'asso "SVS"
    Et que la table newsletter_subscription_requests contient :
      | email                | status        | tiers_id | ignored_at | desinscription_traitee_at |
      | alice@nouveau.fr     | confirmed     | null     | null       | null                      |
      | bob@connu.fr         | confirmed     | null     | null       | null                      |
      | carol@deja.fr        | confirmed     | 42       | null       | null                      |
      | dave@spam.fr         | confirmed     | null     | now        | null                      |
      | erin@desinsc.fr      | unsubscribed  | 99       | null       | null                      |
      | frank@orphan.fr      | unsubscribed  | 100      | null       | null                      |
      | gina@isolated.fr     | unsubscribed  | null     | null       | null                      |
      | henri@traite.fr      | unsubscribed  | 101      | null       | now                       |

  # ─── Topbar et accès ─────────────────────────────────────────────────

  Scénario: Le badge topbar inclut le compteur newsletter
    Quand j'ouvre n'importe quelle page authentifiée
    Alors le badge "Boîte de réception" affiche un cumul incluant 4 (2 inscriptions + 2 désinscriptions)
    Et le dropdown topbar liste une 4e entrée "Inscriptions newsletter" avec le compteur 4

  Scénario: Page accessible aux rôles Admin et Comptable
    Quand j'ouvre /newsletter/inscriptions
    Alors la réponse est 200
    Et la page liste 2 onglets : "Inscriptions à traiter (2)" / "Désinscriptions à traiter (2)"

  Scénario: Page refusée aux autres rôles
    Étant donné que je suis connecté avec le rôle Utilisateur
    Quand j'ouvre /newsletter/inscriptions
    Alors la réponse est 403

  # ─── Onglet Inscriptions ─────────────────────────────────────────────

  Scénario: Liste filtre les demandes à traiter
    Quand j'ouvre l'onglet "Inscriptions à traiter"
    Alors la liste affiche alice@nouveau.fr et bob@connu.fr
    Et la liste n'affiche PAS carol@deja.fr (déjà importée, tiers_id=42)
    Et la liste n'affiche PAS dave@spam.fr (ignorée)

  Scénario: Match suggéré par email exact
    Étant donné qu'il existe un tiers Bob MARTIN avec email "bob@connu.fr"
    Quand j'ouvre l'onglet "Inscriptions à traiter"
    Alors la ligne bob@connu.fr affiche un badge "Match : Bob MARTIN"
    Et le bouton principal s'intitule "Fusionner avec Bob MARTIN"

  Scénario: Aucun match — bouton "Créer le tiers"
    Étant donné qu'il n'existe AUCUN tiers avec email alice@nouveau.fr
    Et qu'il n'existe AUCUN tiers avec (prenom, nom) = (Alice, DUPONT)
    Alors la ligne alice@nouveau.fr affiche le bouton "Créer le tiers"

  Scénario: Match flou par nom et prénom (fallback)
    Étant donné qu'il existe un tiers (prenom=Alice, nom=DUPONT) sans email
    Quand j'ouvre l'onglet "Inscriptions à traiter"
    Alors la ligne alice@nouveau.fr affiche un badge "Match possible : Alice DUPONT"
    Et le bouton principal s'intitule "Fusionner avec Alice DUPONT"

  Scénario: Création nominale d'un Tiers
    Étant donné une ligne alice@nouveau.fr sans match
    Quand je clique "Créer le tiers"
    Alors une modale s'ouvre avec champs pré-remplis :
      | type         | particulier        |
      | prenom       | Alice              |
      | nom          | (depuis buffer)    |
      | email        | alice@nouveau.fr   |
      | pour_recettes| true               |
    Quand je valide la modale
    Alors un nouveau Tiers est créé pour l'asso courante
    Et la ligne buffer porte tiers_id = id du nouveau Tiers
    Et la ligne buffer porte processed_by_user_id = mon user id
    Et la ligne disparaît de l'onglet "Inscriptions à traiter"
    Et un toast "Tiers créé et lié" s'affiche

  Scénario: Fusion avec un Tiers existant
    Étant donné une ligne bob@connu.fr avec match Bob MARTIN
    Quand je clique "Fusionner avec Bob MARTIN"
    Alors la TiersMergeModal s'ouvre en context "newsletter_import"
    Et les données source proviennent du buffer (email, prenom, nom)
    Et les données target proviennent du Tiers Bob MARTIN
    Quand j'arbitre les champs et valide
    Alors le Tiers Bob MARTIN est mis à jour selon l'arbitrage
    Et la ligne buffer porte tiers_id = 5 (id de Bob MARTIN)
    Et la ligne buffer porte processed_by_user_id = mon user id
    Et la ligne disparaît de l'onglet "Inscriptions à traiter"

  Scénario: Ignorer une demande
    Étant donné une ligne alice@nouveau.fr en attente
    Quand je clique "Ignorer" sur cette ligne
    Et que je confirme la modale Bootstrap
    Alors la ligne buffer porte ignored_at = now()
    Et la ligne buffer porte processed_by_user_id = mon user id
    Et la ligne disparaît de l'onglet "Inscriptions à traiter"
    Et le tiers_id reste null (aucun Tiers créé)

  # ─── Onglet Désinscriptions ──────────────────────────────────────────

  Scénario: Liste filtre les désinscriptions à traiter
    Quand j'ouvre l'onglet "Désinscriptions à traiter"
    Alors la liste affiche erin@desinsc.fr et frank@orphan.fr
    Et la liste n'affiche PAS gina@isolated.fr (jamais importée)
    Et la liste n'affiche PAS henri@traite.fr (déjà actée)

  Scénario: Désabonner le Tiers (Tiers avec dépendances)
    Étant donné une ligne erin@desinsc.fr liée au Tiers Erin DURAND (id 99)
    Et que le Tiers Erin DURAND a 12 transactions liées
    Quand j'ouvre l'onglet "Désinscriptions à traiter"
    Alors la ligne affiche le bouton "Désabonner" actif
    Et la ligne affiche le bouton "Supprimer le Tiers" désactivé (tooltip "12 dépendances")
    Quand je clique "Désabonner" et confirme la modale
    Alors le Tiers Erin DURAND a email_optout = true
    Et la ligne buffer porte desinscription_traitee_at = now()
    Et la ligne buffer porte desinscription_action = 'optout'
    Et la ligne buffer porte processed_by_user_id = mon user id
    Et la ligne disparaît de l'onglet "Désinscriptions à traiter"

  Scénario: Supprimer le Tiers (Tiers sans dépendance)
    Étant donné une ligne frank@orphan.fr liée au Tiers Frank ORPHAN (id 100)
    Et que le Tiers Frank ORPHAN n'a aucune dépendance (0 transaction, 0 facture, 0 NDF)
    Quand j'ouvre l'onglet "Désinscriptions à traiter"
    Alors la ligne affiche le bouton "Supprimer le Tiers" actif
    Quand je clique "Supprimer le Tiers" et confirme la modale
    Alors le Tiers Frank ORPHAN est supprimé (DELETE)
    Et la ligne buffer porte tiers_id = null (cascade nullOnDelete)
    Et la ligne buffer porte desinscription_traitee_at = now()
    Et la ligne buffer porte desinscription_action = 'deleted'
    Et la ligne disparaît de l'onglet "Désinscriptions à traiter"

  Scénario: Acter sans rien faire
    Étant donné une ligne erin@desinsc.fr
    Quand je clique "Acter sans action" et confirme
    Alors le Tiers Erin DURAND est INCHANGÉ (email_optout reste false)
    Et la ligne buffer porte desinscription_traitee_at = now()
    Et la ligne buffer porte desinscription_action = 'noop'
    Et la ligne disparaît de l'onglet "Désinscriptions à traiter"

  # ─── Multi-tenant ────────────────────────────────────────────────────

  Scénario: Isolation tenant
    Étant donné une asso B avec une ligne pending pour zoe@asso-b.fr
    Quand je suis connecté sur l'asso A et j'ouvre /newsletter/inscriptions
    Alors la ligne zoe@asso-b.fr n'apparaît PAS
    Et le compteur topbar n'inclut PAS la ligne de l'asso B

  Scénario: Page initiale vide
    Étant donné qu'aucune ligne en file pour l'asso courante
    Quand j'ouvre /newsletter/inscriptions
    Alors les deux onglets affichent "Aucune demande en attente"
    Et le badge topbar "Boîte de réception" n'inclut pas d'entrée newsletter (compteur 0 → entrée masquée comme les autres sources)
```

---

## 3. Architecture Specification

### 3.1 Migration — 4 colonnes ajoutées au buffer

`database/migrations/<ts>_add_admin_processing_columns_to_newsletter_subscription_requests_table.php` :

```php
Schema::table('newsletter_subscription_requests', function (Blueprint $table) {
    $table->timestamp('ignored_at')->nullable()->after('tiers_id');
    $table->timestamp('desinscription_traitee_at')->nullable()->after('ignored_at');
    $table->enum('desinscription_action', ['optout', 'deleted', 'noop'])
        ->nullable()
        ->after('desinscription_traitee_at');
    $table->foreignId('processed_by_user_id')
        ->nullable()
        ->after('desinscription_action')
        ->constrained('users')
        ->nullOnDelete();

    // Index pour les 2 files de queue (badge + listing)
    $table->index(['association_id', 'status', 'tiers_id', 'ignored_at'], 'idx_newsletter_inbox_inscriptions');
    $table->index(['association_id', 'status', 'desinscription_traitee_at'], 'idx_newsletter_inbox_desinscriptions');
});
```

`tiers_id` est déjà présent (slice 1, FK avec `nullOnDelete`). Pas de modification nécessaire.

**Down** : drop des index puis drop des 4 colonnes.

### 3.2 Modèle `App\Models\Newsletter\SubscriptionRequest` — extensions

Ajouter aux `$fillable` (les 4 nouvelles colonnes ne sont **pas** en mass-assign — elles transitent par des méthodes dédiées du service).

Casts à compléter :
```php
protected $casts = [
    // ... existants
    'ignored_at' => 'datetime',
    'desinscription_traitee_at' => 'datetime',
    'desinscription_action' => DesinscriptionAction::class, // nouvel enum
];
```

Nouveaux scopes :
```php
public function scopeInscriptionsAtraiter(Builder $q): Builder
{
    return $q->where('status', SubscriptionRequestStatus::Confirmed)
        ->whereNull('tiers_id')
        ->whereNull('ignored_at');
}

public function scopeDesinscriptionsAtraiter(Builder $q): Builder
{
    return $q->where('status', SubscriptionRequestStatus::Unsubscribed)
        ->whereNotNull('tiers_id')
        ->whereNull('desinscription_traitee_at');
}
```

Nouvelle relation :
```php
public function processedBy(): BelongsTo
{
    return $this->belongsTo(User::class, 'processed_by_user_id');
}
```

### 3.3 Enum `App\Enums\Newsletter\DesinscriptionAction`

```php
enum DesinscriptionAction: string
{
    case Optout = 'optout';
    case Deleted = 'deleted';
    case Noop = 'noop';
}
```

### 3.4 Service `App\Services\Newsletter\BufferImportService`

`final class`, `declare(strict_types=1)`, toutes les mutations dans `DB::transaction()`. Auteur (`processed_by_user_id`) résolu via `Auth::id()` à l'intérieur du service (le controller ne le passe pas explicitement — convention projet sur `LogContext`).

```php
final class BufferImportService
{
    public function __construct(
        private readonly TiersService $tiersService,
    ) {}

    /** Match : email exact d'abord, puis (prenom, nom) en fallback. Renvoie null si aucun. */
    public function suggestMatch(SubscriptionRequest $req): ?Tiers
    {
        if ($req->email !== null) {
            $byEmail = Tiers::where('email', $req->email)->first();
            if ($byEmail !== null) {
                return $byEmail;
            }
        }
        if ($req->prenom !== null && $req->nom !== null) {
            return Tiers::whereRaw('LOWER(prenom) = ?', [mb_strtolower($req->prenom)])
                ->whereRaw('LOWER(nom) = ?', [mb_strtolower($req->nom)])
                ->first();
        }
        return null;
    }

    /** @param array<string, mixed> $tiersAttributes données validées de la modale création */
    public function createTiersFromBuffer(SubscriptionRequest $req, array $tiersAttributes): Tiers
    {
        return DB::transaction(function () use ($req, $tiersAttributes) {
            $tiers = Tiers::create($tiersAttributes); // association_id auto-injecté par TenantModel
            $req->tiers_id = $tiers->id;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
            return $tiers;
        });
    }

    public function linkBufferToExistingTiers(SubscriptionRequest $req, Tiers $tiers): void
    {
        DB::transaction(function () use ($req, $tiers) {
            $req->tiers_id = $tiers->id;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }

    public function ignore(SubscriptionRequest $req): void
    {
        DB::transaction(function () use ($req) {
            $req->ignored_at = now();
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }

    public function applyUnsubscribeOptout(SubscriptionRequest $req): void
    {
        DB::transaction(function () use ($req) {
            $tiers = $req->tiers; // tenant-scopé, OK
            if ($tiers !== null) {
                $tiers->email_optout = true;
                $tiers->save();
            }
            $req->desinscription_traitee_at = now();
            $req->desinscription_action = DesinscriptionAction::Optout;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }

    /** @throws TiersHasDependenciesException */
    public function applyUnsubscribeDelete(SubscriptionRequest $req): void
    {
        DB::transaction(function () use ($req) {
            $tiers = $req->tiers;
            if ($tiers === null) {
                throw new \LogicException('Désinscription sans Tiers lié — désinscription_action=deleted impossible');
            }
            $deps = $this->tiersService->countDependentRecords($tiers);
            if (array_sum($deps) > 0) {
                throw new TiersHasDependenciesException($deps);
            }
            $tiers->delete(); // FK nullOnDelete → req->tiers_id devient null
            $req->refresh(); // pour repérer la cascade
            $req->desinscription_traitee_at = now();
            $req->desinscription_action = DesinscriptionAction::Deleted;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }

    public function applyUnsubscribeNoop(SubscriptionRequest $req): void
    {
        DB::transaction(function () use ($req) {
            $req->desinscription_traitee_at = now();
            $req->desinscription_action = DesinscriptionAction::Noop;
            $req->processed_by_user_id = (int) Auth::id();
            $req->save();
        });
    }
}
```

### 3.5 Composant Livewire `App\Livewire\Newsletter\InscriptionsList`

Page coquille en pleine page (pas de modale), rendu sous `layouts.app`. État :

```php
final class InscriptionsList extends Component
{
    public string $tab = 'inscriptions'; // 'inscriptions' | 'desinscriptions'

    // Listing inscriptions : computed property qui renvoie [SubscriptionRequest, suggested Tiers|null, deps count]
    // Listing désinscriptions : computed property qui renvoie [SubscriptionRequest, deps count Tiers]

    public function mount(): void
    {
        $this->authorize('access-newsletter-inbox'); // Gate à définir : Admin OU Comptable
    }

    public function setTab(string $tab): void { /* validation 'inscriptions'|'desinscriptions' */ }

    public function ignore(int $requestId): void
    {
        $req = SubscriptionRequest::findOrFail($requestId);
        app(BufferImportService::class)->ignore($req);
        $this->dispatch('toast', message: 'Demande ignorée');
    }

    public function openCreateModal(int $requestId): void { /* dispatch open-newsletter-create-tiers */ }

    public function openMergeModal(int $requestId, int $matchId): void
    {
        $req = SubscriptionRequest::findOrFail($requestId);
        $tiers = Tiers::findOrFail($matchId);
        $this->dispatch(
            'open-tiers-merge',
            sourceData: [
                'email' => $req->email,
                'prenom' => $req->prenom,
                'nom' => $req->nom,
                'type' => 'particulier',
            ],
            tiersId: $tiers->id,
            sourceLabel: 'Inscription newsletter',
            targetLabel: 'Tiers existant — '.$tiers->displayName(),
            confirmLabel: 'Fusionner et lier l\'inscription',
            context: 'newsletter_import',
            contextData: ['subscription_request_id' => $req->id],
        );
    }

    #[On('tiers-merge-completed')]
    public function onMergeCompleted(int $tiersId, array $contextData): void
    {
        if (! isset($contextData['subscription_request_id'])) return;
        $req = SubscriptionRequest::findOrFail((int) $contextData['subscription_request_id']);
        $tiers = Tiers::findOrFail($tiersId);
        app(BufferImportService::class)->linkBufferToExistingTiers($req, $tiers);
        $this->dispatch('toast', message: 'Inscription liée à '.$tiers->displayName());
    }

    public function applyOptout(int $requestId): void { /* → service */ }
    public function applyDelete(int $requestId): void { /* try/catch TiersHasDependenciesException → addError */ }
    public function applyNoop(int $requestId): void   { /* → service */ }

    public function render(): View { /* livewire.newsletter.inscriptions-list */ }
}
```

### 3.6 Composant Livewire `App\Livewire\Newsletter\CreateTiersModal`

Modale dédiée pour la création directe (pas via `TiersMergeModal` qui suppose un Target). Champs : `type`, `prenom`, `nom`, `email`, `pour_recettes`. Validation Laravel standard.

```php
final class CreateTiersModal extends Component
{
    public bool $showModal = false;
    public ?int $requestId = null;
    public string $type = 'particulier';
    public string $prenom = '';
    public string $nom = '';
    public string $email = '';
    public bool $pour_recettes = true;

    #[On('open-newsletter-create-tiers')]
    public function open(int $requestId): void
    {
        $req = SubscriptionRequest::findOrFail($requestId);
        $this->requestId = $req->id;
        $this->prenom = (string) ($req->prenom ?? '');
        $this->nom = (string) ($req->nom ?? '');
        $this->email = $req->email;
        $this->pour_recettes = true;
        $this->type = 'particulier';
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'type' => ['required', Rule::in(['particulier', 'entreprise'])],
            'prenom' => ['nullable', 'string', 'max:100'],
            'nom' => ['required_without:prenom', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'pour_recettes' => ['boolean'],
        ]);
        $req = SubscriptionRequest::findOrFail($this->requestId);
        $tiers = app(BufferImportService::class)->createTiersFromBuffer($req, $data);
        $this->dispatch('newsletter-tiers-created', tiersId: $tiers->id);
        $this->dispatch('toast', message: 'Tiers '.$tiers->displayName().' créé');
        $this->reset();
    }
}
```

### 3.7 Routes

`routes/web.php` (groupe `auth` + `boot-tenant`, comme les 3 autres écrans inbox) :

```php
Route::get('/newsletter/inscriptions', InscriptionsList::class)
    ->name('newsletter.inscriptions');
```

### 3.8 View composer — 4ᵉ entrée topbar

Étendre le composer existant dans `app/Providers/AppServiceProvider.php` :

```php
$canSeeNewsletter = false;
$newsletterPendingCount = 0;

// (à l'intérieur du bloc $assocUser !== null && in_array($role, [Admin, Comptable]))
$canSeeNewsletter = true;
$newsletterPendingCount = SubscriptionRequest::query()
    ->inscriptionsAtraiter()
    ->count()
    + SubscriptionRequest::query()
    ->desinscriptionsAtraiter()
    ->count();

$view->with([
    // ... existants
    'canSeeNewsletter' => $canSeeNewsletter,
    'newsletterPendingCount' => $newsletterPendingCount,
]);
```

`resources/views/layouts/app.blade.php` (section dropdown topbar) — étendre le `$cumulCount` et `$hasVisibleSource` pour inclure `$newsletterPendingCount`, et ajouter la 4ᵉ entrée :

```blade
@if(($canSeeNewsletter ?? false) && ($newsletterPendingCount ?? 0) > 0)
<li>
    <a class="dropdown-item d-flex align-items-center justify-content-between"
       href="{{ route('newsletter.inscriptions') }}">
        <span><i class="bi bi-envelope-heart me-2"></i> Inscriptions newsletter</span>
        <span class="badge bg-warning text-dark ms-3">{{ $newsletterPendingCount }}</span>
    </a>
</li>
@endif
```

### 3.9 Authorization

Gate `access-newsletter-inbox` défini dans `AuthServiceProvider` (ou inline par alignement avec la convention projet) :

```php
Gate::define('access-newsletter-inbox', function (User $user): bool {
    if (! TenantContext::hasBooted()) return false;
    $assocUser = AssociationUser::where('user_id', $user->id)
        ->where('association_id', TenantContext::currentId())
        ->whereNull('revoked_at')
        ->first();
    if ($assocUser === null) return false;
    $role = $assocUser->role instanceof RoleAssociation
        ? $assocUser->role
        : RoleAssociation::from((string) $assocUser->role);
    return in_array($role, [RoleAssociation::Admin, RoleAssociation::Comptable], true);
});
```

### 3.10 Vues Blade

- `resources/views/livewire/newsletter/inscriptions-list.blade.php` : layout pleine page, breadcrumb "Boîte de réception > Inscriptions newsletter", 2 onglets nav-tabs Bootstrap, tableau par onglet (en-tête `table-dark` selon convention `--bs-table-bg:#3d5473`), toast `wire:confirm` via modale Bootstrap (jamais `confirm()` natif).
- `resources/views/livewire/newsletter/create-tiers-modal.blade.php` : modale Bootstrap classique avec form inline.

### 3.11 Couplage avec `TiersMergeModal`

`TiersMergeModal` accepte déjà un `context` arbitraire. On ajoute le case `'newsletter_import'` dans son flux de complétion : à la fin du merge, dispatcher l'event `tiers-merge-completed` avec `tiersId` + `contextData` (déjà présent dans la modale).

**À vérifier au build** : si `TiersMergeModal` ne dispatche pas déjà cet event en sortie, l'ajouter (pattern symétrique à ses autres contextes — `merge_full`, `csv_import`).

---

## 4. Acceptance Criteria

### 4.1 Inventaire de fichiers

**Créés :**
- `database/migrations/<ts>_add_admin_processing_columns_to_newsletter_subscription_requests_table.php`
- `app/Enums/Newsletter/DesinscriptionAction.php`
- `app/Services/Newsletter/BufferImportService.php`
- `app/Services/Newsletter/Exceptions/TiersHasDependenciesException.php`
- `app/Livewire/Newsletter/InscriptionsList.php`
- `app/Livewire/Newsletter/CreateTiersModal.php`
- `resources/views/livewire/newsletter/inscriptions-list.blade.php`
- `resources/views/livewire/newsletter/create-tiers-modal.blade.php`
- `tests/Feature/Newsletter/BufferImportTest.php`

**Modifiés :**
- `app/Models/Newsletter/SubscriptionRequest.php` (casts + scopes + relation processedBy + ajouts mineurs)
- `app/Providers/AppServiceProvider.php` (view composer enrichi)
- `app/Livewire/TiersMergeModal.php` (dispatch `tiers-merge-completed` si pas déjà — à confirmer au build)
- `app/Providers/AuthServiceProvider.php` (Gate `access-newsletter-inbox`)
- `resources/views/layouts/app.blade.php` (4ᵉ entrée dropdown topbar)
- `resources/views/layouts/app-sidebar.blade.php` (idem si la sidebar liste les inbox sources — à vérifier)
- `routes/web.php` (route `/newsletter/inscriptions`)
- `database/factories/Newsletter/SubscriptionRequestFactory.php` (states `inscriptionAtraiter()`, `desinscriptionAtraiter()`, `importee()`, `ignoree()`, `desinscriptionTraitee()` pour les tests)

### 4.2 Critères de succès

- ✅ Suite Pest 100 % verte (existante + nouveaux tests)
- ✅ `./vendor/bin/pint --test` : 0 erreur
- ✅ Migration up/down réversible (testée via `migrate:fresh`)
- ✅ Test manuel :
  - Inscription web → ligne pending → confirmation email → ligne confirmed → apparaît dans `/newsletter/inscriptions`
  - Création nominale → Tiers créé en DB, ligne disparaît
  - Fusion via TiersMergeModal → arbitrage 3 colonnes, Tiers mis à jour, ligne disparaît
  - Désinscription web → ligne unsubscribed → apparaît dans onglet désinscriptions si `tiers_id` non-null
  - Désabonnement → `email_optout=true` sur Tiers, ligne disparaît
  - Suppression Tiers (sans dépendance) → Tiers supprimé, ligne disparaît
  - Suppression Tiers (avec dépendance) → bouton désactivé, tooltip explicite
- ✅ Test isolation tenant automatique (asso B ne voit pas asso A)
- ✅ Badge topbar incrémenté/décrémenté correctement après chaque action

### 4.3 Branche et PR

- Branche : `feat/newsletter-buffer-import-tiers`
- Commits conventional, atomiques (1 par étape TDD majeure)
- PR unique vers `main`
- Titre PR : `feat: import buffer newsletter → Tiers (slice 2)`

---

## 5. Hors-scope (PRs ultérieures)

- **Vue historique** des imports/ignored/désinscriptions traitées (3ᵉ onglet ou écran dédié).
- **Réimport en masse** via bouton unique pour les inscriptions sans match (au cas où un admin veut accélérer).
- **Notification email** à l'admin sur nouvelle inscription confirmée.
- **Intégration dans `feat/boite-de-reception`** (page agrégée à 4 sections) — quand cette branche sera mergée, ajouter la 4ᵉ section accordéon `wire:lazy` pointant sur le même service.
- **Autres formulaires SVS** (contact, pré-inscription équithérapie) — chacun aura son propre buffer + écran dans la même famille.

---

## 6. Consistency Gate

| Item | Vérifié |
|---|---|
| Multi-tenant : modèles `TenantModel`, scope global fail-closed | ✅ |
| Conventions code : `declare(strict_types=1)`, `final class`, type hints, FR locale | ✅ |
| Service métier dans `app/Services/Newsletter/` avec `DB::transaction()` | ✅ |
| `wire:confirm` via modale Bootstrap, jamais `confirm()` natif | ✅ |
| En-têtes table-dark `--bs-table-bg:#3d5473` | ✅ |
| Cast `(int)` des deux côtés sur les `===` PK/FK | ✅ |
| Pattern existant réutilisé : `TiersMergeModal`, `TiersService::countDependentRecords` | ✅ |
| Permission alignée sur les 3 autres inbox (Admin/Comptable) | ✅ |
| RGPD : `desinscription_action='noop'` permet d'acter sans muter le Tiers | ✅ |
| Audit : `processed_by_user_id` renseigné sur chaque mutation | ✅ |
| FK `processed_by_user_id` → `nullOnDelete` (ne pas perdre la ligne si user supprimé) | ✅ |
| FK `tiers_id` existante → `nullOnDelete` (cohérent avec scénario "Supprimer le Tiers") | ✅ |
| Hors-scope explicite | ✅ |

**Statut : PASS** — prête pour `/plan`.
