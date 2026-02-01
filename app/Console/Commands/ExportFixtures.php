<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ExportFixtures extends Command
{
    protected $signature = 'fixtures:export
                            {--upload : Upload fixtures to S3 after export}
                            {--branch= : S3 branch folder (default: develop)}';

    protected $description = 'Run contract tests and export JSON fixtures for frontend E2E tests';

    protected string $s3Prefix = 'fixtures';

    public function handle(): int
    {
        $this->info('Running contract tests with fixture export...');

        $result = Process::env(['EXPORT_FIXTURES' => 'true'])
            ->timeout(300)
            ->run('php artisan test --filter=Contracts');

        $this->line($result->output());

        if ($result->failed()) {
            $this->error('Contract tests failed. Fixtures not exported.');
            $this->line($result->errorOutput());
            return Command::FAILURE;
        }

        $fixturesPath = base_path('tests/fixtures');
        $fixtureCount = $this->countFixtures($fixturesPath);

        $this->info("Exported {$fixtureCount} fixtures to tests/fixtures/");

        if ($this->option('upload')) {
            return $this->uploadToS3($fixturesPath);
        }

        $this->line('');
        $this->line('To upload to S3, run:');
        $this->line("  php artisan fixtures:export --upload");

        return Command::SUCCESS;
    }

    protected function uploadToS3(string $fixturesPath): int
    {
        $branch = $this->option('branch') ?: 'develop';
        $s3Base = "{$this->s3Prefix}/{$branch}";

        $this->info("Uploading fixtures to s3://.../{$s3Base}/");

        try {
            $disk = Storage::disk('s3');
            $uploaded = 0;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fixturesPath)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'json') {
                    $relativePath = str_replace($fixturesPath . '/', '', $file->getPathname());
                    $s3Path = "{$s3Base}/{$relativePath}";

                    $disk->put($s3Path, file_get_contents($file->getPathname()));
                    $this->line("  Uploaded: {$relativePath}");
                    $uploaded++;
                }
            }

            $this->info("Uploaded {$uploaded} fixtures to S3");
            $this->line('');
            $this->line('Frontend can now pull fixtures with:');
            $this->line('  npm run pull:fixtures');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to upload to S3: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function countFixtures(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $count++;
            }
        }

        return $count;
    }
}
