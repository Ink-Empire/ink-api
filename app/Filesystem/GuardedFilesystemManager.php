<?php

namespace App\Filesystem;

use App\Services\S3DeleteGuard;
use Illuminate\Filesystem\FilesystemManager;

/**
 * Installs the cross-environment delete guard on every S3 client the
 * application builds. Bound over the framework's manager in AppServiceProvider.
 */
class GuardedFilesystemManager extends FilesystemManager
{
    public function createS3Driver(array $config)
    {
        $disk = parent::createS3Driver($config);

        S3DeleteGuard::guardClient($disk->getClient());

        return $disk;
    }
}
