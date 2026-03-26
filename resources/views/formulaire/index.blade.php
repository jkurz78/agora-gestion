@extends('formulaire.layout')

@section('title', 'Formulaire participant')

@section('content')
<div class="card shadow-sm">
    <div class="card-body p-4">
        <h4 class="card-title text-center mb-3">Formulaire participant</h4>

        @if (session('success'))
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-1"></i>
                {{ session('success') }}
            </div>
        @endif

        @if (session('info'))
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                {{ session('info') }}
            </div>
        @endif

        @if ($errors->has('token'))
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-1"></i>
                {{ $errors->first('token') }}
            </div>
        @endif

        <p class="text-muted">Saisissez le code qui vous a été communiqué.</p>

        <form action="{{ route('formulaire.show') }}" method="GET">
            <div class="mb-3">
                <input
                    type="text"
                    name="token"
                    class="form-control form-control-lg text-center font-monospace"
                    placeholder="XXXX-XXXX"
                    autocapitalize="characters"
                    autocomplete="off"
                    maxlength="9"
                    required
                >
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-right-circle me-1"></i> Accéder
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
