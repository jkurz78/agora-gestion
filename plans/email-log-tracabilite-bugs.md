# Plan — Fix bugs traçabilité EmailLog (bloqueur MEP portail v0)

**Branche** : `feat/portail-membres-slice1-fondation-profil`
**Contexte** : `project_bug_emaillog_tracabilite.md` (mémoire)
**Décidé** : 2026-05-16 — fixer les 4 bugs **avant** la MEP portail.

## But

Garantir que tout envoi d'email via l'app persiste :
- un objet où **toutes les variables sont substituées** (ni `{operation}` ni autre `{xxx}` ne fuit),
- un `corps_html` non-null en base reflétant ce que le destinataire a vu,
- un `attachment_path` pointant vers un PDF persisté sur disque (quand applicable),
- avec un logo footer **discret** (pas 80px).

Bénéfice : l'écran portail `/portail/mes-messages` affiche correctement le corps et propose le téléchargement PJ. Plus largement : tout futur tooling de traçabilité (recherche, ré-envoi, audit RGPD) repose sur des données fidèles.

## Acceptance criteria globaux

- AC1 — Envoyer un devis depuis « Opération / Règlement » (`ReglementTable`) crée un `EmailLog` avec `corps_html` non-null, `attachment_path` non-null, et le PDF lisible sur disque. L'objet du mail ne contient aucun `{xxx}` non substitué.
- AC2 — Idem pour envoi devis/facture/proforma depuis `ParticipantShow` et `FactureShow`.
- AC3 — Tout `Mailable` qui supporte des templates utilisateur (`DocumentMail`, `AttestationPresenceMail`, `CommunicationTiersMail`, `DevisManuelMail`, `MessageLibreMail`, `FormulaireInvitation`) a un test "leak" qui rejette tout rendu contenant `{xxx}` non substitué pour les variables documentées de sa catégorie.
- AC4 — Le logo email (footer) ne dépasse pas une hauteur visuelle raisonnable (≤ 40px) dans le HTML rendu.
- AC5 — Vu sur `/portail/mes-messages` (test feature) : le message d'un devis envoyé depuis Reglement expose son `corpsHtml` et un bouton de téléchargement PJ fonctionnel.
- AC6 — Suite Pest verte (706 portail + reste de la suite, ≥ 4045 tests).

## Architecture

### Service centralisé `App\Services\Email\EmailLogStorageService`

Méthode principale :

```php
public function logSent(
    Mailable $mail,
    Tiers $tiers,
    CategorieEmail $categorie,
    string $destinataireEmail,
    ?string $destinataireNom = null,
    ?int $participantId = null,
    ?int $operationId = null,
    ?int $emailTemplateId = null,
    ?string $pdfContent = null,
    ?string $pdfFilename = null,
    array $extra = [], // pour campagne_id, tracking_token, etc.
): EmailLog
```

Responsabilités :
1. Si `$pdfContent` est fourni → persister sur `storage/app/associations/{associationId}/email_attachments/{uuid}-{filename}` (disque `local`, jamais `public`).
2. Récupérer le HTML rendu via `$mail->render()` et persister dans `corps_html` (passé par `EmailTemplate::sanitizeCorps` si la convention l'exige pour la catégorie).
3. Récupérer l'objet via `$mail->envelope()->subject`.
4. Persister l'`EmailLog` avec tous les champs renseignés + extras.

Méthode complémentaire :

```php
public function logError(
    Tiers $tiers,
    CategorieEmail $categorie,
    string $destinataireEmail,
    string $objetFallback,
    string $erreurMessage,
    ?string $destinataireNom = null,
    ?int $participantId = null,
    ?int $operationId = null,
    ?int $emailTemplateId = null,
    array $extra = [],
): EmailLog
```

Tenant : repose sur `TenantContext::currentId()` pour construire le path.

### Leak tests sur Mailables

Helper Pest : `assertNoUnsubstitutedVariables(Mailable $mail)` (placé dans `tests/Support/EmailAssertions.php` ou trait Pest) :

```php
function assertNoUnsubstitutedVariables(Mailable $mail): void {
    $subject = $mail->envelope()->subject;
    $html = $mail->render();
    $combined = $subject . "\n" . $html;
    preg_match_all('/\{[a-z_]+\}/', $combined, $matches);
    expect($matches[0])->toBe([], 'Variables non substituées: '.implode(', ', $matches[0]));
}
```

Un test par Mailable injecte dans `customCorps` + `customObjet` la liste complète des variables documentées et vérifie zéro fuite.

### Logo footer

Réduire `style="height:80px"` → `style="max-height:40px;height:auto;width:auto"` dans `EmailLogo::buildCidImgTag`. Ajuster `<img>` sortant via attribut `height="40"` aussi (compat clients qui ignorent CSS).

## Steps (TDD, séquentiels sauf indication)

### Step 1 — Leak test infrastructure + tests sur les 6 mailables (RED révèle Bug 1)

**Scope** :
- Créer `tests/Support/EmailAssertions.php` exposant `assertNoUnsubstitutedVariables(Mailable $mail): void` (via fonction globale Pest dans `tests/Pest.php`).
- Créer 1 test feature/unit par Mailable dans `tests/Unit/Mail/` :
  - `DocumentMailVariablesTest.php`
  - `AttestationPresenceMailVariablesTest.php`
  - `CommunicationTiersMailVariablesTest.php`
  - `DevisManuelMailVariablesTest.php`
  - `MessageLibreMailVariablesTest.php`
  - `FormulaireInvitationVariablesTest.php`
- Chaque test :
  1. Liste les variables documentées que la catégorie doit supporter (inspecter `EmailTemplate` modèle / seeders pour comprendre quelles variables sont annoncées à l'utilisateur).
  2. Injecte un `customCorps` ou `customObjet` qui les contient toutes.
  3. Appelle `assertNoUnsubstitutedVariables($mail)`.

**Attendu** : `DocumentMailVariablesTest` RED si on y inclut `{operation}` et `{type_operation}`. Autres mailables : vérifier le statut au cas par cas et adapter le périmètre (si une variable n'est pas censée être supportée, ne pas l'injecter).

**Acceptance** : tous les leak tests passent (sauf `DocumentMail` qui restera rouge tant que Step 2 n'est pas fait).

### Step 2 — Fix Bug 1 : variables `{operation}` + `{type_operation}` dans `DocumentMail`

**Scope** :
- Ajouter constructor params optionnels `?string $operationLabel = null` et `?string $typeOperationLabel = null` à `DocumentMail`.
- Mapper dans `variables()` : `{operation}` → `$operationLabel ?? ''`, `{type_operation}` → `$typeOperationLabel ?? ''`.
- Patcher les 3 call sites qui instancient `DocumentMail` (`ReglementTable`, `ParticipantShow`, `FactureShow`) pour passer ces deux valeurs depuis l'`Operation` du contexte (utiliser `$operation->libelle` ou champ équivalent + `$operation->typeOperation->libelle`).

**RED** : `DocumentMailVariablesTest` (Step 1) passe au vert.
**GREEN** : implémenter substitution + propagation aux call sites.
**REFACTOR** : factoriser la résolution `Operation → (label, typeLabel)` si pattern répété.

**Acceptance** : `DocumentMailVariablesTest` vert.

### Step 3 — Fix Bug 2 : logo footer trop grand

**Scope** :
- Modifier `App\Helpers\EmailLogo::buildCidImgTag()` : remplacer `style="height:80px;width:auto;"` par `style="max-height:40px;height:auto;width:auto;" height="40"`.
- Test unit `tests/Unit/Helpers/EmailLogoTest.php` (créer si absent) : vérifier que `EmailLogo::variables()` produit un `<img>` avec `max-height:40px` et `height="40"`.

**Acceptance** : test logo vert. Pas de régression visuelle dans le rendu des autres Mailables (re-jouer les leak tests).

### Step 4 — `EmailLogStorageService` + tests unit

**Scope** :
- Créer `app/Services/Email/EmailLogStorageService.php` avec signatures décrites en Architecture.
- Tests `tests/Unit/Services/Email/EmailLogStorageServiceTest.php` :
  - `logSent` avec PDF → fichier persisté sur `Storage::disk('local')` au bon path, `EmailLog` créé avec `corps_html` + `attachment_path` + `objet` substitué.
  - `logSent` sans PDF → `EmailLog` créé, `attachment_path` null, `corps_html` rempli.
  - Path inclut bien `association_id` courant (test multi-tenant).
  - `logError` → `EmailLog` créé avec `statut = 'erreur'`, `erreur_message` rempli, sans toucher au disque.
  - Filename construit avec UUID prefix (collision-safe).
- Utiliser `Storage::fake('local')` pour les assertions de fichier.

**Acceptance** : tests Pest verts, service prêt à l'emploi.

### Step 5 — Refactor `ReglementTable::sendDocumentEmail` + test feature

**Scope** :
- Remplacer les 2 `EmailLog::create(...)` (succès + erreur) par appels `EmailLogStorageService::logSent` / `logError`.
- Test feature `tests/Feature/ReglementTableEmailLogTest.php` :
  - Setup : tenant + tiers + operation + devis associé + template `EmailTemplate` (categorie Document) avec corps contenant `{operation}` et `{type_operation}`.
  - Action : déclencher `sendDocumentEmail` via Livewire.
  - Assertions :
    - `EmailLog::latest()->first()` a `corps_html` non null + `attachment_path` non null + objet sans `{xxx}`.
    - `Storage::disk('local')->exists($emailLog->attachment_path)` est `true`.
    - Contenu du fichier = `$pdfContent` envoyé.

**Acceptance** : test vert + AC1 satisfait.

### Step 6 — Refactor `ParticipantShow` (envoi devis/proforma depuis fiche participant) + test feature

**Scope** : symétrique à Step 5, sur les 2 `EmailLog::create` de `ParticipantShow.php` (lignes 803 + 818).
- Test feature `tests/Feature/ParticipantShowEmailLogTest.php` (ou compléter test existant).

**Acceptance** : test vert + AC2 partielle.

### Step 7 — Refactor `FactureShow` (envoi facture) + test feature

**Scope** : symétrique à Step 5, sur `FactureShow.php` (lignes 258 + 274).
- Test feature `tests/Feature/FactureShowEmailLogTest.php` (ou compléter test existant).

**Acceptance** : test vert + AC2 complète.

### Step 8 — Audit `AttestationModal` + `ParticipantTable`

**Scope** :
- Inspecter les 4 `EmailLog::create` de `AttestationModal.php` (215, 230, 309, 325) et les 2 de `ParticipantTable.php` (425, 441).
- Pour chaque : si `corps_html` ou `attachment_path` manquent, refactor vers `EmailLogStorageService::logSent`.
- Ajouter ou compléter test feature pour chaque site refactoré.

**Acceptance** : audit documenté, refactor effectué si nécessaire, tests verts.

### Step 9 — Test e2e portail Mes Messages

**Scope** :
- Test feature `tests/Feature/Portail/MesMessagesDevisVisibleTest.php` :
  - Setup complet : asso + tiers + magic-link auth portail + envoi devis depuis ReglementTable.
  - Navigation : `actingAs($user)->get('/portail/mes-messages')`.
  - Assertions :
    - Le message du devis apparaît dans la liste.
    - L'expand inline contient le `corpsHtml` (assertion sur portion du HTML).
    - Le bouton « Télécharger la pièce jointe » est présent et pointe vers l'URL de téléchargement.
    - Téléchargement effectif : status 200 + content-type `application/pdf`.

**Acceptance** : test vert + AC5 satisfait.

### Step 10 — Vérification manuelle localhost

**Scope** :
- Démarrer Sail (`./vendor/bin/sail up -d` si pas déjà up).
- Connecté en `admin@monasso.fr` : envoyer un devis depuis Opération / Règlement à un membre destinataire.
- Se connecter en portail via magic-link envoyé au membre, vérifier sur `/portail/mes-messages` :
  - Objet sans `{xxx}`.
  - Expand affiche le corps complet.
  - Téléchargement PJ fonctionne.
  - Logo footer discret (≤ 40px).
- Capturer une preuve (screenshot ou résumé écrit).

**Acceptance** : AC1–AC5 validés visuellement.

## Risques & mitigations

- **Substitution `{operation}` mal nommée** : on suppose `Operation::libelle` (ou `titre`). Si le modèle expose un champ différent (`nom`, `intitule`…), demander confirmation avant de propager. Mitigation : le subagent qui fait Step 2 doit inspecter le modèle `Operation` d'abord.
- **`$mail->render()` peut throw** si le template requiert des params view non passés. Mitigation : tester chaque Mailable individuellement (Step 1 le couvre).
- **Disk `local` vs `associations`** : projet utilise `storage/app/associations/{id}/…` pour les PJ tenant. Suivre cette convention strictement (cf `CLAUDE.md`).
- **Réduction logo à 40px** : peut paraître petit dans les inboxes haute-densité. Si retour utilisateur négatif, ajuster en post-MEP.

## Pre-PR quality gate

- `./vendor/bin/sail test` → tous verts (≥ 4045 tests).
- `./vendor/bin/sail composer pint` → 0 violation.
- `./vendor/bin/sail composer phpstan` (si dispo) → 0 nouveau warning.
- Vérification manuelle Step 10 documentée.
- Commits squashés / messages clairs (`fix(email): …`, `feat(email): EmailLogStorageService`, etc.).
