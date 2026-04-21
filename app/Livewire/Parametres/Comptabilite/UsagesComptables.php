<?php

declare(strict_types=1);

namespace App\Livewire\Parametres\Comptabilite;

use App\Enums\RoleAssociation;
use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Services\UsagesComptablesService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class UsagesComptables extends Component
{
    public ?int $fraisKmSelectedId = null;

    public ?int $abandonCreanceSelectedId = null;

    public bool $inlineOpen = false;

    public ?string $inlineUsage = null;

    public ?int $inlineCategorieId = null;

    public string $inlineNom = '';

    public ?string $inlineCodeCerfa = null;

    public function mount(): void
    {
        $this->requireAdmin();
        $fraisKm = SousCategorie::forUsage(UsageComptable::FraisKilometriques)->first();
        $this->fraisKmSelectedId = $fraisKm?->id;
        $abandon = SousCategorie::forUsage(UsageComptable::AbandonCreance)->first();
        $this->abandonCreanceSelectedId = $abandon?->id;
    }

    private function requireAdmin(): void
    {
        abort_unless(
            Auth::check() && Auth::user()->currentRole() === RoleAssociation::Admin->value,
            403,
        );
    }

    public function toggleDon(int $id, bool $active): void
    {
        $this->requireAdmin();
        app(UsagesComptablesService::class)->toggleDon($id, $active);
    }

    public function toggleCotisation(int $id, bool $active): void
    {
        $this->requireAdmin();
        app(UsagesComptablesService::class)->toggleCotisation($id, $active);
    }

    public function toggleInscription(int $id, bool $active): void
    {
        $this->requireAdmin();
        app(UsagesComptablesService::class)->toggleInscription($id, $active);
    }

    public function saveFraisKilometriques(): void
    {
        $this->requireAdmin();
        app(UsagesComptablesService::class)->setFraisKilometriques($this->fraisKmSelectedId);
    }

    public function saveAbandonCreance(): void
    {
        $this->requireAdmin();
        try {
            app(UsagesComptablesService::class)->setAbandonCreance($this->abandonCreanceSelectedId);
        } catch (DomainException $e) {
            $this->addError('abandonCreance', $e->getMessage());
        }
    }

    public function openInline(string $usage): void
    {
        $this->requireAdmin();
        $this->reset(['inlineCategorieId', 'inlineNom', 'inlineCodeCerfa']);
        $this->inlineUsage = $usage;
        $this->inlineOpen = true;
    }

    public function submitInline(): void
    {
        $this->requireAdmin();
        $this->validate([
            'inlineUsage' => 'required|string',
            'inlineCategorieId' => 'required|integer|exists:categories,id',
            'inlineNom' => 'required|string|max:255',
            'inlineCodeCerfa' => 'nullable|string|max:20',
        ]);
        $usage = UsageComptable::from($this->inlineUsage);
        app(UsagesComptablesService::class)->createAndFlag([
            'categorie_id' => $this->inlineCategorieId,
            'nom' => $this->inlineNom,
            'code_cerfa' => $this->inlineCodeCerfa,
        ], $usage);
        $this->inlineOpen = false;
        $this->dispatch('usage-created');
    }

    public function getAbandonCreanceCandidatesProperty(): array
    {
        return SousCategorie::with('categorie')->forUsage(UsageComptable::Don)->orderBy('nom')->get()->all();
    }

    public function getInlineCategoriesEligiblesProperty(): array
    {
        if ($this->inlineUsage === null) {
            return [];
        }
        $polarite = UsageComptable::from($this->inlineUsage)->polarite();

        return Categorie::where('type', $polarite)->orderBy('nom')->get()->all();
    }

    public function render(): View
    {
        return view('livewire.parametres.comptabilite.usages-comptables', [
            'sousCatsDepense' => SousCategorie::with('categorie')->whereHas('categorie', fn ($q) => $q->where('type', TypeCategorie::Depense))->join('categories', 'categories.id', '=', 'sous_categories.categorie_id')->orderBy('categories.nom')->orderBy('sous_categories.nom')->select('sous_categories.*')->get(),
            'sousCatsRecette' => SousCategorie::with('categorie')->whereHas('categorie', fn ($q) => $q->where('type', TypeCategorie::Recette))->join('categories', 'categories.id', '=', 'sous_categories.categorie_id')->orderBy('categories.nom')->orderBy('sous_categories.nom')->select('sous_categories.*')->get(),
            'sousCatsDon' => SousCategorie::forUsage(UsageComptable::Don)->pluck('id'),
            'sousCatsCotisation' => SousCategorie::forUsage(UsageComptable::Cotisation)->pluck('id'),
            'sousCatsInscription' => SousCategorie::forUsage(UsageComptable::Inscription)->pluck('id'),
        ])->layout('layouts.app-sidebar', ['title' => 'Usages comptables']);
    }
}
