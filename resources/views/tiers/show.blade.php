<x-app-layout>
    <x-slot:title>{{ $tiers->displayName() }}</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('tiers.index') }}">Liste des tiers</x-slot:breadcrumbParent>

    <div class="container-fluid py-3">
        <livewire:tiers.fiche-tiers :tiers="$tiers" />
    </div>
</x-app-layout>
