<?php

namespace Datto\App\Controller\Web\Advanced;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\License\KrollService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\NetworkService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Controller to render the Kroll Granular Restore page on the device.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class KrollController extends AbstractBaseController
{
    const KROLL_DOWNLOADS_EXCHANGE_LINK = 'https://dat.to/ontrackexchange';
    const KROLL_DOWNLOADS_EXCHANGE_USER_GUIDE_LINK = 'https://download.ontrack.com/downloads/OPCEX_Manual.pdf';
    const KROLL_DOWNLOADS_SHAREPOINT_USER_GUIDE_LINK = 'https://download.ontrack.com/downloads/OPCSP_Manual.pdf';
    const KROLL_DOWNLOADS_SQL_SERVER_USER_GUIDE_LINK = 'https://download.ontrack.com/downloads/OPCSQL_Manual.pdf';

    private KrollService $krollService;

    public function __construct(
        NetworkService $networkService,
        KrollService $krollService,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->krollService = $krollService;
    }

    /**
     * Render Kroll Granular Restore page
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_GRANULAR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_GRANULAR_WRITE")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        return $this->render(
            'License/index.kroll.html.twig',
            [
                'krollDownloadsExchangeLink' => self::KROLL_DOWNLOADS_EXCHANGE_LINK,
                'krollDownloadsExchangeUserGuideLink' => self::KROLL_DOWNLOADS_EXCHANGE_USER_GUIDE_LINK,
                'krollDownloadsSharepointUserGuideLink' => self::KROLL_DOWNLOADS_SHAREPOINT_USER_GUIDE_LINK,
                'krollDownloadsSqlServerUserGuideLink' => self::KROLL_DOWNLOADS_SQL_SERVER_USER_GUIDE_LINK
            ]
        );
    }

    /**
     * Serve the Exchange/SharePoint/SQL Server Kroll license from the device. Redirects to the Kroll Granular Restore page if
     * there is no license file.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_RESTORE_GRANULAR")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_GRANULAR_WRITE")
     * @return BinaryFileResponse|RedirectResponse
     */
    public function downloadLicenseAction(): Response
    {
        $filePath = $this->krollService->getLicensePath();

        if ($filePath) {
            $response = new BinaryFileResponse($filePath);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        } else {
            $response = $this->redirectToRoute('advanced_kroll');
        }

        return $response;
    }
}
