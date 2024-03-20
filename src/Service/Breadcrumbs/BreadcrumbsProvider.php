<?php

declare(strict_types=1);

namespace Datto\Service\Breadcrumbs;

/**
 * Creates breadcrumbs for File restore page
 *
 * @author Krzysztof Babicki <krzysztof.babicki@datto.com>
 */
class BreadcrumbsProvider
{
    const DIRECTORY_SEPARATOR = '/';

    public function createBreadcrumbs(string $relativePath, string $restoreUrl, string $assetName): array
    {
        $directories = [];
        if ($relativePath !== self::DIRECTORY_SEPARATOR) {
            $directories = explode(self::DIRECTORY_SEPARATOR, $relativePath);
            array_pop($directories);
        }
        $breadcrumbs = [['name' => $assetName, 'url' => $restoreUrl]];

        foreach ($directories as $index => $name) {
            $namesInPath = array_slice($directories, 0, $index + 1);
            $path = implode(self::DIRECTORY_SEPARATOR, $namesInPath);
            $breadcrumbs[] = [
                'name' => $name,
                'url' => $restoreUrl . $path
            ];
        }

        return $breadcrumbs;
    }
}
