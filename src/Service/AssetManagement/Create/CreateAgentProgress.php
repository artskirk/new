<?php

namespace Datto\Service\AssetManagement\Create;

use Datto\Config\JsonConfigRecord;

/**
 * This class defines progress reporting for the agent creation process.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CreateAgentProgress extends JsonConfigRecord
{
    /** @var array Maps states to progress percentages */
    const PROGRESS = [
        self::INIT => 10,
        self::PAIR => 30,
        self::HOST => 70,
        self::DEFAULTS => 90,
        self::UI_SUCCESS => 100,
        self::FINISHED => 100,
        // Agent is already created at this point and background processes will retry any failures from PostCreate later
        self::POST_FAIL => 100
    ];

    const ERROR_STATES = [
        self::FAIL,
        self::AGENT_LIMIT_REACHED,
        self::EXISTS,
        self::NO_HYPERVISOR,
        self::BAD_OFFSITE_TARGET,
        self::NO_ENCRYPTION_SUPPORT,
        self::AGENT_TEMPLATE_DOES_NOT_EXIST,
        self::POST_FAIL
    ];

    // these state constants correspond to translations 'agents.add.progress.state.*'
    const INACTIVE = 'inactive';

    const FAIL = 'fail';
    const AGENT_LIMIT_REACHED = 'limitReached';
    const EXISTS = 'exists';
    const NO_HYPERVISOR = 'noHypervisor';
    const BAD_OFFSITE_TARGET = 'badOffsiteTarget';
    const NO_ENCRYPTION_SUPPORT = 'noEncryption';
    const AGENT_TEMPLATE_DOES_NOT_EXIST = 'noTemplateExists';
    const POST_FAIL = 'postFail';

    const INIT = 'init';
    const PAIR = 'pair';
    const HOST = 'host';
    const DEFAULTS = 'defaults';
    const UI_SUCCESS = 'uiSuccess'; // The point when the agent is created from the perspective of the user
    const FINISHED = 'finished'; // The point after UI_SUCCESS when non user visible creation work is finished

    /** @var string */
    private $state = self::INACTIVE;

    /** @var string */
    private $errorMessage = '';

    /**
     * @param string $state
     * @return bool True if the state is an error state
     */
    public static function isErrorState(string $state): bool
    {
        return in_array($state, self::ERROR_STATES);
    }

    /**
     * @return string name of key file that this config record will be stored to
     */
    public function getKeyName(): string
    {
        return 'createProgress';
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @param string $state One of the state constants above
     */
    public function setState(string $state)
    {
        $this->state = $state;
    }

    /**
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * @param string $errorMessage
     */
    public function setErrorMessage(string $errorMessage)
    {
        $this->errorMessage = $errorMessage;
    }

    /**
     * @return array
     */
    public function getProgress(): array
    {
        return [
            'progress' => self::PROGRESS[$this->state] ?? 0,
            'state' => $this->state,
            'errorMessage' => $this->errorMessage
        ];
    }

    /**
     * @inheritDoc
     */
    protected function load(array $vals)
    {
        $this->state = $vals['state'] ?? self::INACTIVE;
        $this->errorMessage = $vals['errorMessage'] ?? '';
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'state' => $this->state,
            'errorMessage' => $this->errorMessage
        ];
    }
}
