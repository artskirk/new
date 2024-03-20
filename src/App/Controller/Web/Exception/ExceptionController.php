<?php

namespace Datto\App\Controller\Web\Exception;

use Datto\App\Controller\Web\AbstractBaseController;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;

/**
 * Controller to handle and render exception/error pages.
 *
 * This page mimic's Symfony's TwigBundle ExceptionController,
 * except the specific error code pages must be handled. In
 * addition, this extends the base controller class to gain
 * access to the navigational menu.
 *
 * @author Andrew Cope <acope@datto.com>
 */
class ExceptionController extends AbstractBaseController
{
    /**
     * Handle the exception being thrown and display the relevant error page if needed
     *
     * This a special, internally called controller action and should not have annotations!
     *
     * Error code specific pages (e.g. 403, 404) should have the twig name form of
     * "error{code}.html.twig" and be handled in the switch statement.
     * Additional items may be passed to the twig template if needed, not all pages
     * will return all passed through data.
     *
     * @param FlattenException $exception
     * @param DebugLoggerInterface|null $logger
     * @return Response
     */
    public function showException(FlattenException $exception, DebugLoggerInterface $logger = null): Response
    {
        $code = $exception->getStatusCode();
        switch ($code) {
            case Response::HTTP_FORBIDDEN:
                // Display the Access denied page
                $template = 'Exception/error403.html.twig';
                break;
            default:
                // Display the generic error page
                $template = 'Exception/error.html.twig';
                break;
        }
        return $this->render(
            $template,
            [
                'status_code' => $code,
                'status_text' => Response::$statusTexts[$code] ?? '',
            ]
        );
    }

    /**
     * Display the access denied error page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_EXCEPTION")
     *
     * @return Response
     */
    public function deniedAction(): Response
    {
        return $this->render(
            'Exception/error403.html.twig'
        );
    }

    /**
     * Display the access denied due to encryption page.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_EXCEPTION")
     *
     * @return Response
     */
    public function deniedEncryptedAction(): Response
    {
        return $this->render(
            'Exception/error_encrypted.html.twig'
        );
    }
}
