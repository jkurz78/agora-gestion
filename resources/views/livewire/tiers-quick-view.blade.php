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
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
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
                {{-- Contact in header --}}
                @if(!empty($summary['contact']['email']) || !empty($summary['contact']['telephone']))
                    <div class="d-flex gap-3 mt-1" style="font-size:.65rem">
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
                @endif
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
                @endphp

                @if(!$hasSections)
                    <p class="text-muted small mb-0 text-center py-3">
                        <i class="bi bi-inbox me-1"></i>Aucune activité sur cet exercice.
                    </p>
                @else

                    {{-- Dépenses --}}
                    @isset($summary['depenses'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <i class="bi bi-arrow-up-circle-fill text-danger small"></i>
                                <span class="fw-semibold small">Dépenses</span>
                                <span class="ms-1 text-muted small">
                                    {{ $summary['depenses']['count'] }} &bull;
                                    {{ number_format((float)$summary['depenses']['total'], 2, ',', ' ') }}&nbsp;€
                                </span>
                            </div>
                            @isset($summary['depenses']['par_operation'])
                                <ul class="list-unstyled ms-3 mb-0">
                                    @foreach($summary['depenses']['par_operation'] as $op)
                                        <li class="small text-muted">
                                            <a href="{{ route('gestion.operations.show', $op['operation_id']) }}"
                                               class="text-decoration-none text-muted" target="_blank">
                                                <i class="bi bi-link-45deg"></i>{{ $op['operation_nom'] }}
                                            </a>
                                            —
                                            {{ $op['count'] }} &bull; {{ number_format((float)$op['total'], 2, ',', ' ') }}&nbsp;€
                                        </li>
                                    @endforeach
                                </ul>
                            @endisset
                        </div>
                    @endisset

                    {{-- Recettes --}}
                    @isset($summary['recettes'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-1">
                                <i class="bi bi-arrow-down-circle-fill text-success small"></i>
                                <span class="fw-semibold small">Recettes</span>
                                <a href="{{ route('compta.tiers.transactions', $tiers->id) }}"
                                   class="ms-1 text-muted small text-decoration-none" target="_blank">
                                    {{ $summary['recettes']['count'] }} &bull;
                                    {{ number_format((float)$summary['recettes']['total'], 2, ',', ' ') }}&nbsp;€
                                    <i class="bi bi-box-arrow-up-right small"></i>
                                </a>
                            </div>
                        </div>
                    @endisset

                    {{-- Dons --}}
                    @isset($summary['dons'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-1">
                                <i class="bi bi-heart-fill text-warning small"></i>
                                <span class="fw-semibold small">Dons</span>
                                <a href="{{ route('compta.tiers.transactions', $tiers->id) }}"
                                   class="ms-1 text-muted small text-decoration-none" target="_blank">
                                    {{ $summary['dons']['count'] }} &bull;
                                    {{ number_format((float)$summary['dons']['total'], 2, ',', ' ') }}&nbsp;€
                                    <i class="bi bi-box-arrow-up-right small"></i>
                                </a>
                            </div>
                        </div>
                    @endisset

                    {{-- Cotisations --}}
                    @isset($summary['cotisations'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-1">
                                <i class="bi bi-person-check-fill small" style="color:#722281"></i>
                                <span class="fw-semibold small">Adhésion / Cotisation</span>
                                <span class="ms-1 text-muted small">
                                    {{ number_format((float)$summary['cotisations']['total'], 2, ',', ' ') }}&nbsp;€
                                </span>
                            </div>
                        </div>
                    @endisset

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

                    {{-- Factures --}}
                    @isset($summary['factures'])
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-receipt small text-dark"></i>
                                <span class="fw-semibold small">Factures</span>
                                <a href="{{ route('compta.factures') }}"
                                   class="text-muted small text-decoration-none" target="_blank">
                                    {{ $summary['factures']['count'] }}
                                    &bull; {{ number_format((float)$summary['factures']['total'], 2, ',', ' ') }}&nbsp;€
                                    <i class="bi bi-box-arrow-up-right small"></i>
                                </a>
                                @if($summary['factures']['impayees'] > 0)
                                    <span class="badge bg-danger rounded-pill small">
                                        {{ $summary['factures']['impayees'] }} impayée{{ $summary['factures']['impayees'] > 1 ? 's' : '' }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endisset

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
