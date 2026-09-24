<?php

namespace App\Exceptions;

use RuntimeException;

class CrossEnvironmentDeleteException extends RuntimeException
{
    public function __construct(
        public readonly string $key,
        public readonly string $environmentPrefix
    ) {
        $prefix = $environmentPrefix === '' ? '(none)' : $environmentPrefix;

        parent::__construct(
            "Refusing to delete S3 object \"{$key}\": it does not belong to this environment "
            ."(S3_FILE_PREFIX={$prefix}). Every environment shares one bucket, so this would "
            .'remove another environment\'s file. See docs/s3-environments.md.'
        );
    }
}
