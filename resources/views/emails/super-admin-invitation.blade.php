<p>Bonjour {{ $nomAdmin }},</p>

<p>Vous venez d'être désigné·e administrateur·trice de l'association <strong>{{ $nomAsso }}</strong> sur AgoraGestion.</p>

<p>Pour activer votre compte et définir votre mot de passe, cliquez sur le lien suivant :</p>

<p><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>

<p><small>Ce lien expire sous 60 minutes. Si vous n'êtes pas à l'origine de cette invitation, ignorez cet email.</small></p>
