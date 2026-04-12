<x-app-layout>
    <x-slot:title>Transactions — {{ $compte->nom }}</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('banques.comptes.index') }}">Comptes bancaires</x-slot:breadcrumbParent>
    <div class="container-fluid py-3">
        <livewire:transaction-universelle
            :compte-id="$compte->id"
            :page-title="'Transactions — '.$compte->nom"
            page-title-icon="bank" />
    </div>
</x-app-layout>
