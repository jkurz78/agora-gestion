<x-app-layout>
    <h1 class="mb-4">Recettes</h1>
    <livewire:recette-form />
    <div class="d-flex gap-2 align-items-center mb-3">
        <livewire:import-csv type="recette" />
        <a href="{{ route('recettes.import.template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download"></i> Télécharger le modèle
        </a>
    </div>
    <livewire:recette-list />
</x-app-layout>
