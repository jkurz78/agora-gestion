<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>{{ $membre->prenom }} {{ $membre->nom }}</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('membres.edit', $membre) }}" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Modifier
            </a>
            <a href="{{ route('membres.index') }}" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Informations du membre</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Nom</dt>
                        <dd class="col-sm-8">{{ $membre->nom }}</dd>

                        <dt class="col-sm-4">Prénom</dt>
                        <dd class="col-sm-8">{{ $membre->prenom }}</dd>

                        <dt class="col-sm-4">Email</dt>
                        <dd class="col-sm-8">{{ $membre->email ?? '—' }}</dd>

                        <dt class="col-sm-4">Téléphone</dt>
                        <dd class="col-sm-8">{{ $membre->telephone ?? '—' }}</dd>
                    </dl>
                </div>
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Adresse</dt>
                        <dd class="col-sm-8">{{ $membre->adresse ?? '—' }}</dd>

                        <dt class="col-sm-4">Date d'adhésion</dt>
                        <dd class="col-sm-8">{{ $membre->date_adhesion?->format('d/m/Y') ?? '—' }}</dd>

                        <dt class="col-sm-4">Statut</dt>
                        <dd class="col-sm-8">
                            <span class="badge {{ $membre->statut === \App\Enums\StatutMembre::Actif ? 'bg-success' : 'bg-secondary' }}">
                                {{ $membre->statut->label() }}
                            </span>
                        </dd>

                        <dt class="col-sm-4">Notes</dt>
                        <dd class="col-sm-8">{{ $membre->notes ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <livewire:cotisation-form :membre="$membre" />
</x-app-layout>
