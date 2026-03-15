<x-app-layout>
    <h1 class="mb-4">Dépenses</h1>
    <livewire:depense-form />
    <div class="d-flex gap-2 align-items-center mb-3">
        <livewire:import-csv type="depense" />
        <a href="{{ route('depenses.import.template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download"></i> Télécharger le modèle
        </a>
    </div>
    <livewire:depense-list />
</x-app-layout>
