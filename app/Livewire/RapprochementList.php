<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutRapprochement;
use App\Enums\TypeRapprochement;
use App\Enums\TypeTransaction;
use App\Exceptions\OcrAnalysisException;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Livewire\Concerns\WithPerPage;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use App\Services\ReleveOcrService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

final class RapprochementList extends Component
{
    use RespectsExerciceCloture;
    use WithFileUploads;
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public ?int $compte_id = null;

    /** Filtre par type : 'bancaire' (défaut), 'lettrage', 'tous'. */
    public string $filterType = 'bancaire';

    public bool $showCreateForm = false;

    public bool $showPieceJointeModal = false;

    public ?int $pieceJointeRapprochementId = null;

    /** @var TemporaryUploadedFile|null */
    public $pieceJointeUpload = null;

    /** @var TemporaryUploadedFile|null */
    public $extraitCompte = null;

    /** @var array{solde_ouverture: ?float, solde_cloture: ?float, date_cloture: ?string, banque: ?string, concordant: ?bool, warnings: list<string>}|null */
    public ?array $extraitAnalyse = null;

    public ?string $extraitErreur = null;

    public bool $extraitBloquant = false;

    public bool $extraitEnCours = false;

    public function mount(): void
    {
        $premier = CompteBancaire::saisieManuelle()->orderBy('nom')->first();
        $this->compte_id = $premier?->id;
    }

    public string $date_fin = '';

    public string $solde_fin = '';

    public function updatedCompteId(): void
    {
        $this->showCreateForm = false;
        $this->date_fin = '';
        $this->solde_fin = '';
        $this->resetExtrait();
        $this->resetValidation();
        $this->resetPage();
    }

    public function updatedFilterType(): void
    {
        $this->resetPage();
    }

    public function updatedExtraitCompte(): void
    {
        $this->validate([
            'extraitCompte' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $this->extraitAnalyse = null;
        $this->extraitErreur = null;
        $this->extraitBloquant = false;

        if (! ReleveOcrService::isConfigured()) {
            return;
        }

        $this->extraitEnCours = true;

        try {
            $resultat = app(ReleveOcrService::class)->analyze($this->extraitCompte);

            $soldeOuvertureCalcule = null;
            if ($this->compte_id) {
                $compte = CompteBancaire::find($this->compte_id);
                if ($compte) {
                    $soldeOuvertureCalcule = app(RapprochementBancaireService::class)
                        ->calculerSoldeOuverture($compte);
                }
            }

            $concordant = null;
            if ($resultat->solde_ouverture !== null && $soldeOuvertureCalcule !== null) {
                $concordant = round($resultat->solde_ouverture, 2) === round($soldeOuvertureCalcule, 2);
                if (! $concordant) {
                    $this->extraitBloquant = true;
                }
            }

            $this->extraitAnalyse = [
                'solde_ouverture' => $resultat->solde_ouverture,
                'solde_cloture' => $resultat->solde_cloture,
                'date_cloture' => $resultat->date_cloture,
                'banque' => $resultat->banque,
                'concordant' => $concordant,
                'solde_ouverture_attendu' => $soldeOuvertureCalcule,
                'warnings' => $resultat->warnings,
            ];

            if ($resultat->solde_cloture !== null) {
                $this->solde_fin = (string) $resultat->solde_cloture;
            }
            if ($resultat->date_cloture !== null) {
                $this->date_fin = $resultat->date_cloture;
            }
        } catch (OcrAnalysisException $e) {
            $this->extraitErreur = $e->getMessage();
        } finally {
            $this->extraitEnCours = false;
        }
    }

    public function retirerExtrait(): void
    {
        $this->resetExtrait();
    }

    private function resetExtrait(): void
    {
        $this->extraitCompte = null;
        $this->extraitAnalyse = null;
        $this->extraitErreur = null;
        $this->extraitBloquant = false;
        $this->extraitEnCours = false;
    }

    public function supprimer(int $id): void
    {
        $rapprochement = RapprochementBancaire::findOrFail($id);
        try {
            app(RapprochementBancaireService::class)->supprimer($rapprochement);
            session()->flash('success', 'Rapprochement supprimé.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function deverrouiller(int $id): void
    {
        $rapprochement = RapprochementBancaire::findOrFail($id);
        try {
            app(RapprochementBancaireService::class)->deverrouiller($rapprochement);
            session()->flash('success', 'Rapprochement déverrouillé.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function create(): void
    {
        if ($this->extraitBloquant) {
            $this->addError('extraitCompte', 'Le solde d\'ouverture du relevé ne correspond pas — corrigez la chronologie avant de créer.');

            return;
        }

        $this->validate([
            'compte_id' => ['required', 'exists:comptes_bancaires,id'],
            'date_fin' => ['required', 'date'],
            'solde_fin' => ['required', 'numeric'],
        ]);

        try {
            $compte = CompteBancaire::findOrFail($this->compte_id);
            $service = app(RapprochementBancaireService::class);
            $rapprochement = $service->create($compte, $this->date_fin, (float) $this->solde_fin);

            if ($this->extraitCompte instanceof TemporaryUploadedFile) {
                $service->storePieceJointe($rapprochement, $this->extraitCompte);
            }

            $this->showCreateForm = false;
            $this->date_fin = '';
            $this->solde_fin = '';
            $this->resetExtrait();
            $this->resetValidation();

            $this->redirect(route('banques.rapprochement.detail', $rapprochement));
        } catch (\RuntimeException $e) {
            $this->addError('date_fin', $e->getMessage());
        }
    }

    public function openPieceJointeModal(int $rapprochementId): void
    {
        $this->pieceJointeRapprochementId = $rapprochementId;
        $this->pieceJointeUpload = null;
        $this->resetErrorBag('pieceJointeUpload');
        $this->showPieceJointeModal = true;
    }

    public function closePieceJointeModal(): void
    {
        $this->showPieceJointeModal = false;
        $this->pieceJointeRapprochementId = null;
        $this->pieceJointeUpload = null;
        $this->resetErrorBag('pieceJointeUpload');
    }

    public function uploadPieceJointe(): void
    {
        $this->validate([
            'pieceJointeUpload' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $rapprochement = RapprochementBancaire::findOrFail($this->pieceJointeRapprochementId);

        try {
            app(RapprochementBancaireService::class)->storePieceJointe(
                $rapprochement,
                $this->pieceJointeUpload
            );
            session()->flash('success', 'Pièce jointe enregistrée.');
            $this->closePieceJointeModal();
        } catch (\InvalidArgumentException $e) {
            $this->addError('pieceJointeUpload', $e->getMessage());
        }
    }

    public function deletePieceJointe(int $rapprochementId): void
    {
        $rapprochement = RapprochementBancaire::findOrFail($rapprochementId);
        app(RapprochementBancaireService::class)->deletePieceJointe($rapprochement);
        session()->flash('success', 'Pièce jointe supprimée.');
        $this->closePieceJointeModal();
    }

    public function getCurrentPieceJointeRapprochementProperty(): ?RapprochementBancaire
    {
        return $this->pieceJointeRapprochementId !== null
            ? RapprochementBancaire::find($this->pieceJointeRapprochementId)
            : null;
    }

    public function render(): View
    {
        $comptes = CompteBancaire::saisieManuelle()->orderBy('nom')->get();
        $rapprochements = collect();
        $aEnCours = false;
        $soldeOuverture = null;

        if ($this->compte_id) {
            $aEnCours = RapprochementBancaire::where('compte_id', $this->compte_id)
                ->whereNull('verrouille_at')
                ->where('type', TypeRapprochement::Bancaire)
                ->exists();

            $rapprochements = RapprochementBancaire::where('compte_id', $this->compte_id)
                ->when(
                    $this->filterType === 'bancaire',
                    fn ($q) => $q->where('type', TypeRapprochement::Bancaire),
                )
                ->when(
                    $this->filterType === 'lettrage',
                    fn ($q) => $q->where('type', TypeRapprochement::Lettrage),
                )
                ->orderByDesc('date_fin')
                ->paginate($this->effectivePerPage());

            if (! $aEnCours) {
                $compte = CompteBancaire::find($this->compte_id);
                if ($compte) {
                    $soldeOuverture = app(RapprochementBancaireService::class)
                        ->calculerSoldeOuverture($compte);
                }
            }
        }

        $dernierVerrouilleId = null;
        if ($this->compte_id && ! $aEnCours) {
            $dernierVerrouilleId = RapprochementBancaire::where('compte_id', $this->compte_id)
                ->where('statut', StatutRapprochement::Verrouille)
                ->orderByDesc('date_fin')
                ->orderByDesc('id')
                ->value('id');
        }

        $rapprochementTotals = [];
        foreach ($rapprochements as $r) {
            $credit = Transaction::where('rapprochement_id', $r->id)
                ->where('type', TypeTransaction::Recette)
                ->operationnel()
                ->sum('montant_total');
            $debit = Transaction::where('rapprochement_id', $r->id)
                ->where('type', TypeTransaction::Depense)
                ->operationnel()
                ->sum('montant_total');
            $creditVir = VirementInterne::where('rapprochement_destination_id', $r->id)->sum('montant');
            $debitVir = VirementInterne::where('rapprochement_source_id', $r->id)->sum('montant');

            $rapprochementTotals[$r->id] = [
                'credit' => (float) $credit + (float) $creditVir,
                'debit' => (float) $debit + (float) $debitVir,
            ];
        }

        return view('livewire.rapprochement-list', [
            'comptes' => $comptes,
            'rapprochements' => $rapprochements,
            'aEnCours' => $aEnCours,
            'soldeOuverture' => $soldeOuverture,
            'dernierVerrouilleId' => $dernierVerrouilleId,
            'rapprochementTotals' => $rapprochementTotals,
            'iaConfiguree' => ReleveOcrService::isConfigured(),
        ]);
    }
}
