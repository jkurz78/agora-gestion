<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; }
        .token { display: inline-block; padding: 8px 16px; background: #f0f0f0; border: 1px solid #ddd; border-radius: 6px; font-size: 1.4rem; font-family: monospace; letter-spacing: 3px; }
        .btn { display: inline-block; padding: 10px 24px; background: #3d5473; color: #fff; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #eee; font-size: 0.85rem; color: #888; }
    </style>
</head>
<body>
    <div>{!! $corpsHtml !!}</div>

    @if($showAutoBlock)
    <p style="text-align: center; margin: 25px 0;">
        <a href="{{ $formulaireUrl }}" class="btn">Accéder au formulaire</a>
    </p>

    <p>Vous pouvez aussi saisir ce code sur <a href="{{ route('formulaire.index') }}">la page d'accueil du formulaire</a> :</p>
    <p style="text-align: center;">
        <span class="token" style="font-size: 1.1rem;">{{ $tokenCode }}</span>
    </p>

    <div class="footer">
        <p>Ce lien est valable jusqu'au {{ $dateExpiration }}.</p>
        <p>Si vous n'êtes pas concerné par ce message, vous pouvez l'ignorer.</p>
    </div>
    @endif
</body>
</html>
