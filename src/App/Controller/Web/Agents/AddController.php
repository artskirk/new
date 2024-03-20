<?php

namespace Datto\App\Controller\Web\Agents;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\Retention;
use Datto\Asset\VerificationSchedule;
use Datto\Billing\Service as BillingService;
use Datto\Billing\ServicePlanService;
use Datto\Common\Resource\Filesystem;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;
use Datto\License\AgentLimit;

/**
 * Controller for add agent page.
 *
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class AddController extends AbstractBaseController
{
    private AgentService $agentService;
    private DeviceConfig $deviceConfig;
    private BillingService $billingService;
    private ServicePlanService $servicePlanService;
    private AgentLimit $agentLimit;
    private FeatureService $featureService;

    public function __construct(
        NetworkService $networkService,
        AgentService $agentService,
        DeviceConfig $deviceConfig,
        BillingService $billingService,
        ServicePlanService $servicePlanService,
        AgentLimit $agentLimit,
        FeatureService $featureService,
        Filesystem $filesystem,
        ClfService $clfService
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->agentService = $agentService;
        $this->deviceConfig = $deviceConfig;
        $this->billingService = $billingService;
        $this->servicePlanService = $servicePlanService;
        $this->agentLimit = $agentLimit;
        $this->featureService = $featureService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_CREATE")
     *
     * @return Response
     */
    public function indexAction()
    {
        $isInfiniteRetention = $this->billingService->isInfiniteRetention();
        $isTimeBasedRetention = $this->billingService->isTimeBasedRetention();

        $agents = $this->agentService->getAll();
        $pairedCount = count($agents);
        $maxAdditionalAgent = $this->agentLimit->getMaxAdditionalAgents();
        $maxAdditionalAgents = $maxAdditionalAgent < AgentLimit::UNLIMITED ? $maxAdditionalAgent : -1;
        $isFree = $this->servicePlanService->get()->getServicePlanName() === ServicePlanService::PLAN_TYPE_FREE;
        $hasInfiniteRetentionGracePeriodExpired = $this->billingService->hasInfiniteRetentionGracePeriodExpired();
        $offsiteRetentionDefaults = Retention::createApplicableDefault($this->billingService);

        return $this->render(
            'Agents/Add/index.html.twig',
            array(
                /* Used by "Assets/Configure/local.backup.html.twig" */
                'agent' => array(
                    'local' => array(
                        'interval' => array(
                            'minutes' => LocalSettings::DEFAULT_INTERVAL,
                            'default' => LocalSettings::DEFAULT_INTERVAL,
                        ),
                        'scheduleParameters' => array(
                            'firstScheduleHour' => WeeklySchedule::FIRST_WEEKDAY_HOUR_DEFAULT,
                            'lastScheduleHour' => WeeklySchedule::LAST_WEEKDAY_HOUR_DEFAULT,
                            'isWeekendScheduleSame' => false,
                        ),
                        'retention' => array(
                            'daily' => $isFree ? Retention::FREE_DAILY : Retention::DEFAULT_DAILY,
                            'weekly' => $isFree ? Retention::FREE_WEEKLY : Retention::DEFAULT_WEEKLY,
                            'monthly' => $isFree ? Retention::FREE_MONTHLY : Retention::DEFAULT_MONTHLY,
                            'keep' => $isFree ? Retention::FREE_MAXIMUM : Retention::DEFAULT_MAXIMUM
                        )
                    ),
                    'offsite' => array(
                        'interval' => OffsiteSettings::DEFAULT_REPLICATION,
                        'retention' => array(
                            'isInfiniteRetention' => $isInfiniteRetention,
                            'isTimeBasedRetention' => $isTimeBasedRetention,
                            'hasInfiniteRetentionGracePeriodExpired' => $hasInfiniteRetentionGracePeriodExpired,
                            'daily' => $offsiteRetentionDefaults->getDaily(),
                            'weekly' => $offsiteRetentionDefaults->getWeekly(),
                            'monthly' => $offsiteRetentionDefaults->getMonthly(),
                            'keep' => $offsiteRetentionDefaults->getMaximum(),
                            'defaults' => [
                                'daily' => $offsiteRetentionDefaults->getDaily(),
                                'weekly' => $offsiteRetentionDefaults->getWeekly(),
                                'monthly' => $offsiteRetentionDefaults->getMonthly(),
                                'keep' => $offsiteRetentionDefaults->getMaximum()
                            ]
                        )
                    ),
                    'screenshot' => array(
                        'options' => array(
                            'first' => VerificationSchedule::FIRST_POINT,
                            'last' => VerificationSchedule::LAST_POINT,
                            'offsite' => VerificationSchedule::OFFSITE
                        ),
                    ),
                ),
                'closeUrl' => $this->generateUrl($pairedCount > 0 ? 'agents_index' : 'homepage'),
                'successUrl' => $this->generateUrl('agents_index'),
                'addHypervisorWizardUrl' => $this->generateUrl(
                    'connections_add',
                    array('onCloseRoute' => 'agents_add')
                ),
                'systems' => $this->getPairedSystems($agents),
                'isSirisLite' => $this->deviceConfig->isSirisLite(),
                'canOffsite' => $this->featureService->isSupported(FeatureService::FEATURE_OFFSITE),
                'maxAdditionalAgents' => $maxAdditionalAgents,
                'copyCloudRetentionFromTemplate' => !$isTimeBasedRetention,
            )
        );
    }

    /**
     * @param Agent[] $agents
     * @return array
     */
    private function getPairedSystems(array $agents)
    {
        $systemList = [];
        foreach ($agents as $agent) {
            if ($agent->getOriginDevice()->isReplicated()) {
                continue; // replicated agents can't be used as templates
            }

            $systemList[] = [
                'keyName' => $agent->getKeyName(),
                'name' => $agent->getHostname()
            ];
        }
        return $systemList;
    }
}
