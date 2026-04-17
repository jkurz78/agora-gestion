<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

final class Wizard extends Component
{
    use WithFileUploads;

    public const TOTAL_STEPS = 9;

    public int $currentStep = 1;

    public array $state = [];

    // Step 1 — Identité
    #[Validate('required|string|max:255')]
    public string $identiteAdresse = '';

    #[Validate('required|string|max:10')]
    public string $identiteCodePostal = '';

    #[Validate('required|string|max:100')]
    public string $identiteVille = '';

    #[Validate('required|email|max:255')]
    public string $identiteEmail = '';

    #[Validate('nullable|string|max:20')]
    public ?string $identiteTelephone = null;

    #[Validate('nullable|string|size:14|regex:/^\d{14}$/')]
    public ?string $identiteSiret = null;

    #[Validate('nullable|string|max:100')]
    public ?string $identiteFormeJuridique = null;

    public $logoUpload = null;
    public $cachetUpload = null;

    // Step 2 — Exercice
    #[Validate('required|integer|between:1,12')]
    public int $exerciceMoisDebut = 1;

    public function mount(): void
    {
        $association = $this->currentAssociation();

        $this->currentStep = max(1, (int) $association->wizard_current_step);
        $this->state = $association->wizard_state ?? [];

        $this->identiteAdresse = $association->adresse ?? '';
        $this->identiteCodePostal = $association->code_postal ?? '';
        $this->identiteVille = $association->ville ?? '';
        $this->identiteEmail = $association->email ?? '';
        $this->identiteTelephone = $association->telephone;
        $this->identiteSiret = $association->siret;
        $this->identiteFormeJuridique = $association->forme_juridique;
        $this->exerciceMoisDebut = (int) ($association->exercice_mois_debut ?? 1);
    }

    public function goToStep(int $step): void
    {
        if ($step < 1 || $step > self::TOTAL_STEPS) {
            return;
        }

        if ($step > $this->currentStep) {
            return;
        }

        $this->currentStep = $step;
    }

    public function saveStep1(): void
    {
        $this->validate([
            'identiteAdresse'        => 'required|string|max:255',
            'identiteCodePostal'     => 'required|string|max:10',
            'identiteVille'          => 'required|string|max:100',
            'identiteEmail'          => 'required|email|max:255',
            'identiteTelephone'      => 'nullable|string|max:20',
            'identiteSiret'          => 'nullable|string|size:14|regex:/^\d{14}$/',
            'identiteFormeJuridique' => 'nullable|string|max:100',
            'logoUpload'             => 'nullable|image|max:2048',
            'cachetUpload'           => 'nullable|image|max:2048',
        ]);

        $association = $this->currentAssociation();

        $data = [
            'adresse'         => $this->identiteAdresse,
            'code_postal'     => $this->identiteCodePostal,
            'ville'           => $this->identiteVille,
            'email'           => $this->identiteEmail,
            'telephone'       => $this->identiteTelephone,
            'siret'           => $this->identiteSiret,
            'forme_juridique' => $this->identiteFormeJuridique,
        ];

        if ($this->logoUpload !== null) {
            $shortName = 'logo.'.$this->logoUpload->extension();
            $fullPath  = $association->storagePath('branding/'.$shortName);
            $dir       = dirname($fullPath);
            Storage::disk('local')->makeDirectory($dir);
            Storage::disk('local')->putFileAs($dir, $this->logoUpload, $shortName);

            if ($association->logo_path !== null) {
                $oldFull = $association->storagePath('branding/'.basename($association->logo_path));
                if ($oldFull !== $fullPath && Storage::disk('local')->exists($oldFull)) {
                    Storage::disk('local')->delete($oldFull);
                }
            }

            $data['logo_path'] = $shortName;
        }

        if ($this->cachetUpload !== null) {
            $shortName = 'cachet.'.$this->cachetUpload->extension();
            $fullPath  = $association->storagePath('branding/'.$shortName);
            $dir       = dirname($fullPath);
            Storage::disk('local')->makeDirectory($dir);
            Storage::disk('local')->putFileAs($dir, $this->cachetUpload, $shortName);

            if ($association->cachet_signature_path !== null) {
                $oldFull = $association->storagePath('branding/'.basename($association->cachet_signature_path));
                if ($oldFull !== $fullPath && Storage::disk('local')->exists($oldFull)) {
                    Storage::disk('local')->delete($oldFull);
                }
            }

            $data['cachet_signature_path'] = $shortName;
        }

        $association->update($data);
        $this->advanceTo(2);
    }

    public function saveStep2(): void
    {
        $this->validate([
            'exerciceMoisDebut' => 'required|integer|between:1,12',
        ]);

        $this->currentAssociation()->update([
            'exercice_mois_debut' => $this->exerciceMoisDebut,
        ]);

        $this->advanceTo(3);
    }

    protected function advanceTo(int $step): void
    {
        $this->currentStep = $step;
        $this->currentAssociation()->update(['wizard_current_step' => $step]);
    }

    protected function currentAssociation(): Association
    {
        $association = TenantContext::current();

        if ($association === null) {
            abort(404, 'Tenant non résolu pour l\'onboarding.');
        }

        return $association;
    }

    public function render(): View
    {
        return view('livewire.onboarding.wizard');
    }
}
