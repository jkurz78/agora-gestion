<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class VersionStampCommand extends Command
{
    protected $signature = 'app:version-stamp';

    protected $description = 'Génère config/version.php à partir des métadonnées git';

    public function handle(): int
    {
        $data = self::readGitVersion();

        self::writeVersionFile($data);

        $this->info("Version stamped: {$data['tag']} ({$data['date']})");

        return self::SUCCESS;
    }

    /**
     * @return array{tag: string, date: string}
     */
    public static function readGitVersion(): array
    {
        exec('git describe --tags --always 2>/dev/null', $tagOutput, $tagCode);
        exec("git log -1 --format=%cd --date=format:'%Y-%m-%d' 2>/dev/null", $dateOutput, $dateCode);

        return [
            'tag'  => ($tagCode === 0 && isset($tagOutput[0])) ? trim($tagOutput[0]) : 'unknown',
            'date' => ($dateCode === 0 && isset($dateOutput[0])) ? trim($dateOutput[0]) : 'unknown',
        ];
    }

    /**
     * @param array{tag: string, date: string} $data
     */
    public static function writeVersionFile(array $data): void
    {
        $content = "<?php\nreturn " . var_export($data, true) . ";\n";
        $result = file_put_contents(config_path('version.php'), $content);

        if ($result === false) {
            throw new \RuntimeException('Could not write ' . config_path('version.php'));
        }
    }
}
