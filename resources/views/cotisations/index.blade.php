<x-app-layout>
    <div class="container-fluid py-3">
        <h4 class="mb-3"><i class="bi bi-people me-2"></i>Cotisations</h4>
        <livewire:transaction-universelle :locked-types="['cotisation']" />
    </div>
</x-app-layout>
