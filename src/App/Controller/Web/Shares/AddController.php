<?php
namespace Datto\App\Controller\Web\Shares;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Asset\AssetType;
use Datto\Asset\LocalSettings;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\Retention;
use Datto\Asset\Share\ShareRepository;
use Datto\Billing\Service as BillingService;
use Datto\Common\Resource\Filesystem;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\Response;

class AddController extends AbstractBaseController
{
    private ShareRepository $shareRepository;
    private BillingService $billingService;

    public function __construct(
        NetworkService $networkService,
        ShareRepository $shareRepository,
        BillingService $billingService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->shareRepository = $shareRepository;
        $this->billingService = $billingService;
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_SHARES")
     * @Datto\App\Security\RequiresPermission("PERMISSION_SHARE_CREATE")
     *
     * @return Response
     */
    public function indexAction(): Response
    {
        $closeUrl = $this->hasShares() ?
            $this->generateUrl('shares_index') :
            $this->generateUrl('homepage');

        $isInfiniteRetention = $this->billingService->isInfiniteRetention();
        $isTimeBasedRetention = $this->billingService->isTimeBasedRetention();
        $hasInfiniteRetentionGracePeriodExpired = $this->billingService->hasInfiniteRetentionGracePeriodExpired();
        $offsiteRetentionDefaults = Retention::createApplicableDefault($this->billingService);

        return $this->render(
            'Shares/Add/index.html.twig',
            [
                'share' => [
                    'local' => [
                        'interval' => [
                            'minutes' => LocalSettings::DEFAULT_INTERVAL,
                            'default' => LocalSettings::DEFAULT_INTERVAL,
                        ],
                        'scheduleParameters' => [
                            'firstScheduleHour' => WeeklySchedule::FIRST_WEEKDAY_HOUR_DEFAULT,
                            'lastScheduleHour' => WeeklySchedule::LAST_WEEKDAY_HOUR_DEFAULT,
                            'isWeekendScheduleSame' => false,
                        ],
                        'retention' => [
                            'daily' => Retention::DEFAULT_DAILY,
                            'weekly' => Retention::DEFAULT_WEEKLY,
                            'monthly' => Retention::DEFAULT_MONTHLY,
                            'keep' => Retention::DEFAULT_MAXIMUM
                        ]
                    ],
                    'offsite' => [
                        'interval' => OffsiteSettings::DEFAULT_REPLICATION,
                        'retention' => [
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
                        ]
                    ]
                ],
                'urls' => [
                    'close' => $closeUrl,
                    'success' => $this->generateUrl('shares_add_success', [
                        'newShareName' => '-name-', // Placeholders replaced in javascript
                        'newShareKey' => '-key-'
                    ])
                ],
                'localOnly' => $this->billingService->isLocalOnly(),
            ]
        );
    }

    private function hasShares(): bool
    {
        return count($this->shareRepository->getAllNames(true, true, AssetType::SHARE)) > 0;
    }
}
