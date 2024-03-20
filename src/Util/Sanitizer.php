<?php
namespace Datto\Util;

/**
 * Provides methods for sanitizing input variables for which there
 * are no naitve PHP sanitizers.
 *
 * @todo It would be better to place this class in composerLib as it's not OS2
 * specific.
 */
class Sanitizer
{
    /**
     * Sanitizes agent name.
     *
     * Since agent names are used for ZFS dataset names, the following rule
     * naming rule applies:
     * {@link http://docs.oracle.com/cd/E19253-01/819-5461/6n7ht6qtq/index.html}
     *
     * @param string $name
     *
     * @return string
     */
    public static function agentName($name)
    {
        $sanitized = preg_replace('([^-\w_.])', '', $name);
        // remove any dots runs created after first pass (like '..').
        $sanitized = preg_replace("([\.]{2,})", '', $sanitized);

        return $sanitized;
    }
}
