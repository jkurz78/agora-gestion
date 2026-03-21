<x-app-layout>
    <x-slot name="header">
        <h2 class="fw-semibold fs-5 text-dark">Tiers</h2>
    </x-slot>

    <div class="container py-4">
        @livewire('tiers-form', ['showNewButton' => true])
        @livewire('tiers-list')
    </div>
</x-app-layout>
