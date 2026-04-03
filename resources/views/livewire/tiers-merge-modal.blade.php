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
                                    $srcHasValue = $src !== null && $src !== '';
                                    $tgtHasValue = $tgt !== null && $tgt !== '';
                                    $needsColoring = ($srcHasValue && $tgtHasValue && $src !== $tgt)
                                        || ($srcHasValue && $res !== $src)
                                        || ($tgtHasValue && $res !== $tgt);
                                    $srcColor = '';
                                    $tgtColor = '';
                                    if ($needsColoring) {
                                        $srcMatchesResult = $srcHasValue && $src === $res;
                                        $tgtMatchesResult = $tgtHasValue && $tgt === $res;
                                        $vert = 'background-color: rgba(46,125,50,0.15)';
                                        $rouge = 'background-color: rgba(181,69,58,0.15)';
                                        if ($srcMatchesResult && !$tgtMatchesResult) {
                                            $srcColor = $vert;
                                            $tgtColor = $tgtHasValue ? $rouge : '';
                                        } elseif ($tgtMatchesResult && !$srcMatchesResult) {
                                            $srcColor = $srcHasValue ? $rouge : '';
                                            $tgtColor = $vert;
                                        } elseif (!$srcMatchesResult && !$tgtMatchesResult) {
                                            $srcColor = $srcHasValue ? $rouge : '';
                                            $tgtColor = $tgtHasValue ? $rouge : '';
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
