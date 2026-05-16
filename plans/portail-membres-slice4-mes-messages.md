# Plan: Portail membres et participants — Slice 4 : Mes messages

**Created**: 2026-05-15
**Branch**: `feat/portail-membres-slice1-fondation-profil` (option B git, dernier slice)
**Status**: implemented (2026-05-15, ~10 commits, 660 tests Portail / 0 failure, security PASS, spec compliance PASS après gaps comblés)
**Spec**: [docs/specs/2026-05-15-portail-membres-slice4-mes-messages.md](../docs/specs/2026-05-15-portail-membres-slice4-mes-messages.md)
**Slices 1+2+3 statut** : implementés et validés en local.

## Goal

Livrer le **dernier slice du programme portail v0** : « Mes messages » — historique des emails envoyés par l'asso vers le Tiers connecté, avec affichage chronologique inverse, expand inline du corps HTML, téléchargement des pièces jointes en nouvel onglet, pagination 25/page. Vocabulaire UI « Mes messages » ; code interne `EmailLog`/`Communications` inchangé. Pas de filtrage ni badge catégorie en v0 (parqué pour évaluation).

## Acceptance Criteria

- [ ] Provider `MesMessagesProvider` registered dans `PortailServiceProvider::boot()`. Visible ssi le Tiers a ≥ 1 EmailLog (via `tiers_id` ou `participant_id` de ses Participants). Sidebar et tuile dashboard apparaissent en conséquence.
- [ ] Service `TiersCommunicationsTimelineService::forTiers()` accepte un nouveau param optionnel `int $pageSize = self::PAGE_SIZE` (défaut 50, override 25 côté portail). Helper `tiersAUnMessage(Tiers): bool` ajouté pour le provider.
- [ ] DTO `EmailLogLigneDTO` étendu avec 2 nouveaux champs `corpsHtml: string` et `attachmentPath: ?string`. La méthode `fromEmailLog()` les hydrate. Les usages existants (fiche tiers slice 8) ne régressent pas.
- [ ] `/{slug}/portail/mes-messages` rend la liste des emails du Tiers en ordre `created_at` desc, paginée 25/page.
- [ ] Click sur une ligne → expand inline (Bootstrap collapse) qui révèle le corps HTML rendu fidèle dans une div `.portail-email-body` (CSS scope minimal). Un seul email ouvert à la fois.
- [ ] Si `attachment_path` non null sur l'email ouvert : bouton « Télécharger la pièce jointe » avec href vers route portail dédiée + `target="_blank"`.
- [ ] Téléchargement PJ : route `portail.messages.attachment.{emailLog}`, ownership strict (Tiers connecté = destinataire de l'EmailLog), Content-Disposition inline, MIME deviné depuis l'extension/`Storage::mimeType()`.
- [ ] Métadonnées masquées : `envoye_par`, `statut`, `erreur_message`, `tracking_token`, `nbOuvertures` jamais dans le rendu HTML public.
- [ ] Méthode `toggleMessage($id)` n'accepte que des IDs présents dans la page courante du DTO (sinon ignoré silencieusement) — défense Livewire wire-set intrusion.
- [ ] Sécurité — Tiers asso A ne voit pas EmailLog asso B (TenantScope sur Tiers, vérifié explicitement).
- [ ] Sécurité — Tiers ne peut pas télécharger PJ d'un autre Tiers (test 403 intra-asso ; 404 cross-tenant).
- [ ] Logger émet `portail.message.attachment.telecharge` avec `email_log_id` + `tiers_id`.
- [ ] Mode mono : routes mirror (`portail.mono.mes-messages`, `portail.mono.messages.attachment`) avec test feature.
- [ ] Régression : suite Pest verte (objectif 0 failure). Pint clean. Larastan baseline inchangée.

## Pré-décisions confirmées (cf. spec)

| Point | Décision |
| ----- | -------- |
| Vocabulaire UI | « Mes messages » (code reste `EmailLog`/`Communications`) |
| Liste | Chronologique inverse, date + objet uniquement |
| Affichage détail | Expand inline (Bootstrap collapse), un à la fois |
| Corps HTML | `{!! corps_html !!}` dans div CSS-scope (acteur asso trusté) |
| PJ | Route portail dédiée, inline, nouvel onglet |
| Métadonnées internes | Masquées (envoyé par, statut, tracking) |
| Catégories / filtrage | Pas en v0 (parqué) |
| Pagination | 25/page (override service) |
| Sidebar | Groupe « Mes messages » + 1 entrée, ordre 90 |

## Hypothèses techniques verrouillées (cf. spec)

| Item | État |
| ---- | ---- |
| `App\Models\EmailLog` champs : tiers_id, participant_id, categorie, destinataire_email, objet, corps_html, attachment_path (nullable), created_at | ✓ |
| `App\Services\Tiers\TiersCommunicationsTimelineService::forTiers(Tiers, ?string, int)` retourne paginator | ✓ |
| `App\Services\Tiers\DTO\EmailLogLigneDTO` existant | ✓ (à étendre) |
| `EmailLog` n'extends pas TenantModel — protection via `Tiers::baseQuery` qui utilise `whereIn('tiers_id', Tiers::select('id'))` | ✓ (TenantScope sur Tiers) |
| Pattern HTTP route inline + nouvel onglet (slice 2 hotfix `RecuPortailController`) à reproduire | ✓ |
| `Storage::disk('local')->mimeType($path)` ou `Storage::mimeType()` ou `Symfony\Component\Mime\MimeTypes::guessMimeType()` pour le Content-Type PJ | ✓ (à choisir en build) |

## Steps

### Step 1: Évolution `EmailLogLigneDTO` + service `TiersCommunicationsTimelineService`

**Complexity**: standard

**RED**:
- `tests/Unit/Services/Tiers/TiersCommunicationsTimelineServicePageSizeTest.php` (nouveau) — couvre :
  1. `forTiers($tiers, null, 1, pageSize: 25)` retourne 25 emails (sur 30) en page 1 + 5 en page 2
  2. `forTiers(...)` sans param `pageSize` continue à utiliser 50 (compat back-office)
- Si test sur DTO étendu existe ou pertinent : ajouter dans un fichier de test DTO (`tests/Unit/Services/Tiers/DTO/EmailLogLigneDTOTest.php` si existe, sinon créer) — vérifier que `corpsHtml` et `attachmentPath` sont bien hydratés depuis EmailLog par `fromEmailLog()`

**GREEN** :
- Étendre `app/Services/Tiers/DTO/EmailLogLigneDTO.php` :
  - Ajouter constructor params `public string $corpsHtml` et `public ?string $attachmentPath`
  - Mettre à jour `fromEmailLog(EmailLog $log): self` pour passer les 2 nouveaux champs
- Étendre `app/Services/Tiers/TiersCommunicationsTimelineService.php` :
  - Signature `public function forTiers(Tiers $tiers, ?string $filtreCategorie = null, int $page = 1, int $pageSize = self::PAGE_SIZE): CommunicationsTimelineDTO`
  - Utiliser `$pageSize` dans `->paginate(...)`
  - Ajouter méthode `public function tiersAUnMessage(Tiers $tiers): bool` qui appelle `$this->baseQuery($tiers)->exists()` (factorisé)

**REFACTOR** : aucun.

**Files** :
- `app/Services/Tiers/DTO/EmailLogLigneDTO.php`
- `app/Services/Tiers/TiersCommunicationsTimelineService.php`
- `tests/Unit/Services/Tiers/TiersCommunicationsTimelineServicePageSizeTest.php`

**Commit** : `feat(portail): EmailLogLigneDTO étendu (corpsHtml/attachmentPath) + service pageSize override + helper tiersAUnMessage`

⚠️ Vérifier les usages existants de `EmailLogLigneDTO` (fiche tiers slice 8 — `TiersDocumentsTimelineService` ou autre) — l'ajout de paramètres au DTO est rétrocompatible si position fixe et pas de breakage assumé. Smoke test.

---

### Step 2: Provider `MesMessagesProvider`

**Complexity**: standard

**RED**: `tests/Unit/Portail/Providers/MesMessagesProviderTest.php` (nouveau) — 2 cas :
1. Tiers avec ≥ 1 EmailLog → DTO non null (id `mes-messages`, label `Mes messages`, route `portail.mes-messages`, icon `bi-envelope`, ordre 90, groupe `Mes messages`)
2. Tiers sans EmailLog → null

**GREEN** :
- Créer `app/Services/Portail/Providers/MesMessagesProvider.php` (final, implements `PortailSectionProvider`)
- Critère visibilité : `app(TiersCommunicationsTimelineService::class)->tiersAUnMessage($tiers)` (réutilise le helper du Step 1)
- Enregistrer dans `App\Providers\PortailServiceProvider::boot()` via `register()` (single section, pas multi)

**REFACTOR** : aucun.

**Files** : provider + test + édition `PortailServiceProvider`

**Commit** : `feat(portail): provider sidebar Mes messages (visible si ≥1 email reçu)`

---

### Step 3: Composant Livewire `MesMessages` + vue + route

**Complexity**: complex (UI + sécurité Livewire intrusion + sanitize HTML constraint + interaction)

**RED** : `tests/Feature/Portail/MesMessagesTest.php` (nouveau), 6+ cas :
1. **Affichage liste — chronologique inverse** : 3 EmailLog dates différentes → ordre desc dans le DOM
2. **Affichage liste — pagination 25** : 30 EmailLog → page 1 affiche 25, links pagination présents
3. **Click ligne — expand inline** : `Livewire::test(...)->call('toggleMessage', $emailId)` → la vue contient `$emailLog->corps_html` rendu
4. **Click 2nd ligne** : un nouvel `toggleMessage($otherId)` ferme le 1er et ouvre le 2nd
5. **Métadonnées masquées** : la vue ne contient PAS `envoyé par`, `statut`, le tracking_token, etc.
6. **Empty state** : Tiers sans EmailLog → message muted « Vous n'avez pas encore reçu de message. »

`tests/Feature/Portail/MesMessagesSecurityTest.php` (nouveau), 3 cas :
7. **Pas de fuite intra-asso** : Alice connectée + Bob (autre Tiers) avec 5 EmailLog objets uniques → la liste d'Alice ne contient AUCUN objet de Bob
8. **Cross-tenant** : Alice asso A connectée + EmailLog en asso B avec objet « SecretB » → la liste ne contient pas « SecretB »
9. **Intrusion `toggleMessage` ID hors résultats** : `Livewire::test(...)->call('toggleMessage', $bobEmailId)` → `$messageOuvertId` reste null (pas d'expand, pas d'erreur)

**GREEN** :
- Créer `app/Livewire/Portail/MesMessages.php` (full-page Livewire, layout `portail.layouts.authenticated`, trait `WithPortailTenant`)
- État Livewire : `public int $page = 1` + `public ?int $messageOuvertId = null`
- `render()` : appel service `forTiers($tiers, null, $this->page, 25)` → DTO avec paginator. Passe à la vue.
- Méthode `toggleMessage(int $id): void` :
  - Récupérer la liste des IDs présents dans la page courante (du DTO)
  - Si `$id` ∈ liste : toggle `$messageOuvertId = ($messageOuvertId === $id) ? null : $id`
  - Sinon : ignore silencieusement
- Méthode `gotoPage(int $page): void` (ou utiliser le pagination Livewire natif). Reset `$messageOuvertId = null` au changement de page.
- Créer `resources/views/livewire/portail/mes-messages.blade.php` :
  - H4 « Mes messages »
  - Liste `list-group` avec un `wire:click="toggleMessage({{ $email->id }})"` par ligne
  - Si `$messageOuvertId === $email->id` : div `.portail-email-body` avec `{!! $email->corpsHtml !!}` (depuis le DTO étendu, pas un find direct)
  - Si `$email->attachmentPath !== null` : bouton « Télécharger la pièce jointe » target="_blank"
  - Pagination Bootstrap (Livewire `withQueryString` ou manuel via `gotoPage`)
- CSS minimal scope `.portail-email-body { max-width:100%; overflow-x:auto; } .portail-email-body img { max-width:100%; }` (inline ou dans le layout)
- Ajouter route dans `routes/portail.php` (groupe post-auth) :
  ```php
  Route::get('/mes-messages', MesMessages::class)->name('mes-messages');
  ```

**REFACTOR** : aucun.

**Files** :
- `app/Livewire/Portail/MesMessages.php`
- `resources/views/livewire/portail/mes-messages.blade.php`
- `routes/portail.php` (1 route)
- `tests/Feature/Portail/MesMessagesTest.php`
- `tests/Feature/Portail/MesMessagesSecurityTest.php`

**Commit** : `feat(portail): écran Mes messages — liste chronologique + expand inline corps HTML`

---

### Step 4: Controller PJ `MessageAttachmentController` + route + sécurité

**Complexity**: complex (sécurité — ownership PJ + multi-tenant via Tiers)

**RED** : `tests/Feature/Portail/MessageAttachmentControllerTest.php` (nouveau), 5+ cas :
1. **Téléchargement PJ — succès** : Alice + EmailLog Alice avec `attachment_path` réel sur disque → GET 200, Content-Type approprié, Content-Disposition contient `inline`, body match les bytes du fichier
2. **Sans PJ** : EmailLog Alice avec `attachment_path = null` → 404
3. **Intrusion intra-asso** : Alice tente la PJ de l'EmailLog de Bob (autre Tiers même asso) → 403
4. **Cross-tenant** : Alice asso A tente PJ d'EmailLog asso B → 404 (Tiers asso B introuvable via TenantScope)
5. **Logger** : `Log::info('portail.message.attachment.telecharge', ['email_log_id' => ..., 'tiers_id' => ...])` (Log::spy)

**GREEN** :
- Créer `app/Http/Controllers/Portail/MessageAttachmentController.php` (final, single `__invoke(Request)` ou méthode nommée — préférer `__invoke` pour single-action) :
  - Auth `tiers-portail` + abort_unless tiers
  - Récupère `EmailLog::find($request->route('emailLog'))` (sans TenantScope car EmailLog n'extends pas TenantModel)
  - abort_unless emailLog null → 404
  - Récupère `Tiers::find($emailLog->tiers_id)` (TenantScope filtre asso)
  - abort_unless tiers correspond au tiers connecté → 403
  - abort_unless `attachment_path !== null` → 404
  - Lit le fichier via `Storage::disk('local')->get($emailLog->attachment_path)`
  - Détermine le MIME : `Storage::disk('local')->mimeType($emailLog->attachment_path) ?? 'application/octet-stream'`
  - Filename : `basename($emailLog->attachment_path)`
  - Logger `Log::info(...)`
  - Retourne `Response` avec headers `Content-Type`, `Content-Disposition: inline; filename="..."`, `Content-Length`, `Cache-Control: private, no-cache, no-store, must-revalidate`
- Ajouter route dans `routes/portail.php` :
  ```php
  Route::get('/messages/attachment/{emailLog}', MessageAttachmentController::class)->name('messages.attachment');
  ```

**REFACTOR** : aucun.

**Files** : controller + route + test

**Commit** : `feat(portail): controller MessageAttachment — téléchargement PJ inline avec ownership strict`

---

### Step 5: Mode mono — routes miroir + test parité

**Complexity**: standard

**RED** : `tests/Feature/Portail/MonoMesMessagesTest.php` (nouveau), 3 cas :
1. Mode mono actif + Tiers connecté GET `/portail/mes-messages` → 200, contient « Mes messages »
2. Téléchargement PJ depuis mode mono → 200, Content-Type valide
3. Empty state mode mono → 200, message muted

**GREEN** :
- Modifier `routes/portail-mono.php` : ajouter dans le groupe post-auth :
  ```php
  Route::get('/mes-messages', MesMessages::class)->name('mes-messages');
  Route::get('/messages/attachment/{emailLog}', MessageAttachmentController::class)->name('messages.attachment');
  ```
- Imports nécessaires en haut du fichier

**REFACTOR** : aucun.

**Files** : `routes/portail-mono.php`, test

**Commit** : `feat(portail): mode mono — parité routes mes-messages + attachment`

---

### Step 6: Documentation portail

**Complexity**: trivial

**RED** : N/A

**GREEN** : Mettre à jour `docs/portail-tiers.md` :
- Section « Slice 4 (E) — Mes messages (2026-05-15) » avec lien vers spec et plan
- Tableau provider : `MesMessagesProvider` ordre 90
- Pattern « Téléchargement PJ via route portail » (cf. controller `MessageAttachmentController`)
- Note vocabulaire : UI « Mes messages », code interne `EmailLog`/`Communications` (cohérent slice 3 avec « activité » vs `Operation`)
- Note sécurité : `EmailLog` n'extends pas TenantModel — protection via `Tiers` (TenantScope) et ownership strict côté controller PJ
- Pas de filtrage par catégorie ni de badge en v0 (parqué — à évaluer après usage)

**REFACTOR** : aucun.

**Files** : `docs/portail-tiers.md`

**Commit** : `docs(portail): documenter slice 4 (Mes messages + téléchargement PJ inline)`

---

## Complexity Classification

| Step | Complexity |
|------|-----------|
| 1 — DTO étendu + service pageSize + helper | standard |
| 2 — Provider MesMessages | standard |
| 3 — Composant Livewire + vue + route | **complex** (UI + sécurité Livewire) |
| 4 — Controller PJ + route + sécurité | **complex** (sécurité PJ + ownership multi-tenant) |
| 5 — Mode mono | standard |
| 6 — Documentation | trivial |

## Pre-PR Quality Gate

- [ ] Suite Pest verte (objectif ~640+ tests Portail / 0 failure)
- [ ] `./vendor/bin/sail bin pint` clean
- [ ] Larastan baseline inchangée
- [ ] `/code-review --changed` passe sur le diff vs `main`
- [ ] Test manuel localhost (port 80) :
  - Ouvrir `/{slug}/portail/mes-messages` avec un Tiers ayant des emails reçus → vérifier la liste desc
  - Click sur une ligne → expand inline avec corps HTML rendu fidèle
  - Click sur une 2e ligne → ferme la 1ère, ouvre la 2e
  - Tester pagination si > 25 emails
  - Tester téléchargement PJ → ouverture nouvel onglet, fichier servi inline
  - Vérifier sidebar : entrée « Mes messages » apparaît si emails reçus
  - Vérifier dashboard : tuile « Mes messages »
  - Vérifier qu'aucune métadonnée interne (envoyé par, statut, tracking) n'apparaît
- [ ] `docs/portail-tiers.md` à jour

## Risks & Open Questions

| # | Risque / Question | Mitigation / Réponse |
| - | ----------------- | -------------------- |
| 1 | `EmailLogLigneDTO` est utilisé dans `TiersDocumentsTimelineService` (slice 8 fiche tiers) — l'ajout de 2 props peut-il casser ? | Step 1 : ajouter en fin de constructeur (compat ascendante PHP). Smoke test sur tests existants `TiersDocuments` après modif. |
| 2 | `Storage::mimeType()` peut retourner null sur certains types — fallback `application/octet-stream` | Step 4 : prévoir le fallback dans le controller. |
| 3 | `EmailLog` n'extends pas TenantModel — risque cross-tenant via `EmailLog::find()` direct dans le controller PJ. Mitigation : check ownership via `Tiers::find($emailLog->tiers_id)` qui passe par TenantScope. Si Tiers introuvable → cross-tenant → 404. | Step 4 : test cross-tenant explicite. |
| 4 | Pagination Livewire 3 : `wire:navigate` ou `WithPagination` trait peut être nécessaire pour les liens pagination. | Step 3 : utiliser `Livewire\WithPagination` trait + `$paginator->links()` Bootstrap. Fallback : `gotoPage($n)` méthode publique avec links manuels. |
| 5 | Corps HTML peut contenir des `<style>`, `<script>`, `<iframe>` injectés — risque XSS via templates email mal configurés. Acteur asso trusté en v0, mais à monitorer. | Spec hors scope : sanitize HTMLPurifier parqué. Acceptation risque en v0 (admin trusté). |
| 6 | EmailLog peut avoir corps_html très lourd (campagne newsletter) → impact perf rendering | v0 : accepté. À monitorer si plainte utilisateur. |
| 7 | `attachment_path` est un seul chemin par EmailLog (1 PJ max). Si demain on supporte plusieurs PJ par email, refactor majeur. | Hors scope. |

## Notes d'exécution

- **Mode subagent-driven** (préférence projet).
- **Inline review checkpoints** sur les steps complex (3 et 4) : security-review minimum.
- **Branche unique** : on continue sur `feat/portail-membres-slice1-fondation-profil`. Pas de merge intermédiaire vers main.
- **Pas de push prod** : test local d'abord.
- **Cast (int) strict** dans tous les `===` PK/FK.
- **Vocabulaire UI** : « Mes messages », « pièce jointe », pas « EmailLog » ni « Communication » dans les libellés.

## Estimation

6 steps total : 4 standard + 2 complex (3, 4) + 1 trivial (6). Slice plus court que slice 3 (11 steps) car capitalise lourdement sur les patterns établis (route portail inline, ownership strict, resolver/registry, mode mono).
