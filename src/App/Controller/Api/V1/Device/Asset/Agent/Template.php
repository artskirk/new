<?php

namespace Datto\App\Controller\Api\V1\Device\Asset\Agent;

use Datto\Asset\Agent\Template\AgentTemplateService;

/**
 * API endpoints for creating and applying agent settings templates
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 * @author Stephen Allan <sallan@datto.com>
 */
class Template
{
    /** @var AgentTemplateService */
    private $agentTemplateService;

    public function __construct(AgentTemplateService $agentTemplateService)
    {
        $this->agentTemplateService = $agentTemplateService;
    }

    /**
     * Create a new agent template.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentKey Keyname of the agent
     * @param string $templateName Name to call the new template
     */
    public function create(string $agentKey, string $templateName): void
    {
        $this->agentTemplateService->create($agentKey, $templateName);
    }

    /**
     * Apply the settings from an agent template to an existing agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $agentKey Keyname of the agent
     * @param int $templateId Agent template ID
     */
    public function apply(string $agentKey, int $templateId): void
    {
        $this->agentTemplateService->applyTemplateToAgent($agentKey, $templateId);
    }

    /**
     * Get a list of the current agent templates.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     * @return array Names and IDs of the templates
     */
    public function list(): array
    {
        $result = [];

        $templates = $this->agentTemplateService->getList();
        foreach ($templates as $template) {
            $result[] = [
                'id' => $template->getId(),
                'name' => $template->getName()
            ];
        }

        return $result;
    }
}
