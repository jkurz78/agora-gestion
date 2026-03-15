<x-app-layout>
    <h1 class="mb-4">Recettes</h1>
    <div class="d-flex align-items-center mb-3">
        <livewire:recette-form />
        <div class="ms-auto">
            <livewire:import-csv type="recette" />
        </div>
    </div>
    <livewire:recette-list />
</x-app-layout>
