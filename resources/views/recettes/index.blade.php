<x-app-layout>
    <h1 class="mb-4">Recettes</h1>
    <div class="d-flex gap-2 align-items-start mb-3">
        <livewire:recette-form />
        <livewire:import-csv type="recette" />
    </div>
    <livewire:recette-list />
</x-app-layout>
