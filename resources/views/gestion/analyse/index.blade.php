<x-app-layout>
    {{-- PivotTable.js CDN dependencies --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.css">
    <style>
        .pvtUi { width: 100%; }
        .pvtTable { font-size: 0.85rem; }
        .pvtAxisContainer, .pvtVals { background: #f8f9fa; border-color: #dee2e6 !important; }
        .pvtFilterBox { font-size: 0.85rem; }
        .pvtTable tbody tr td { padding: 4px 8px; }
        .pvtTable thead tr th, .pvtTable tbody tr th { background-color: #3d5473; color: white; padding: 4px 8px; }
        .pvtTotalLabel, .pvtTotal, .pvtGrandTotal { font-weight: bold; background-color: #e9ecef; }
    </style>

    <livewire:analyse-pivot />

    @push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.14.1/jquery-ui.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.fr.min.js"></script>
    @endpush
</x-app-layout>
