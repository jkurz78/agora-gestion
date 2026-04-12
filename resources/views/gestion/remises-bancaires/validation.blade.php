<x-app-layout>
    <x-slot:title>Validation de la remise</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('banques.remises.index') }}">Remises en banque</x-slot:breadcrumbParent>
    <livewire:remise-bancaire-validation :remise="$remise" />
</x-app-layout>
