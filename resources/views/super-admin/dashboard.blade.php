@extends('layouts.super-admin')

@section('content')
    <h1>Super-administration</h1>
    <p class="text-muted">Gestion des tenants, accès support, audit.</p>
    <div class="row g-3">
        <div class="col-md-4">
            <a href="{{ route('super-admin.associations.index') }}" class="card h-100 text-decoration-none">
                <div class="card-body">
                    <h5 class="card-title">Associations</h5>
                    <p class="card-text text-muted small">Liste des tenants, création, suspension, mode support.</p>
                </div>
            </a>
        </div>
    </div>
@endsection
