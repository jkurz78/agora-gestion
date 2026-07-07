<?php

declare(strict_types=1);

use App\Mail\QuestionnaireInvitationMail;

it('rend l objet et le corps fournis', function (): void {
    $mail = new QuestionnaireInvitationMail(objet: 'Votre avis', corpsHtml: '<p>Bonjour Marie, <a href="https://x/q/abc">répondez</a></p>');
    $rendu = $mail->render();

    expect($rendu)->toContain('Bonjour Marie');
    expect($rendu)->toContain('https://x/q/abc');
});
