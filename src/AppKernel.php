<?php

namespace Datto;

use Datto\App\Container\ServiceCollectionCompilerPass;
use Datto\JsonRpc\Routing\JsonRpcRouteCompilerPass;
use Datto\Config\DeviceConfig;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Central entry point for the Symfony application, responsible for
 * the discovery and registration of bundles.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class AppKernel extends Kernel
{
    use MicroKernelTrait;

    const CONFIG_EXTS = '.{php,yaml}';

    const DEFAULT_CACHE_DIR_FORMAT = '/var/cache/datto/device/%s';
    const DEFAULT_LOG_DIR = '/var/log/datto/';

    private string $appDir;
    private string $logDir;
    private string $cacheDir;
    private static ?AppKernel $instance = null;

    /**
     * Create instance of the application kernel
     * @param string $env Environment (dev or prod)
     * @param bool $debug Whether debug is enabled
     * @param string $logDir The log directory
     * @param string $cacheDir The cache directory
     */
    public function __construct(
        string $env,
        bool $debug,
        string $logDir = self::DEFAULT_LOG_DIR,
        string $cacheDir = self::DEFAULT_CACHE_DIR_FORMAT
    ) {
        parent::__construct($env, $debug);

        $this->appDir = $this->getAppDir();
        $this->logDir = $logDir;
        $this->cacheDir = sprintf($cacheDir, $env);
    }

    /**
     * ==== THIS IS A HACK ====
     *
     * We override this with a hardcoded string because symfony's Kernel.php implementation of this function
     * does not play nicely with our spencer cube code obfuscation.
     *
     * The symfony implementation tries to determine the project directory by looking at where this file is located.
     * It uses reflection to determine the file name, but because of our obfuscation, the string returned is
     * "/usr/lib/datto/device/src/App/AppKernel.php(2) : eval()'d code". Note the garbage at the end of the string.
     * It then throws an exception and refuses to continue because the file does not exist.
     *
     * @return string Our hardcoded os2 project directory
     */
    public function getProjectDir(): string
    {
        return '/usr/lib/datto/device';
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new JsonRpcRouteCompilerPass());
        $container->addCompilerPass(new ServiceCollectionCompilerPass());
    }

    /**
     * Overrides the Symfony log directory
     * @return string Log dir
     */
    public function getLogDir(): string
    {
        return $this->logDir;
    }

    /**
     * Overrides the Symfony cache directory
     * @return string Cache dir
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Returns the application directory.
     *
     * @return string
     */
    protected function getAppDir(): string
    {
        return __DIR__;
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->addResource(new FileResource($this->getProjectDir().'/config/bundles.php'));
        $container->setParameter('container.dumper.inline_class_loader', \PHP_VERSION_ID < 70400 || $this->debug);
        $container->setParameter('container.dumper.inline_factories', true);
        $confDir = $this->getProjectDir().'/config';

        $loader->load($confDir.'/{packages}/*'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{packages}/'.$this->environment.'/*'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{services}'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{services}_'.$this->environment.self::CONFIG_EXTS, 'glob');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $confDir = $this->getProjectDir().'/config';

        $routes->import($confDir.'/{routes}/'.$this->environment.'/*'.self::CONFIG_EXTS, 'glob');
        $routes->import($confDir.'/{routes}/*'.self::CONFIG_EXTS, 'glob');
        $routes->import($confDir.'/{routes}'.self::CONFIG_EXTS, 'glob');
    }

    /**
     * Gets an instance of a booted AppKernel
     *
     * @param DeviceConfig|null $config
     * @return AppKernel
     */
    public static function getBootedInstance(DeviceConfig $config = null): AppKernel
    {
        static::assertNotRunningInUnitTest();

        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = $config ?: new DeviceConfig();

        // Read environment and debug from command line arguments (overrides config)
        $argv = $_SERVER['argv'] ?? [];
        $input = new ArgvInput($argv);

        $env = $config->get('env', 'prod');
        $env = $input->getParameterOption(['--env', '-e'], $env);
        $debug = $config->has('showDebug') || $env === 'dev';

        if ($input->hasParameterOption(['--no-debug', ''])) {
            $debug = false;
        }

        if ($debug) {
            Debug::enable();
        }

        self::$instance = new AppKernel($env, $debug);
        self::$instance->boot();

        return self::$instance;
    }

    public static function isRunningUnitTests(): bool
    {
        // Since the PHPUNIT_TESTSUITE constant can only be defined globally for
        // phpunit runs, we use the presence of the deviceID file to
        // differentiate between unit and integration tests.
        return defined('PHPUNIT_TESTSUITE') &&
               PHPUNIT_TESTSUITE === true &&
               !file_exists('/datto/config/deviceID');
    }

    public function isDevMode(): bool
    {
        return $this->environment === 'dev';
    }

    /**
     * We don't want to start an app kernel within unit tests.
     * If this function throws an exception, it usually means that we've forgotten to mock a dependency and some
     * class is trying to fetch it from the symfony container. We want to fail fast when that happens so there's
     * no chance of the unit test succeeding by chance and not alerting us to broken code.
     */
    private static function assertNotRunningInUnitTest(): void
    {
        if (self::isRunningUnitTests()) {
            throw new \Exception('Attempting to use the symfony container in unit tests.');
        }
    }
}
