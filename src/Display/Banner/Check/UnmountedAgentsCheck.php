<?php

namespace Datto\Display\Banner\Check;

use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Display\Banner\Banner;
use Datto\Display\Banner\ClfBanner;
use Datto\Display\Banner\Context;
use Datto\Service\Device\ClfService;
use Datto\ZFS\ZfsDatasetService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Display a banner that shows agents whose datasets are currently unmounted
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class UnmountedAgentsCheck extends Check
{
    private AgentConfigFactory $agentConfigFactory;
    private ZfsDatasetService $zfsDatasetService;

    public function __construct(
        Environment $twig,
        AgentConfigFactory $agentConfigFactory,
        ZfsDatasetService $zfsDatasetService,
        ClfService $clfService,
        TranslatorInterface $translator
    ) {
        parent::__construct($twig, $clfService, $translator);
        $this->agentConfigFactory = $agentConfigFactory;
        $this->zfsDatasetService = $zfsDatasetService;
    }

    public function getId(): string
    {
        return $this->clf ? 'bannerUnmountedAgents' : 'banner-unmounted-agents';
    }

    public function check(Context $context): ?Banner
    {
        $route = basename($context->getUri());
        if ($route !== 'agents') {
            return null;
        }

        $keyNames = $this->agentConfigFactory->getAllKeyNames();
        $baseAgentDatasetsMounted = $this->zfsDatasetService->areBaseAgentDatasetsMounted();

        foreach ($keyNames as $keyName) {
            $agentConfig = $this->agentConfigFactory->create($keyName);

            // Until replicated assets receive their first offsite point, speedsync doesn't
            // create the zfs dataset. This means the volume being unmounted is expected behavior.
            if ($agentConfig->isShare() ||
                ($agentConfig->isReplicated() && !($this->hasRecoveryPoints($agentConfig) && $this->hasDataset($keyName)))
            ) {
                continue;
            }

            if (!$baseAgentDatasetsMounted || !$this->zfsDatasetService->isAgentDatasetMounted($keyName)) {
                $unmountedAgents[] = $agentConfig->getFullyQualifiedDomainName();
            }
        }

        if (!isset($unmountedAgents)) {
            return null;
        }

        return $this->danger(
            'Banners/Agent/unmounted.html.twig',
            $this->clf ? $this->getUnmountedAgentsCheckBanner($unmountedAgents)->toArray() : ['unmountedAgents' => $unmountedAgents],
            Banner::CLOSE_SESSION
        );
    }

    private function hasDataset(string $keyName): bool
    {
        return $this->zfsDatasetService->exists(ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET . '/' . $keyName);
    }

    private function hasRecoveryPoints(AgentConfig $agentConfig): bool
    {
        return count(array_filter(explode(PHP_EOL, $agentConfig->get('recoveryPoints')))) > 0;
    }

    private function getUnmountedAgentsCheckBanner(array $unmountedAgents): ClfBanner
    {
        return $this->getBaseBanner(ClfBanner::TYPE_ERROR)
            ->setMessageTitle($this->translator->trans('agents.list.warning'))
            ->setMessageText(
                $this->translator->trans('agents.list.volumes.are.unmounted') .
                implode(', ', $unmountedAgents) .
                $this->translator->trans('agents.list.contact.support')
            )
            ->setIsDismissible(true);
    }
}
