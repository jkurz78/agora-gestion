<x-app-layout>
    <h1 class="mb-4">Dépenses</h1>
    <div class="d-flex align-items-center mb-3">
        <livewire:depense-form />
        <div class="ms-auto">
            <livewire:import-csv type="depense" />
        </div>
    </div>
    <livewire:depense-list />
</x-app-layout>
