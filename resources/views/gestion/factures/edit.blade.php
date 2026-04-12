<x-app-layout>
    <x-slot:title>Brouillon de facture</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('facturation.factures') }}">Liste des factures</x-slot:breadcrumbParent>
    <livewire:facture-edit :facture="$facture" />
</x-app-layout>
