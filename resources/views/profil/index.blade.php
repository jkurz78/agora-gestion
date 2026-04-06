@php
    $espace = auth()->user()->dernier_espace ?? \App\Enums\Espace::Compta;
    view()->share('espace', $espace);
    view()->share('espaceColor', $espace->color());
    view()->share('espaceLabel', $espace->label());
    $retourRoute = route($espace->value . '.dashboard');
@endphp

<x-app-layout>
    <div class="d-flex align-items-center mb-4">
        <a href="{{ $retourRoute }}" class="btn btn-sm btn-outline-secondary me-3">
            <i class="bi bi-arrow-left me-1"></i>Retour
        </a>
        <h1 class="mb-0">Mon profil</h1>
    </div>
    <livewire:mon-profil />
    <livewire:two-factor-setup />
</x-app-layout>
