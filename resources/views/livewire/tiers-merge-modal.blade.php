@php
    $fields = [
        'type' => 'Type',
        'nom' => 'Nom',
        'prenom' => 'Prénom',
        'entreprise' => 'Entreprise',
        'email' => 'Email',
        'telephone' => 'Téléphone',
        'adresse_ligne1' => 'Adresse',
        'code_postal' => 'Code postal',
        'ville' => 'Ville',
        'pays' => 'Pays',
    ];
@endphp

<div>
    @if($showModal)
    <div class="modal fade show d-block" tabindex="-1" style="background-color:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Enrichissement du tiers</h5>
                    <button type="button" class="btn-close" wire:click="cancelMerge"></button>
                </div>
                <div class="modal-body p-0"
                     x-data="{
                         copyToResult(field, value) {
                             $wire.set('resultData.' + field, value);
                         }
                     }">

                    @if($helloassoIdConflict)
                        <div class="alert alert-danger m-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Ces deux tiers ont des identités HelloAsso différentes. La fusion n'est pas possible.
                        </div>
                    @endif

                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th style="width:15%">Champ</th>
                                <th style="width:25%">{{ $sourceLabel }}</th>
                                <th style="width:25%">{{ $targetLabel }}</th>
                                <th style="width:35%">Résultat</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($fields as $key => $label)
                                @php
                                    $src = $sourceData[$key] ?? null;
                                    $tgt = $targetData[$key] ?? null;
                                    $res = $resultData[$key] ?? null;
                                    $hasConflict = $src !== null && $src !== '' && $tgt !== null && $tgt !== '' && $src !== $tgt;
                                    $srcColor = '';
                                    $tgtColor = '';
                                    if ($hasConflict) {
                                        $srcMatchesResult = $src === $res;
                                        $tgtMatchesResult = $tgt === $res;
                                        if ($srcMatchesResult && !$tgtMatchesResult) {
                                            $srcColor = 'background-color: rgba(46,125,50,0.15)'; // vert anglais
                                            $tgtColor = 'background-color: rgba(181,69,58,0.15)'; // rouge brique
                                        } elseif ($tgtMatchesResult && !$srcMatchesResult) {
                                            $srcColor = 'background-color: rgba(181,69,58,0.15)';
                                            $tgtColor = 'background-color: rgba(46,125,50,0.15)';
                                        } else {
                                            // manual edit or both match
                                            $srcColor = $srcMatchesResult && $tgtMatchesResult ? '' : 'background-color: rgba(181,69,58,0.15)';
                                            $tgtColor = $srcMatchesResult && $tgtMatchesResult ? '' : 'background-color: rgba(181,69,58,0.15)';
                                        }
                                    }
                                @endphp
                                <tr wire:key="merge-row-{{ $key }}">
                                    <td class="fw-bold small align-middle">{{ $label }}</td>
                                    <td style="{{ $src !== null && $src !== '' ? 'cursor:pointer;' : '' }}{{ $srcColor }}"
                                        @if($src !== null && $src !== '')
                                            x-on:click="copyToResult('{{ $key }}', {{ \Js::from($src) }})"
                                            title="Cliquer pour copier vers Résultat"
                                        @endif
                                        class="small align-middle">
                                        {{ $src ?? '—' }}
                                    </td>
                                    <td style="{{ $tgt !== null && $tgt !== '' ? 'cursor:pointer;' : '' }}{{ $tgtColor }}"
                                        @if($tgt !== null && $tgt !== '')
                                            x-on:click="copyToResult('{{ $key }}', {{ \Js::from($tgt) }})"
                                            title="Cliquer pour copier vers Résultat"
                                        @endif
                                        class="small align-middle">
                                        {{ $tgt ?? '—' }}
                                    </td>
                                    <td class="p-1">
                                        @if($key === 'type')
                                            <select wire:model.live="resultData.{{ $key }}" class="form-select form-select-sm">
                                                <option value="particulier">Particulier</option>
                                                <option value="entreprise">Entreprise</option>
                                            </select>
                                        @else
                                            <input type="text"
                                                   wire:model.live.debounce.300ms="resultData.{{ $key }}"
                                                   class="form-control form-control-sm">
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="cancelMerge">Annuler</button>
                    <button type="button" class="btn btn-success"
                            wire:click="confirmMerge"
                            @disabled($helloassoIdConflict)>
                        <i class="bi bi-check-lg me-1"></i>{{ $confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
