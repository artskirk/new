<?php

namespace Datto\App\Console\Command;

use Datto\Common\Resource\Environment;

/**
 * Class to retrieve the current user for a terminal session.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class SessionUserHelper
{
    const SYSTEM_USER_ENVIRONMENT_VARIABLE = 'USER';
    const RLY_USER_ENVIRONMENT_VARIABLE = 'DAS_USER';

    /** @var Environment */
    private $environment;

    /**
     * @param Environment $environment
     */
    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
    }

    /**
     * Get the logged in system user for the current shell session.
     *
     * @return string
     */
    public function getSystemUser(): string
    {
        return $this->environment->getEnv(self::SYSTEM_USER_ENVIRONMENT_VARIABLE);
    }

    /**
     * Get the RLY user if the current shell is an open RLY connection.
     *
     * @return string
     */
    public function getRlyUser(): string
    {
        return $this->environment->getEnv(self::RLY_USER_ENVIRONMENT_VARIABLE);
    }
}
