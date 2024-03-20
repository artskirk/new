<?php

namespace Datto\Service\Metrics;

class TelegrafConfigException extends \RuntimeException
{
    public static function forExistingNonSymlinkFile(string $fileName): self
    {
        return new self("Non-symlink file already exists: $fileName");
    }

    public static function forFailureToCreateSymlink(string $fileName): self
    {
        return new self("Failed to create a symlink for file: $fileName");
    }

    public static function forFailureToDeleteFile(string $fileName): self
    {
        return new self("Failed to delete file: $fileName");
    }
}
