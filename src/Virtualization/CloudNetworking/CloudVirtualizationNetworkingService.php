<?php

namespace Datto\Virtualization\CloudNetworking;

use Datto\Asset\UuidGenerator;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Util\RetryHandler;
use Datto\Utility\Network\DattoNetctl;
use Datto\Utility\Network\IpHelper;
use Datto\Utility\Network\IpInterface;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * Class CloudVirtualizationNetworkingService makes calls to datto-netctl executable
 * @author Jimi Ford <jford@datto.com>
 * @author Scott Ventura <sventura@datto.com>
 */
class CloudVirtualizationNetworkingService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const CREDENTIAL_FILE_NAMES = [
        'clientKey',
        'clientCertificate',
        'caCertificate'
    ];

    private IpHelper $ipHelper;
    private RetryHandler $retryHandler;
    private Filesystem $filesystem;
    private DattoNetctl $dattoNetctl;

    public function __construct(
        IpHelper $ipHelper,
        RetryHandler $retryHandler,
        Filesystem $filesystem,
        DattoNetctl $dattoNetctl
    ) {
        $this->ipHelper = $ipHelper;
        $this->retryHandler = $retryHandler;
        $this->filesystem = $filesystem;
        $this->dattoNetctl = $dattoNetctl;
    }

    public function connect(
        string $networkUuid,
        string $shortCode,
        string $parentFqdn,
        string $parentPort,
        string $credentialsPath
    ): void {
        if (!UuidGenerator::isUuid($networkUuid, true)) {
            throw new \InvalidArgumentException('networkUuid was not of the required format.');
        }
        $this->logger->info('CVN1000 Connecting to cloud network', [
            'shortCode' => $shortCode,
            'networkUuid' => $networkUuid
        ]);

        try {
            $this->connectToCloudNetwork($credentialsPath, $networkUuid, $shortCode, $parentFqdn, $parentPort);
            $tapInterface = $this->getTapInterface('tapnetctl' . $shortCode);
            $defaultInterface = $this->getDefaultBridgeInterface();
            $this->moveTapInterfaceToBridge($tapInterface, $defaultInterface);
        } finally {
            // datto-netctl copies the credentials into its own managed directory so clean up the old directory
            foreach (self::CREDENTIAL_FILE_NAMES as $credentialFileName) {
                $path = $this->filesystem->pathJoin($credentialsPath, $credentialFileName);
                $unlinked = $this->filesystem->unlink($path);
                if (!$unlinked) {
                    $this->logger->error('CVN0030 Error unlinking credentials file', ['path' => $path]);
                }
            }
            $removed = $this->filesystem->rmdir($credentialsPath);
            if (!$removed) {
                $this->logger->error('CVN0031 Error removing credentials directory', ['path' => $credentialsPath]);
            }
        }
    }

    public function disconnect(string $networkUuid): void
    {
        if (!UuidGenerator::isUuid($networkUuid, true)) {
            throw new \InvalidArgumentException('networkUuid was not of the required format.');
        }
        if ($this->dattoNetctl->networkExists($networkUuid)) {
            $this->logger->info('CVN1001 Disconnecting from cloud network', [
                'networkUuid' => $networkUuid
            ]);
            $this->dattoNetctl->stop($networkUuid);
            $this->dattoNetctl->delete($networkUuid);
        } else {
            $this->logger->info('CVN1002 Cloud network not found', [
                'networkUuid' => $networkUuid
            ]);
        }
    }

    private function connectToCloudNetwork(
        string $credentialsPath,
        string $networkUuid,
        string $shortCode,
        string $parentFqdn,
        string $parentPort
    ): void {
        $clientKeyPath = $this->filesystem->pathJoin($credentialsPath, 'clientKey');
        $clientCertificatePath = $this->filesystem->pathJoin($credentialsPath, 'clientCertificate');
        $caCertificatePath = $this->filesystem->pathJoin($credentialsPath, 'caCertificate');
        $dattoNetctlArguments = [
            'uuid' => $networkUuid,
            'shortCode' => $shortCode,
            'parentFqdn' => $parentFqdn,
            'port' => $parentPort
        ];
        $this->logger->info('CVN0001 datto-netctl network:client:external:connect invocation starting...', [
            'arguments' => $dattoNetctlArguments
        ]);

        $this->dattoNetctl->connect(
            $networkUuid,
            $shortCode,
            $parentFqdn,
            $parentPort,
            $clientKeyPath,
            $clientCertificatePath,
            $caCertificatePath
        );
    }

    private function getDefaultBridgeInterface(): IpInterface
    {
        $defaultRoutes = array_filter($this->ipHelper->getRoutes(), function ($r) {
            $interface = $this->ipHelper->getInterface($r->getInterface());
            return $interface !== null && $r->isDefault() && $interface->isUp();
        });
        if (count($defaultRoutes) !== 1) {
            $this->logger->error('CVN0006 unexpected number of default routes', [
                'defaultRoutes' => $defaultRoutes
            ]);
            throw new Exception('Unexpected number of default routes');
        }
        $defaultRoute = $defaultRoutes[0];
        $defaultInterface = $this->ipHelper->getInterface($defaultRoute->getInterface());

        if ($defaultInterface->isBridge() === false) {
            $this->logger->error('CVN0007 default interface is not a bridge interface', [
                'defaultInterface' => $defaultInterface
            ]);
            throw new Exception('Default interface is not a bridge interface');
        }
        return $defaultInterface;
    }

    private function getTapInterface(string $tapInterfaceName): IpInterface
    {
        return $this->retryHandler->executeAllowRetry(function () use ($tapInterfaceName) {
            $tapInterface = $this->ipHelper->getInterface($tapInterfaceName);
            if (is_null($tapInterface)) {
                throw new Exception('CVN0005 tap interface does not exist: "' . $tapInterfaceName . '"');
            }
            return $tapInterface;
        });
    }

    private function moveTapInterfaceToBridge(IpInterface $tapInterface, IpInterface $defaultInterface): void
    {
        $context = [
            'tapInterface' => $tapInterface->getName(),
            'defaultInterface' => $defaultInterface->getName(),
            'tapInterfaceMemberOf' => $tapInterface->getMemberOf()
        ];
        if ($tapInterface->getMemberOf() !== $defaultInterface->getName()) {
            $this->logger->info('CVN0009 linking tap interface to default bridge', $context);

            $tapInterface->setDown();
            $tapInterface->removeFromBridge();
            $tapInterface->setUp();
            $tapInterface->addToBridge($defaultInterface->getName());
        } else {
            $this->logger->info('CVN0010 tap interface is already linked to default bridge', $context);
        }
    }
}
