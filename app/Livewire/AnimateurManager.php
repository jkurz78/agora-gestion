<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\InvoiceOcrResult;
use App\Enums\Espace;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\TypeTransaction;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Livewire\Concerns\MontantValidation;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\EncadrementMatrixBuilder;
use App\Services\ExerciceService;
use App\Services\InvoiceOcrService;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class AnimateurManager extends Component
{
    use WithFileUploads;

    public Operation $operation;

    // --- Tiers autocomplete for adding new animateur ---
    public ?int $newTiersId = null;

    public ?int $newTiersIdEnCours = null; // tiers sélectionné mais sous-cat pas encore choisie

    public ?int $addingScForTiersId = null; // pour ajout de ligne sous-cat sur encadrant existant

    // --- Modal state ---
    public bool $showModal = false;

    public bool $isEditing = false;

    public ?int $editingTransactionId = null;

    public ?int $modalTiersId = null;

    public string $modalTiersLabel = '';

    public string $modalDate = '';

    public string $modalReference = '';

    public ?string $modalModePaiement = null;

    public ?int $modalCompteId = null;

    /** @var array<int, array{compte_id: int|string|null, operation_id: int|string|null, seance: int|string|null, montant: string, id: int|null}> */
    public array $modalLignes = [];

    public string $errorMessage = '';

    public string $modalStep = 'form'; // 'upload' | 'form'

    /** @var TemporaryUploadedFile|null */
    public $modalPieceJointe = null;

    public ?string $existingPieceJointeNom = null;

    public ?string $existingPieceJointeUrl = null;

    public bool $ocrAnalyzing = false;

    public ?string $ocrError = null;

    /** @var array<string> */
    public array $ocrWarnings = [];

    public ?string $previewUrl = null;

    public ?string $previewMime = null;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    #[On('tiers-selected')]
    public function onTiersSelected(int $id): void
    {
        $this->newTiersIdEnCours = $id;
        $this->newTiersId = null;
    }

    public function ajouterEncadrantAvecCompte(int $tiersId, int $compteId): void
    {
        $this->guardCanEdit();

        if (! $this->upsertPrevisionPremierSeance($tiersId, $compteId)) {
            $this->addError('ajouterEncadrant', "Aucune séance définie sur l'opération.");

            return;
        }

        $this->newTiersIdEnCours = null;
        $this->newTiersId = null;
    }

    public function annulerAjoutEncadrant(): void
    {
        $this->newTiersIdEnCours = null;
        $this->newTiersId = null;
    }

    public function ouvrirAjoutLigne(int $tiersId): void
    {
        $this->addingScForTiersId = $tiersId;
    }

    public function fermerAjoutLigne(): void
    {
        $this->addingScForTiersId = null;
    }

    public function ajouterLigneCompte(int $tiersId, int $compteId): void
    {
        $this->guardCanEdit();

        if (! $this->upsertPrevisionPremierSeance($tiersId, $compteId)) {
            $this->addError('ajouterLigne', "Aucune séance définie sur l'opération.");

            return;
        }

        $this->fermerAjoutLigne();
    }

    public function updateMontantPrevu(int $tiersId, int $compteId, int $seanceId, string $montant): void
    {
        $this->guardCanEdit();

        if (! Seance::where('id', $seanceId)->where('operation_id', $this->operation->id)->exists()) {
            return;
        }

        $parsed = (float) str_replace(',', '.', $montant);

        $validator = Validator::make(
            ['montant' => $parsed],
            ['montant' => ['required', 'numeric', MontantValidation::RULE]],
            MontantValidation::messages(['montant'])
        );
        if ($validator->fails()) {
            $this->addError('updateMontantPrevu', $validator->errors()->first('montant'));

            return;
        }

        EncadrementPrevision::updateOrCreate(
            [
                'operation_id' => $this->operation->id,
                'tiers_id' => $tiersId,
                'compte_id' => $compteId,
                'seance_id' => $seanceId,
            ],
            ['montant_prevu' => $parsed]
        );
    }

    public function recopierLigne(int $tiersId, int $compteId): void
    {
        $this->guardCanEdit();

        $seances = Seance::where('operation_id', $this->operation->id)->orderBy('numero')->get();
        if ($seances->count() < 2) {
            return;
        }

        $premiere = $seances->first();
        $source = EncadrementPrevision::where('operation_id', $this->operation->id)
            ->where('tiers_id', $tiersId)
            ->where('compte_id', $compteId)
            ->where('seance_id', $premiere->id)
            ->first();

        if ($source === null) {
            return;
        }

        foreach ($seances->skip(1) as $seance) {
            EncadrementPrevision::updateOrCreate(
                [
                    'operation_id' => $this->operation->id,
                    'tiers_id' => $tiersId,
                    'compte_id' => $compteId,
                    'seance_id' => $seance->id,
                ],
                ['montant_prevu' => $source->montant_prevu]
            );
        }
    }

    public function supprimerLigne(int $tiersId, int $compteId): void
    {
        $this->guardCanEdit();

        $realiseCount = TransactionLigne::query()
            ->whereHas('transaction', fn ($q) => $q->where('type', TypeTransaction::Depense)->where('tiers_id', $tiersId))
            ->where('operation_id', $this->operation->id)
            ->where('compte_id', $compteId)
            ->count();

        if ($realiseCount > 0) {
            $this->addError('supprimerLigne', 'Impossible de supprimer : des transactions réalisées existent.');

            return;
        }

        EncadrementPrevision::where('operation_id', $this->operation->id)
            ->where('tiers_id', $tiersId)
            ->where('compte_id', $compteId)
            ->delete();
    }

    public function supprimerEncadrant(int $tiersId): void
    {
        $this->guardCanEdit();

        $realiseCount = TransactionLigne::query()
            ->whereHas('transaction', fn ($q) => $q->where('type', TypeTransaction::Depense)->where('tiers_id', $tiersId))
            ->where('operation_id', $this->operation->id)
            ->count();

        if ($realiseCount > 0) {
            $this->addError('supprimerEncadrant', 'Impossible de supprimer : des transactions réalisées existent pour cet encadrant.');

            return;
        }

        EncadrementPrevision::where('operation_id', $this->operation->id)
            ->where('tiers_id', $tiersId)
            ->delete();
    }

    private function upsertPrevisionPremierSeance(int $tiersId, int $compteId): bool
    {
        $premiereSeance = Seance::where('operation_id', $this->operation->id)->orderBy('numero')->first();
        if ($premiereSeance === null) {
            return false;
        }

        EncadrementPrevision::updateOrCreate(
            [
                'operation_id' => $this->operation->id,
                'tiers_id' => $tiersId,
                'compte_id' => $compteId,
                'seance_id' => $premiereSeance->id,
            ],
            ['montant_prevu' => 0]
        );

        return true;
    }

    private function guardCanEdit(): void
    {
        $role = auth()->user()?->currentRole();
        $hasWrite = RoleAssociation::tryFrom($role ?? '')?->canWrite(Espace::Gestion) ?? false;

        if (! $hasWrite) {
            abort(403);
        }
    }

    public function getCanEditProperty(): bool
    {
        $role = auth()->user()?->currentRole();

        return RoleAssociation::tryFrom($role ?? '')?->canWrite(Espace::Gestion) ?? false;
    }

    /**
     * Options du sélecteur : comptes de charge (classe 6), affichés « numéro — intitulé ».
     *
     * @return array<int, array{id: int, nom: string}>
     */
    public function getComptesDepenseProperty(): array
    {
        return Compte::query()
            ->where('classe', 6)
            ->where('actif', true)
            ->orderBy('numero_pcg')
            ->get(['id', 'numero_pcg', 'intitule'])
            ->map(fn (Compte $c) => ['id' => (int) $c->id, 'nom' => $c->numero_pcg.' — '.$c->intitule])
            ->toArray();
    }

    public function openCreateModal(int $tiersId, int $compteId, ?int $seanceNum): void
    {
        $tiers = Tiers::find($tiersId);
        if ($tiers === null) {
            return;
        }

        // Récupérer la dernière transaction de ce tiers pour pré-remplir
        $lastTx = Transaction::where('tiers_id', $tiersId)
            ->where('type', TypeTransaction::Depense)
            ->latest('id')
            ->first();

        $this->isEditing = false;
        $this->editingTransactionId = null;
        $this->modalTiersId = $tiersId;
        $this->modalTiersLabel = $tiers->displayName();
        $this->modalDate = now()->format('Y-m-d');
        $this->modalReference = '';
        $this->modalModePaiement = $lastTx?->mode_paiement?->value;
        $this->modalCompteId = $lastTx?->compte_id;
        $this->errorMessage = '';

        $this->modalLignes = [
            [
                'compte_id' => $compteId,
                'operation_id' => $this->operation->id,
                'seance' => $seanceNum,
                'montant' => '',
                'id' => null,
            ],
        ];

        $this->modalStep = 'upload';
        $this->modalPieceJointe = null;
        $this->existingPieceJointeNom = null;
        $this->existingPieceJointeUrl = null;

        $this->showModal = true;
    }

    public function skipUpload(): void
    {
        $this->modalStep = 'form';
        $this->modalPieceJointe = null;
    }

    public function removePieceJointe(): void
    {
        $this->modalPieceJointe = null;
        $this->previewUrl = null;
        $this->previewMime = null;
        $this->existingPieceJointeNom = null;
        $this->existingPieceJointeUrl = null;

        // Supprimer la PJ existante en base si on est en édition
        if ($this->isEditing && $this->editingTransactionId !== null) {
            $transaction = Transaction::find($this->editingTransactionId);
            if ($transaction?->hasPieceJointe()) {
                app(TransactionService::class)->deletePieceJointe($transaction);
            }
        }
    }

    public function updatedModalPieceJointe(): void
    {
        $this->ocrError = null;
        $this->ocrWarnings = [];

        // Validate and set preview URL (existing behavior)
        $this->proceedWithFile();

        // If validation failed or no file, stop
        if ($this->modalPieceJointe === null || $this->modalStep !== 'form') {
            return;
        }

        // Launch OCR if configured
        if (! InvoiceOcrService::isConfigured()) {
            return;
        }

        $this->ocrAnalyzing = true;

        try {
            $tiers = Tiers::find($this->modalTiersId);
            $context = [
                'tiers_attendu' => $tiers?->displayName() ?? '',
                'operation_attendue' => $this->operation->nom,
            ];
            $seance = $this->modalLignes[0]['seance'] ?? null;
            if ($seance !== null && $seance !== '') {
                $context['seance_attendue'] = (int) $seance;
            }

            $result = app(InvoiceOcrService::class)->analyze($this->modalPieceJointe, $context);
            $this->applyOcrResult($result);
        } catch (OcrAnalysisException|OcrNotConfiguredException $e) {
            $this->ocrError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->ocrError = 'Erreur inattendue : '.$e->getMessage();
        } finally {
            $this->ocrAnalyzing = false;
        }
    }

    public function proceedWithFile(): void
    {
        if ($this->modalPieceJointe !== null) {
            $this->validate([
                'modalPieceJointe' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            ], [
                'modalPieceJointe.mimes' => 'Le justificatif doit être un fichier PDF, JPG ou PNG.',
                'modalPieceJointe.max' => 'Le justificatif ne doit pas dépasser 10 Mo.',
            ]);

            $this->previewUrl = $this->modalPieceJointe->temporaryUrl();
            $this->previewMime = $this->modalPieceJointe->getMimeType();
        }
        $this->modalStep = 'form';
    }

    public function openEditModal(array $transactionIds): void
    {
        if (empty($transactionIds)) {
            return;
        }

        $transactionId = (int) $transactionIds[0];
        $transaction = Transaction::with(['tiers', 'lignes'])->find($transactionId);

        if ($transaction === null) {
            return;
        }

        $this->isEditing = true;
        $this->editingTransactionId = $transaction->id;
        $this->modalTiersId = $transaction->tiers_id;
        $this->modalTiersLabel = $transaction->tiers?->displayName() ?? '';
        $this->modalDate = $transaction->date->format('Y-m-d');
        $this->modalReference = $transaction->reference ?? '';
        $this->modalModePaiement = $transaction->mode_paiement?->value;
        $this->modalCompteId = $transaction->compte_id;
        $this->errorMessage = '';

        $this->modalLignes = [];
        foreach ($transaction->lignes as $ligne) {
            $this->modalLignes[] = [
                'compte_id' => $ligne->compte_id,
                'operation_id' => $ligne->operation_id,
                'seance' => $ligne->seance,
                'montant' => number_format((float) $ligne->montant, 2, '.', ''),
                'id' => $ligne->id,
            ];
        }

        if (empty($this->modalLignes)) {
            $this->modalLignes = [
                [
                    'compte_id' => null,
                    'operation_id' => $this->operation->id,
                    'seance' => null,
                    'montant' => '',
                    'id' => null,
                ],
            ];
        }

        $this->modalStep = 'form';
        $this->modalPieceJointe = null;
        $this->existingPieceJointeNom = $transaction->piece_jointe_nom;
        $this->existingPieceJointeUrl = $transaction->pieceJointeUrl();
        $this->previewUrl = $transaction->pieceJointeUrl();
        $this->previewMime = $transaction->piece_jointe_mime;

        $this->showModal = true;
    }

    public function retryOcr(): void
    {
        if ($this->modalPieceJointe !== null) {
            $this->ocrError = null;
            $this->ocrWarnings = [];
            $this->ocrAnalyzing = true;

            try {
                $tiers = Tiers::find($this->modalTiersId);
                $context = [
                    'tiers_attendu' => $tiers?->displayName() ?? '',
                    'operation_attendue' => $this->operation->nom,
                ];
                $seance = $this->modalLignes[0]['seance'] ?? null;
                if ($seance !== null && $seance !== '') {
                    $context['seance_attendue'] = (int) $seance;
                }

                $result = app(InvoiceOcrService::class)->analyze($this->modalPieceJointe, $context);
                $this->applyOcrResult($result);
            } catch (OcrAnalysisException|OcrNotConfiguredException $e) {
                $this->ocrError = $e->getMessage();
            } catch (\Throwable $e) {
                $this->ocrError = 'Erreur inattendue : '.$e->getMessage();
            } finally {
                $this->ocrAnalyzing = false;
            }
        }
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->isEditing = false;
        $this->editingTransactionId = null;
        $this->modalTiersId = null;
        $this->modalTiersLabel = '';
        $this->modalDate = '';
        $this->modalReference = '';
        $this->modalModePaiement = null;
        $this->modalCompteId = null;
        $this->modalLignes = [];
        $this->errorMessage = '';
        $this->modalStep = 'form';
        $this->modalPieceJointe = null;
        $this->existingPieceJointeNom = null;
        $this->existingPieceJointeUrl = null;
        $this->previewUrl = null;
        $this->previewMime = null;
        $this->ocrAnalyzing = false;
        $this->ocrError = null;
        $this->ocrWarnings = [];
    }

    public function addModalLigne(): void
    {
        $lastLigne = end($this->modalLignes) ?: [];

        $this->modalLignes[] = [
            'compte_id' => null,
            'operation_id' => $lastLigne['operation_id'] ?? $this->operation->id,
            'seance' => $lastLigne['seance'] ?? null,
            'montant' => '',
            'id' => null,
        ];
    }

    public function removeModalLigne(int $index): void
    {
        if (count($this->modalLignes) <= 1) {
            return;
        }

        unset($this->modalLignes[$index]);
        $this->modalLignes = array_values($this->modalLignes);
    }

    public function saveTransaction(): void
    {
        $this->errorMessage = '';

        $this->validate([
            'modalDate' => ['required', 'date'],
            'modalReference' => ['required', 'string', 'max:100'],
            'modalLignes' => ['required', 'array', 'min:1'],
            'modalLignes.*.compte_id' => ['required', 'integer', 'exists:comptes,id'],
            'modalLignes.*.montant' => ['required', 'numeric', 'min:0.01'],
        ], [
            'modalDate.required' => 'La date est obligatoire.',
            'modalDate.date' => 'La date n\'est pas valide.',
            'modalReference.required' => 'La référence est obligatoire.',
            'modalReference.max' => 'La référence ne doit pas dépasser 100 caractères.',
            'modalLignes.required' => 'Au moins une ligne est requise.',
            'modalLignes.min' => 'Au moins une ligne est requise.',
            'modalLignes.*.compte_id.required' => 'Le compte est obligatoire.',
            'modalLignes.*.compte_id.exists' => 'Compte invalide.',
            'modalLignes.*.montant.required' => 'Le montant est obligatoire.',
            'modalLignes.*.montant.numeric' => 'Le montant doit être un nombre.',
            'modalLignes.*.montant.min' => 'Le montant doit être supérieur à 0.',
        ]);

        $montantTotal = 0.0;
        foreach ($this->modalLignes as $ligne) {
            $montantTotal += (float) $ligne['montant'];
        }

        $tiers = Tiers::findOrFail($this->modalTiersId);

        $data = [
            'type' => TypeTransaction::Depense->value,
            'date' => $this->modalDate,
            'libelle' => "Facture d'encadrement {$this->modalReference} de {$tiers->displayName()}",
            'montant_total' => round($montantTotal, 2),
            'mode_paiement' => $this->modalModePaiement,
            'tiers_id' => $this->modalTiersId,
            'reference' => $this->modalReference,
            'compte_id' => $this->modalCompteId,
        ];

        $operationIds = collect($this->modalLignes)->pluck('operation_id')->filter()->unique()->map(fn ($v) => (int) $v);
        $operations = Operation::whereIn('id', $operationIds)->pluck('nom', 'id');
        $compteIds = collect($this->modalLignes)->pluck('compte_id')->filter()->unique()->map(fn ($v) => (int) $v);
        $comptes = Compte::whereIn('id', $compteIds)->pluck('intitule', 'id');

        $lignes = [];
        foreach ($this->modalLignes as $modalLigne) {
            $seance = ($modalLigne['seance'] !== null && $modalLigne['seance'] !== '')
                ? (int) $modalLigne['seance']
                : null;

            $operationId = ($modalLigne['operation_id'] !== null && $modalLigne['operation_id'] !== '')
                ? (int) $modalLigne['operation_id']
                : null;

            $ligneData = [
                'compte_id' => (int) $modalLigne['compte_id'],
                'operation_id' => $operationId,
                'seance' => $seance,
                'montant' => round((float) $modalLigne['montant'], 2),
                'notes' => $this->buildLigneNotes($modalLigne, $operations, $comptes),
            ];

            if ($this->isEditing && isset($modalLigne['id']) && $modalLigne['id'] !== null) {
                $ligneData['id'] = (int) $modalLigne['id'];
            }

            $lignes[] = $ligneData;
        }

        try {
            $service = app(TransactionService::class);
            $savedTransaction = null;

            if ($this->isEditing && $this->editingTransactionId !== null) {
                $transaction = Transaction::findOrFail($this->editingTransactionId);
                $service->update($transaction, $data, $lignes);
                $savedTransaction = $transaction;
            } else {
                $savedTransaction = $service->create($data, $lignes);
            }

            // Sauvegarder la pièce jointe si uploadée
            if ($this->modalPieceJointe !== null && $savedTransaction !== null) {
                $service->storePieceJointe($savedTransaction, $this->modalPieceJointe);
            }

            $this->closeModal();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    /**
     * @return array<int, array{id: int, nom: string}>
     */
    public function getModalOperationsProperty(): array
    {
        return Operation::orderBy('nom')
            ->get(['id', 'nom', 'nombre_seances'])
            ->map(fn (Operation $op): array => [
                'id' => $op->id,
                'nom' => $op->nom,
                'nombre_seances' => $op->nombre_seances,
            ])
            ->toArray();
    }

    public function render(): View
    {
        $seances = Seance::where('operation_id', $this->operation->id)
            ->orderBy('numero')
            ->get();

        $matrixData = app(EncadrementMatrixBuilder::class)->build($this->operation);

        $comptes = $this->showModal
            ? CompteBancaire::saisieManuelle()->orderBy('nom')->get(['id', 'nom'])
            : collect();

        return view('livewire.animateur-manager', [
            'seances' => $seances,
            'matrixData' => $matrixData,
            'comptes' => $comptes,
            'modesPaiement' => ModePaiement::cases(),
            'comptesDepense' => $this->comptesDepense,
        ]);
    }

    private function applyOcrResult(InvoiceOcrResult $result): void
    {
        $validCompteIds = Compte::where('classe', 6)->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        if ($result->date !== null) {
            $this->modalDate = $this->adjustDateToExercice($result->date);
        }
        if ($result->reference !== null) {
            $this->modalReference = $result->reference;
        }

        // Apply extracted lines but keep operation and seance from matrix context
        if (! empty($result->lignes)) {
            $existingOpId = $this->modalLignes[0]['operation_id'] ?? null;
            $existingSeance = $this->modalLignes[0]['seance'] ?? null;

            $this->modalLignes = [];
            foreach ($result->lignes as $ligne) {
                $this->modalLignes[] = [
                    'compte_id' => $ligne->compte_id !== null && in_array((int) $ligne->compte_id, $validCompteIds, true) ? (int) $ligne->compte_id : null,
                    'operation_id' => $existingOpId,
                    'seance' => $existingSeance,
                    'montant' => number_format($ligne->montant, 2, '.', ''),
                    'id' => null,
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

        $plusOne = $parsed->addYear();
        if ($plusOne->between($start, $end)) {
            return $plusOne->format('Y-m-d');
        }

        $minusOne = $parsed->subYear();
        if ($minusOne->between($start, $end)) {
            return $minusOne->format('Y-m-d');
        }

        return $date;
    }

    private function buildLigneNotes(array $ligne, Collection $operations, Collection $comptes): string
    {
        $parts = [];

        $operationId = ($ligne['operation_id'] !== null && $ligne['operation_id'] !== '')
            ? (int) $ligne['operation_id']
            : null;

        if ($operationId !== null && $operations->has($operationId)) {
            $parts[] = $operations->get($operationId);
        }

        $seance = ($ligne['seance'] !== null && $ligne['seance'] !== '')
            ? (int) $ligne['seance']
            : null;

        if ($seance !== null) {
            $parts[] = "Séance {$seance}";
        }

        $compteId = ($ligne['compte_id'] !== null && $ligne['compte_id'] !== '')
            ? (int) $ligne['compte_id']
            : null;

        if ($compteId !== null && $comptes->has($compteId)) {
            $parts[] = $comptes->get($compteId);
        }

        return implode(' — ', $parts);
    }
}
