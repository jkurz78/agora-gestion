# Spec — Fiche campagne questionnaire + fix scans inversés

Statut : **validée** (design + mockup approuvés en session le 2026-07-06).
Branche : `feat/questionnaires-fiche-campagne` (basée sur `feat/questionnaires-ressenti-pixel`).

## Contexte — trois problèmes constatés en recette

1. **Bug data — scans inversés.** La page résultats affiche le scan d'un autre répondant sur les
   réponses papier anonymes (bearer). Cause racine : aucun lien persisté soumission↔scan ;
   `CampagneResultats` reconstruit l'association **par position** en appariant deux listes triées
   en sens opposés (soumissions `orderByDesc('submitted_at')`, scans `orderBy('created_at')`).
2. **Breadcrumbs faux.** Les écrans Résultats/Scans/Envoi affichent
   `… / <opération> / Résultats <questionnaire>` au lieu de `… / <opération> / <questionnaire> / Résultats` ;
   le layout ne supporte que 2 niveaux cliquables. Le crumb `<opération>#questionnaires` ramène sur
   l'onglet Participants : les onglets de la fiche opération sont des onglets Livewire (`$activeTab`),
   l'ancre est ignorée.
3. **Navigation fouillis.** Le questionnaire n'a pas de page à lui : actions éclatées en boutons de
   ligne (Résultats, Invitations, Clôturer, Plus→Imprimer/Scans), liste des participants cachée dans
   une modale derrière la colonne Participants, réponses individuelles en pied de résultats.

## Décisions validées

- **D1 — Fiche campagne** : nouvelle page pivot `GET /questionnaires/campagnes/{campagne}`
  (route `questionnaires.campagnes.show`), composant Livewire `CampagneShow`, onglets Livewire avec
  `#[Url(as: 'tab')]` : `suivi` (défaut) | `diffusion` | `scans` | `resultats`.
- **D2 — En-tête de fiche** : titre affiché + badge statut + « Créée le … — modèle « … » — opération … » ;
  actions d'état à droite : Lancer (si brouillon), Clôturer (si ouverte), via `wire:confirm`
  (modale Bootstrap, convention app).
- **D3 — Onglet Suivi** : 3 compteurs (invités, réponses, taux) + tableau des invitations
  (participant, statut, actions Saisir / Scanner (upload inline ciblé) / Rouvrir) — reprend à
  l'identique le contenu de l'actuelle modale « Participants », qui disparaît.
- **D4 — Onglet Diffusion** : « Envoyer les invitations » → écran envoi existant (TinyMCE reste en
  pleine page — gotcha connu, ne jamais l'inliner) ; Imprimer PDF papier (non anonyme) / PDF anonyme
  (si `anonymise`) ; Aperçu répondant.
- **D5 — Onglet Scans** : intègre `<livewire:questionnaire.scan-upload>` tel quel ; l'onglet porte un
  badge = nombre de scans à traiter (drafts `brouillon`).
- **D6 — Onglet Résultats** : intègre `<livewire:questionnaire.campagne-resultats>` tel quel
  (consolidés + réponses individuelles + exports).
- **D7 — Liste épurée** dans l'onglet Questionnaires de l'opération : colonnes Titre (lien → fiche),
  Statut, Participants (nombre simple), Réponses/Taux. **Plus aucun bouton de ligne, plus de modale
  participants.** Restent : « + Nouvelle campagne » (redirige vers la fiche créée, onglet Suivi) et
  « Consolider ».
- **D8 — Redirections** : `campagnes/{c}/resultats` → 302 fiche `?tab=resultats` ;
  `campagnes/{c}/scans` → 302 fiche `?tab=scans`. Routes conservées telles quelles : `envoi`,
  `apercu`, `pdf`, `pdf-anonyme`, `export`, `resultats/pdf`, `scans/{scan}/image`,
  `scans/{scan}/valider`, consolidés.
- **D9 — Breadcrumbs** : le layout gagne un 3ᵉ niveau cliquable (slot `breadcrumbGreatGrandParent`,
  avant `breadcrumbGrandParent`). Cibles :
  - Fiche : `Opérations / Liste des opérations / <opération> / <questionnaire>` (titre = questionnaire).
  - Envoi : `… / <opération> / <questionnaire> / Invitations` ; Validation OCR :
    `… / <opération> / <questionnaire> / Validation OCR`.
  - Le crumb `<opération>` pointe `route('operations.show', $op).'?tab=questionnaires'`.
  - Résultats/Scans étant des onglets, le dernier crumb de la fiche reste `<questionnaire>`
    (l'onglet actif est porté par la page et l'URL `?tab=`).
- **D10 — Onglet opération deep-linkable** : `OperationDetail::$activeTab` reçoit `#[Url(as: 'tab')]`
  (pattern existant `OperationList`). L'ancre `#questionnaires` est remplacée par `?tab=questionnaires`
  partout.
- **D11 — Fix scans inversés** :
  - Migration additive : `paper_scan_id` FK nullable sur `questionnaire_submissions`
    (`constrained('questionnaire_paper_scans')->nullOnDelete()`).
  - `QuestionnaireReponseService::creerDepuisOcr()` et `creerDepuisOcrAnonyme()` : nouveau paramètre
    `?int $paperScanId = null`, persisté à la création ; `AssistantSaisie::valider()` passe
    `$this->scan->id`.
  - `CampagneResultats::render()` : mapping par `paper_scan_id` d'abord, repli par `invitation_id`
    (fiable) pour l'historique ; **l'appariement positionnel bearer est supprimé**. Les soumissions
    bearer antérieures au fix n'affichent plus de badge scan (préférable à un mauvais scan).

## Hors périmètre (YAGNI)

Contenu des écrans résultats/envoi/validation OCR inchangé ; modèles de questionnaires intacts ;
résultats consolidés inchangés ; pas de backfill `paper_scan_id` (historique local uniquement).

## Critères d'acceptation

- **AC1** : `/questionnaires/campagnes/{id}` affiche la fiche, onglet Suivi par défaut
  (compteurs + tableau invitations).
- **AC2** : `?tab=resultats` (resp. `scans`, `diffusion`) ouvre l'onglet correspondant (deep-link).
- **AC3** : les anciennes routes `resultats` et `scans` redirigent (302) vers la fiche avec le bon `?tab=`.
- **AC4** : la liste de l'onglet Questionnaires n'a plus d'actions de ligne ; le titre ouvre la fiche ;
  la modale participants n'existe plus.
- **AC5** : Lancer et Clôturer fonctionnent depuis l'en-tête de fiche (confirmation modale).
- **AC6** : Saisir / Scanner (upload ciblé) / Rouvrir fonctionnent depuis l'onglet Suivi
  (parité fonctionnelle avec l'ancienne modale).
- **AC7** : `operations/{id}?tab=questionnaires` ouvre l'onglet Questionnaires de la fiche opération.
- **AC8** : breadcrumb fiche = `Opérations / Liste des opérations / <op> / <questionnaire>` avec
  `<op>` cliquable vers `?tab=questionnaires` ; écrans envoi et validation OCR rattachés à la fiche.
- **AC9** (bug) : après validation d'un scan dans l'assistant, `paper_scan_id` est renseigné ; le
  badge « Voir le scan » pointe le bon scan — test reproduisant l'inversion (2 soumissions bearer
  + 2 scans créés dans des ordres opposés) rouge avant fix, vert après.
- **AC10** : soumission bearer sans `paper_scan_id` (historique) → aucun badge scan.
- **AC11** : fiche 404 en accès cross-tenant (TenantScope) — smoke.
- **AC12** : suite questionnaires complète verte + pint.

## Réutilisation

`ScanUpload`, `CampagneResultats`, `EnvoiCompose`, `AssistantSaisie` inchangés dans leur contenu ;
méthodes métier déplacées de `OperationQuestionnaires` vers `CampagneShow` (ouvrir/cloturer/
rouvrirInvitation/ouvrirScanPour/importerScanPour) ; services existants
(`QuestionnaireCampaignService`, `QuestionnaireInvitationService`, `QuestionnaireReponseService`,
`QuestionnaireScanService`) inchangés hors D11.
