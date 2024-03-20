<?php

namespace Datto\App\Controller;

use Datto\Agentless\Proxy\AgentlessBackupService;
use Datto\Agentless\Proxy\AgentlessSessionId;
use Datto\Agentless\Proxy\AgentlessSessionService;
use Datto\Agentless\Proxy\Exceptions\SessionBusyException;
use Datto\Agentless\Proxy\Exceptions\SessionNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restful API controller for the agentless proxy.
 *
 * This API mimics a physical Datto agent but since the proxy may be responsible for backing up
 * more than one virtual machine, it has been extended to include an "agentless session".
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessProxyController extends AbstractController
{
    private const AGENTLESS_SESSION_ID_HEADER = 'X-AGENTLESS-SESSION-ID';

    private AgentlessSessionService $agentlessSessionService;
    private AgentlessBackupService $agentlessBackupService;

    public function __construct(
        AgentlessSessionService $agentlessSessionService,
        AgentlessBackupService $agentlessBackupService
    ) {
        $this->agentlessSessionService = $agentlessSessionService;
        $this->agentlessBackupService = $agentlessBackupService;
    }

    /**
     * GET /api/v1/agentless/esx/host
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     */
    public function hostAction(Request $request): Response
    {
        $agentlessSessionId = $request->headers->get(self::AGENTLESS_SESSION_ID_HEADER);
        if (!$agentlessSessionId) {
            return new Response(self::AGENTLESS_SESSION_ID_HEADER . " header missing", 400);
        }

        try {
            $sessionId = AgentlessSessionId::fromString($agentlessSessionId);
            $agentlessSession = $this->agentlessSessionService->getSession($sessionId);
        } catch (SessionNotFoundException $exception) {
            return new Response($exception->getMessage(), 404);
        } catch (SessionBusyException $exception) {
            return new Response($exception->getMessage(), 503);
        } catch (\Throwable $exception) {
            return new Response($exception->getMessage(), 500);
        }

        $agentInfo = $agentlessSession->getAgentVmInfo();
        $agentInfo['esxInfo'] = $agentlessSession->getEsxVmInfo();

        return JsonResponse::fromJsonString(json_encode($agentInfo, JSON_PRETTY_PRINT));
    }

    /**
     * POST /api/v1/agentless/esx/backup
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     */
    public function startBackupAction(Request $request): Response
    {
        $agentlessSessionId = $request->headers->get(self::AGENTLESS_SESSION_ID_HEADER);
        if (!$agentlessSessionId) {
            return new Response(self::AGENTLESS_SESSION_ID_HEADER . " header missing", 400);
        }

        $content = json_decode($request->getContent(), true);

        $volumeGuids = [];
        $destinationFiles = [];
        $changeIdFiles = [];
        $forceDiffMerge = $content['forceDiffMerge'] ?? false;
        $forceFull = $content['cacheWrites'] ?? false;

        $volumes = $content['volumes'];
        foreach ($volumes as $volume) {
            $volumeGuids[] = $volume['guid'];
            $destinationFiles[] = $volume['lun'];
            $changeIdFiles[] = $volume['lunChecksum'];
        }

        try {
            $sessionId = AgentlessSessionId::fromString($agentlessSessionId);
            $jobId = $this->agentlessBackupService->takeBackupBackground(
                $sessionId,
                $volumeGuids,
                $destinationFiles,
                $changeIdFiles,
                $forceDiffMerge,
                $forceFull
            );
        } catch (SessionNotFoundException $exception) {
            return new Response($exception->getMessage(), 404);
        } catch (SessionBusyException $exception) {
            return new Response($exception->getMessage(), 503);
        } catch (\Throwable $exception) {
            return new Response($exception->getMessage(), 500);
        }

        return new Response($jobId, 201);
    }

    /**
     * GET /api/v1/agentless/esx/backup/<jobId>
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     */
    public function getBackupStatusAction(Request $request, string $jobId): Response
    {
        $agentlessSessionId = $request->headers->get(self::AGENTLESS_SESSION_ID_HEADER);
        if (!$agentlessSessionId) {
            return new Response(self::AGENTLESS_SESSION_ID_HEADER . " header missing", 400);
        }

        try {
            $sessionId = AgentlessSessionId::fromString($agentlessSessionId);
            $status = $this->agentlessBackupService->getBackupStatus($sessionId, $jobId);
        } catch (SessionNotFoundException $exception) {
            return new Response($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return new Response($exception->getMessage(), 500);
        }

        return new JsonResponse($status);
    }

    /**
     * DELETE /api/v1/agentless/esx/backup/<jobId>
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     */
    public function cancelBackupAction(Request $request, string $jobId): Response
    {
        $agentlessSessionId = $request->headers->get(self::AGENTLESS_SESSION_ID_HEADER);
        if (!$agentlessSessionId) {
            return new Response(self::AGENTLESS_SESSION_ID_HEADER . " header missing", 400);
        }

        try {
            $sessionId = AgentlessSessionId::fromString($agentlessSessionId);
            $this->agentlessBackupService->cancelBackup($sessionId, $jobId);
        } catch (SessionNotFoundException $exception) {
            return new JsonResponse(["success" => false, "errorString" => $exception->getMessage()], 404);
        } catch (\Throwable $exception) {
            return new JsonResponse(["success" => false, "errorString" => $exception->getMessage()]);
        }

        return new JsonResponse(["success" => true, "errorString" => ""]);
    }

    /**
     * POST /api/v1/agentless/esx/sessions
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     */
    public function createSessionAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true);

        $host = $content['host'];
        $user = $content['user'];
        $password = $content['password'];
        $vmName = $content['vmName'];
        $agentlessKeyName = $content['agentlessKeyName'];
        $forceNbd = $content['forceNbd'] ?? false;
        $fullDiskBackup = $content['fullDiskBackup'] ?? false;

        try {
            $agentlessSessionId =
                $this->agentlessSessionService->createAgentlessSessionBackground(
                    $host,
                    $user,
                    $password,
                    $vmName,
                    $agentlessKeyName,
                    $forceNbd,
                    $fullDiskBackup
                );
        } catch (\Throwable $exception) {
            //A session initialization start should be always possible regardless any circumstance and prior state.
            return new Response($exception->getMessage(), 500);
        }

        return new Response($agentlessSessionId);
    }

    /**
     * GET /api/v1/agentless/esx/sessions/<sessionId>
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_READ")
     */
    public function getSessionStatus(string $agentlessSessionId): Response
    {
        try {
            $sessionId = AgentlessSessionId::fromString($agentlessSessionId);
            $sessionStatus = $this->agentlessSessionService->getSessionStatus($sessionId);

            return new JsonResponse($sessionStatus);
        } catch (\Throwable $exception) {
            return new Response($exception->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/agentless/esx/sessions/<agentlessSessionId>
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     */
    public function cleanupSessionAction(string $agentlessSessionId): Response
    {
        try {
            $sessionId = AgentlessSessionId::fromString($agentlessSessionId);
            $this->agentlessSessionService->cleanupSessionInBackground($sessionId);
            return new Response('', 204);
        } catch (\Throwable $exception) {
            return new Response($exception->getMessage(), 500);
        }
    }
}
