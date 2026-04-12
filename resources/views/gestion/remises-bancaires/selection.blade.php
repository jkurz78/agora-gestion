<x-app-layout>
    <x-slot:title>Sélection des règlements</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('banques.remises.index') }}">Remises en banque</x-slot:breadcrumbParent>
    <livewire:remise-bancaire-selection :remise="$remise" />
</x-app-layout>
