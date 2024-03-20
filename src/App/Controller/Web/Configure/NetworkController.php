<?php

namespace Datto\App\Controller\Web\Configure;

use Datto\App\Controller\Web\AbstractBaseController;
use Datto\Common\Resource\Filesystem;
use Datto\Core\Network\WindowsDomain;
use Datto\Feature\FeatureService;
use Datto\Service\Device\ClfService;
use Datto\Service\Networking\LinkService;
use Datto\Service\Networking\NetworkService;

/**
 * Handles requests to the network config page
 */
class NetworkController extends AbstractBaseController
{
    private LinkService $linkService;
    private FeatureService $featureService;
    private WindowsDomain $windowsDomain;

    public function __construct(
        NetworkService $networkService,
        LinkService $linkService,
        FeatureService $featureService,
        WindowsDomain $windowsDomain,
        ClfService $clfService,
        Filesystem $filesystem
    ) {
        parent::__construct($networkService, $clfService, $filesystem);

        $this->linkService = $linkService;
        $this->featureService = $featureService;
        $this->windowsDomain = $windowsDomain;
    }

    /**
     * Render the main index page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_NETWORK_WRITE")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        return $this->render(
            'Configure/Network/index.html.twig',
            array_merge_recursive(
                $this->getConnectivityStatus(),
                $this->getNetworkLinks(),
                $this->getDnsSettings(),
                $this->getHostnameSettings(),
                $this->getWindowsNetworkingSettings(),
                $this->getIpmiSettings()
            )
        );
    }

    protected function getConnectivityStatus(): array
    {
        return array(
            'connectivity' => $this->networkService->getConnectivityStatus()
        );
    }

    /**
     * Gets the array of all network links sorted by network name using a
     * natural sort algorithm.  The natural sort ensures that VLAN names
     * appear in the correct order when displayed to the user
     * (e.g. "eth0.2" before "eth0.10").
     *
     * @return array
     */
    protected function getNetworkLinks()
    {
        $links = $this->linkService->getLinks();

        usort($links, function ($a, $b) {
            return strnatcasecmp($a->getName(), $b->getName());
        });
        return array(
            'links' => json_decode(json_encode($links), true)
        );
    }

    protected function getDnsSettings(): array
    {
        $dnsInfo = $this->networkService->getGlobalDns();
        $dnsInfo['nameservers'] = array_pad($dnsInfo['nameservers'], 3, null);
        $dnsInfo['search'] = implode(' ', $dnsInfo['search']);

        return array(
            'dns' => $dnsInfo
        );
    }
    /**
     * Getting hostname and server ip address
     * @psalm-suppress PossiblyUndefinedArrayOffset
     */
    protected function getHostnameSettings(): array
    {
        return [
            'hostname' => $this->networkService->getHostname(),
            'serverip' => $_SERVER['SERVER_ADDR']
        ];
    }

    protected function getWindowsNetworkingSettings(): array
    {
        $domain = $this->windowsDomain->getDomain();
        $mode = is_null($domain) ? 'workgroup' : 'domain';
        return array(
            'windows' => [
                'mode' => $mode,
                'domain' => $domain
            ],
            'workgroup' => $this->windowsDomain->getWorkgroup()
        );
    }

    protected function getIpmiSettings(): array
    {
        return array(
            'ipmi' => array (
                'showIpmi' => $this->featureService->isSupported(FeatureService::FEATURE_IPMI),
            )
        );
    }
}
