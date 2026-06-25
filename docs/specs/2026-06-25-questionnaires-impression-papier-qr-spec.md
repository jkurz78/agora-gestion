# Questionnaires — Lot 6 : Impression papier + QR — Spec

> Génère un **PDF papier** d'une campagne, **une invitation par participant**, à remplir à la
> main **ou** à scanner pour répondre en ligne (QR). Préalable au Lot 7 (scan/OCR).
> Branche `feat/questionnaires`. Construit **sur** les sections légères livrées le 2026-06-25.
> Stack : Laravel 11 + Livewire 4 + DomPDF (`barryvdh/laravel-dompdf`) + `endroid/qr-code` v6,
> Pest. Tenant : TenantContext booté (admin authentifié), URLs via `TenantUrl`.

## 1. Contexte & objectif

Le jumeau **papier** de l'envoi email (`EnvoiCompose`). On sélectionne des participants, on
**garantit une invitation** pour chacun (`QuestionnaireInvitationService::genererPour`, idempotent),
puis on génère un PDF : une invitation par participant, avec ses **questions à remplir à la
main** et son **QR individuel** (= le lien public tokenisé) + un **code court lisible** en
secours. Le répondant peut remplir au stylo (→ scan/OCR Lot 7) **ou** scanner le QR pour
répondre en ligne (même token que le lien email).

## 2. Décisions actées (brainstorming 2026-06-25)

| # | Sujet | Décision |
|---|-------|----------|
| P1 | Portée | **Per-participant uniquement.** Une invitation = une page de départ. Pas de feuille générique/anonyme en Lot 6 (le per-invitation est requis pour l'attribution OCR du Lot 7). |
| P2 | Pagination (révise D12) | **Saut de page *avant* chaque invitation** ; une invitation **peut s'étendre sur plusieurs pages**. Deux invitations ne partagent jamais une page. (« 1 page » n'est vrai que pour un questionnaire court.) |
| P3 | Contenu du QR | `endroid/qr-code` encodant **`$invitation->lienReponse()`** (URL publique, token clair déchiffré via le cast `encrypted`). Rendu en **data-URI PNG** inline (DomPDF). |
| P4 | Secours | **`code_court`** (déjà sur l'invitation) imprimé en clair près du QR (rattachement manuel si QR abîmé). |
| P5 | Placement identifiant | **QR + code court dans l'en-tête de chaque invitation** (haut de sa 1ʳᵉ page) → présents quelle que soit la longueur. |
| P6 | Regroupement visuel | Les groupes d'écran (même `grouper_avec_precedente` qu'en ligne, via `QuestionnaireEcranResolver`) sont rendus comme **unités visuelles** : **bloc à fond subtil + espacement** (resserré dedans, large entre groupes), `page-break-inside: avoid` par groupe. |
| P7 | Coupe de page | `page-break-inside: avoid` aussi **par question** (libellé + zone de réponse jamais coupés). |
| P8 | Type Information | Rendu **intertitre** (titre `libelle` + texte `aide`), en tête de son groupe, **sans zone de réponse**. |
| P9 | Consentement papier | Bloc « ☐ J'accepte d'être recontacté(e) » **seulement si `anonymise`** (sinon identité connue). |
| P10 | Persistance | **Aucune** en Lot 6 : PDF généré à la volée. Tables `paper_batches`/scans/OCR créées au Lot 7. |

## 3. Déclenchement & sélection (UI)

- Nouveau composant Livewire **`App\Livewire\Questionnaire\ImpressionPapier`**, jumeau de
  `EnvoiCompose` : `mount(QuestionnaireCampaign $campagne)` présélectionne tous les participants
  de l'opération ; vue listant les participants (cases) ; bouton **« Générer le PDF »**.
- **Entrée** : ajouter l'accès « Imprimer (papier) » là où l'on accède déjà à l'envoi email
  (mirroir exact du point d'entrée d'`EnvoiCompose` — route ou écran de campagne ; l'implémenteur
  reproduit le câblage existant).
- Action `imprimer(QuestionnaireInvitationService, QuestionnaireImpressionService)` :
  `genererPour($campagne, $selectedParticipants)` (idempotent), puis
  `return $service->telecharger($campagne, $selectedParticipants)` qui renvoie le **download
  DomPDF** (`$pdf->download("questionnaire-{slug}.pdf")`). Si le download direct depuis Livewire
  pose problème, repli : redirection vers une route GET `campagnes/{campagne}/impression`.

## 4. Génération (service)

**`App\Services\Questionnaire\QuestionnaireImpressionService`**
- `telecharger(QuestionnaireCampaign $campagne, array $participantIds): \Illuminate\Http\Response`
  (ou le `PDF` DomPDF) :
  1. Charger les invitations des participants sélectionnés (`with('participant.tiers')`),
     ordre stable (par nom).
  2. Charger les questions de la campagne **ordonnées**, les découper en groupes via
     `app(QuestionnaireEcranResolver::class)->decouper(...)`.
  3. Pour chaque invitation, construire le **QR data-URI** depuis `lienReponse()`.
  4. Rendre `pdf.questionnaire-papier` (DomPDF, format A4) → download.
- **QR** : petit helper `QuestionnaireQrCode::dataUri(string $url): string` (encapsule
  `endroid/qr-code` v6 → PNG base64 `data:image/png;base64,...`).

## 5. Mise en page (blade DomPDF)

`resources/views/pdf/questionnaire-papier.blade.php` — boucle sur les invitations :
- Chaque invitation = un conteneur avec **`page-break-before: always`** (sauf la 1ʳᵉ).
- **En-tête d'invitation** : logo + nom de l'association (via `Association::brandingLogoDataUri()`
  + `CurrentAssociation`, comme l'écran répondant) ; titre affiché de la campagne ; intro ; et,
  aligné à droite, le **QR** + le **code court** + « Scannez pour répondre en ligne ».
- **Corps** : pour chaque **groupe** (P6) → un bloc `.groupe-papier` (fond subtil + espacement,
  `page-break-inside: avoid`) ; à l'intérieur, chaque question (`page-break-inside: avoid`) :
  - `Information` → intertitre (`libelle`) + texte (`aide`), pas de zone de réponse ;
  - sinon → libellé (+ `*` si obligatoire) + l'`aide` éventuelle + la **zone de réponse papier**
    selon le type (partiel `champ-papier`).
- **Consentement** (P9) puis **remerciement court** en fin d'invitation.
- CSS **DomPDF-safe** : pas de flexbox ; `table`/`inline-block`, largeurs explicites, styles
  inline ; couleurs douces (fond groupe ~ `#f5f7fb`).

## 6. Rendu papier par type — `resources/views/pdf/partials/champ-papier.blade.php`

Jumeau papier de `champ.blade.php` (match sur `$question->type`) :
- **texte_court** → une ligne à remplir (filet bas, ~2 cm de haut).
- **texte_long** → plusieurs lignes (bloc bordé, ~4–5 lignes).
- **satisfaction** → les **5 niveaux** alignés (Très insatisfait → Très satisfait), chacun avec
  une **case ☐** à cocher (pas de SVG — DomPDF-safe : libellés + cases/cercles).
- **ressenti** → une **échelle horizontale** à marquer d'une croix, avec `label_gauche` /
  `label_droite` aux extrémités (défauts si absents).
- **case_a_cocher** → une seule case ☐ + libellé.
- **choix_unique** → chaque option avec une case ☐.

## 7. Sécurité / tenant

- Composant + service exécutés authentifiés (admin) ; TenantContext déjà booté.
- Invitations tenant-scopées ; QR via `lienReponse()` (→ `TenantUrl`, token clair déchiffré).
- `genererPour` est idempotent (réutilise les invitations existantes, n'en recrée pas).

## 8. Tests (Pest)

- **Service / blade** : rendre `view('pdf.questionnaire-papier', [...])->render()` et asserter :
  nom du participant ; balise `<img ... src="data:image/png;base64` (QR) ; le `code_court` ;
  chaque libellé de question ; un type `Information` rendu en intertitre **sans** zone de réponse ;
  2 questions groupées dans le **même** bloc `.groupe-papier`, 2 non groupées dans des blocs
  distincts.
- **Invitations** : `imprimer` appelle `genererPour` → les participants sans invitation en
  obtiennent une (token + `code_court`) ; idempotent (pas de doublon au 2ᵉ appel).
- **Consentement** : présent si `anonymise=true`, absent sinon.
- **QR helper** : `dataUri($url)` renvoie une chaîne `data:image/png;base64,...` non vide.
- **Multi-invitations** : 2 participants → 2 conteneurs d'invitation (marqueur `page-break-before`
  sur le 2ᵉ).

## 9. Hors périmètre (→ Lot 7)

- Réception/scan des feuilles, détection QR, OCR/IA, assistant de saisie, tables
  `questionnaire_paper_batches` / `_paper_scans` / `_ocr_drafts`.
- Feuille générique/anonyme (QR de campagne).
- Réglage fin des sauts de page / pagination avancée.
