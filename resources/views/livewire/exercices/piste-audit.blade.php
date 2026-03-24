<div>
    <h4 class="mb-3">Piste d'audit des exercices</h4>

    @if($actions->isEmpty())
        <div class="alert alert-info">Aucune action enregistrée.</div>
    @else
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Date</th>
                        <th>Exercice</th>
                        <th>Action</th>
                        <th>Utilisateur</th>
                        <th>Commentaire</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($actions as $action)
                        <tr>
                            <td>{{ $action->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $action->exercice->label() }}</td>
                            <td>
                                <span class="badge {{ $action->action->badge() }}">
                                    {{ $action->action->label() }}
                                </span>
                            </td>
                            <td>{{ $action->user->nom }}</td>
                            <td>{{ $action->commentaire ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
