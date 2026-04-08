<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ParticipantDonneesMedicales extends Model
{
    protected $table = 'participant_donnees_medicales';

    protected $fillable = [
        'participant_id',
        'date_naissance',
        'sexe',
        'poids',
        'taille',
        'notes',
        'medecin_nom',
        'medecin_prenom',
        'medecin_telephone',
        'medecin_email',
        'medecin_adresse',
        'medecin_code_postal',
        'medecin_ville',
        'therapeute_nom',
        'therapeute_prenom',
        'therapeute_telephone',
        'therapeute_email',
        'therapeute_adresse',
        'therapeute_code_postal',
        'therapeute_ville',
    ];

    protected function casts(): array
    {
        return [
            'date_naissance' => 'encrypted',
            'sexe' => 'encrypted',
            'poids' => 'encrypted',
            'taille' => 'encrypted',
            'notes' => 'encrypted',
            'medecin_nom' => 'encrypted',
            'medecin_prenom' => 'encrypted',
            'medecin_telephone' => 'encrypted',
            'medecin_email' => 'encrypted',
            'medecin_adresse' => 'encrypted',
            'medecin_code_postal' => 'encrypted',
            'medecin_ville' => 'encrypted',
            'therapeute_nom' => 'encrypted',
            'therapeute_prenom' => 'encrypted',
            'therapeute_telephone' => 'encrypted',
            'therapeute_email' => 'encrypted',
            'therapeute_adresse' => 'encrypted',
            'therapeute_code_postal' => 'encrypted',
            'therapeute_ville' => 'encrypted',
        ];
    }

    /**
     * Date de naissance parsée en Carbon, null si vide ou format invalide.
     *
     * Le champ chiffré peut contenir plusieurs formats selon l'origine de la
     * saisie : ISO (Y-m-d), ISO datetime (Y-m-d H:i:s), français (d/m/Y),
     * tiret français (d-m-Y).
     */
    public function dateNaissanceCarbon(): ?Carbon
    {
        $raw = $this->date_naissance;
        if ($raw === null || $raw === '') {
            return null;
        }

        // On extrait juste la partie date si jamais il y a une heure derrière.
        $dateOnly = trim(explode(' ', $raw)[0]);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $fmt) {
            try {
                $parsed = Carbon::createFromFormat($fmt, $dateOnly);
                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable) {
                // Format ne correspond pas, on passe au suivant.
            }
        }

        return null;
    }

    /**
     * Sanitise le HTML des notes médicales : ne garde que les balises de mise en forme.
     */
    public static function sanitizeNotes(string $html): string
    {
        $clean = strip_tags($html, '<p><br><strong><em><b><i><u><ul><ol><li>');

        return (string) preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
