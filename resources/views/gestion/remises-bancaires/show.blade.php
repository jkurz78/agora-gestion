<x-app-layout>
    <x-slot:title>{{ $remise->libelle }}</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('banques.remises.index') }}">Remises en banque</x-slot:breadcrumbParent>
    <livewire:remise-bancaire-show :remise="$remise" />
</x-app-layout>
