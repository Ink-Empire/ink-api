<?php

namespace App\Services;

use App\Exceptions\CrossEnvironmentDeleteException;
use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Refuses deletes of S3 objects belonging to another environment.
 *
 * Every environment writes to the same bucket and is separated only by the
 * S3_FILE_PREFIX that ImageService::prefixFilename() puts on each filename.
 * That makes a local machine holding a copy of the production database one
 * click away from deleting a real artist's image, because the local rows name
 * real production objects.
 *
 * The check is installed as AWS SDK middleware on the S3 client rather than at
 * the call sites, so it also covers tinker sessions, ad-hoc artisan commands
 * and anything reaching for Storage::disk('s3')->getClient() directly. It runs
 * in the init step, before signing, so a refused delete never leaves the
 * process.
 *
 * See docs/s3-environments.md.
 */
class S3DeleteGuard
{
    /**
     * Key namespaces written without an environment prefix. Nothing under
     * these can be attributed to an environment, so the guard cannot protect
     * them and does not pretend to.
     */
    private const UNPREFIXED_NAMESPACES = ['bulk-uploads/', 'fixtures/'];

    /**
     * Values S3_FILE_PREFIX is set to across environments. A key whose leading
     * segment is one of these belongs to that environment. Anything else is a
     * legacy key written before the prefix existed.
     */
    private const KNOWN_PREFIXES = ['local', 'dev', 'staging', 'test', 'production'];

    public static function guardClient(S3Client $client): void
    {
        $client->getHandlerList()->appendInit(
            Middleware::mapCommand(function (CommandInterface $command) {
                foreach (self::deletedKeys($command) as $key) {
                    self::assertDeletable($key);
                }

                return $command;
            }),
            'inkedin.s3_delete_guard'
        );
    }

    public static function assertDeletable(string $key): void
    {
        if (self::allows($key)) {
            return;
        }

        $prefix = ImageService::getFilePrefix();

        Log::warning('S3DeleteGuard: blocked cross-environment delete', [
            'key' => $key,
            'environment_prefix' => $prefix,
            'app_env' => app()->environment(),
        ]);

        throw new CrossEnvironmentDeleteException($key, $prefix);
    }

    public static function allows(string $key): bool
    {
        if (config('filesystems.disks.s3.allow_cross_env_deletes')) {
            return true;
        }

        $key = ltrim($key, '/');

        foreach (self::UNPREFIXED_NAMESPACES as $namespace) {
            if (str_starts_with($key, $namespace)) {
                return true;
            }
        }

        $own = ImageService::getFilePrefix();

        if ($own !== '' && str_starts_with($key, $own.'-')) {
            return true;
        }

        $segment = strstr($key, '-', true);

        if ($segment !== false && in_array($segment, self::KNOWN_PREFIXES, true)) {
            return false;
        }

        // No environment prefix, so the key predates prefixing. Only production
        // has legacy objects of its own to remove.
        return app()->environment('production');
    }

    /**
     * The object keys a command would remove, or none if it removes nothing.
     */
    private static function deletedKeys(CommandInterface $command): array
    {
        return match ($command->getName()) {
            'DeleteObject' => array_filter([$command['Key'] ?? null]),
            'DeleteObjects' => array_filter(
                array_column($command['Delete']['Objects'] ?? [], 'Key')
            ),
            default => [],
        };
    }
}
