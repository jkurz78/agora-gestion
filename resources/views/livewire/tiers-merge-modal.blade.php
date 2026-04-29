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
                    <h5 class="modal-title">
                        @if($context === 'merge_full')
                            <i class="bi bi-people-fill me-2"></i>Fusion de deux tiers
                        @else
                            <i class="bi bi-arrow-left-right me-2"></i>Enrichissement du tiers
                        @endif
                    </h5>
                    <button type="button" class="btn-close" wire:click="cancelMerge"></button>
                </div>
                <div class="modal-body px-4 py-3"
                     x-data="{
                         copyToResult(field, value) {
                             $wire.set('resultData.' + field, value);
                         }
                     }">

                    @if($helloassoIdConflict)
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Ces deux tiers ont des identités HelloAsso différentes. La fusion n'est pas possible.
                        </div>
                    @endif

                    @if($context === 'merge_full' && count($blockingConflicts) > 0)
                        <div class="alert alert-danger">
                            <h6 class="mb-2"><i class="bi bi-shield-exclamation me-1"></i> Fusion bloquée</h6>
                            <ul class="mb-0 small">
                                @foreach($blockingConflicts as $conflict)
                                    <li><strong>{{ $conflict['label'] }}</strong> — {{ $conflict['detail'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <table class="table table-sm table-borderless mb-0">
                        <thead>
                            <tr style="border-bottom:2px solid #3d5473">
                                <th class="text-end pe-3 text-muted" style="width:12%">Champ</th>
                                <th class="text-center" style="width:28%;background-color:#f8f9fa">{{ $sourceLabel }}</th>
                                <th class="text-center" style="width:28%;background-color:#f8f9fa">{{ $targetLabel }}</th>
                                <th style="width:32%">Résultat</th>
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
                                    $srcBg = 'background-color:#f8f9fa';
                                    $tgtBg = 'background-color:#f8f9fa';
                                    if ($needsColoring) {
                                        $srcMatchesResult = $srcHasValue && $src === $res;
                                        $tgtMatchesResult = $tgtHasValue && $tgt === $res;
                                        $vert = 'background-color: rgba(46,125,50,0.15)';
                                        $rouge = 'background-color: rgba(181,69,58,0.15)';
                                        if ($srcMatchesResult && !$tgtMatchesResult) {
                                            $srcBg = $vert;
                                            $tgtBg = $tgtHasValue ? $rouge : 'background-color:#f8f9fa';
                                        } elseif ($tgtMatchesResult && !$srcMatchesResult) {
                                            $srcBg = $srcHasValue ? $rouge : 'background-color:#f8f9fa';
                                            $tgtBg = $vert;
                                        } elseif (!$srcMatchesResult && !$tgtMatchesResult) {
                                            $srcBg = $srcHasValue ? $rouge : 'background-color:#f8f9fa';
                                            $tgtBg = $tgtHasValue ? $rouge : 'background-color:#f8f9fa';
                                        }
                                    }
                                @endphp
                                <tr wire:key="merge-row-{{ $key }}" style="border-bottom:1px solid #e9ecef">
                                    <td class="fw-bold small align-middle text-end pe-3 text-muted">{{ $label }}</td>
                                    <td style="{{ $srcHasValue ? 'cursor:pointer;' : '' }}{{ $srcBg }}"
                                        @if($srcHasValue)
                                            x-on:click="copyToResult('{{ $key }}', {{ \Js::from($src) }})"
                                            title="Cliquer pour copier vers Résultat"
                                        @endif
                                        class="small align-middle text-center">
                                        {{ $src ?? '—' }}
                                    </td>
                                    <td style="{{ $tgtHasValue ? 'cursor:pointer;' : '' }}{{ $tgtBg }}"
                                        @if($tgtHasValue)
                                            x-on:click="copyToResult('{{ $key }}', {{ \Js::from($tgt) }})"
                                            title="Cliquer pour copier vers Résultat"
                                        @endif
                                        class="small align-middle text-center">
                                        {{ $tgt ?? '—' }}
                                    </td>
                                    <td class="py-1 px-0">
                                        @if($key === 'type')
                                            <select wire:model.live="resultData.{{ $key }}"
                                                    class="form-select form-select-sm border-0 bg-transparent shadow-none">
                                                <option value="particulier">Particulier</option>
                                                <option value="entreprise">Entreprise</option>
                                            </select>
                                        @else
                                            <input type="text"
                                                   wire:model.live.debounce.300ms="resultData.{{ $key }}"
                                                   class="form-control form-control-sm border-0 bg-transparent shadow-none">
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if($context === 'merge_full')
                        @php
                            $impactLabels = [
                                'transactions' => 'transactions',
                                'factures' => 'factures',
                                'devis' => 'devis',
                                'notes_de_frais' => 'notes de frais',
                                'factures_partenaires_deposees' => 'factures partenaires',
                                'provisions' => 'provisions',
                                'email_logs' => 'logs email',
                                'participants' => 'inscriptions participants',
                                'participants_medecin' => 'liens médecin référent',
                                'participants_therapeute' => 'liens thérapeute',
                                'participants_refere_par' => 'liens référent (parrain)',
                            ];
                            $totalImpact = array_sum($impactCounts ?? []);
                        @endphp
                        <div class="alert alert-warning mt-3 mb-0">
                            <h6 class="mb-2"><i class="bi bi-arrow-repeat me-1"></i> Récapitulatif de la fusion</h6>
                            @if($totalImpact === 0)
                                <p class="small mb-2">Le tiers source n'a aucun enregistrement rattaché. Sa suppression sera silencieuse.</p>
                            @else
                                <p class="small mb-2">
                                    Les enregistrements suivants seront <strong>réaffectés</strong> du tiers source vers le tiers survivant :
                                </p>
                                <ul class="small mb-2">
                                    @foreach($impactCounts as $key => $count)
                                        @if($count > 0)
                                            <li>{{ $count }} {{ $impactLabels[$key] ?? $key }}</li>
                                        @endif
                                    @endforeach
                                </ul>
                            @endif
                            <p class="small mb-0">
                                <i class="bi bi-trash me-1"></i>
                                À la fin, le tiers source <strong>« {{ $sourceLabel }} »</strong> sera <strong>supprimé définitivement</strong>.
                            </p>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="cancelMerge">Annuler</button>
                    @if($context !== 'merge_full')
                        <button type="button" class="btn btn-outline-success" wire:click="createNewTiers">
                            <i class="bi bi-person-plus me-1"></i>Créer un nouveau tiers
                        </button>
                    @endif
                    <button type="button"
                            class="btn {{ $context === 'merge_full' ? 'btn-danger' : 'btn-success' }}"
                            wire:click="confirmMerge"
                            @disabled($helloassoIdConflict || count($blockingConflicts) > 0)>
                        <i class="bi {{ $context === 'merge_full' ? 'bi-people-fill' : 'bi-check-lg' }} me-1"></i>{{ $confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
