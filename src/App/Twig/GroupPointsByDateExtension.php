<?php

declare(strict_types=1);

namespace Datto\App\Twig;

use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Adds 'groupPointsByDate' filter for array of recovery points.
 * Array will be grouped by newest date.
 *
 * @author Jamal Akbary <jamal.akbary@datto.com>
 */
class GroupPointsByDateExtension extends AbstractExtension
{
    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('groupPointsByDate', [$this, 'groupPointsByDate'], ['needs_environment' => true])
        ];
    }

    /**
     * @param Environment $env
     * @param array $points
     * @return array
     */
    public function groupPointsByDate(Environment $env, array $points): array
    {
        $pointsByDate = [];
        foreach ($points as $point) {
            /** @psalm-suppress UndefinedFunction twig_date_converter is defined in vendor/twig/twig/src/Extension/CoreExtension.php */
            $date = twig_date_converter($env, $point['snapshotEpoch'])
                ->setTime(0, 0, 0)
                ->getTimestamp();
            $pointsByDate[$date][] = $point;
        }
        
        return $pointsByDate;
    }
}
