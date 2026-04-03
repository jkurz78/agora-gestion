<div>
    {{-- Header with exercice selector --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Analyse</h4>
        <div class="d-flex gap-3 align-items-center">
            <select class="form-select form-select-sm" style="width:auto" wire:model.live="filterExercice">
                @foreach($exerciceYears as $year)
                    <option value="{{ $year }}">{{ $year }}/{{ $year + 1 }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Data carrier + pivot container --}}
    <div id="pivot-wrapper" data-pivot='@json($pivotData)' data-view="{{ $mode }}">
        <div id="pivot-output" wire:ignore class="border rounded bg-white p-2"></div>
    </div>

    @script
    <script>
        function renderPivot() {
            var wrapper = document.getElementById('pivot-wrapper');
            var el = document.getElementById('pivot-output');
            if (!wrapper || !el || typeof jQuery === 'undefined' || typeof jQuery.fn.pivotUI === 'undefined') {
                setTimeout(renderPivot, 100);
                return;
            }

            var data = JSON.parse(wrapper.dataset.pivot || '[]');
            var view = wrapper.dataset.view || 'participants';

            var defaults = view === 'participants'
                ? { rows: ["Opération"], vals: ["Montant prévu"], aggregatorName: "Somme" }
                : { rows: ["Catégorie"], vals: ["Montant"], aggregatorName: "Somme" };

            jQuery(el).empty().pivotUI(data, Object.assign({
                locale: "fr",
                cols: [],
                rendererName: "Table",
            }, defaults));
        }

        renderPivot();
        $wire.$watch('filterExercice', () => setTimeout(renderPivot, 100));
    </script>
    @endscript
</div>
