<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0"><i class="bi bi-receipt me-1"></i> Vos notes de frais</h2>
        <a href="{{ route('portail.ndf.create', ['association' => $association->slug]) }}"
           class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Nouvelle note de frais
        </a>
    </div>

    @if ($notes->isEmpty())
        <div class="alert alert-info">
            Aucune note de frais pour le moment.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Date</th>
                        <th>Libellé</th>
                        <th class="text-end">Total</th>
                        <th>Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($notes as $note)
                        @php
                            $total = $note->lignes->sum('montant');
                            $statut = $note->statut;
                        @endphp
                        <tr>
                            <td data-sort="{{ $note->date?->format('Y-m-d') }}">
                                {{ $note->date?->format('d/m/Y') }}
                            </td>
                            <td>{{ $note->libelle }}</td>
                            <td class="text-end" data-sort="{{ number_format($total, 2, '.', '') }}">
                                {{ number_format((float) $total, 2, ',', ' ') }} €
                            </td>
                            <td>
                                @switch($statut->value)
                                    @case('brouillon')
                                        <span class="badge bg-secondary">{{ $statut->label() }}</span>
                                        @break
                                    @case('soumise')
                                        <span class="badge bg-primary">{{ $statut->label() }}</span>
                                        @break
                                    @case('rejetee')
                                        <span class="badge bg-danger">{{ $statut->label() }}</span>
                                        @break
                                    @case('validee')
                                        <span class="badge bg-success">{{ $statut->label() }}</span>
                                        @break
                                    @case('payee')
                                        <span class="badge bg-success text-dark">{{ $statut->label() }}</span>
                                        @break
                                    @default
                                        <span class="badge bg-secondary">{{ $statut->label() }}</span>
                                @endswitch
                            </td>
                            <td class="text-end">
                                @if (in_array($statut->value, ['brouillon', 'soumise']))
                                    <a href="{{ route('portail.ndf.edit', ['association' => $association->slug, 'noteDeFrais' => $note->id]) }}"
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil me-1"></i>Modifier
                                    </a>
                                @else
                                    <a href="{{ route('portail.ndf.show', ['association' => $association->slug, 'noteDeFrais' => $note->id]) }}"
                                       class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-eye me-1"></i>Consulter
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mt-3 text-end">
        <a href="{{ route('portail.home', ['association' => $association->slug]) }}"
           class="btn btn-link btn-sm text-muted">
            <i class="bi bi-arrow-left me-1"></i>Retour à l'accueil
        </a>
    </div>
</div>
