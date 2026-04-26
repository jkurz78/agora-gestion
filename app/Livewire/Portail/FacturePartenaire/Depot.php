<?php

declare(strict_types=1);

namespace App\Livewire\Portail\FacturePartenaire;

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\FacturePartenaireService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

final class Depot extends Component
{
    use WithFileUploads;
    use WithPortailTenant;

    public Association $association;

    public ?string $date_facture = null;

    public ?string $numero_facture = null;

    public mixed $pdf = null;

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function submit(): void
    {
        $this->validate([
            'date_facture' => ['required', 'date', 'before_or_equal:today'],
            'numero_facture' => ['required', 'string', 'min:1', 'max:50'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ], [
            'date_facture.required' => 'La date de facture est obligatoire.',
            'date_facture.date' => 'La date de facture est invalide.',
            'date_facture.before_or_equal' => 'La date de facture ne peut pas être dans le futur.',
            'numero_facture.required' => 'Le numéro de facture est obligatoire.',
            'numero_facture.min' => 'Le numéro de facture est obligatoire.',
            'numero_facture.max' => 'Le numéro de facture ne doit pas dépasser 50 caractères.',
            'pdf.required' => 'Le fichier PDF est obligatoire.',
            'pdf.file' => 'Le fichier est invalide.',
            'pdf.mimes' => 'Seuls les fichiers PDF sont acceptés.',
            'pdf.max' => 'Le fichier PDF ne doit pas dépasser 10 Mo.',
        ]);

        /** @var Tiers $tiers */
        $tiers = Auth::guard('tiers-portail')->user();

        app(FacturePartenaireService::class)->submit($tiers, [
            'date_facture' => $this->date_facture,
            'numero_facture' => $this->numero_facture,
        ], $this->pdf);

        session()->flash('portail.success', 'Votre facture a été déposée avec succès.');

        $this->redirectRoute('portail.factures.index', ['association' => $this->association->slug]);
    }

    public function render(): View
    {
        return view('livewire.portail.facture-partenaire.depot')
            ->layout('portail.layouts.app');
    }
}
