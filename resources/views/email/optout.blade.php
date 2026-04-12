<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $resubscribed ? 'Réinscription' : 'Désinscription' }} — {{ $nomAsso }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="text-center mb-4">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $nomAsso }}" height="100" class="mb-3">
                    @endif
                    <h4 class="mb-0">{{ $nomAsso }}</h4>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body text-center py-5">
                        @if($resubscribed)
                            <i class="bi bi-envelope-check text-success" style="font-size:3rem"></i>
                            <h5 class="mt-3">Vous avez été réinscrit(e)</h5>
                            <p class="text-muted mb-0">
                                Vous recevrez de nouveau nos communications.
                            </p>
                        @else
                            <i class="bi bi-envelope-slash text-warning" style="font-size:3rem"></i>
                            <h5 class="mt-3">Vous avez été désinscrit(e)</h5>
                            <p class="text-muted">
                                Vous ne recevrez plus de communications de notre part.
                            </p>
                            <hr>
                            <p class="small text-muted mb-0">
                                Vous avez cliqué par erreur ?
                                <a href="{{ route('email.resubscribe', ['token' => $token]) }}">
                                    Cliquez ici pour continuer de recevoir nos courriels
                                </a>
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
