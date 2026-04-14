<?php

declare(strict_types=1);

namespace App\Services;

use stdClass;

final class SmtpService
{
    /**
     * Teste la joignabilité TCP du serveur SMTP et lit le banner de bienvenue.
     * Ne tente pas d'authentification (évite le verrouillage de compte).
     */
    public function testerConnexion(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        int $timeout = 10,
    ): stdClass {
        $wrapper = ($encryption === 'ssl') ? 'ssl' : 'tcp';
        $address = "{$wrapper}://{$host}:{$port}";

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        set_error_handler(static fn () => true);
        $socket = stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        restore_error_handler();

        if ($socket === false) {
            return $this->failure($errstr !== '' ? $errstr : "Connexion refusée (errno {$errno})");
        }

        stream_set_timeout($socket, $timeout);
        $banner = fgets($socket, 512);
        fclose($socket);

        if ($banner === false || $banner === '') {
            return $this->failure('Connexion établie mais aucune réponse du serveur SMTP.');
        }

        $code = (int) substr(trim($banner), 0, 3);
        if ($code !== 220) {
            return $this->failure("Réponse inattendue du serveur : " . trim($banner));
        }

        $result          = new stdClass();
        $result->success = true;
        $result->error   = null;
        $result->banner  = trim($banner);

        return $result;
    }

    private function failure(string $error): stdClass
    {
        $result          = new stdClass();
        $result->success = false;
        $result->error   = $error;
        $result->banner  = null;

        return $result;
    }
}
