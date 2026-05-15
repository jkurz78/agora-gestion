# Portail membres et participants — Slice 4 : Mes messages

- **Date** : 2026-05-15
- **Programme** : Portail membres et participants
- **Slice** : 4 / 4 — Mes messages (historique des emails reçus de l'association)
- **Branche cible** : `feat/portail-membres-slice1-fondation-profil` (option B git)
- **Dépend de** : Slices 1+2+3 implementés (~75 commits sur la branche)

## Vocabulaire

Côté UI portail : **« Mes messages »** (vocabulaire utilisateur naturel).
Côté code interne : `TiersCommunicationsTimelineService`, `EmailLog`, `EmailLogLigneDTO` — inchangés (jargon métier préservé, comme `Operation` → « activité »).

## Décisions actées en cadrage (pré-spec)

1. **Périmètre** : historique des emails envoyés **par l'asso** (back-office) **vers le Tiers** connecté. Service back-office `TiersCommunicationsTimelineService::forTiers($tiers, ?string $filtreCategorie = null, int $page = 1)` existe déjà — on capitalise.
2. **Ordre** : chronologique inverse (date desc — déjà natif au service via `orderByDesc('created_at')`).
3. **Affichage liste** : date + objet uniquement par défaut. **Pas de badge catégorie en v0** (à évaluer après usage local — parqué).
4. **Affichage détail** : expand inline (Bootstrap collapse) sur la même page — pas de modale ni nouvelle page. Plus fluide UX.
5. **Corps HTML** : rendu fidèle via `{!! $email->corps_html !!}` dans une `div` containerisée (style scope minimal). Les emails viennent de l'asso (acteur trusté du tenant), pas de sanitize HTMLPurifier en v0.
6. **Pièces jointes** : téléchargeables via route portail dédiée + ownership strict. Ouvert en nouvel onglet inline (cohérent slice 2/3).
7. **Métadonnées internes** : **masquées** côté portail : `envoye_par`, `statut`, `erreur_message`, `tracking_token`, `nbOuvertures`. Le membre n'a pas besoin de savoir que l'email a été tracké.
8. **Catégories** : pas de filtrage par catégorie en v0 (toutes mélangées chronologiquement). À évaluer après usage local.
9. **Pagination** : 25/page (override de la const PAGE_SIZE=50 du service back-office côté composant — plus mobile-friendly).
10. **Sidebar** : nouveau groupe « Mes messages » + 1 entrée unique « Mes messages », ordre 90 (après « Mes activités »). Visible si ≥ 1 EmailLog destiné au Tiers.
11. **Dashboard** : 1 tuile « Mes messages » dans le cadre « Mes messages ».

## Hypothèses techniques verrouillées

| Item | État |
| ---- | ---- |
| `App\Models\EmailLog` champs : `tiers_id`, `participant_id`, `categorie`, `destinataire_email`, `objet`, `corps_html`, `attachment_path` (nullable), `created_at` | ✓ |
| `App\Enums\CategorieEmail` : Formulaire / Attestation / Document / Message / Communication | ✓ (utilisé en interne, pas exposé v0) |
| `App\Services\Tiers\TiersCommunicationsTimelineService::forTiers(Tiers, ?string $filtreCategorie = null, int $page = 1): CommunicationsTimelineDTO` | ✓ paginé via Laravel Paginator (`->paginate(50, ...)`). À overrider à 25/page — soit en exposant un param `pageSize`, soit en repaginant côté composant. |
| `App\Services\Tiers\DTO\EmailLogLigneDTO` expose tous les champs nécessaires (id, dateEnvoi, categorie, objet, destinataire, statut, aPieceJointe, attachmentNom, etc.) | ✓ |
| `EmailLog.attachment_path` nullable string | ✓ (migration `2026_04_27_000004_add_attachment_path_to_email_logs.php`) |
| `EmailLog::baseQuery` inclut emails ciblés sur `tiers_id` OU `participant_id` du Tiers | ✓ (donc emails liés à participations remontent aussi) |
| `EmailLog` n'a pas de TenantScope explicite mais est filtré via `tiers_id IN Tiers::select('id')` (TenantScope sur Tiers) | ✓ — vérifier en build qu'aucune fuite cross-tenant n'est possible via une requête forgée |

⚠️ **Point de vigilance multi-tenant** : `EmailLog` n'extends pas `TenantModel`. La protection vient du `whereHas`/`whereIn` via Tiers (TenantScope global sur Tiers filtre association_id). À auditer en step sécurité du build.

---

## 1. Intent Description

**Objectif** : permettre au Tiers connecté au portail de consulter l'historique des emails que l'association lui a envoyés. Liste chronologique inverse paginée (25/page). Click sur une ligne → expansion inline (Bootstrap collapse) qui révèle le corps HTML fidèle de l'email + boutons de téléchargement des pièces jointes éventuelles. Aucune action « renvoyer », aucune modification — pure consultation.

**Pourquoi maintenant** : c'est le **dernier slice** du programme portail membres (après F+A fondation, B/C adhésions/dons, D activités). Sa valeur est de fermer la boucle « le membre a tout sous la main » : ses adhésions, ses dons, ses activités, et désormais sa correspondance avec l'asso. Si un membre a perdu un email (asso a envoyé une attestation par email, le membre a supprimé le message), il peut le retrouver ici avec sa pièce jointe.

**Frontière** :
- Pas d'action « répondre » ni « renvoyer » (consultation seule, conforme cadrage initial)
- Pas d'exposition des métadonnées internes (envoyé par, statut technique, tracking, ouvertures)
- Pas de filtrage par catégorie en v0
- Pas de badge catégorie en v0 (à évaluer après usage)
- Pas de recherche par mot-clé en v0
- Pas de sanitize HTMLPurifier (acteur asso trusté du tenant)

**Acceptance** : un Tiers connecté ouvre « Mes messages », voit ses N derniers emails (date + objet), clique sur l'un d'eux → le corps HTML s'affiche en dessous fidèlement + lien PJ s'il y en a une. Pagination 25 par page. Aucune fuite cross-Tiers (Bob ne voit pas les emails d'Alice) ni cross-tenant (asso A ne voit pas asso B).

## 2. User-Facing Behavior (Gherkin)

```gherkin
Fonctionnalité: Portail membres — Mes messages

Contexte:
  Étant donné une association "MonAsso" avec slug "monasso"
  Et un Tiers identifié "Alice Martin" rattaché à cette association

# ============================================================
# SIDEBAR + TABLEAU DE BORD
# ============================================================

Scénario: Sidebar — aucun message reçu
  Étant donné Alice n'a aucun EmailLog destiné à elle
  Quand Alice se connecte au portail
  Alors la sidebar n'affiche pas "Mes messages"
  Et le tableau de bord n'affiche pas la tuile "Mes messages"

Scénario: Sidebar — au moins un message reçu
  Étant donné Alice a au moins un EmailLog destiné à elle (via tiers_id ou via participant.tiers_id)
  Quand Alice se connecte au portail
  Alors la sidebar affiche "Mes messages" dans le groupe "Mes messages"
  Et la tuile "Mes messages" apparaît sur le tableau de bord

# ============================================================
# ÉCRAN /portail/mes-messages
# ============================================================

Scénario: Affichage liste — chronologique inverse
  Étant donné Alice a 3 EmailLog avec dates :
    | objet         | created_at          |
    | Bienvenue     | 2025-01-10 10:00    |
    | Attestation 1 | 2026-03-15 09:00    |
    | Newsletter    | 2026-04-01 14:30    |
  Quand Alice ouvre /portail/mes-messages
  Alors la liste affiche dans l'ordre :
    | 1 | Newsletter    | 01/04/2026 |
    | 2 | Attestation 1 | 15/03/2026 |
    | 3 | Bienvenue     | 10/01/2025 |

Scénario: Click sur une ligne — expand inline corps HTML
  Étant donné Alice a un EmailLog avec corps_html = "<p>Bonjour Alice</p>"
  Quand Alice ouvre /portail/mes-messages
  Et clique sur la ligne du message
  Alors un bloc s'expand sous la ligne contenant le rendu HTML "<p>Bonjour Alice</p>"
  Et le bloc est dans une div containerisée (classe CSS scope)

Scénario: Pièce jointe — bouton télécharger visible
  Étant donné Alice a un EmailLog avec attachment_path = "associations/1/email_attachments/abc.pdf"
  Quand Alice ouvre /portail/mes-messages et expand le message
  Alors un bouton "Télécharger la pièce jointe" est affiché avec href vers la route portail dédiée
  Et le bouton ouvre en nouvel onglet (target="_blank")

Scénario: Téléchargement PJ — succès
  Étant donné Alice a un EmailLog avec PJ
  Quand Alice clique le bouton télécharger
  Alors un nouvel onglet sert le fichier inline (Content-Type approprié)
  Et le filename est le nom original ou un fallback

Scénario: Pas de PJ — pas de bouton
  Étant donné Alice a un EmailLog sans attachment_path
  Quand Alice expand le message
  Alors aucun bouton "Télécharger la pièce jointe" n'est affiché

Scénario: Pagination — 25 par page
  Étant donné Alice a 30 EmailLog
  Quand Alice ouvre /portail/mes-messages
  Alors la liste affiche 25 entrées
  Et un lien "page suivante" est visible
  Et le clic charge les 5 restantes

Scénario: Métadonnées internes — masquées
  Étant donné Alice a un EmailLog avec envoye_par = User Bob, statut = "envoye", erreur_message = null
  Quand Alice ouvre /portail/mes-messages
  Alors le rendu HTML ne contient ni le nom "Bob" (envoye_par) ni le statut technique ni le tracking_token

Scénario: Vocabulaire — pas de jargon technique
  Étant donné Alice est connectée
  Quand elle ouvre /portail/mes-messages
  Alors le rendu HTML ne contient pas le mot "EmailLog" ni "communication" (en clair)

# ============================================================
# SÉCURITÉ
# ============================================================

Scénario: Pas de fuite intra-asso
  Étant donné Alice est connectée
  Et Bob (autre Tiers même asso) a 5 EmailLog avec objets uniques "Bob-X"
  Quand Alice ouvre /portail/mes-messages
  Alors la réponse ne contient AUCUN des objets "Bob-X"

Scénario: Téléchargement PJ — Tiers ne peut accéder à la PJ d'un autre Tiers
  Étant donné Alice est connectée et Bob (autre Tiers même asso) a un EmailLog avec PJ
  Quand Alice tente l'URL forgée pour la PJ de Bob
  Alors elle reçoit 403

Scénario: Cross-tenant — pas de fuite
  Étant donné Alice (asso A) est connectée
  Et un EmailLog existe en asso B avec objet "Top secret asso B"
  Quand Alice ouvre /portail/mes-messages
  Alors la réponse ne contient pas "Top secret asso B"

Scénario: PJ cross-tenant — refusé
  Étant donné Alice (asso A) tente l'URL d'une PJ d'un EmailLog en asso B
  Alors elle reçoit 404 (résolution Tiers/EmailLog impossible cross-tenant)

# ============================================================
# RÉGRESSION
# ============================================================

Scénario: Pas de régression slices 1+2+3
  Étant donné Alice est connectée
  Quand elle visite Tableau de bord, Mon profil, Mes adhésions, Mes dons, Mes activités, NDF (si applicable)
  Alors toutes ces pages restent fonctionnelles à l'identique
```

## 3. Architecture Specification

### Composant Livewire

`App\Livewire\Portail\MesMessages` (final, layout `portail.layouts.authenticated`) :

- Mount `Association`. Trait `WithPortailTenant`.
- État Livewire :
  - `public int $page = 1` (pagination via wire:click)
  - `public ?int $messageOuvertId = null` (état d'expansion — un seul à la fois)
- `render()` :
  - Récupère le Tiers connecté
  - Appelle le service `TiersCommunicationsTimelineService::forTiers($tiers, null, $this->page)` mais override la page size à 25 (soit en passant un param si on étend la signature, soit en re-paginant en PHP — préférer extension du service avec param `pageSize`)
  - Passe le DTO + `messageOuvertId` à la vue
- Méthode `toggleMessage(int $id): void` qui set/unset `$messageOuvertId`

### Évolution mineure du service back-office

`TiersCommunicationsTimelineService::forTiers(Tiers, ?string $filtreCategorie = null, int $page = 1, int $pageSize = self::PAGE_SIZE)` — ajout d'un param `pageSize` optionnel avec valeur par défaut 50 (préserve back-office) et override 25 côté portail.

### Vue `mes-messages.blade.php`

```blade
<div>
    <h4 class="mb-3">Mes messages</h4>

    @if($timeline->emails->isEmpty())
        <p class="text-muted">Vous n'avez pas encore reçu de message.</p>
    @endif

    <div class="list-group">
        @foreach($timeline->emails as $email)
            <div class="list-group-item">
                <button type="button"
                        wire:click="toggleMessage({{ $email->id }})"
                        class="btn btn-link text-decoration-none text-start p-0 d-flex justify-content-between align-items-center w-100">
                    <span>
                        <strong>{{ $email->objet }}</strong>
                    </span>
                    <span class="text-muted small">{{ $email->dateEnvoi->format('d/m/Y') }}</span>
                </button>

                @if($messageOuvertId === $email->id)
                    @php $emailLog = \App\Models\EmailLog::find($email->id); @endphp
                    <div class="mt-3 portail-email-body p-3 bg-light rounded">
                        {!! $emailLog->corps_html !!}
                    </div>

                    @if($email->aPieceJointe)
                        <div class="mt-2">
                            <a href="{{ \App\Support\PortailRoute::to('messages.attachment', $portailAssociation, ['emailLog' => $email->id]) }}"
                               target="_blank" rel="noopener"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-paperclip"></i>
                                Télécharger la pièce jointe ({{ $email->attachmentNom ?? 'fichier' }})
                            </a>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    {{-- Pagination --}}
    @if($timeline->emails->hasPages())
        <div class="mt-3">
            {{ $timeline->emails->links() }}
        </div>
    @endif
</div>

<style>
    .portail-email-body { max-width: 100%; overflow-x: auto; }
    .portail-email-body img { max-width: 100%; height: auto; }
    .portail-email-body table { max-width: 100%; }
</style>
```

⚠️ **Sécurité ownership** sur le `EmailLog::find($email->id)` du expand : le DTO listé vient déjà du service `forTiers($tiers)` qui filtre par tiers_id (intra Tiers) et tiers (TenantScope). Mais le `find()` direct dans la vue contourne ce filtrage. **Risque** : un Tiers pourrait inspecter le DOM, modifier l'ID Livewire, et le rerender pourrait charger un EmailLog d'un autre Tiers.

**Mitigation** : la méthode `toggleMessage(int $id)` doit vérifier que l'ID appartient bien aux résultats du service avant de l'enregistrer dans `$messageOuvertId`. Soit on récupère `$tokensActifs` du DTO et on vérifie `in_array($id, $tokensActifs->pluck('id')->all())`, soit on fait un re-check via service à chaque toggle. **Approche choisie** : précharge `corps_html` dans le DTO et évite le `find()` dans la vue.

### Évolution `EmailLogLigneDTO`

Ajouter le champ `corps_html: string` au DTO + `attachmentMime: ?string` (pour le Content-Type au téléchargement) :

```php
final readonly class EmailLogLigneDTO
{
    public function __construct(
        public int $id,
        public Carbon $dateEnvoi,
        public string $categorie,
        public string $objet,
        public string $destinataire,
        public string $statut,
        public ?string $erreurMessage,
        public int $nbOuvertures,
        public ?Carbon $premiereOuvertureAt,
        public bool $aPieceJointe,
        public ?string $attachmentNom,
        public ?int $participantId,
        public ?string $participantNom,
        public ?int $operationId,
        public ?string $operationNom,
        public ?string $campagneNom,
        public ?string $envoyeParNom,
        public string $corpsHtml,        // ← NOUVEAU (toujours chargé)
        public ?string $attachmentPath,  // ← NOUVEAU (pour ownership PJ)
    ) {}
}
```

⚠️ Impact : la méthode `EmailLogLigneDTO::fromEmailLog()` doit être étendue. Les usages back-office du DTO (fiche tiers slice 8) doivent continuer à fonctionner — vérifier en build.

### Provider sidebar

`App\Services\Portail\Providers\MesMessagesProvider` (final, implements `PortailSectionProvider` — single section, pas multi) :

- Visible si `EmailLog::query()->where(/* via baseQuery du service */)->exists()` — à factoriser via une méthode helper du service `tiersAUnMessage(Tiers): bool`
- DTO :
  - `id` = `"mes-messages"`
  - `label` = `"Mes messages"`
  - `routeName` = `"portail.mes-messages"`
  - `icon` = `"bi-envelope"`
  - `ordre` = 90
  - `groupe` = `"Mes messages"`
- Enregistré dans `PortailServiceProvider::boot()`

### Routes

`routes/portail.php` (groupe post-auth) :
```php
Route::get('/mes-messages', MesMessages::class)->name('mes-messages');
Route::get('/messages/attachment/{emailLog}', [MessageAttachmentController::class, '__invoke'])->name('messages.attachment');
```

`routes/portail-mono.php` mirror.

### Controller PJ

`App\Http\Controllers\Portail\MessageAttachmentController` :

```php
public function __invoke(Request $request): Response
{
    $tiers = Auth::guard('tiers-portail')->user();
    abort_unless($tiers !== null, 403);

    $emailLogId = (int) $request->route('emailLog');
    $emailLog = EmailLog::find($emailLogId);
    abort_unless($emailLog !== null, 404);

    // Multi-tenant : EmailLog n'extends pas TenantModel — guard manuel via tiers
    $emailTiers = Tiers::find($emailLog->tiers_id);  // TenantScope filtre association
    abort_unless($emailTiers !== null && (int) $emailTiers->id === (int) $tiers->id, 403);

    abort_unless($emailLog->attachment_path !== null, 404);

    $contents = Storage::disk('local')->get($emailLog->attachment_path);
    abort_unless($contents !== null, 404);

    Log::info('portail.message.attachment.telecharge', [
        'email_log_id' => $emailLog->id,
        'tiers_id' => $tiers->id,
    ]);

    $filename = basename($emailLog->attachment_path);

    return response($contents, 200, [
        'Content-Type' => $this->guessContentType($filename),
        'Content-Disposition' => 'inline; filename="'.$filename.'"',
        'Content-Length' => (string) strlen($contents),
        'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
    ]);
}
```

### Tests

| Fichier | Type | Couvre |
| ------- | ---- | ------ |
| `tests/Unit/Portail/Providers/MesMessagesProviderTest.php` | Unit | Visible si ≥ 1 EmailLog, null sinon |
| `tests/Feature/Portail/MesMessagesTest.php` | Feature | Affichage liste (ordre desc), expand inline, pagination 25 |
| `tests/Feature/Portail/MesMessagesSecurityTest.php` | Feature/sécurité | Pas de fuite intra-asso ni cross-tenant ; toggleMessage(ID hors résultats) ignoré |
| `tests/Feature/Portail/MessageAttachmentControllerTest.php` | Feature | Téléchargement PJ : 200 + ownership intra-asso 403 + cross-tenant 404 + logger |
| `tests/Feature/Portail/MonoMesMessagesTest.php` | Feature | Mode mono : routes accessibles + contenu identique |

## 4. Acceptance Criteria

### Fonctionnels

- [ ] `MesMessagesProvider` enregistré, visible ssi Tiers a ≥ 1 EmailLog (via `tiers_id` ou via `participant_id` du Tiers).
- [ ] Sidebar et tuile dashboard apparaissent en conséquence.
- [ ] `/{slug}/portail/mes-messages` liste les emails du Tiers en ordre `created_at` desc.
- [ ] Pagination 25/page (override de la const PAGE_SIZE=50 du service).
- [ ] Click sur une ligne expand le corps HTML inline (Bootstrap collapse, un seul à la fois).
- [ ] Le corps HTML est rendu fidèle via `{!! ... !!}` dans une div CSS-scope (`.portail-email-body`).
- [ ] Si `attachment_path` non null : bouton « Télécharger la pièce jointe » avec href vers route portail + `target="_blank"`.
- [ ] Téléchargement PJ : route portail dédiée, ownership strict (Tiers connecté = destinataire), `Content-Disposition: inline`, signature fichier respectée (préserver MIME si possible).
- [ ] Métadonnées masquées : `envoye_par`, `statut`, `erreur_message`, `tracking_token`, `nbOuvertures` jamais dans le rendu.
- [ ] Pas de filtrage par catégorie en v0 (toutes catégories mélangées).
- [ ] `toggleMessage($id)` n'accepte que des IDs présents dans la page courante du DTO (sinon ignoré silencieusement) — défense Livewire.

### Sécurité

- [ ] Tiers ne voit pas les EmailLog d'un autre Tiers même asso (test d'intrusion sur la liste).
- [ ] Cross-tenant : Tiers asso A ne voit pas les EmailLog asso B (TenantScope sur Tiers — vérification explicite).
- [ ] Téléchargement PJ : Tiers ne peut télécharger la PJ d'un autre Tiers (test 403) ni cross-tenant (test 404).
- [ ] Logger émet `portail.message.attachment.telecharge` avec `email_log_id` + `tiers_id`.
- [ ] Mode mono : routes mirror + tests.

### Régression

- [ ] Slices 1+2+3 inchangés pour les Tiers sans EmailLog.
- [ ] Suite Pest verte (0 failure).

### Non-fonctionnels

- [ ] Pint clean.
- [ ] Larastan baseline inchangée.
- [ ] Test manuel : first paint /mes-messages < 1s en localhost.

## Consistency Gate

| Item | Verdict |
| ---- | ------- |
| Intent unambigu | ✓ — frontière scope explicite, vocabulaire imposé |
| Chaque comportement intent → ≥ 1 scénario Gherkin | ✓ |
| Architecture contrainte sans over-engineering | ✓ — capitalise sur service existant + pattern HTTP inline + resolver/registry |
| Naming consistent (« Mes messages », « message », « pièce jointe ») | ✓ |
| Pas de contradiction entre artifacts | ✓ |

**Verdict global : PASS.**

Réserves mineures à lever en `/plan` :
1. Convention filename PJ (utiliser `basename($attachment_path)` ou un nom plus parlant ?).
2. MIME detection : Storage Laravel a-t-il un helper ? Sinon utiliser `Symfony\Mime\MimeTypes`.
3. Si `EmailLogLigneDTO` est utilisé ailleurs (fiche tiers 360 slice 8), s'assurer que l'extension du DTO ne casse pas les usages existants.

## Hors scope (parqué)

- Filtrage par catégorie (à évaluer après usage local — pas de badge non plus en v0)
- Recherche full-text par mot-clé
- Action « marquer comme lu / non lu »
- Action « renvoyer cet email »
- Indicateur visuel « non lu » sur la liste
- Compteur de PJ multiples (la table EmailLog n'a qu'un `attachment_path` unique — pas plusieurs PJ par email)
- Sanitize HTMLPurifier (acteur asso trusté en v0 — à reconsidérer si on ouvre le portail à des emails externes)
- Préférences communication granulaires par catégorie (toggle email_optout global déjà livré slice 1)

## Prochaine étape

`/agentic-dev-team:plan` puis `/agentic-dev-team:build` (subagent-driven Sonnet) sur la même branche.
