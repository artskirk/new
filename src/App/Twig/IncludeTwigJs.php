<?php

namespace Datto\App\Twig;

use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Adds 'include_js' function which is the same as 'include'
 * but wraps the output in <script> tags and puts the verbatim
 * twig content inside suitable to be consumed by twigjs
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class IncludeTwigJs extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'include_js',
                [$this, 'includeForTwigJs'],
                ['needs_environment' => true]
            )
        ];
    }

    public function includeForTwigJs(
        Environment $environment,
        string $path,
        string $id
    ): string {
        $rawSource = $environment->getLoader()->getSourceContext($path)->getCode();

        $html = sprintf(
            '<script type="text/x-twig" id="%s">%s</script>',
            $id,
            $rawSource
        );

        return $html;
    }
}
