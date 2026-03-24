<?php

declare(strict_types=1);

namespace App\Livewire\Banques;

use App\Models\HelloAssoParametres;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncWizard extends Component
{
    public int $step = 1;

    public bool $configBloquante = false;

    /** @var list<string> */
    public array $configErrors = [];

    /** @var list<string> */
    public array $configWarnings = [];

    public ?string $stepOneSummary = null;

    public ?string $stepTwoSummary = null;

    public ?string $stepThreeSummary = null;

    public function mount(): void
    {
        $this->checkConfig();
    }

    public function goToStep(int $step): void
    {
        if ($step >= $this->step) {
            return;
        }

        $this->step = $step;
    }

    private function checkConfig(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();

        if ($p === null || $p->client_id === null) {
            $this->configErrors[] = 'Les credentials HelloAsso ne sont pas encore configurés.';
        }

        if ($p !== null && $p->compte_helloasso_id === null) {
            $this->configErrors[] = 'Le compte bancaire HelloAsso n\'est pas configuré.';
        }

        if (count($this->configErrors) > 0) {
            $this->configBloquante = true;

            return;
        }

        if ($p->compte_versement_id === null) {
            $this->configWarnings[] = 'Le compte de versement n\'est pas configuré — les versements (cashouts) ne seront pas traités.';
        }
        if ($p->sous_categorie_don_id === null) {
            $this->configWarnings[] = 'La sous-catégorie Dons n\'est pas configurée — les dons ne seront pas importés.';
        }
        if ($p->sous_categorie_cotisation_id === null) {
            $this->configWarnings[] = 'La sous-catégorie Cotisations n\'est pas configurée — les cotisations ne seront pas importées.';
        }
        if ($p->sous_categorie_inscription_id === null) {
            $this->configWarnings[] = 'La sous-catégorie Inscriptions n\'est pas configurée — les inscriptions ne seront pas importées.';
        }
    }

    public function render(): View
    {
        return view('livewire.banques.helloasso-sync-wizard');
    }
}
