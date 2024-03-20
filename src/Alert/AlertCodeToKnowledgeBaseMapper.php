<?php

namespace Datto\Alert;

/**
 * Provides a mapping between error codes defined in AlertCodes.php
 *  and knowledge base search queries provided to us by the product
 *  team for displaying as links in the UI.
 *
 * Any error code of the form BK### will return BK### as the search
 *  query. Otherwise, specific mappings can be placed in
 *  ALERT_TO_KB_SEARCH_MAP.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class AlertCodeToKnowledgeBaseMapper
{
    const ALERT_TO_KB_SEARCH_MAP = [
        'BAK0027' => 'BK027',
        'BAK0028' => 'BK028',
        'BAK0029' => 'BK029',
        'BAK0030' => 'BK030',
        'BAK0032' => 'BK031'
    ];

    /**
     * Given an alert code, return
     *  a knowledge base search term
     *
     * @param string $code
     * @param string $message
     * @return string
     */
    public function getSearchQuery(string $code, string $message): string
    {
        if (preg_match('/^(BK\d\d\d)\b/', $message, $matches)) {
            return $matches[1];
        } elseif (preg_match('/^(AGENT\d{3,4})\b/', $message, $matches)) {
            return $matches[1];
        } elseif (isset(self::ALERT_TO_KB_SEARCH_MAP[$code])) {
            return self::ALERT_TO_KB_SEARCH_MAP[$code];
        } else {
            return '';
        }
    }
}
