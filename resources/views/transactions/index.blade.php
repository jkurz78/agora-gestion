<x-app-layout>
    <div class="container-fluid py-3">
        <h4 class="mb-3"><i class="bi bi-list-ul me-2"></i>Transactions</h4>
        <livewire:transaction-universelle :locked-types="['depense', 'recette']" />
    </div>
</x-app-layout>
