<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\InvoiceOcrResult;
use App\Enums\Espace;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\Sens;
use App\Enums\StatutFactureDeposee;
use App\Enums\StatutReglement;
use App\Enums\UsageComptable;
use App\Exceptions\ExerciceCloturedException;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Livewire\Concerns\MontantValidation;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\FacturePartenaireDeposee;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\IncomingDocument;
use App\Models\NoteDeFrais;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\TransactionAvecReglementService;
use App\Services\ExerciceService;
use App\Services\InvoiceOcrService;
use App\Services\Portail\FacturePartenaireService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class TransactionForm extends Component
{
    use RespectsExerciceCloture;
    use WithFileUploads;

    public ?int $transactionId = null;

    public string $type = '';

    public string $date = '';

    public ?string $libelle = null;

    public string $mode_paiement = '';

    public string $dateReglement = '';

    public string $etatPaiement = 'ouvert';

    public int $soldeRestantCentimes = 0;

    public bool $isLockedByReglement = false;

    /** @var array<int, array{transactionId:int,date:string,montant:string,mode:string,annulable:bool}> */
    public array $reglementsEnregistres = [];

    public ?int $posteTiersLigneId = null;

    /**
     * Pour les recettes : true = paiement déjà reçu (comptant), false = recette attendue (créance).
     * Pour les dépenses : true = paiement déjà effectué (comptant), false = dette ouverte.
     */
    public bool $paiementRecu = true;

    /**
     * Le bascule « Paiement effectué ? » peut-il encore agir ?
     *
     * `save()` n'écrit `statut_reglement` qu'à la création, et ne crée un
     * règlement que si la transaction n'a pas déjà son mode de paiement. Sur
     * tout le reste, basculer l'option ne produit rien : l'offrir quand même
     * fait croire à un échec de l'application. Constaté le 2026-08-03, où
     * l'utilisateur a conclu qu'une annulation de règlement pourtant réussie
     * n'avait pas fonctionné. Le retrait d'un règlement passe par la modale
     * « Annuler le règlement », qui demande confirmation.
     */
    public bool $paiementModifiable = true;

    public ?int $tiers_id = null;

    public ?string $reference = null;

    public ?int $compte_id = null;

    public ?string $notes = null;

    /**
     * DC-10a : la clé `compte_id` porte l'id `comptes.id` sélectionné par le
     * composant d'autocomplete de ventilation (classe 6/7) et est transmise
     * telle quelle à `TransactionService` (contrat lignes compte-first).
     *
     * @var array<int, array{compte_id: string, operation_id: string, seance: string, montant: string, notes: string}>
     */
    public array $lignes = [];

    public bool $showForm = false;

    public bool $isLocked = false;

    public bool $isLockedByFacture = false;

    public bool $isLockedByHelloAsso = false;

    /**
     * Transaction pilotée par une fiche d'immobilisation (acquisition ou dotation) — la fiche est le maître.
     *
     * #[Locked] empêche l'hydratation de cette propriété depuis le client — un complément défensif,
     * pas une garde à elle seule : l'invariant réel vit dans TransactionService::update()
     * (isLockedByImmobilisation()), seule frontière qui compte contre un appelant serveur forgé.
     */
    #[Locked]
    public bool $isLockedByImmobilisation = false;

    /** True quand la transaction verrouillée est une dotation, false quand c'est l'acquisition elle-même. */
    public bool $isImmobilisationDotation = false;

    public ?int $immobilisationId = null;

    public string $immobilisationLibelle = '';

    /** Transaction miroir d'extourne — verrouille les champs comptables. */
    public bool $isExtourneMiroir = false;

    /**
     * Sens de trésorerie pour l'affichage IHM (« depense » ou « recette »).
     * Différent de $type pour les miroirs d'extourne (recette → depense et vice-versa).
     * La logique comptable (filtre comptes, save, validation) reste sur $type.
     */
    public string $sensTresorerie = '';

    public ?string $usageFilter = null;

    /** @var TemporaryUploadedFile|null */
    public $pieceJointe = null;

    public ?NoteDeFrais $linkedNdf = null;

    public ?string $existingPieceJointeNom = null;

    public ?string $existingPieceJointeUrl = null;

    public bool $ocrMode = false;

    public bool $ocrWaitingForFile = false;

    public bool $ocrAnalyzing = false;

    public ?string $ocrError = null;

    public ?string $ocrTiersNom = null;

    public ?int $incomingDocumentId = null;

    public ?int $factureDeposeeId = null;

    public ?string $incomingDocumentPreviewUrl = null;

    /** @var array<string> */
    public array $ocrWarnings = [];

    // État du panneau de ventilation
    public ?int $ventilationLigneId = null;

    public string $ventilationLigneCompteLabel = '';

    public string $ventilationLigneMontant = '';

    /** @var array<int, array{operation_id: string, seance: string, montant: string, notes: string}> */
    public array $affectations = [];

    public bool $ventilationHasAffectations = false;

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function getMontantTotalProperty(): float
    {
        // Transaction HelloAsso : la ligne de remise (709A) est exclue de
        // $this->lignes (edit() — item a, Tâche 14) : elle n'a aucune notion
        // débit/crédit dans ce formulaire et ne peut donc pas y être éditée
        // sans être corrompue. Sommer les seules lignes visibles donnerait
        // alors le BRUT, pas le net. montant_total en base porte déjà le net
        // posé par la synchro (HelloAssoSyncService) : c'est la source de
        // vérité ici, pas une somme locale.
        if ($this->isLockedByHelloAsso && $this->transactionId !== null) {
            return (float) (Transaction::find($this->transactionId)?->montant_total ?? 0.0);
        }

        return round(collect($this->lignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2);
    }

    public function mount(): void
    {
        $this->dateReglement = app(ExerciceService::class)->defaultDate();
    }

    public function showNewForm(string $type): void
    {
        $this->reset(['transactionId', 'type', 'date', 'libelle', 'mode_paiement', 'dateReglement', 'paiementRecu', 'paiementModifiable',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes',
            'etatPaiement', 'soldeRestantCentimes', 'isLockedByReglement', 'reglementsEnregistres', 'posteTiersLigneId',
            'ventilationLigneId', 'ventilationLigneCompteLabel', 'ventilationLigneMontant', 'affectations',
            'ventilationHasAffectations',
            'pieceJointe', 'existingPieceJointeNom', 'existingPieceJointeUrl',
            'ocrMode', 'ocrWaitingForFile', 'ocrAnalyzing', 'ocrError', 'ocrWarnings', 'ocrTiersNom',
            'incomingDocumentId', 'factureDeposeeId', 'incomingDocumentPreviewUrl', 'linkedNdf']);
        $this->type = $type;
        $this->sensTresorerie = $type;
        $this->isExtourneMiroir = false;
        $this->isLocked = false;
        $this->isLockedByHelloAsso = false;
        $this->isLockedByImmobilisation = false;
        $this->isImmobilisationDotation = false;
        $this->immobilisationId = null;
        $this->immobilisationLibelle = '';
        $this->resetValidation();
        $this->showForm = true;
        $this->date = app(ExerciceService::class)->defaultDate();
        $this->dateReglement = $this->date;
        $this->compte_id = Transaction::where('saisi_par', auth()->id())
            ->whereNotNull('compte_id')
            ->latest()
            ->value('compte_id');
        $this->addLigne();
    }

    #[On('open-transaction-form')]
    public function openForm(string $type, ?int $id = null, ?string $usageFilter = null): void
    {
        $this->usageFilter = $usageFilter;
        if ($id !== null) {
            $this->edit($id);
        } else {
            $this->showNewForm($type);
        }
    }

    #[On('open-transaction-form-ocr')]
    public function openFormOcr(): void
    {
        $this->showNewForm('depense');
        $this->ocrMode = true;
        $this->ocrWaitingForFile = true;
        $this->ocrWarnings = [];
        $this->ocrError = null;
    }

    #[On('open-transaction-form-from-incoming')]
    public function openFormFromIncoming(int $docId): void
    {
        if (! $this->canEdit) {
            session()->flash('error', 'Vous n\'avez pas les droits pour créer une dépense.');

            return;
        }

        $doc = IncomingDocument::find($docId);
        if ($doc === null) {
            return;
        }

        $diskPath = Storage::disk('local')->path($doc->incomingFullPath());
        if (! file_exists($diskPath)) {
            session()->flash('error', 'Fichier introuvable sur le disque.');

            return;
        }

        $this->showNewForm('depense');
        $this->ocrMode = true;
        $this->ocrWaitingForFile = false;
        $this->incomingDocumentId = $doc->id;
        $this->existingPieceJointeNom = $doc->original_filename;

        // URL servie depuis le controller pour que l'iframe de prévisu puisse la lire.
        $this->incomingDocumentPreviewUrl = route('facturation.documents-en-attente.download', $doc);

        if (! InvoiceOcrService::isConfigured()) {
            return;
        }

        $this->runOcrAnalysis(fn ($svc) => $svc->analyzeFromPath($diskPath, 'application/pdf'));
    }

    #[On('open-transaction-form-from-depot-facture')]
    public function openFormFromDepotFacture(int $depotId): void
    {
        if (! $this->canEdit) {
            session()->flash('error', 'Vous n\'avez pas les droits pour créer une dépense.');

            return;
        }

        $depot = FacturePartenaireDeposee::find($depotId);
        if ($depot === null) {
            session()->flash('error', 'Dépôt introuvable.');

            return;
        }

        if ($depot->statut !== StatutFactureDeposee::Soumise) {
            session()->flash('error', 'Ce dépôt n\'est plus traitable (déjà traité ou rejeté).');

            return;
        }

        $diskPath = Storage::disk('local')->path($depot->pdf_path);
        if (! file_exists($diskPath)) {
            session()->flash('error', 'Fichier PDF introuvable sur le disque.');

            return;
        }

        $this->showNewForm('depense');
        $this->ocrMode = true;
        $this->ocrWaitingForFile = false;
        $this->factureDeposeeId = $depot->id;
        $this->tiers_id = $depot->tiers_id;
        $this->date = $depot->date_facture->format('Y-m-d');
        $this->reference = $depot->numero_facture;

        // URL plain route pour l'iframe de prévisualisation PDF (auth + policy suffisent, signed cause des problèmes iframe).
        $this->incomingDocumentPreviewUrl = route(
            'comptabilite.factures-fournisseurs.pdf',
            ['depot' => $depot->id],
        );

        if (! InvoiceOcrService::isConfigured()) {
            return;
        }

        $context = $this->buildDepotOcrContext($depot);

        $this->runOcrAnalysis(fn ($svc) => $svc->analyzeFromPath($diskPath, 'application/pdf', $context));
    }

    public function addLigne(): void
    {
        $this->lignes[] = [
            'id' => null,
            'compte_id' => '',
            'operation_id' => '',
            'seance' => '',
            'montant' => '',
            'notes' => '',
            'piece_jointe_path' => null,
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
            'piece_jointe_existing_url' => null,
            'piece_jointe_filename' => null,
        ];
    }

    public function removeLigne(int $index): void
    {
        unset($this->lignes[$index]);
        $this->lignes = array_values($this->lignes);
    }

    public function ouvrirVentilation(int $ligneId): void
    {
        $allowedIds = collect($this->lignes)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        if (! in_array($ligneId, $allowedIds, true)) {
            abort(403);
        }

        $ligne = TransactionLigne::with('affectations', 'compte')->findOrFail($ligneId);
        $this->ventilationLigneId = $ligneId;
        // DC-10a : libellé lu depuis le compte (source unique de la ventilation).
        $this->ventilationLigneCompteLabel = $ligne->compte?->intitule ?? '';
        $this->ventilationLigneMontant = (string) $ligne->montant;
        $this->ventilationHasAffectations = $ligne->affectations->isNotEmpty();

        if ($ligne->affectations->isEmpty()) {
            $this->affectations = [[
                'operation_id' => (string) ($ligne->operation_id ?? ''),
                'seance' => (string) ($ligne->seance ?? ''),
                'montant' => (string) $ligne->montant,
                'notes' => (string) ($ligne->notes ?? ''),
            ]];
        } else {
            $this->affectations = $ligne->affectations->map(fn ($a) => [
                'operation_id' => (string) ($a->operation_id ?? ''),
                'seance' => (string) ($a->seance ?? ''),
                'montant' => (string) $a->montant,
                'notes' => (string) ($a->notes ?? ''),
            ])->toArray();
        }
    }

    public function fermerVentilation(): void
    {
        $this->ventilationLigneId = null;
        $this->ventilationLigneCompteLabel = '';
        $this->ventilationLigneMontant = '';
        $this->affectations = [];
        $this->ventilationHasAffectations = false;
    }

    public function addAffectation(): void
    {
        $this->affectations[] = ['operation_id' => '', 'seance' => '', 'montant' => '', 'notes' => ''];
    }

    public function removeAffectation(int $index): void
    {
        if ($this->ventilationLigneId === null) {
            return;
        }
        if (! isset($this->affectations[$index])) {
            return;
        }
        array_splice($this->affectations, $index, 1);
    }

    public function saveVentilation(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $allowedIds = collect($this->lignes)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        if ($this->ventilationLigneId === null || ! in_array($this->ventilationLigneId, $allowedIds, true)) {
            abort(403);
        }

        $this->validate(
            [
                'affectations' => ['required', 'array', 'min:1'],
                'affectations.*.montant' => ['required', 'numeric', MontantValidation::RULE],
                // IMP-06, même règle que 'lignes.*.operation_id' — voir le
                // commentaire de save().
                'affectations.*.operation_id' => [
                    'nullable',
                    Rule::exists('operations', 'id')
                        ->where('association_id', TenantContext::currentId())
                        ->whereNull('deleted_at'),
                ],
                'affectations.*.seance' => ['nullable', 'integer', 'min:1'],
                'affectations.*.notes' => ['nullable', 'string', 'max:255'],
            ],
            MontantValidation::messages(['affectations.*.montant'])
        );

        $ligne = TransactionLigne::findOrFail($this->ventilationLigneId);
        $ligneMontantCents = (int) round((float) $ligne->montant * 100);
        $affectationCents = (int) round(collect($this->affectations)->sum(fn ($a) => (float) ($a['montant'] ?? 0)) * 100);
        if ($ligneMontantCents !== $affectationCents) {
            $this->addError('affectations', 'La somme des affectations doit être égale au montant de la ligne.');

            return;
        }

        try {
            app(TransactionService::class)->affecterLigne(
                $ligne,
                collect($this->affectations)->map(fn ($a) => [
                    'operation_id' => $a['operation_id'] !== '' ? (int) $a['operation_id'] : null,
                    'seance' => $a['seance'] !== '' ? (int) $a['seance'] : null,
                    'montant' => $a['montant'],
                    'notes' => $a['notes'] ?: null,
                ])->toArray()
            );
        } catch (ExerciceCloturedException $e) {
            // Pas de champ « date » dans ce panneau : la date en cause est celle
            // de la transaction parente, non éditable ici. Même clé que le
            // refus « somme des affectations » ci-dessus, pour rester cohérent.
            $this->addError('affectations', $e->getMessage());

            return;
        }

        $this->fermerVentilation();
        $this->dispatch('transaction-saved');
    }

    public function supprimerVentilation(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $allowedIds = collect($this->lignes)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        if ($this->ventilationLigneId === null || ! in_array($this->ventilationLigneId, $allowedIds, true)) {
            abort(403);
        }

        $ligne = TransactionLigne::findOrFail($this->ventilationLigneId);
        app(TransactionService::class)->supprimerAffectations($ligne);
        $this->fermerVentilation();
        $this->dispatch('transaction-saved');
    }

    #[On('edit-transaction')]
    public function edit(int $id): void
    {
        $this->ventilationLigneId = null;
        $this->ventilationLigneCompteLabel = '';
        $this->ventilationLigneMontant = '';
        $this->affectations = [];
        $this->ventilationHasAffectations = false;

        // Filtre `ventilation()` — exclut les lignes PD-only (411/5121/411)
        // générées par EcritureGenerator. L'utilisateur ne saisit/n'édite que
        // les lignes de ventilation métier (classe 6/7).
        //
        // horsRemiseHelloAsso() exclut en plus la ligne de remise
        // HelloAsso (helloasso_line_key = 'discount', item a de la Tâche 14) :
        // ce formulaire manipule des montants positifs sans notion de sens
        // débit/crédit, il ne peut pas éditer cette ligne technique (709A,
        // posée au débit par la synchro) sans la corrompre. Elle reste en
        // base, gérée exclusivement par TransactionService::update().
        $transaction = Transaction::with([
            'lignes' => fn ($q) => $q->ventilation()->horsRemiseHelloAsso(),
            'noteDeFrais',
        ])->findOrFail($id);

        $this->transactionId = $transaction->id;
        $this->type = $transaction->type->value;
        $this->date = $transaction->date->format('Y-m-d');
        $this->libelle = $transaction->libelle;
        $this->mode_paiement = $transaction->mode_paiement?->value ?? '';
        $this->dateReglement = app(ExerciceService::class)->defaultDate();
        $this->tiers_id = $transaction->tiers_id;
        $this->reference = $transaction->reference;
        $this->compte_id = $transaction->compte_id;
        $this->notes = $transaction->notes;

        $this->lignes = $transaction->lignes->map(fn ($ligne) => [
            'id' => $ligne->id,
            // DC-10a : le sélecteur de ventilation lit compte_id directement (source unique).
            'compte_id' => (string) ($ligne->compte_id ?? ''),
            'operation_id' => (string) ($ligne->operation_id ?? ''),
            'seance' => (string) ($ligne->seance ?? ''),
            'montant' => (string) $ligne->montant,
            'notes' => (string) ($ligne->notes ?? ''),
            'piece_jointe_path' => $ligne->piece_jointe_path,
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
            'piece_jointe_existing_url' => $ligne->piece_jointe_path
                ? route('comptabilite.transactions.piece-jointe-ligne', ['transaction' => $id, 'ligne' => $ligne->id])
                : null,
            'piece_jointe_filename' => $ligne->piece_jointe_path
                ? basename($ligne->piece_jointe_path)
                : null,
        ])->toArray();

        $this->existingPieceJointeNom = $transaction->piece_jointe_nom;
        $this->existingPieceJointeUrl = $transaction->pieceJointeUrl();
        $this->pieceJointe = null;
        $this->linkedNdf = $transaction->noteDeFrais;

        $this->isLocked = $transaction->isLockedByRapprochement() || $transaction->isLockedByRemise();
        $this->isLockedByFacture = $transaction->isLockedByFacture();
        $this->isLockedByHelloAsso = $transaction->helloasso_order_id !== null;

        $this->isLockedByImmobilisation = $transaction->isLockedByImmobilisation();
        $this->isImmobilisationDotation = false;
        $immobilisation = null;
        if ($this->isLockedByImmobilisation) {
            $immobilisation = Immobilisation::where('transaction_id', (int) $transaction->id)->first();
            if ($immobilisation === null) {
                // Pas une acquisition : c'est forcément une dotation, seul autre
                // cas reconnu par isLockedByImmobilisation().
                $dotation = ImmobilisationDotation::where('transaction_id', (int) $transaction->id)->first();
                $immobilisation = $dotation?->immobilisation;
                $this->isImmobilisationDotation = $immobilisation !== null;
            }
        }
        $this->immobilisationId = $immobilisation === null ? null : (int) $immobilisation->id;
        $this->immobilisationLibelle = $immobilisation === null
            ? ''
            : $immobilisation->numero.' — '.$immobilisation->libelle;

        $this->chargerEtatReglement($transaction);

        // Miroir d'extourne : le sens de trésorerie est inversé par rapport au type comptable.
        // $type reste le type réel (recette/depense) pour le filtrage comptes 6xx/7xx.
        // $sensTresorerie reflète le sens du flux d'argent pour l'IHM.
        $this->isExtourneMiroir = $transaction->type_ecriture === 'extourne';
        $this->sensTresorerie = $this->isExtourneMiroir
            ? ($transaction->sensTresorerie() === Sens::Depense ? 'depense' : 'recette')
            : $this->type;

        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'transactionId', 'type', 'date', 'libelle', 'mode_paiement', 'dateReglement', 'paiementRecu', 'paiementModifiable',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes', 'showForm', 'isLocked', 'isLockedByFacture', 'isLockedByHelloAsso', 'isLockedByImmobilisation', 'isImmobilisationDotation', 'immobilisationId', 'immobilisationLibelle', 'isLockedByReglement', 'isExtourneMiroir', 'sensTresorerie',
            'etatPaiement', 'soldeRestantCentimes', 'reglementsEnregistres', 'posteTiersLigneId',
            'ventilationLigneId', 'ventilationLigneCompteLabel', 'ventilationLigneMontant', 'affectations',
            'ventilationHasAffectations',
            'pieceJointe', 'existingPieceJointeNom', 'existingPieceJointeUrl',
            'ocrMode', 'ocrWaitingForFile', 'ocrAnalyzing', 'ocrError', 'ocrWarnings', 'ocrTiersNom',
            'incomingDocumentId', 'factureDeposeeId', 'incomingDocumentPreviewUrl', 'linkedNdf',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->isLockedByImmobilisation) {
            $this->addError('lignes', 'Cette transaction est pilotée par une fiche d’immobilisation : modifiez la fiche.');

            return;
        }

        if ($this->isLockedByHelloAsso && $this->transactionId !== null) {
            $source = Transaction::findOrFail($this->transactionId);

            $lockedFields = [
                'compte_id' => $source->compte_id,
                'date' => $source->date->format('Y-m-d'),
                'mode_paiement' => $source->mode_paiement?->value ?? '',
                'tiers_id' => $source->tiers_id,
            ];

            $hasDrift = false;
            foreach ($lockedFields as $prop => $originalValue) {
                if ((string) $this->{$prop} !== (string) $originalValue) {
                    $this->addError($prop, 'Champ verrouillé pour les transactions HelloAsso — modifiez uniquement les notes, la ventilation ou la pièce jointe.');
                    $hasDrift = true;
                }
            }

            // Montant total via somme des lignes de ventilation VISIBLES (exclut les
            // lignes PD-only ET la ligne de remise HelloAsso — item a, jamais soumise
            // par le formulaire). Comparer deux grandeurs homogènes : sans l'exclusion,
            // $sourceTotal porterait la remise (montant strictement positif quel que
            // soit son sens) alors que $currentTotal ne la contient plus jamais —
            // fausse détection de dérive sur toute transaction remisée.
            $sourceTotal = round((float) $source->lignes()->ventilation()->horsRemiseHelloAsso()->sum('montant'), 2);
            $currentTotal = round(collect($this->lignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2);
            if (abs($sourceTotal - $currentTotal) > 0.001) {
                $this->addError('lignes', 'Montant verrouillé pour les transactions HelloAsso.');
                $hasDrift = true;
            }

            if ($hasDrift) {
                return;
            }
        }

        // --- Chemin dédié miroir extourne : seuls les champs bancaires/opérationnels ---
        if ($this->isExtourneMiroir && $this->transactionId !== null) {
            $this->saveExtourneMiroir();

            return;
        }

        $exerciceService = app(ExerciceService::class);

        $this->validate(
            [
                // Plus de bornes d'exercice affiché — seule la clôture de
                // l'exercice de la date peut refuser une date
                // (ExerciceCloturedException, attrapée plus bas). Un exercice
                // futur sans ligne en base n'est pas non plus un motif de
                // refus (ExerciceService::assertOuvert()).
                'date' => ['required', 'date'],
                'libelle' => ['nullable', 'string', 'max:255'],
                'reference' => ['nullable', 'string', 'max:100'],
                'mode_paiement' => [
                    // Requis sauf : recette non reçue, ou dépense non payée
                    Rule::requiredIf(fn () => match ($this->type) {
                        'recette' => $this->paiementRecu && ! $this->isLockedByReglement,
                        'depense' => $this->paiementRecu && ! $this->isLockedByReglement,
                        default => true,
                    }),
                    'nullable',
                    'in:virement,cheque,especes,cb,prelevement',
                ],
                'dateReglement' => [
                    Rule::requiredIf(fn (): bool => in_array($this->type, ['recette', 'depense'], true)
                        && $this->paiementRecu
                        && ! $this->isLockedByReglement),
                    'nullable',
                    'date_format:Y-m-d',
                ],
                // Tiers obligatoire : toute recette/dépense génère sa contrepartie
                // via le compte de tiers (411 client / 401 fournisseur), qui porte
                // le tiers. Sans tiers, EcritureGenerator ne peut pas équilibrer
                // l'écriture — la transaction resterait déséquilibrée (equilibree=false).
                'tiers_id' => ['required', 'exists:tiers,id'],
                'compte_id' => ['nullable', 'exists:comptes_bancaires,id'],
                'lignes' => ['required', 'array', 'min:1'],
                // DC-10a : ventilation compte-first (classe 6/7 via le sélecteur).
                'lignes.*.compte_id' => ['required', 'exists:comptes,id'],
                'lignes.*.montant' => ['required', 'numeric', MontantValidation::RULE],
                // IMP-06 : le scope global d'Eloquent ne couvre pas un `exists`
                // de validation — la colonne association_id est donc explicite.
                // Seule défense serveur conservée : c'est de la sécurité, pas du
                // métier. Le statut de l'opération n'entre pas dans la règle.
                'lignes.*.operation_id' => [
                    'nullable',
                    Rule::exists('operations', 'id')
                        ->where('association_id', TenantContext::currentId())
                        ->whereNull('deleted_at'),
                ],
                'lignes.*.seance' => ['nullable', 'integer', 'min:1'],
                'lignes.*.notes' => ['nullable', 'string', 'max:255'],
            ],
            array_merge(
                [
                    'tiers_id.required' => 'Un tiers est obligatoire : il porte la contrepartie comptable de l\'écriture.',
                ],
                MontantValidation::messages(['lignes.*.montant'])
            )
        );

        if ($this->pieceJointe !== null) {
            $this->validate([
                'pieceJointe' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            ], [
                'pieceJointe.mimes' => 'Le justificatif doit être un fichier PDF, JPG ou PNG.',
                'pieceJointe.max' => 'Le justificatif ne doit pas dépasser 10 Mo.',
            ]);
        }

        // Validation des PJ de lignes
        $lignesPjRules = [];
        $lignesPjMessages = [];
        foreach ($this->lignes as $index => $ligne) {
            if (($ligne['piece_jointe_upload'] ?? null) !== null) {
                $lignesPjRules["lignes.{$index}.piece_jointe_upload"] = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];
                $lignesPjMessages["lignes.{$index}.piece_jointe_upload.mimes"] = 'Le justificatif de la ligne '.($index + 1).' doit être un fichier PDF, JPG ou PNG.';
                $lignesPjMessages["lignes.{$index}.piece_jointe_upload.max"] = 'Le justificatif de la ligne '.($index + 1).' ne doit pas dépasser 10 Mo.';
            }
        }
        if (! empty($lignesPjRules)) {
            $this->validate($lignesPjRules, $lignesPjMessages);
        }

        $transactionExistante = $this->transactionId === null
            ? null
            : Transaction::findOrFail($this->transactionId);

        $doitRegler = ($this->type === 'recette' || $this->type === 'depense')
            && $this->paiementRecu
            && ! $this->isLockedByReglement
            && $transactionExistante?->mode_paiement === null;
        $modeReglement = $this->mode_paiement;
        $compteReglementId = $this->compte_id;

        $data = [
            'type' => $this->type,
            'date' => $this->date,
            'libelle' => $this->libelle,
            'montant_total' => $this->montantTotal,
            // Les nouvelles T1 et les créances/dettes modernes (mode nul) restent
            // ouvertes : leur règlement est porté par une T2 distincte. Les flux
            // historiques gardent en revanche leur mode sur la transaction source.
            //
            // « Non » le retire : sans cela une dette portant un mode résiduel se
            // déclarait payée à la première mise à jour — enrichirPartieDouble()
            // déduit « comptant » de ce seul champ. Une facture non payée sortait
            // ainsi son montant du solde bancaire sur un simple enregistrement.
            // « Non » ne retire ce mode que là où le bascule est réellement en
            // jeu. Sur une transaction déjà réglée, TransactionService refuse le
            // changement — et le formulaire n'a pas à lui soumettre ce qu'il sait
            // refusé : le retrait y passe par « Annuler le règlement ».
            'mode_paiement' => ($this->paiementModifiable && ! $this->paiementRecu)
                ? null
                : $transactionExistante?->mode_paiement?->value,
            'tiers_id' => $this->tiers_id,
            'reference' => $this->reference,
            'compte_id' => $this->compte_id,
            'notes' => $this->notes ?: null,
        ];

        if ($this->transactionId === null && in_array($this->type, ['recette', 'depense'], true)) {
            $data['statut_reglement'] = StatutReglement::EnAttente->value;
        }

        // DC-10a : le wire property `compte_id` porte l'id de compte sélectionné,
        // transmis tel quel au contrat `$lignes[]` de TransactionService.
        $lignes = [];
        foreach ($this->lignes as $index => $l) {
            $lignes[] = [
                'id' => isset($l['id']) ? (int) $l['id'] : null,
                'compte_id' => (int) $l['compte_id'],
                'operation_id' => $l['operation_id'] !== '' ? (int) $l['operation_id'] : null,
                'seance' => $l['seance'] !== '' ? (int) $l['seance'] : null,
                'montant' => $l['montant'],
                'notes' => $l['notes'] ?: null,
            ];
        }

        $inscriptionCompteIds = Compte::forUsage(UsageComptable::Inscription)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
        foreach ($this->lignes as $index => $ligne) {
            if (in_array((int) ($ligne['compte_id'] ?? 0), $inscriptionCompteIds, true)
                && empty($ligne['operation_id'])) {
                $this->addError("lignes.{$index}.operation_id", "L'opération est obligatoire pour une inscription.");

                return;
            }
        }

        $service = app(TransactionService::class);

        // Capturer les anciens paths PJ par index AVANT l'update (le service forceDelete les lignes).
        // Filtre ventilation() + horsRemiseHelloAsso() : aligne les indices avec
        // $this->lignes (qui n'a que les ventilations visibles — item a, Tâche 14).
        // Sans cette seconde exclusion, une transaction HelloAsso remisée à plusieurs
        // lignes désalignerait les indices (la remise s'intercale en base mais jamais
        // dans $this->lignes) et ce rattrapage récupérerait le PJ de la mauvaise ligne.
        $anciensPieceJointePaths = [];
        if ($this->transactionId) {
            $existingLignes = Transaction::findOrFail($this->transactionId)
                ->lignes()->ventilation()->horsRemiseHelloAsso()
                ->get()->values();
            foreach ($this->lignes as $index => $ligneData) {
                $existingLigne = $existingLignes->get($index);
                if ($existingLigne !== null) {
                    $anciensPieceJointePaths[$index] = $existingLigne->piece_jointe_path;
                }
            }
        }

        $createdTransaction = null;
        try {
            if ($this->transactionId) {
                $createdTransaction = app(TransactionAvecReglementService::class)->enregistrer(
                    transaction: Transaction::findOrFail($this->transactionId),
                    data: $data,
                    lignes: $lignes,
                    dateReglement: $doitRegler ? CarbonImmutable::parse($this->dateReglement) : null,
                    mode: $doitRegler ? ModePaiement::from($modeReglement) : null,
                    compteBancaireId: $doitRegler ? $compteReglementId : null,
                    exercice: $exerciceService->current(),
                );
            } else {
                $createdTransaction = app(TransactionAvecReglementService::class)->enregistrer(
                    transaction: null,
                    data: $data,
                    lignes: $lignes,
                    dateReglement: $doitRegler ? CarbonImmutable::parse($this->dateReglement) : null,
                    mode: $doitRegler ? ModePaiement::from($modeReglement) : null,
                    compteBancaireId: $doitRegler ? $compteReglementId : null,
                    exercice: $exerciceService->current(),
                );
            }
        } catch (ExerciceCloturedException $e) {
            // Le refus porte sur la DATE saisie — c'est elle qui est en cause,
            // pas la ventilation. L'ordre des catch compte : ce cas, plus
            // spécifique, doit être attrapé avant le \RuntimeException générique
            // ci-dessous (ExerciceCloturedException en hérite).
            $this->addError('date', $e->getMessage());

            return;
        } catch (\InvalidArgumentException $e) {
            // PosteTiersReglementService::regler() impose que la date du
            // règlement (T2) reste dans l'exercice de traitement — une règle
            // propre au poste tiers, distincte de la clôture ci-dessus, et
            // déjà appliquée côté « Régler le reliquat »
            // (PosteTiersReglementModal). Sans ce catch, retirer les bornes
            // d'exercice affiché du champ dateReglement laisserait cette
            // exception remonter non attrapée.
            $this->addError('dateReglement', $e->getMessage());

            return;
        } catch (\RuntimeException $e) {
            $this->addError('lignes', $e->getMessage());

            return;
        }

        // Sauvegarder la pièce jointe si uploadée
        if ($this->pieceJointe !== null) {
            $tx = $createdTransaction ?? Transaction::find($this->transactionId);
            if ($tx) {
                $service->storePieceJointe($tx, $this->pieceJointe);
            }
        }

        // Sauvegarder depuis un IncomingDocument (flux inbox)
        if ($this->incomingDocumentId !== null) {
            $tx = $createdTransaction ?? Transaction::find($this->transactionId);
            if ($tx !== null) {
                $this->finalizeIncomingDocumentCleanup($tx, $service);
            }
        }

        // Sauvegarder depuis un FacturePartenaireDeposee (flux portail back-office)
        if ($this->factureDeposeeId !== null) {
            $tx = $createdTransaction ?? Transaction::find($this->transactionId);
            if ($tx !== null) {
                try {
                    $this->finalizeFactureDeposeeCleanup($tx);
                } catch (\DomainException $e) {
                    session()->flash('error', 'Erreur de comptabilisation : '.$e->getMessage());

                    // La Transaction a été créée, mais la comptabilisation du dépôt a échoué.
                    // On garde le form ouvert (pas de resetForm) pour que l'utilisateur puisse retenter
                    // ou corriger. factureDeposeeId reste renseigné.
                    return;
                } catch (\RuntimeException $e) {
                    // Erreur système (déplacement disque, etc.). La Transaction est créée mais orpheline.
                    // Loguer pour investigation ; le comptable ne peut rien faire sans intervention admin.
                    Log::error('portail.facture-partenaire.comptabilisation-echec', [
                        'depot_id' => $this->factureDeposeeId,
                        'transaction_id' => (int) $tx->id,
                        'exception' => $e->getMessage(),
                    ]);
                    session()->flash('error', 'Erreur système lors de la comptabilisation. La transaction a été créée mais non rattachée ; contactez l\'administrateur.');

                    return;
                }
            }
        }

        // Sauvegarder les PJ de lignes.
        // Filtre ventilation() + horsRemiseHelloAsso() : aligne les indices avec
        // $this->lignes (qui n'a que les ventilations visibles — item a, Tâche 14).
        $tx = $createdTransaction ?? Transaction::with(['lignes' => fn ($q) => $q->ventilation()->horsRemiseHelloAsso()])->find($this->transactionId);
        if ($tx !== null) {
            $tx->load(['lignes' => fn ($q) => $q->ventilation()->horsRemiseHelloAsso()]);
            $lignesModels = $tx->lignes->values();
            foreach ($this->lignes as $index => $ligneData) {
                $ligneModel = $lignesModels->get($index);
                if ($ligneModel === null) {
                    continue;
                }

                // Path de l'ancienne ligne (capturé avant l'update qui forceDelete)
                $ancienPath = $anciensPieceJointePaths[$index] ?? $ligneModel->piece_jointe_path;

                if (! empty($ligneData['piece_jointe_remove'])) {
                    // Supprimer l'ancien fichier (peut être sur l'ancien ou nouveau modèle)
                    if ($ancienPath !== null && Storage::disk('local')->exists($ancienPath)) {
                        Storage::disk('local')->delete($ancienPath);
                    }
                    $ligneModel->update(['piece_jointe_path' => null]);
                } elseif (($ligneData['piece_jointe_upload'] ?? null) instanceof TemporaryUploadedFile) {
                    $upload = $ligneData['piece_jointe_upload'];
                    $ext = $upload->getClientOriginalExtension();
                    $slug = Str::slug($ligneData['notes'] ?? $ligneData['libelle'] ?? 'justif') ?: 'justif';
                    $n = $index + 1;
                    $path = sprintf(
                        'associations/%d/transactions/%d/ligne-%d-%s.%s',
                        (int) TenantContext::currentId(),
                        (int) $tx->id,
                        $n,
                        $slug,
                        $ext
                    );
                    // Supprimer ancien si présent
                    if ($ancienPath !== null && Storage::disk('local')->exists($ancienPath)) {
                        Storage::disk('local')->delete($ancienPath);
                    }
                    $upload->storeAs(dirname($path), basename($path), 'local');
                    $ligneModel->update(['piece_jointe_path' => $path]);
                } elseif ($ancienPath !== null && $ligneModel->piece_jointe_path === null) {
                    // Pas d'upload, pas de suppression demandée — restaurer le path existant sur la nouvelle ligne
                    $ligneModel->update(['piece_jointe_path' => $ancienPath]);
                }
            }
        }

        $this->dispatch('transaction-saved');
        $this->resetForm();
    }

    private function saveExtourneMiroir(): void
    {
        $this->validate([
            'mode_paiement' => ['nullable', 'in:virement,cheque,especes,cb,prelevement'],
            'compte_id' => ['nullable', 'exists:comptes_bancaires,id'],
        ]);

        $modeEffectif = $this->mode_paiement !== '' ? $this->mode_paiement : null;

        $transaction = Transaction::findOrFail($this->transactionId);

        $data = [
            'date' => $transaction->date->toDateString(),
            'libelle' => $transaction->libelle,
            'mode_paiement' => $modeEffectif,
            'compte_id' => $this->compte_id ?: null,
            'notes' => $this->notes ?: null,
            'reference' => $this->reference,
        ];

        try {
            app(TransactionService::class)->updateExtourneMiroir($transaction, $data);
        } catch (\RuntimeException $e) {
            $this->addError('lignes', $e->getMessage());

            return;
        }

        $this->dispatch('transaction-saved');
        $this->resetForm();
    }

    public function deletePieceJointe(): void
    {
        if (! $this->canEdit || $this->transactionId === null) {
            return;
        }

        $transaction = Transaction::findOrFail($this->transactionId);
        app(TransactionService::class)->deletePieceJointe($transaction);
        $this->existingPieceJointeNom = null;
        $this->existingPieceJointeUrl = null;
    }

    public function updatedPieceJointe(): void
    {
        if ($this->ocrWaitingForFile) {
            $this->ocrWaitingForFile = false;
        }

        if ($this->pieceJointe === null || ! $this->ocrMode) {
            return;
        }

        if (! InvoiceOcrService::isConfigured()) {
            return;
        }

        $this->runOcrAnalysis(function ($svc) {
            $this->validate([
                'pieceJointe' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            ]);

            return $svc->analyze($this->pieceJointe);
        });
    }

    public function retryOcr(): void
    {
        // Mode dépôt de facture partenaire
        if ($this->factureDeposeeId !== null) {
            $depot = FacturePartenaireDeposee::find($this->factureDeposeeId);
            if ($depot === null) {
                $this->ocrError = 'Le dépôt a été supprimé.';

                return;
            }
            $diskPath = Storage::disk('local')->path($depot->pdf_path);
            if (! file_exists($diskPath)) {
                $this->ocrError = 'Fichier introuvable sur le disque.';

                return;
            }
            if (! InvoiceOcrService::isConfigured()) {
                $this->ocrError = 'Service OCR non configuré.';

                return;
            }
            $context = $this->buildDepotOcrContext($depot);
            $this->runOcrAnalysis(fn ($svc) => $svc->analyzeFromPath($diskPath, 'application/pdf', $context));

            return;
        }

        // Mode inbox : relancer depuis le fichier disque
        if ($this->incomingDocumentId !== null) {
            $doc = IncomingDocument::find($this->incomingDocumentId);
            if ($doc === null) {
                $this->ocrError = 'Le document a été supprimé.';

                return;
            }
            $diskPath = Storage::disk('local')->path($doc->incomingFullPath());
            if (! file_exists($diskPath)) {
                $this->ocrError = 'Fichier introuvable sur le disque.';

                return;
            }
            if (! InvoiceOcrService::isConfigured()) {
                $this->ocrError = 'Service OCR non configuré.';

                return;
            }

            $this->runOcrAnalysis(fn ($svc) => $svc->analyzeFromPath($diskPath, 'application/pdf'));

            return;
        }

        // Mode upload (existant) : re-déclenche updatedPieceJointe() qui utilise déjà le helper
        if ($this->pieceJointe !== null) {
            $this->updatedPieceJointe();
        }
    }

    private function finalizeIncomingDocumentCleanup(Transaction $tx, TransactionService $service): void
    {
        if ($this->incomingDocumentId === null) {
            return;
        }

        $doc = IncomingDocument::find($this->incomingDocumentId);
        if ($doc === null) {
            return;
        }

        $diskPath = Storage::disk('local')->path($doc->incomingFullPath());
        if (! file_exists($diskPath)) {
            session()->flash('warning', 'Le fichier inbox a disparu pendant la sauvegarde ; la dépense a été créée sans justificatif.');

            return;
        }

        $service->storePieceJointeFromPath(
            $tx,
            $diskPath,
            $doc->original_filename,
            'application/pdf',
        );

        $fullPath = $doc->incomingFullPath();

        // Ordre : on supprime la row d'abord (source de vérité). Si la row-delete
        // échoue (exception DB), la méthode propage et les fichiers disque restent
        // en place — pas d'orphelin. Si la suppression disque échoue ensuite
        // (très rare sur le disk local), on a au pire des fichiers orphelins
        // sans row — le backfill artisan les détectera.
        $doc->delete();

        Storage::disk('local')->delete($fullPath);
    }

    private function finalizeFactureDeposeeCleanup(Transaction $tx): void
    {
        if ($this->factureDeposeeId === null) {
            return;
        }

        $depot = FacturePartenaireDeposee::find($this->factureDeposeeId);
        if ($depot === null) {
            session()->flash('warning', 'Dépôt introuvable pendant la finalisation ; la transaction a été créée sans rattachement.');

            return;
        }

        app(FacturePartenaireService::class)->comptabiliser($depot, $tx);
    }

    /**
     * @return array{tiers_attendu: string, reference_attendue: string, date_attendue: string}
     */
    private function buildDepotOcrContext(FacturePartenaireDeposee $depot): array
    {
        $depot->loadMissing('tiers');

        return [
            'tiers_attendu' => (string) ($depot->tiers?->displayName() ?? ''),
            'reference_attendue' => (string) $depot->numero_facture,
            'date_attendue' => $depot->date_facture->format('Y-m-d'),
        ];
    }

    /**
     * Exécute une analyse OCR avec gestion d'état uniforme.
     * Le callable reçoit l'instance de InvoiceOcrService et doit retourner un InvoiceOcrResult.
     */
    private function runOcrAnalysis(callable $analyze): void
    {
        $this->ocrAnalyzing = true;
        $this->ocrError = null;

        try {
            $result = $analyze(app(InvoiceOcrService::class));
            $this->applyOcrResult($result);
        } catch (OcrAnalysisException|OcrNotConfiguredException $e) {
            $this->ocrError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->ocrError = 'Erreur inattendue : '.$e->getMessage();
        } finally {
            $this->ocrAnalyzing = false;
        }
    }

    private function applyOcrResult(InvoiceOcrResult $result): void
    {
        // DC-10a : InvoiceOcrService renvoie directement des ids comptes.id (classe 6).
        // On valide l'id contre les comptes de charge actifs avant de le poser.
        $validCompteIds = Compte::where('classe', 6)
            ->where('actif', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
        $validOpIds = Operation::pluck('id')->toArray();

        if ($result->date !== null) {
            $this->date = $this->adjustDateToExercice($result->date);
        }
        if ($result->reference !== null) {
            $this->reference = $result->reference;
        }
        if ($result->tiers_id !== null) {
            $this->tiers_id = $result->tiers_id;
        }

        // Construire le libellé depuis le nom du tiers et la référence
        $parts = [];
        if ($result->tiers_nom !== null) {
            $parts[] = $result->tiers_nom;
        }
        if ($result->reference !== null) {
            $parts[] = 'Facture '.$result->reference;
        }
        if (! empty($parts)) {
            $this->libelle = implode(' — ', $parts);
        }

        // Stocker le nom du tiers OCR pour pré-remplir l'autocomplete
        $this->ocrTiersNom = $result->tiers_nom;

        if (! empty($result->lignes)) {
            $this->lignes = [];
            foreach ($result->lignes as $ligne) {
                $compteId = ($ligne->compte_id !== null && in_array($ligne->compte_id, $validCompteIds, true))
                    ? (string) $ligne->compte_id
                    : '';

                $this->lignes[] = [
                    'id' => null,
                    'compte_id' => $compteId,
                    'operation_id' => $ligne->operation_id !== null && in_array($ligne->operation_id, $validOpIds, true) ? (string) $ligne->operation_id : '',
                    'seance' => $ligne->seance !== null ? (string) $ligne->seance : '',
                    'montant' => (string) $ligne->montant,
                    'notes' => $ligne->description ?? '',
                    'piece_jointe_path' => null,
                    'piece_jointe_upload' => null,
                    'piece_jointe_remove' => false,
                    'piece_jointe_existing_url' => null,
                    'piece_jointe_filename' => null,
                ];
            }
        }

        $this->ocrWarnings = $result->warnings;
    }

    private function adjustDateToExercice(string $date): string
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $start = $range['start'];
        $end = $range['end'];

        $parsed = CarbonImmutable::parse($date);

        if ($parsed->between($start, $end)) {
            return $date;
        }

        // Essayer avec l'année +1 ou -1 (erreur IA fréquente)
        $plusOne = $parsed->addYear();
        if ($plusOne->between($start, $end)) {
            return $plusOne->format('Y-m-d');
        }

        $minusOne = $parsed->subYear();
        if ($minusOne->between($start, $end)) {
            return $minusOne->format('Y-m-d');
        }

        // Aucune correction possible, garder la date originale
        return $date;
    }

    #[On('poste-tiers-reglement:enregistre')]
    #[On('poste-tiers-reglement:annule')]
    public function rafraichirEtatReglement(): void
    {
        if ($this->transactionId === null) {
            return;
        }

        $this->chargerEtatReglement(Transaction::findOrFail($this->transactionId));
    }

    public function reglerReliquat(): void
    {
        if ($this->posteTiersLigneId === null) {
            return;
        }

        $this->dispatch(
            'poste-tiers-reglement:ouvrir',
            ligneId: $this->posteTiersLigneId,
            exercice: app(ExerciceService::class)->current(),
        );
    }

    public function annulerReglement(int $transactionReglementId): void
    {
        $this->dispatch('poste-tiers-reglement:annuler', transactionReglementId: $transactionReglementId);
    }

    private function chargerEtatReglement(Transaction $transaction): void
    {
        // Le bascule suit l'état réel, y compris après l'annulation d'un
        // règlement : sans cela le formulaire affichait « En attente de
        // règlement » et « Paiement effectué : oui » en même temps.
        $this->paiementRecu = $transaction->statut_reglement !== StatutReglement::EnAttente;

        $service = app(PostesTiersOuvertsService::class);
        $exercice = app(ExerciceService::class)->current();
        $poste = $service->pourTransaction($transaction, $exercice);
        $reglements = $service->reglements($transaction);

        $this->posteTiersLigneId = $poste?->ligneActionId;
        $this->soldeRestantCentimes = $poste?->soldeCentimes ?? 0;
        $this->reglementsEnregistres = $reglements
            ->map(fn ($reglement): array => [
                'transactionId' => $reglement->transactionId,
                'date' => $reglement->date->toDateString(),
                'montant' => number_format($reglement->montantCentimes / 100, 2, ',', ' '),
                'mode' => $reglement->mode?->label() ?? '—',
                'annulable' => $reglement->annulable,
            ])
            ->all();
        $this->isLockedByReglement = $reglements->isNotEmpty();

        // Le bascule n'agit que tant qu'aucun règlement n'existe. Au-delà,
        // TransactionService refuse tout changement de mode sur une transaction
        // réglée — « Le mode de paiement ne peut pas être modifié sur une
        // transaction réglée » — et le retrait passe par « Annuler le règlement ».
        // Une dette portant un mode résiduel, elle, reste modifiable : c'est la
        // forme héritée du backfill, et lui refuser le bascule laissait la
        // contradiction en place.
        $this->paiementModifiable = $reglements->isEmpty();
        $this->etatPaiement = $reglements->isEmpty()
            ? 'ouvert'
            : ($poste === null ? 'solde' : 'partiel');
    }

    public function render(): View
    {
        // DC-8 : source des options de ventilation (comptes classe 6/7, groupés par
        // famille) — remplace l'ex-liste `comptes` (déjà morte côté blade, le
        // select vit dans <livewire:compte-autocomplete>, gardé pour tout
        // futur consommateur direct de ce render()).
        $groupesComptesVentilation = $this->type !== ''
            ? PlanComptableSelecteur::groupesPourType($this->type)
            : collect();

        // IMP-01 : plus de borne de période. La transaction porte sa propre
        // date ; celle de l'opération ne détermine pas son exercice.
        $operations = Operation::with('typeOperation')
            ->proposableALaSaisie()
            ->orderBy('nom')
            ->get();

        // IMP-02 : table d'affichage indexée par id — les proposables plus
        // celles déjà référencées par $this->lignes/$this->affectations (état
        // Livewire en tableaux, pas une relation Eloquent : pas d'eager load
        // possible ici, contrairement à FactureEdit::operation()).
        //
        // La requête de rattrapage ne porte que sur les ids ABSENTS des
        // proposables : le cas courant — une opération en cours déjà imputée —
        // est déjà en mémoire, et cet écran se rend à chaque frappe.
        $operationsAffichees = $operations->keyBy('id');

        $idsARattraper = collect($this->lignes)->pluck('operation_id')
            ->merge(collect($this->affectations)->pluck('operation_id'))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->diff($operations->pluck('id')->map(fn ($id): int => (int) $id));

        if ($idsARattraper->isNotEmpty()) {
            $operationsAffichees = $operationsAffichees->union(
                Operation::whereIn('id', $idsARattraper)->get()->keyBy('id')
            );
        }

        return view('livewire.transaction-form', [
            'comptes' => CompteBancaire::saisieManuelle()->orderBy('nom')->get(),
            'groupesComptesVentilation' => $groupesComptesVentilation,
            'operations' => $operations,
            'operationsAffichees' => $operationsAffichees,
            'modesPaiement' => ModePaiement::cases(),
            'transaction_numero_piece' => $this->transactionId
                ? Transaction::select('id', 'numero_piece')->find($this->transactionId)?->numero_piece
                : null,
            'lignesAffectations' => $this->transactionId
                ? TransactionLigneAffectation::whereIn(
                    'transaction_ligne_id',
                    collect($this->lignes)->pluck('id')->filter()->toArray()
                )->pluck('transaction_ligne_id')->unique()->toArray()
                : [],
        ]);
    }
}
