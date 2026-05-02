<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Confirmez votre inscription</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; color: #1f2937; background: #f9fafb; margin: 0; padding: 24px; }
        .container { max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 32px; border: 1px solid #e5e7eb; }
        h1 { font-size: 20px; margin: 0 0 16px; color: #111827; }
        p { line-height: 1.55; margin: 0 0 16px; }
        .btn { display: inline-block; background: #3d5473; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; }
        .footer { font-size: 13px; color: #6b7280; margin-top: 32px; padding-top: 16px; border-top: 1px solid #e5e7eb; }
        a { color: #3d5473; }
    </style>
</head>
<body>
<div class="container">
    <h1>Bonjour{{ $prenom ? ' '.$prenom : '' }},</h1>

    <p>Vous avez demandé à recevoir la newsletter de <strong>{{ $associationNom }}</strong>.</p>

    <p>Pour confirmer votre inscription, cliquez sur le bouton ci-dessous :</p>

    <p>
        <a href="{{ $confirmUrl }}" class="btn">Confirmer mon inscription</a>
    </p>

    <p>Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>
        <a href="{{ $confirmUrl }}">{{ $confirmUrl }}</a></p>

    <p style="color: #6b7280; font-size: 13px;">
        Ce lien expire dans {{ config('newsletter.confirmation_ttl_days', 7) }} jours.
        Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer ce message.
    </p>

    <div class="footer">
        Vous pouvez vous désinscrire à tout moment :
        <a href="{{ $unsubscribeUrl }}">{{ $unsubscribeUrl }}</a>
    </div>
</div>
</body>
</html>
