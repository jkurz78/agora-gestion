<div
    x-data="{}"
    x-on:keydown.escape.window="$wire.close()"
>
    @if($visible && $tiers !== null)
        {{-- Backdrop --}}
        <div
            class="position-fixed top-0 start-0 w-100 h-100"
            style="background:rgba(0,0,0,.45);z-index:2049"
            wire:click="close"
        ></div>

        {{-- Floating card --}}
        <div
            class="position-fixed top-50 start-50 translate-middle shadow-lg rounded-3"
            style="z-index:2050;width:560px;max-height:85vh;overflow-y:auto;background:#fff"
            @click.stop
        >
            {{-- Header --}}
            <div class="px-3 py-2 rounded-top-3" style="background:#f0e8f5;border-bottom:1px solid #d9c7ea">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-1 overflow-hidden">
                        <span style="font-size:.8rem">@if($tiers->type === 'entreprise')🏢@else👤@endif</span>
                        <span class="fw-semibold text-truncate" style="color:#4a1060;max-width:280px;font-size:.85rem">
                            {{ $tiers->displayName() }}
                        </span>
                    </div>
                    <div class="d-flex flex-column align-items-end flex-shrink-0">
                        <div class="d-flex align-items-center gap-2">
                            <select wire:model.live="exercice"
                                    class="form-select py-0 px-1 border-0"
                                    style="width:auto;font-size:.6rem;background:#f0e8f5;color:#4a1060">
                                @foreach($availableYears as $year)
                                    <option value="{{ $year }}">{{ $year }}-{{ $year + 1 }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn-close" wire:click="close" aria-label="Fermer" style="font-size:.55rem"></button>
                        </div>
                    </div>
                </div>
                {{-- Contact + Adhésion --}}
                <div class="d-flex justify-content-between align-items-center mt-1" style="font-size:.6rem">
                    <div class="d-flex gap-3">
                        @if(!empty($summary['contact']['email']))
                            <a href="mailto:{{ $summary['contact']['email'] }}" class="text-decoration-none" style="color:#6b5077">
                                <i class="bi bi-envelope me-1"></i>{{ $summary['contact']['email'] }}
                            </a>
                        @endif
                        @if(!empty($summary['contact']['telephone']))
                            <a href="tel:{{ $summary['contact']['telephone'] }}" class="text-decoration-none" style="color:#6b5077">
                                <i class="bi bi-telephone me-1"></i>{{ $summary['contact']['telephone'] }}
                            </a>
                        @endif
                    </div>
                    @isset($summary['cotisations'])
                        <span style="color:#4a1060">
                            <i class="bi bi-person-check-fill me-1"></i>Adhérent — {{ number_format((float)$summary['cotisations']['total'], 2, ',', ' ') }} €
                        </span>
                    @endisset
                </div>
            </div>

            <div class="p-3">

                @php
                    $hasSections = isset($summary['depenses'])
                        || isset($summary['recettes'])
                        || isset($summary['dons'])
                        || isset($summary['cotisations'])
                        || isset($summary['participations'])
                        || isset($summary['referent'])
                        || isset($summary['factures']);
                    // cotisations shown in header, factures in flux line — but still count for "has activity"
                @endphp

                @if(!$hasSections)
                    <p class="text-muted small mb-0 text-center py-3">
                        <i class="bi bi-inbox me-1"></i>Aucune activité sur cet exercice.
                    </p>
                @else

                    {{-- Flux financiers (ligne compacte avec badges) --}}
                    @if(isset($summary['recettes']) || isset($summary['dons']) || isset($summary['depenses']) || isset($summary['factures']))
                        <div class="d-flex flex-wrap gap-3 mb-3 small justify-content-center">
                            @isset($summary['recettes'])
                                <a href="{{ route('compta.tiers.transactions', $tiers->id) }}" class="text-decoration-none text-nowrap text-dark">
                                    <span class="badge bg-success" style="font-size:.65rem">{{ $summary['recettes']['count'] }} REC</span>
                                    {{ number_format((float)$summary['recettes']['total'], 2, ',', ' ') }} €
                                </a>
                            @endisset
                            @isset($summary['dons'])
                                <a href="{{ route('compta.tiers.transactions', $tiers->id) }}" class="text-decoration-none text-nowrap text-dark">
                                    <span class="badge bg-warning text-dark" style="font-size:.65rem">{{ $summary['dons']['count'] }} DON</span>
                                    {{ number_format((float)$summary['dons']['total'], 2, ',', ' ') }} €
                                </a>
                            @endisset
                            @isset($summary['depenses'])
                                <a href="{{ route('compta.tiers.transactions', $tiers->id) }}" class="text-decoration-none text-nowrap text-dark">
                                    <span class="badge bg-danger" style="font-size:.65rem">{{ $summary['depenses']['count'] }} DEP</span>
                                    {{ number_format((float)$summary['depenses']['total'], 2, ',', ' ') }} €
                                </a>
                            @endisset
                            @isset($summary['factures'])
                                <a href="{{ route('compta.factures') }}" class="text-decoration-none text-nowrap text-dark">
                                    <span class="badge bg-danger" style="font-size:.65rem">{{ $summary['factures']['count'] }} FAC</span>
                                    {{ number_format((float)$summary['factures']['total'], 2, ',', ' ') }} €
                                    @if($summary['factures']['impayees'] > 0)
                                        <span class="badge bg-danger rounded-pill" style="font-size:.55rem">{{ $summary['factures']['impayees'] }} imp.</span>
                                    @endif
                                </a>
                            @endisset
                        </div>
                    @endif

                    {{-- Détail dépenses par opération --}}
                    @if(isset($summary['depenses']['par_operation']) && count($summary['depenses']['par_operation']) > 0)
                        <div class="mb-3">
                            <div class="fw-semibold small mb-1">Dépenses par opération</div>
                            <ul class="list-unstyled ms-2 mb-0">
                                @foreach($summary['depenses']['par_operation'] as $op)
                                    <li class="small text-muted">
                                        <a href="{{ route('gestion.operations.show', $op['operation_id']) }}"
                                           class="text-decoration-none text-muted">
                                            <i class="bi bi-link-45deg"></i>{{ $op['operation_nom'] }}
                                        </a>
                                        @if($op['sous_categorie']) — {{ $op['sous_categorie'] }}@endif
                                        : {{ number_format((float)$op['total'], 2, ',', ' ') }} € ({{ $op['count'] }})
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Participations --}}
                    @isset($summary['participations'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <i class="bi bi-calendar-event small text-primary"></i>
                                <span class="fw-semibold small">Participations</span>
                            </div>
                            <ul class="list-unstyled ms-3 mb-0">
                                @foreach($summary['participations'] as $part)
                                    <li class="small text-muted">
                                        <a href="{{ route('gestion.operations.show', $part['operation_id']) }}"
                                           class="text-decoration-none text-muted" target="_blank">
                                            <i class="bi bi-link-45deg"></i>{{ $part['operation_nom'] }}
                                        </a>
                                        @if(!empty($part['date_debut']))
                                            <span class="ms-1">— {{ \Carbon\Carbon::parse($part['date_debut'])->format('d/m/Y') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endisset

                    {{-- Référent --}}
                    @isset($summary['referent'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <i class="bi bi-people-fill small text-secondary"></i>
                                <span class="fw-semibold small">Référents</span>
                            </div>
                            <ul class="list-unstyled ms-3 mb-0">
                                @isset($summary['referent']['refere_par'])
                                    <li class="small text-muted mb-1">
                                        <i class="bi bi-person-fill me-1"></i><em>A référé :</em>
                                        @foreach($summary['referent']['refere_par'] as $r)
                                            <div class="ps-3">{{ $r['nom'] }}@if($r['operation']) <span class="text-muted">— {{ $r['operation'] }}</span>@endif</div>
                                        @endforeach
                                    </li>
                                @endisset
                                @isset($summary['referent']['medecin'])
                                    <li class="small text-muted mb-1">
                                        <i class="bi bi-hospital me-1"></i><em>Médecin de :</em>
                                        @foreach($summary['referent']['medecin'] as $r)
                                            <div class="ps-3">{{ $r['nom'] }}@if($r['operation']) <span class="text-muted">— {{ $r['operation'] }}</span>@endif</div>
                                        @endforeach
                                    </li>
                                @endisset
                                @isset($summary['referent']['therapeute'])
                                    <li class="small text-muted mb-1">
                                        <i class="bi bi-heart-pulse me-1"></i><em>Thérapeute de :</em>
                                        @foreach($summary['referent']['therapeute'] as $r)
                                            <div class="ps-3">{{ $r['nom'] }}@if($r['operation']) <span class="text-muted">— {{ $r['operation'] }}</span>@endif</div>
                                        @endforeach
                                    </li>
                                @endisset
                            </ul>
                        </div>
                    @endisset

                    {{-- Factures moved to flux financiers line --}}

                @endif
            </div>

            {{-- Footer --}}
            <div class="px-3 pb-3 pt-0 text-end">
                <a href="{{ route('compta.tiers.transactions', $tiers->id) }}"
                   class="small text-decoration-none" style="color:#722281" target="_blank">
                    Toutes les transactions →
                </a>
            </div>
        </div>
    @endif
</div>
