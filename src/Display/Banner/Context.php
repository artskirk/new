<?php

namespace Datto\Display\Banner;

/**
 * This class contains all of the information related to displaying a banner.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Context
{
    /**
     * @var String
     */
    private $uri;

    /**
     * @param $uri
     */
    public function __construct(string $uri)
    {
        $this->uri = $uri;
    }

    /**
     * @return String
     */
    public function getUri(): string
    {
        return $this->uri;
    }
}
