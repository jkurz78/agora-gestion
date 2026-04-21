<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\InvoiceOcrResult;
use App\Enums\Espace;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutOperation;
use App\Enums\UsageComptable;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\CompteBancaire;
use App\Models\IncomingDocument;
use App\Models\NoteDeFrais;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Services\ExerciceService;
use App\Services\InvoiceOcrService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public ?int $tiers_id = null;

    public ?string $reference = null;

    public ?int $compte_id = null;

    public ?string $notes = null;

    /** @var array<int, array{sous_categorie_id: string, operation_id: string, seance: string, montant: string, notes: string}> */
    public array $lignes = [];

    public bool $showForm = false;

    public bool $isLocked = false;

    public bool $isLockedByFacture = false;

    public bool $isLockedByHelloAsso = false;

    public ?string $sousCategorieFilter = null;

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

    public ?string $incomingDocumentPreviewUrl = null;

    /** @var array<string> */
    public array $ocrWarnings = [];

    // État du panneau de ventilation
    public ?int $ventilationLigneId = null;

    public string $ventilationLigneSousCategorie = '';

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
        return round(collect($this->lignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2);
    }

    public function showNewForm(string $type): void
    {
        $this->reset(['transactionId', 'type', 'date', 'libelle', 'mode_paiement',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes',
            'ventilationLigneId', 'ventilationLigneSousCategorie', 'ventilationLigneMontant', 'affectations',
            'ventilationHasAffectations',
            'pieceJointe', 'existingPieceJointeNom', 'existingPieceJointeUrl',
            'ocrMode', 'ocrWaitingForFile', 'ocrAnalyzing', 'ocrError', 'ocrWarnings', 'ocrTiersNom',
            'incomingDocumentId', 'incomingDocumentPreviewUrl', 'linkedNdf']);
        $this->type = $type;
        $this->isLocked = false;
        $this->isLockedByHelloAsso = false;
        $this->resetValidation();
        $this->showForm = true;
        $this->date = app(ExerciceService::class)->defaultDate();
        $this->compte_id = Transaction::where('saisi_par', auth()->id())
            ->whereNotNull('compte_id')
            ->latest()
            ->value('compte_id');
        $this->addLigne();
    }

    #[On('open-transaction-form')]
    public function openForm(string $type, ?int $id = null, ?string $sousCategorieFilter = null): void
    {
        $this->sousCategorieFilter = $sousCategorieFilter;
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

    public function addLigne(): void
    {
        $this->lignes[] = [
            'id' => null,
            'sous_categorie_id' => '',
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

        $ligne = TransactionLigne::with('affectations', 'sousCategorie')->findOrFail($ligneId);
        $this->ventilationLigneId = $ligneId;
        $this->ventilationLigneSousCategorie = $ligne->sousCategorie->nom ?? '';
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
        $this->ventilationLigneSousCategorie = '';
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

        $this->validate([
            'affectations' => ['required', 'array', 'min:1'],
            'affectations.*.montant' => ['required', 'numeric', 'min:0.01'],
            'affectations.*.operation_id' => ['nullable'],
            'affectations.*.seance' => ['nullable', 'integer', 'min:1'],
            'affectations.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $ligne = TransactionLigne::findOrFail($this->ventilationLigneId);
        $ligneMontantCents = (int) round((float) $ligne->montant * 100);
        $affectationCents = (int) round(collect($this->affectations)->sum(fn ($a) => (float) ($a['montant'] ?? 0)) * 100);
        if ($ligneMontantCents !== $affectationCents) {
            $this->addError('affectations', 'La somme des affectations doit être égale au montant de la ligne.');

            return;
        }

        app(TransactionService::class)->affecterLigne(
            $ligne,
            collect($this->affectations)->map(fn ($a) => [
                'operation_id' => $a['operation_id'] !== '' ? (int) $a['operation_id'] : null,
                'seance' => $a['seance'] !== '' ? (int) $a['seance'] : null,
                'montant' => $a['montant'],
                'notes' => $a['notes'] ?: null,
            ])->toArray()
        );

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
        $this->ventilationLigneSousCategorie = '';
        $this->ventilationLigneMontant = '';
        $this->affectations = [];
        $this->ventilationHasAffectations = false;

        $transaction = Transaction::with(['lignes', 'noteDeFrais'])->findOrFail($id);

        $this->transactionId = $transaction->id;
        $this->type = $transaction->type->value;
        $this->date = $transaction->date->format('Y-m-d');
        $this->libelle = $transaction->libelle;
        $this->mode_paiement = $transaction->mode_paiement?->value ?? '';
        $this->tiers_id = $transaction->tiers_id;
        $this->reference = $transaction->reference;
        $this->compte_id = $transaction->compte_id;
        $this->notes = $transaction->notes;

        $this->lignes = $transaction->lignes->map(fn ($ligne) => [
            'id' => $ligne->id,
            'sous_categorie_id' => (string) $ligne->sous_categorie_id,
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
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'transactionId', 'type', 'date', 'libelle', 'mode_paiement',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes', 'showForm', 'isLocked', 'isLockedByFacture', 'isLockedByHelloAsso',
            'ventilationLigneId', 'ventilationLigneSousCategorie', 'ventilationLigneMontant', 'affectations',
            'ventilationHasAffectations',
            'pieceJointe', 'existingPieceJointeNom', 'existingPieceJointeUrl',
            'ocrMode', 'ocrWaitingForFile', 'ocrAnalyzing', 'ocrError', 'ocrWarnings', 'ocrTiersNom',
            'incomingDocumentId', 'incomingDocumentPreviewUrl', 'linkedNdf',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        if (! $this->canEdit) {
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

            // Montant total via somme des lignes
            $sourceTotal = round((float) $source->lignes()->sum('montant'), 2);
            $currentTotal = round(collect($this->lignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2);
            if (abs($sourceTotal - $currentTotal) > 0.001) {
                $this->addError('lignes', 'Montant verrouillé pour les transactions HelloAsso.');
                $hasDrift = true;
            }

            if ($hasDrift) {
                return;
            }
        }

        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        $isLocked = $this->transactionId
            ? Transaction::findOrFail($this->transactionId)->loadMissing('rapprochement')->isLockedByRapprochement()
            : false;

        $this->validate(
            [
                'date' => $isLocked
                    ? ['required', 'date']
                    : ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
                'libelle' => ['nullable', 'string', 'max:255'],
                'reference' => ['nullable', 'string', 'max:100'],
                'mode_paiement' => ['required', 'in:virement,cheque,especes,cb,prelevement'],
                'tiers_id' => ['nullable', 'exists:tiers,id'],
                'compte_id' => ['nullable', 'exists:comptes_bancaires,id'],
                'lignes' => ['required', 'array', 'min:1'],
                'lignes.*.sous_categorie_id' => ['required', 'exists:sous_categories,id'],
                'lignes.*.montant' => ['required', 'numeric', 'min:0.01'],
                'lignes.*.operation_id' => ['nullable'],
                'lignes.*.seance' => ['nullable', 'integer', 'min:1'],
                'lignes.*.notes' => ['nullable', 'string', 'max:255'],
            ],
            [
                'date.after_or_equal' => 'La date doit être dans l\'exercice en cours (à partir du '.$range['start']->format('d/m/Y').').',
                'date.before_or_equal' => 'La date doit être dans l\'exercice en cours (jusqu\'au '.$range['end']->format('d/m/Y').').',
            ]
        );

        if ($this->pieceJointe !== null && $this->type === 'depense') {
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

        $data = [
            'type' => $this->type,
            'date' => $this->date,
            'libelle' => $this->libelle,
            'montant_total' => $this->montantTotal,
            'mode_paiement' => $this->mode_paiement,
            'tiers_id' => $this->tiers_id,
            'reference' => $this->reference,
            'compte_id' => $this->compte_id,
            'notes' => $this->notes ?: null,
        ];

        $lignes = collect($this->lignes)->map(fn ($l) => [
            'id' => isset($l['id']) ? (int) $l['id'] : null,
            'sous_categorie_id' => (int) $l['sous_categorie_id'],
            'operation_id' => $l['operation_id'] !== '' ? (int) $l['operation_id'] : null,
            'seance' => $l['seance'] !== '' ? (int) $l['seance'] : null,
            'montant' => $l['montant'],
            'notes' => $l['notes'] ?: null,
        ])->toArray();

        $inscriptionIds = SousCategorie::forUsage(UsageComptable::Inscription)->pluck('id')->toArray();
        foreach ($this->lignes as $index => $ligne) {
            if (in_array((int) ($ligne['sous_categorie_id'] ?? 0), $inscriptionIds, true)
                && empty($ligne['operation_id'])) {
                $this->addError("lignes.{$index}.operation_id", "L'opération est obligatoire pour une inscription.");

                return;
            }
        }

        $service = app(TransactionService::class);

        // Capturer les anciens paths PJ par index AVANT l'update (le service forceDelete les lignes)
        $anciensPieceJointePaths = [];
        if ($this->transactionId) {
            $existingLignes = Transaction::findOrFail($this->transactionId)->lignes()->get()->values();
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
                $transaction = Transaction::findOrFail($this->transactionId);
                $service->update($transaction, $data, $lignes);
            } else {
                $createdTransaction = $service->create($data, $lignes);
            }
        } catch (\RuntimeException $e) {
            $this->addError('lignes', $e->getMessage());

            return;
        }

        // Sauvegarder la pièce jointe si uploadée
        if ($this->pieceJointe !== null && $this->type === 'depense') {
            $tx = $createdTransaction ?? Transaction::find($this->transactionId);
            if ($tx) {
                $service->storePieceJointe($tx, $this->pieceJointe);
            }
        }

        // Sauvegarder depuis un IncomingDocument (flux inbox)
        if ($this->incomingDocumentId !== null && $this->type === 'depense') {
            $tx = $createdTransaction ?? Transaction::find($this->transactionId);
            if ($tx !== null) {
                $this->finalizeIncomingDocumentCleanup($tx, $service);
            }
        }

        // Sauvegarder les PJ de lignes
        $tx = $createdTransaction ?? Transaction::with('lignes')->find($this->transactionId);
        if ($tx !== null) {
            $tx->load('lignes');
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
        $validScIds = SousCategorie::whereHas('categorie', fn ($q) => $q->where('type', 'depense'))->pluck('id')->toArray();
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
                $this->lignes[] = [
                    'id' => null,
                    'sous_categorie_id' => $ligne->sous_categorie_id !== null && in_array($ligne->sous_categorie_id, $validScIds, true) ? (string) $ligne->sous_categorie_id : '',
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

    public function render(): View
    {
        $flagToUsage = [
            'pour_dons' => UsageComptable::Don,
            'pour_cotisations' => UsageComptable::Cotisation,
            'pour_inscriptions' => UsageComptable::Inscription,
        ];
        $scUsage = $flagToUsage[$this->sousCategorieFilter] ?? null;

        $sousCategories = SousCategorie::with('categorie')
            ->when($this->type !== '', fn ($q) => $q->whereHas('categorie', fn ($q2) => $q2->where('type', $this->type)))
            ->when($scUsage !== null, fn ($q) => $q->forUsage($scUsage))
            ->orderBy('nom')
            ->get();

        return view('livewire.transaction-form', [
            'comptes' => CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get(),
            'sousCategories' => $sousCategories,
            'operations' => Operation::with('typeOperation')
                ->forExercice(app(ExerciceService::class)->current())
                ->where('statut', StatutOperation::EnCours)
                ->orderBy('nom')
                ->get(),
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
