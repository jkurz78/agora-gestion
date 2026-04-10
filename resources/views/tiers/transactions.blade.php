<x-app-layout>
    <x-slot:title>Transactions — {{ $tiers->displayName() }}</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('compta.tiers.index') }}">Liste des tiers</x-slot:breadcrumbParent>
    <div class="container-fluid py-3">
        <livewire:transaction-universelle
            :tiers-id="$tiers->id"
            :locked-types="['depense', 'recette', 'don', 'cotisation']"
            :page-title="'Transactions — '.$tiers->displayName()"
            page-title-icon="person-lines-fill" />
    </div>
</x-app-layout>
