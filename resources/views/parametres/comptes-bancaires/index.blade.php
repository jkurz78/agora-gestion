<x-app-layout>
    <h1 class="mb-4">Comptes bancaires</h1>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-3">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                data-bs-target="#addCompteForm">
            <i class="bi bi-plus-lg"></i> Ajouter un compte bancaire
        </button>
    </div>

    <div class="collapse mb-3" id="addCompteForm">
        <div class="card card-body">
            <form action="{{ route('parametres.comptes-bancaires.store') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label for="cb_nom" class="form-label">Nom</label>
                    <input type="text" name="nom" id="cb_nom" class="form-control" required maxlength="150">
                </div>
                <div class="col-md-3">
                    <label for="cb_iban" class="form-label">IBAN</label>
                    <input type="text" name="iban" id="cb_iban" class="form-control" maxlength="34">
                </div>
                <div class="col-md-2">
                    <label for="cb_solde" class="form-label">Solde initial</label>
                    <input type="number" name="solde_initial" id="cb_solde" class="form-control" required step="0.01">
                </div>
                <div class="col-md-2">
                    <label for="cb_date" class="form-label">Date solde</label>
                    <input type="date" name="date_solde_initial" id="cb_date" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Nom</th>
                <th>IBAN</th>
                <th>Solde initial</th>
                <th>Date solde</th>
                <th style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($comptesBancaires as $compte)
                <tr>
                    <td>{{ $compte->nom }}</td>
                    <td>{{ $compte->iban ?? '—' }}</td>
                    <td>{{ number_format((float) $compte->solde_initial, 2, ',', ' ') }} &euro;</td>
                    <td>{{ $compte->date_solde_initial->format('d/m/Y') }}</td>
                    <td>
                        <form action="{{ route('parametres.comptes-bancaires.destroy', $compte) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Supprimer ce compte bancaire ?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-muted">Aucun compte bancaire enregistré.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</x-app-layout>
