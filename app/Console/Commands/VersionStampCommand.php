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
        exec('git rev-list --count HEAD 2>/dev/null', $countOutput, $countCode);
        exec('git rev-parse --short HEAD 2>/dev/null', $shaOutput, $shaCode);

        $build = ($countCode === 0 && isset($countOutput[0])) ? trim($countOutput[0]) : '0';
        $sha   = ($shaCode === 0 && isset($shaOutput[0])) ? trim($shaOutput[0]) : 'unknown';

        return [
            'tag'  => 'v1.0.' . $build,
            'date' => $sha,
            'year' => date('Y'),
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
