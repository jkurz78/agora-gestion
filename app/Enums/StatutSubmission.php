<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutSubmission: string
{
    case EnCours = 'en_cours';
    case Soumise = 'soumise';
    case Remplacee = 'remplacee'; // utilisé au lot 7 (scan-remplace)
}
