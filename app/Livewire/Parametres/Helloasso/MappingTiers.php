<?php

declare(strict_types=1);

namespace App\Livewire\Parametres\Helloasso;

use App\Models\FormuleAdhesion;
use App\Models\HelloAssoParametres;
use App\Models\HelloAssoTierMapping;
use App\Services\HelloAssoApiClient;
use Illuminate\View\View;
use Livewire\Component;

final class MappingTiers extends Component
{
    public string $newFormSlug = '';

    public ?int $newTierId = null;

    public string $newTierLabel = '';

    public ?int $newFormuleId = null;

    public ?string $errorMessage = null;

    public string $importFormType = 'Membership';

    public string $importFormSlug = '';

    /** @var list<array{id: int, label: string, price: int|null, isEligibleTaxReceipt: bool|null}> */
    public array $importedTiers = [];

    public ?string $importError = null;

    public function create(): void
    {
        $this->errorMessage = null;
        $this->validate([
            'newFormSlug' => ['required', 'string', 'max:255'],
            'newTierId' => ['required', 'integer', 'min:1'],
            'newTierLabel' => ['required', 'string', 'max:255'],
            'newFormuleId' => ['required', 'integer', 'exists:formules_adhesion,id'],
        ]);

        $existant = HelloAssoTierMapping::query()
            ->where('helloasso_form_slug', $this->newFormSlug)
            ->where('helloasso_tier_id', $this->newTierId)
            ->exists();

        if ($existant) {
            $this->errorMessage = 'Un mapping pour ce tier existe déjà.';

            return;
        }

        HelloAssoTierMapping::create([
            'helloasso_form_slug' => $this->newFormSlug,
            'helloasso_tier_id' => $this->newTierId,
            'helloasso_tier_label' => $this->newTierLabel,
            'target_type' => FormuleAdhesion::class,
            'target_id' => $this->newFormuleId,
        ]);

        session()->flash('success', 'Mapping créé.');
        $this->reset(['newFormSlug', 'newTierId', 'newTierLabel', 'newFormuleId']);
    }

    public function delete(int $id): void
    {
        HelloAssoTierMapping::findOrFail($id)->delete();
        session()->flash('success', 'Mapping supprimé.');
    }

    public function importerTiers(): void
    {
        $this->importError = null;
        $this->importedTiers = [];
        $this->validate([
            'importFormType' => ['required', 'in:Membership,Event,Donation,PaymentForm'],
            'importFormSlug' => ['required', 'string', 'max:255'],
        ]);

        $parametres = HelloAssoParametres::first();
        if ($parametres === null || empty($parametres->client_id) || empty($parametres->organisation_slug)) {
            $this->importError = 'Configurez la connexion HelloAsso avant d\'importer.';

            return;
        }

        try {
            $client = new HelloAssoApiClient($parametres);
            $form = $client->fetchFormDetail($this->importFormType, $this->importFormSlug);

            $this->importedTiers = collect($form['tiers'] ?? [])
                ->map(fn (array $t) => [
                    'id' => (int) $t['id'],
                    'label' => $t['label'] ?? '—',
                    'price' => $t['price'] ?? null,
                    'isEligibleTaxReceipt' => $t['isEligibleTaxReceipt'] ?? null,
                ])
                ->all();
        } catch (\Throwable $e) {
            $this->importError = 'Erreur lors de l\'import : '.$e->getMessage();
        }
    }

    public function preremplir(int $tierId, string $label): void
    {
        $this->newFormSlug = $this->importFormSlug;
        $this->newTierId = $tierId;
        $this->newTierLabel = $label;
    }

    public function render(): View
    {
        $mappings = HelloAssoTierMapping::query()
            ->with('target')
            ->orderBy('helloasso_form_slug')
            ->orderBy('helloasso_tier_id')
            ->get();

        $formules = FormuleAdhesion::query()
            ->where('actif', true)
            ->orderBy('nom')
            ->get();

        return view('livewire.parametres.helloasso.mapping-tiers', compact('mappings', 'formules'));
    }
}
