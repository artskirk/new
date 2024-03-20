<?php

namespace Datto\App\Security;

use Datto\JsonRpc\Validator\Validate;
use Datto\Log\LoggerFactory;
use Doctrine\Common\Annotations\Reader;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Looks for controller methods that have parameter validation via the Datto\JsonRpc\Validation\Validate annotation
 * and executes the validators. Validation that passes is allowed to continue while an exception is thrown for
 * failed validation.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class ConstraintValidatorListener implements EventSubscriberInterface
{
    const INVALID_PARAMS_CODE = -32602;

    /** @var ArgumentResolver */
    private $argumentResolver;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Reader */
    private $reader;

    public function __construct(ArgumentResolverInterface $argumentResolver, Reader $reader)
    {
        $this->argumentResolver = $argumentResolver;
        $this->logger = LoggerFactory::getDeviceLogger();
        $this->reader = $reader;
    }

    /**
     * Inspect the request, if the controller action has validation annotations, use them to validate the parameters.
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        if (!is_array($controller)) {
            return; // not a controller/action pair
        }

        try {
            /** @var Validate $validateAnnotation */
            $reflectMethod = new ReflectionMethod($controller[0], $controller[1]);
            $validateAnnotation = $this->reader->getMethodAnnotation($reflectMethod, Validate::class);
        } catch (Exception $e) {
            $this->logger->error('VAL0001 Error in annotation.', ['error' => $e->getMessage(), 'stacktrace' => $e->getTraceAsString()]);
            throw $e;
        }

        if ($validateAnnotation) {
            $arguments = $this->argumentResolver->getArguments($event->getRequest(), $event->getController());
            $this->validateArguments($arguments, $validateAnnotation, $reflectMethod);
        }
    }

    /**
     * Validates each method arguments against the constraints specified
     * in the Validator\Validate annotation.
     *
     * @param array $filledArguments Positional array of all arguments (including not provided optional arguments)
     * @param Validate $validateAnnotation Annotation containing the argument constraints
     * @param ReflectionMethod $reflectMethod Reflection method to be used to retrieve parameters
     */
    private function validateArguments(
        array $filledArguments,
        Validate $validateAnnotation,
        ReflectionMethod $reflectMethod
    ): void {
        $validator = Validation::createValidatorBuilder()->getValidator();
        foreach ($reflectMethod->getParameters() as $param) {
            $hasConstraints = isset($validateAnnotation->fields, $validateAnnotation->fields[$param->getName()]);
            if ($hasConstraints) {
                $value = $filledArguments[$param->getPosition()];
                $name = $param->getName();
                $constraints = $validateAnnotation->fields[$name]->constraints;
                $this->validateValue($reflectMethod, $name, $value, $constraints, $validator);
            }
        }
    }

    /**
     * Validate a single value using the given constraints array and validator. If any
     * of the constraints are violated, an exception is thrown.
     *
     * @param ReflectionMethod $reflectMethods
     * @param string $name the parameter name
     * @param mixed $value Argument value
     * @param Constraint[] $constraints List of constraints for the given argument
     * @param ValidatorInterface $validator Validator to be used for validation
     */
    private function validateValue(
        ReflectionMethod $reflectMethod,
        string $name,
        $value,
        array $constraints,
        ValidatorInterface $validator
    ): void {
        $violations = $validator->validate($value, $constraints);

        if (count($violations) > 0) {
            $method = $reflectMethod->class . '->' . $reflectMethod->name . '()';

            /** @var ConstraintViolationInterface $violation */
            foreach ($violations as $violation) {
                $this->logger->error('VAL0002 Constraint Violated for parameter', ['parameter' => $name, 'method' => $method, 'valuePassed' => $value, 'error' => $violation->getMessage()]);
            }

            throw new Exception('Invalid params', self::INVALID_PARAMS_CODE);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => [['onKernelController', 32]]];
    }
}
