# Spec — Fiche tiers 360° slice 8 : Communications + Documents

**Date** : 2026-05-12
**Branche cible** : `feat/fiche-tiers-slice4-recus-cotisations` (continuité, MEP groupée avec slice 3 + 4 + 7a + 7b + 7c)
**Statut** : PASS — prêt pour /plan

## 1. Contexte

Le programme « Fiche tiers 360° » (vision 2026-05-08) prévoit deux onglets jumeaux non encore livrés : « Historique emails / communications » et « Documents disponibles ». Ce slice clôt la matière vivante de la fiche avant le slice de clôture qui démontera `TiersQuickView`.

Les slices 0+1 (squelette + Coordonnées + Dons), 2 (Adhésions), 3a–d (formules + auto-création HelloAsso), 4 (reçus fiscaux cotisations) et 7a/b/c (Opérations en 4 sections) sont livrés. Le pattern « composant Livewire lazy + service + DTOs typés + section-card optionnelle » est éprouvé et amorti.

## 2. Objectif

Livrer 2 nouveaux onglets sur `/tiers/{tiers}` :
- **Communications** : timeline paginée des emails reçus par le tiers (envois directs + envois via ses participants), avec filtre catégorie et modale détail.
- **Documents** : sections-cards empilées listant 5 sources canoniques de documents disponibles pour ce tiers.

## 3. Hors scope (dette dormante)

| Item | Raison |
|---|---|
| `incoming_documents` (matching `sender_email`) | Heuristique non fiable, mérite slice dédié avec UI de rattachement manuel multi-tiers |
| `email_logs.attachment_path` en section Documents | Déjà accessible via modale Communications, pas de doublon |
| `documents_previsionnels` | Granularité exercice, pas tiers |
| `rapprochements_bancaires.piece_jointe` | Granularité asso, pas tiers |
| Notes de frais (`notes_de_frais`) | Slice 6 dédié du programme prévu |
| Filtre période / range date Communications | Pagination DESC suffit au MVP |
| Action « Renvoyer cet email » | Hors scope, slice futur si retour utilisateur |
| Export CSV historique | Hors scope MVP |
| Écran portail « Mes communications » + « Mes documents » | Services réutilisables (cf. §7), écran à brancher dans slice portail dédié |

## 4. Décisions de design

**D1 — Approche A monolithique** : un seul slice livre les 2 onglets. Le pattern slice 7 est entièrement amorti et le slice 8 est la dernière brique avant clôture du programme.

**D2 — Deux services séparés** : `TiersCommunicationsTimelineService` et `TiersDocumentsTimelineService`. Les sémantiques (traces d'envoi vs matière téléchargeable) divergent assez pour que la fusion en service composite nuise à la lisibilité.

**D3 — Compteurs passés en props au composant lazy** : `FicheTiers::render()` calcule les compteurs (1 query EmailLog + `countTotal` documents = 5 SELECT COUNT), les passe en props `wire:lazy`. Évite la double exécution observée en dette slice 7b.

**D4 — Onglets conditionnels** : compteur = 0 → onglet absent. Une section Documents vide est masquée individuellement (5 sections empilables).

**D5 — UNION emails tiers/participants** : un email peut cibler un participant sans `tiers_id` renseigné. La query utilise `WHERE tiers_id = X OR participant_id IN (SELECT id FROM participants WHERE tiers_id = X)`. Pas de doublon par construction (1 row dans `email_logs`, qu'il soit relié par l'un ou l'autre).

**D6 — Pagination Communications uniquement** : 50/page Livewire `WithPagination`. Documents non paginé (volumétrie attendue < 100 lignes total par tiers).

**D7 — Iframe sandboxé pour `corps_html`** : sécurité. L'HTML est en général notre TinyMCE mais l'iframe sandbox supprime le risque d'injection si un email entrant remontait un jour.

**D8 — `<x-tiers.section-card>`** : le composant `<x-tiers.operations.section-card>` (slice 7) est renommé en `<x-tiers.section-card>` car il n'est plus exclusif aux opérations. Refactor non breaking, 4 fichiers à patcher.

**D9 — Pas de filtre Documents** : la matière est faible et clairement structurée par sections. Cohérent avec slice 7 (pas de filtre intra-onglet).

**D10 — Liens de navigation interne sortants (fiche participant, facture, opération)** : ouverture même onglet, cohérent avec la navigation actuelle de la fiche tiers.

## 5. Architecture

### 5.1 Composants Livewire

- `App\Livewire\Tiers\Onglets\Communications` (lazy)
  - Props : `Tiers $tiers`, `int $nbInitial`
  - State : `?string $filtreCategorie`, `?int $selectedEmailId`
  - Méthodes : `setFiltre(?string $cat)`, `openDetail(int $id)`, `closeDetail()`
  - Trait : `WithPagination`
- `App\Livewire\Tiers\Onglets\Documents` (lazy)
  - Props : `Tiers $tiers`, `int $nbInitial`
  - Pas de state au MVP (5 sections statiques)

### 5.2 Services

- `App\Services\Tiers\TiersCommunicationsTimelineService`
  - `forTiers(Tiers $tiers, ?string $filtreCategorie = null): CommunicationsTimelineDTO`
- `App\Services\Tiers\TiersDocumentsTimelineService`
  - `forTiers(Tiers $tiers): DocumentsTimelineDTO`
  - `countTotal(Tiers $tiers): int`

Les deux signatures ne dépendent ni de `Auth`, ni du rôle. Réutilisables depuis un futur composant portail.

### 5.3 DTOs (tous `readonly`, dans `App\Services\Tiers\DTO\` — namespace singulier, cohérent slice 7)

```php
final readonly class CommunicationsTimelineDTO {
    public function __construct(
        public LengthAwarePaginator $emails, // paginator de EmailLogLigneDTO
        public int $total,
        public array $compteursParCategorie, // ['attestation' => 12, 'message' => 3, ...]
    ) {}
}

final readonly class EmailLogLigneDTO {
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
    ) {}
}

final readonly class DocumentsTimelineDTO {
    public function __construct(
        /** @var RecuFiscalLigneDTO[] */     public array $recusFiscaux,
        /** @var FactureEmiseLigneDTO[] */   public array $facturesEmises,
        /** @var FactureDeposeeLigneDTO[] */ public array $facturesDeposees,
        /** @var DocumentParticipantLigneDTO[] */ public array $justificatifsParticipants,
        /** @var PieceJointeLigneDTO[] */    public array $piecesJointes,
        public int $totalGlobal,
    ) {}
}

final readonly class RecuFiscalLigneDTO {
    public function __construct(
        public int $id,
        public string $numero,
        public string $type, // 'don' | 'cotisation'
        public Carbon $dateEmission,
        public float $montant,
        public string $downloadUrl,
        public ?string $sourceUrl, // lien vers transaction ou onglet adhésion
    ) {}
}

final readonly class FactureEmiseLigneDTO {
    public function __construct(
        public int $id,
        public string $numero,
        public Carbon $date,
        public string $type, // 'facture' | 'devis' | 'pro_forma'
        public string $statut,
        public float $montantTtc,
        public string $ficheUrl,
    ) {}
}

final readonly class FactureDeposeeLigneDTO {
    public function __construct(
        public int $id,
        public string $numeroFournisseur,
        public Carbon $dateFacture,
        public string $statut,
        public int $pdfTaille,
        public Carbon $dateDepot,
        public string $downloadUrl,
        public ?string $ficheUrl,
    ) {}
}

final readonly class DocumentParticipantLigneDTO {
    public function __construct(
        public int $id,
        public string $label,
        public int $participantId,
        public string $participantNom,
        public string $source,
        public Carbon $dateDepot,
        public string $downloadUrl,
    ) {}
}

final readonly class PieceJointeLigneDTO {
    public function __construct(
        public int $transactionId,
        public ?int $ligneId, // null si PJ portée par la transaction elle-même
        public Carbon $dateTransaction,
        public string $type, // 'recette' | 'depense'
        public string $libelle,
        public string $niveau, // 'transaction' | 'ligne'
        public string $downloadUrl,
    ) {}
}
```

### 5.4 Vues

- `resources/views/livewire/tiers/onglets/communications.blade.php`
- `resources/views/livewire/tiers/onglets/_communications-table.blade.php` (partial table)
- `resources/views/livewire/tiers/onglets/_communications-modal-detail.blade.php` (modale détail)
- `resources/views/livewire/tiers/onglets/documents.blade.php`
- `resources/views/livewire/tiers/onglets/_documents-recus-fiscaux.blade.php`
- `resources/views/livewire/tiers/onglets/_documents-factures-emises.blade.php`
- `resources/views/livewire/tiers/onglets/_documents-factures-deposees.blade.php`
- `resources/views/livewire/tiers/onglets/_documents-justificatifs-participants.blade.php`
- `resources/views/livewire/tiers/onglets/_documents-pieces-jointes.blade.php`

## 6. Détails par onglet

### 6.1 Onglet Communications

**Source** :
```php
EmailLog::query()
    ->where(function ($q) use ($tiers) {
        $q->where('tiers_id', $tiers->id)
          ->orWhereIn('participant_id', $tiers->participants()->select('id'));
    })
    ->when($filtreCategorie, fn ($q, $cat) => $q->where('categorie', $cat))
    ->with([
        'participant:id,nom,prenom',
        'operation:id,nom',
        'campagne:id,nom',
        'envoyePar:id,name',
        'opens',
    ])
    ->orderByDesc('created_at')
    ->paginate(50);
```

**Compteurs par catégorie** (pour badges du filtre) : 1 query GROUP BY sur la même base que ci-dessus, sans filtre catégorie.

**Colonnes du tableau** :

| Date | Catégorie | Objet | Destinataire | Statut | Ouvertures | PJ | Actions |
|---|---|---|---|---|---|---|---|
| `d/m/Y H:i` + `data-sort` ISO | badge coloré, `CategorieEmail::label()` si match | tronqué CSS + tooltip | `nom <email>` | badge `envoyé`/`erreur` (popover erreur_message) | `bi-eye` + compteur, tooltip 1ère ouverture | `bi-paperclip` ou `—` | bouton `bi-arrows-fullscreen` → modale |

**Filtres** (carte header) : sélecteur catégorie avec badges compteur. Pas de range date.

**Modale détail** (`#emailLogDetailModal`) :
- Header : objet rendu + badges catégorie/statut
- Métadonnées : date, destinataire, envoyé par, template, campagne (si présente), opération liée (lien), participant lié (lien)
- Corps : `corps_html` dans `<iframe sandbox="" srcdoc="...">`, fallback `<pre>` si vide
- Pièce jointe : bouton « Télécharger » si `attachment_path` non null
- Ouvertures : liste `EmailOpen::opened_at` + ip + user_agent tronqué, si > 0
- Erreur : `<pre>` rouge si statut=erreur

**Pagination** : `WithPagination`, theme Bootstrap, 50/page.

### 6.2 Onglet Documents

**5 sections empilées** (cards), chacune `<x-tiers.section-card>`. Section avec 0 ligne → masquée intégralement.

#### Section A — Reçus fiscaux émis
- Source : `RecuFiscalEmis::where('tiers_id', X)->whereNull('annule_at')->orderByDesc('emitted_at')`
- Type Don/Cotisation : résolu par **présence d'une `Adhesion` liée à la même transaction que la `transaction_ligne_id` du reçu** :
  - `Adhesion::where('transaction_id', $recu->transactionLigne->transaction_id)->exists()` → Cotisation
  - sinon → Don
- Implémentation : eager-load `transactionLigne:id,transaction_id` puis 1 query agrégée `Adhesion::whereIn('transaction_id', ...)` pour bâtir une map. Pas de N+1.
- Colonnes : N° | Type | Date émission | Montant | Actions (Télécharger PDF, Voir source)
- Download URL : route existante du programme reçu fiscal (slice 4)
- Source URL : `/tiers/{id}?onglet=dons` (don) ou `/tiers/{id}?onglet=adhesion` (cotisation)

#### Section B — Factures émises (asso → tiers)
- Source : `Facture::where('tiers_id', X)->orderByDesc('date')`, tous statuts inclus
- Colonnes : N° | Date | Type (Facture/Devis/Pro forma) | Statut | Montant TTC | Actions (Voir, PDF)
- Lien : `/factures/{id}` (route existante)

#### Section C — Factures partenaires déposées (tiers → asso)
- Source : `FacturePartenaireDeposee::where('tiers_id', X)->orderByDesc('date_facture')`
- Colonnes : N° fournisseur | Date facture | Statut | Taille | Date dépôt | Actions (PDF, Voir fiche back-office)

#### Section D — Justificatifs participants
- Source : `ParticipantDocument::whereIn('participant_id', $tiers->participants()->select('id'))->latest()`
- Colonnes : Label | Participant (lien) | Source | Date dépôt | Actions (Télécharger)
- Download : `ParticipantDocument::documentFullPath()` via TenantStorage

#### Section E — Pièces jointes comptables
- Source : UNION
  - `Transaction::where('tiers_id', X)->whereNotNull('piece_jointe_path')`
  - `TransactionLigne::whereHas('transaction', fn ($q) => $q->where('tiers_id', X))->whereNotNull('piece_jointe_path')`
- Note : risque doublon visuel rare (PJ à la fois sur TX et sur une ligne) — accepté au MVP, à noter en dette si rencontré
- Colonnes : Date transaction | Type | Libellé | Niveau (transaction/ligne) | Actions (Télécharger, Voir transaction)

## 7. Symétrie portail

Les services `forTiers(Tiers $tiers)` reçoivent un `Tiers` quelconque sans dépendance Auth. Réutilisables tels quels depuis un futur composant portail tiers (authentification OTP livrée slice 1 portail, expose `Auth::user()->tiers_id`).

**Dette d'alignement portail** : pas d'écran portail « Mes communications » ni « Mes documents » au MVP. Slice portail dédié à brancher ultérieurement avec :
- Restrictions de visibilité (par exemple, masquer corps_html des emails « erreur », masquer envoyé_par interne)
- Pagination conservée

## 8. Tests & critères d'acceptation

### 8.1 Critères d'acceptation fonctionnels

1. Un tiers sans email ni document → ni onglet Communications ni onglet Documents visible.
2. Tiers avec 1 email direct uniquement → onglet Communications visible avec compteur (1), Documents absent.
3. Tiers avec 1 email envoyé via participant (`tiers_id=null, participant_id` du tiers) → onglet Communications visible compteur (1).
4. Tiers avec 1 email cumulant `tiers_id` ET `participant_id` du tiers → 1 seule ligne dans la timeline.
5. Filtre catégorie restreint la liste à la catégorie sélectionnée + le badge garde le total non filtré.
6. Modale détail : corps_html visible sandboxé, ouvertures listées, PJ téléchargeable, erreur visible si statut=erreur.
7. Tiers avec un reçu fiscal non annulé → section A visible avec 1 ligne, téléchargement opérationnel.
8. Tiers avec un reçu fiscal annulé uniquement → section A absente.
9. Tiers avec une facture en brouillon → section B la liste avec badge statut.
10. Tiers avec un ParticipantDocument via ses participants → section D le liste.
11. PJ sur transaction `tiers_id=X` → section E.
12. PJ sur transaction_ligne via une transaction `tiers_id=X` → section E.
13. Multi-tenant : document d'une autre association invisible (TenantModel + scope global).
14. Compteurs onglet = total réel (somme = ce qui est rendu).

### 8.2 Couverture tests Pest

- **Unit `TiersCommunicationsTimelineService`** : ~9 scénarios (cf. §6.1 + UNION + dédoublonnage + tri + pagination + eager loads + ouverture count + multi-tenant)
- **Unit `TiersDocumentsTimelineService`** : ~10 scénarios (cf. §6.2 + exclusion annulé + multi-tenant + countTotal)
- **Livewire `Onglets\Communications`** : ~6 scénarios (filtre catégorie, modale, badge erreur, badge ouvertures, PJ, pagination)
- **Livewire `Onglets\Documents`** : ~7 scénarios (5 sections masquage vide + badges compteurs + lien sortant)
- **Feature `FicheTiers`** : ~4 scénarios (visibilité onglets selon présence email/doc + nav `?onglet=`)

Total estimé : ~36 tests, suite cible ~4030 (vs 3994 actuel).

## 9. Plan TDD — 8 phases

| Phase | Contenu | Tests ajoutés |
|---|---|---|
| 1 | Factories manquantes : `EmailLogFactory`, `EmailOpenFactory`, `FactureFactory`, `ParticipantDocumentFactory` + refactor `<x-tiers.section-card>` (rename non breaking) | 0 |
| 2 | DTOs `CommunicationsTimelineDTO` + `EmailLogLigneDTO` + service `TiersCommunicationsTimelineService::forTiers` | 0 |
| 3 | Tests unit Communications service | ~9 |
| 4 | Composant Livewire `Onglets\Communications` + vue + partial table + modale détail + tests Livewire | ~6 |
| 5 | DTOs Documents (5 lignes + container) + service `TiersDocumentsTimelineService::forTiers` + `countTotal` | 0 |
| 6 | Tests unit Documents service (incluant assertion multi-tenant) | ~10 |
| 7 | Composant Livewire `Onglets\Documents` + vue + 5 partials sections + tests Livewire | ~7 |
| 8 | Intégration `FicheTiers` : nav-tabs + props compteurs lazy + tests feature | ~4 |

**Mode** : subagent-driven Sonnet (cf. `feedback_sonnet_subagents.md` + `feedback_execution_mode.md`).

## 10. Test manuel localhost (avant push prod)

Login `admin@monasso.fr / password`, ouvrir `/tiers`.

1. Tiers sans email ni doc : onglets Communications + Documents absents.
2. Tiers ayant reçu 1 email d'attestation : onglet Communications (1) visible → clic → tableau → catégorie « Attestation de présence » → clic « Voir » → modale ouverte, corps_html lisible, PJ téléchargeable, ouvertures listées si trackées.
3. Tiers ayant un reçu fiscal (don ou cotisation) : onglet Documents (N) visible → section « Reçus fiscaux émis » avec ligne → clic « Télécharger PDF » → PDF téléchargé.
4. Tiers ayant une facture en brouillon : section « Factures émises » visible avec badge statut Brouillon → clic ligne → ouvre `/factures/{id}`.
5. Tiers ayant déposé une facture partenaire (statut soumise/acceptée/rejetée) : section « Factures partenaires déposées » avec badge.
6. Tiers ayant un participant avec attestation médicale : section « Justificatifs participants » visible avec lien fiche participant.
7. Tiers ayant une transaction avec PJ : section « Pièces jointes comptables » visible.
8. Filtre catégorie Communications : sélection « Document » → seules les lignes catégorie=document s'affichent → badge total inchangé.
9. Tiers d'une autre asso (multi-tenant via super-admin support read-only) : documents de la 1re asso invisibles.
10. Compteur onglet correspond au nombre de lignes affichées globalement (par sommation sections / par UNION emails).

## 11. Notes d'implémentation

- **Cohérence soft-delete** : faire confiance au scope `SoftDeletes` Eloquent (pattern slice 7c). Pas de `whereNull('deleted_at')` explicite.
- **TenantStorage** : tous les téléchargements passent par les méthodes existantes des modèles (`pdf_path` / `storage_path` / `documentFullPath()` / `piece_jointe_path`). Pas de mécanisme générique nouveau.
- **Iframe sandbox** : `<iframe sandbox="" srcdoc="{{ e($email->corps_html) }}" style="width:100%; height:60vh; border:0;"></iframe>` — `sandbox=""` (vide) bloque tout (scripts, forms, navigation), seules les ressources statiques s'affichent. `srcdoc` injecte le HTML directement.
- **Indexation** : `email_logs.tiers_id` et `email_logs.participant_id` doivent être indexés (vérifier en phase 1, ajouter migration si manquant — `email_logs` a été créé en 2026-03-30 sans index dédié sur `tiers_id`, à confirmer).
- **Sécurité multi-tenant** : tous les modèles concernés étendent `TenantModel` (vérifié : `Facture`, `RecuFiscalEmis`, `FacturePartenaireDeposee`, `ParticipantDocument`, `IncomingDocument`). `EmailLog` étend `Model` standard — **à vérifier** en phase 2, le scope tenant est sinon assuré via `tiers_id` qui est lui-même tenant-scopé. Test multi-tenant explicite en phase 6.

## 12. Statut

- **Spec** : PASS 2026-05-12
- **Plan** : à générer via `writing-plans` après revue utilisateur de cette spec
- **Implémentation** : subagent-driven Sonnet sur la branche `feat/fiche-tiers-slice4-recus-cotisations` (continuité MEP groupée slice 3 + 4 + 7a + 7b + 7c + 8)
