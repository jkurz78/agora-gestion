<x-app-layout>
    {{-- PivotTable.js CDN dependencies --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.css">
    <style>
        .pvtUi { width: 100%; }
        .pvtTable { font-size: 0.85rem; }
        .pvtAxisContainer, .pvtVals { background: #f8f9fa; border-color: #dee2e6 !important; }
        .pvtFilterBox { font-size: 0.85rem; }
        .pvtTable td, .pvtTable th { padding: 4px 8px; color: #212529; }
        .pvtTotalLabel, .pvtTotal, .pvtGrandTotal { font-weight: bold; background-color: #e9ecef; }
        .pvtAxisLabel { background-color: #3d5473 !important; color: white !important; }
        /* Subtotal.js styling */
        .pvtTable .pvtRowSubtotal td, .pvtTable .pvtRowSubtotal th { font-weight: 700; background-color: #dce6f0; }
        .pvtTable .pvtColSubtotal td, .pvtTable .pvtColSubtotal th { font-weight: 700; background-color: #dce6f0; }
        .pvtTable .pvtGrandTotal { font-weight: 700; background-color: #3d5473 !important; color: #fff !important; }
        .pvtTable .toggle { cursor: pointer; color: #3d5473; font-size: 0.9rem; }
    </style>

    <h1 class="mb-4">Analyse financière</h1>
    <livewire:analyse-pivot mode="financier" />

    @push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.14.1/jquery-ui.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.fr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/subtotal@1.10.0/dist/subtotal.min.js"></script>
    @endpush
</x-app-layout>
