<x-app-layout>
    <h1 class="mb-4">Dépenses</h1>
    <div class="d-flex gap-2 align-items-center mb-3">
        <livewire:depense-form />
        <livewire:import-csv type="depense" />
    </div>
    <livewire:depense-list />
</x-app-layout>
