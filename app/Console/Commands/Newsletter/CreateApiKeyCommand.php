<?php

declare(strict_types=1);

namespace App\Console\Commands\Newsletter;

use App\Models\Association;
use App\Models\Association\ApiKey;
use Illuminate\Console\Command;

final class CreateApiKeyCommand extends Command
{
    protected $signature = 'newsletter:keys:create
        {--association= : ID de l\'asso (obligatoire)}
        {--label= : Libellé descriptif (ex. "Site vitrine prod")}
        {--scope=* : Scopes (défaut: newsletter:subscribe)}';

    protected $description = 'Crée une clé API HMAC pour une asso. Affiche le secret UNE SEULE FOIS.';

    public function handle(): int
    {
        $assoId = (int) $this->option('association');
        if ($assoId === 0) {
            $this->error('--association=<id> requis.');

            return self::INVALID;
        }

        $association = Association::find($assoId);
        if ($association === null) {
            $this->error("Association #{$assoId} introuvable.");

            return self::FAILURE;
        }

        $secret = bin2hex(random_bytes(32));
        $keyId = 'ak_'.bin2hex(random_bytes(16));
        $scopes = $this->option('scope') ?: ['newsletter:subscribe'];
        $label = (string) ($this->option('label') ?: 'Sans libellé');

        $apiKey = new ApiKey([
            'association_id' => $association->id,
            'key_id' => $keyId,
            'secret_encrypted' => $secret, // le cast 'encrypted' chiffre au save()
            'label' => $label,
            'scopes' => $scopes,
        ]);
        $apiKey->save();

        $this->newLine();
        $this->info("✓ Clé API créée pour l'asso #{$association->id} ({$association->nom}).");
        $this->newLine();
        $this->line("  KEY_ID  : {$keyId}");
        $this->line("  SECRET  : {$secret}");
        $this->newLine();
        $this->warn("⚠️  Le secret n'est affiché qu'une seule fois. Stocker en lieu sûr.");
        $this->warn('   Pour le perdre = révoquer la clé et en créer une nouvelle.');
        $this->newLine();

        return self::SUCCESS;
    }
}
