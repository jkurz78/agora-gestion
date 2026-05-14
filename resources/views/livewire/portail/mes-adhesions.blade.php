<div>
    @if (session('portail.error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('portail.error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Mes adhésions</h4>
        @if ($urlRenouvellement)
            <a class="btn btn-primary" href="{{ $urlRenouvellement }}" target="_blank" rel="noopener">
                <i class="bi bi-arrow-up-right-square"></i> Renouveler mon adhésion
            </a>
        @endif
    </div>

    @if ($adhesions->isEmpty())
        <div class="text-muted py-4 text-center">
            <i class="bi bi-card-checklist fs-3 d-block mb-2"></i>
            Aucune adhésion enregistrée.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Exercice</th>
                        <th>Formule</th>
                        <th>Du</th>
                        <th>Au</th>
                        <th class="text-end">Montant</th>
                        <th>Statut</th>
                        <th>Type</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($adhesions as $dto)
                        <tr>
                            <td>{{ $dto->libelleExercice() }}</td>
                            <td>{{ $dto->adhesion->formuleAdhesion?->libelle ?? ($dto->adhesion->label_formule ?? '—') }}</td>
                            <td>{{ $dto->adhesion->date_debut?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $dto->adhesion->date_fin?->format('d/m/Y') ?? '—' }}</td>
                            <td class="text-end">
                                {{ number_format((float) $dto->adhesion->montant_facial, 2, ',', ' ') }}&nbsp;€
                            </td>
                            <td>
                                @if ($dto->adhesion->date_fin && $dto->adhesion->date_fin->gte(now()->startOfDay()))
                                    <span class="badge bg-success">À jour</span>
                                @else
                                    <span class="badge bg-secondary">Expirée</span>
                                @endif
                            </td>
                            <td>{{ $dto->libelleType() }}</td>
                            <td>
                                @if ($dto->peutEmettreRecu($portailAssociation) || $dto->recuFiscalActif() !== null)
                                    <button
                                        wire:click="telechargerRecuCotisation({{ $dto->adhesion->id }})"
                                        class="btn btn-sm btn-outline-secondary"
                                        wire:loading.attr="disabled"
                                    >
                                        <i class="bi bi-download"></i> Télécharger le reçu
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
