<div>
    {{-- Header with exercice selector and view toggle --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Analyse</h4>
        <div class="d-flex gap-3 align-items-center">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button"
                        class="btn {{ $activeView === 'participants' ? 'btn-primary' : 'btn-outline-primary' }}"
                        wire:click="switchView('participants')">
                    <i class="bi bi-people me-1"></i>Participants / Règlements
                </button>
                <button type="button"
                        class="btn {{ $activeView === 'financier' ? 'btn-primary' : 'btn-outline-primary' }}"
                        wire:click="switchView('financier')">
                    <i class="bi bi-cash-stack me-1"></i>Financière
                </button>
            </div>
            <select class="form-select form-select-sm" style="width:auto" wire:model.live="filterExercice">
                @foreach($exerciceYears as $year)
                    <option value="{{ $year }}">{{ $year }}/{{ $year + 1 }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Pivot table container --}}
    <div id="pivot-output" class="border rounded bg-white p-2"
         wire:ignore
         wire:key="pivot-{{ $activeView }}-{{ $filterExercice }}">
    </div>

    {{-- CDN dependencies (loaded only on this page) --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.14.1/jquery-ui.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.fr.min.js"></script>

    <script>
        document.addEventListener('livewire:navigated', initPivot);
        document.addEventListener('DOMContentLoaded', initPivot);

        Livewire.hook('morph.updated', ({ el }) => {
            if (el.id === 'pivot-output' || el.closest?.('#pivot-output')) {
                setTimeout(initPivot, 50);
            }
        });

        function initPivot() {
            if (typeof jQuery === 'undefined' || typeof jQuery.pivotUI === 'undefined') {
                setTimeout(initPivot, 100);
                return;
            }

            var data = @json($pivotData);
            var view = @json($activeView);

            var defaults = view === 'participants'
                ? { rows: ["Opération"], vals: ["Montant prévu"], aggregatorName: "Somme" }
                : { rows: ["Opération"], vals: ["Montant"], aggregatorName: "Somme" };

            jQuery("#pivot-output").empty().pivotUI(data, Object.assign({
                locale: "fr",
                cols: [],
                rendererName: "Table",
            }, defaults));
        }
    </script>

    <style>
        .pvtUi { width: 100%; }
        .pvtTable { font-size: 0.85rem; }
        .pvtAxisContainer, .pvtVals { background: #f8f9fa; border-color: #dee2e6 !important; }
        .pvtFilterBox { font-size: 0.85rem; }
        .pvtTable tbody tr td { padding: 4px 8px; }
        .pvtTable thead tr th { background-color: #3d5473; color: white; padding: 4px 8px; }
    </style>
</div>
