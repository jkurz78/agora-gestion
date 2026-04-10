<x-app-layout>
    <x-slot:title>Recettes & dépenses</x-slot:title>
    <div class="container-fluid py-3">
        <livewire:transaction-universelle
            :locked-types="['depense', 'recette']"
            page-title="Transactions"
            :show-import="true" />
    </div>
</x-app-layout>
