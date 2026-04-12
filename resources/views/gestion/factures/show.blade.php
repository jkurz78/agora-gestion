<x-app-layout>
    @if ($facture->statut === \App\Enums\StatutFacture::Annulee && $facture->numero_avoir)
        <x-slot:title>Avoir {{ $facture->numero_avoir }}</x-slot:title>
    @else
        <x-slot:title>Facture {{ $facture->numero ?? 'brouillon' }}</x-slot:title>
    @endif
    <x-slot:breadcrumbParent url="{{ route('facturation.factures') }}">Liste des factures</x-slot:breadcrumbParent>
    <livewire:facture-show :facture="$facture" />
</x-app-layout>
