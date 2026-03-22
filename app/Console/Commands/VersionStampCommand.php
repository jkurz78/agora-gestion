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
     * @return array{tag: string, date: string, year: string}
     */
    public static function readGitVersion(): array
    {
        $versionFile = base_path('VERSION');
        $tag = file_exists($versionFile) ? trim((string) file_get_contents($versionFile)) : 'dev';

        exec('git log -1 --format=%as 2>/dev/null', $dateOutput, $dateCode);
        $date = ($dateCode === 0 && isset($dateOutput[0])) ? trim($dateOutput[0]) : date('Y-m-d');

        return [
            'tag' => $tag,
            'date' => $date,
            'year' => substr($date, 0, 4),
        ];
    }

    /**
     * @param  array{tag: string, date: string}  $data
     */
    public static function writeVersionFile(array $data): void
    {
        $content = "<?php\nreturn ".var_export($data, true).";\n";
        $result = file_put_contents(config_path('version.php'), $content);

        if ($result === false) {
            throw new \RuntimeException('Could not write '.config_path('version.php'));
        }
    }
}
