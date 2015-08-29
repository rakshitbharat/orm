<?php

namespace LaravelDoctrine\ORM;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Persistence\Proxy;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\ServiceProvider;
use LaravelDoctrine\ORM\Auth\DoctrineUserProvider;
use LaravelDoctrine\ORM\Configuration\Cache\CacheManager;
use LaravelDoctrine\ORM\Configuration\Connections\ConnectionManager;
use LaravelDoctrine\ORM\Configuration\CustomTypeManager;
use LaravelDoctrine\ORM\Configuration\MetaData\MetaDataManager;
use LaravelDoctrine\ORM\Console\ClearMetadataCacheCommand;
use LaravelDoctrine\ORM\Console\ClearQueryCacheCommand;
use LaravelDoctrine\ORM\Console\ClearResultCacheCommand;
use LaravelDoctrine\ORM\Console\ConvertConfigCommand;
use LaravelDoctrine\ORM\Console\EnsureProductionSettingsCommand;
use LaravelDoctrine\ORM\Console\GenerateProxiesCommand;
use LaravelDoctrine\ORM\Console\InfoCommand;
use LaravelDoctrine\ORM\Console\SchemaCreateCommand;
use LaravelDoctrine\ORM\Console\SchemaDropCommand;
use LaravelDoctrine\ORM\Console\SchemaUpdateCommand;
use LaravelDoctrine\ORM\Console\SchemaValidateCommand;
use LaravelDoctrine\ORM\Exceptions\ExtensionNotFound;
use LaravelDoctrine\ORM\Extensions\DriverChain;
use LaravelDoctrine\ORM\Extensions\ExtensionManager;
use LaravelDoctrine\ORM\Validation\DoctrinePresenceVerifier;

class DoctrineServiceProvider extends ServiceProvider
{
    /**
     * @var array
     */
    protected $config;

    /**
     * Indicates if loading of the provider is deferred.
     * @var bool
     */
    protected $defer = true;

    /**
     * Boot service provider.
     */
    public function boot()
    {
        // Boot the extension manager
        $this->app->make(ExtensionManager::class)->boot();

        $this->extendAuthManager();

        $this->publishes([
            $this->getConfigPath() => config_path('doctrine.php'),
        ], 'config');
    }

    /**
     * Register the service provider.
     * @return void
     */
    public function register()
    {
        $this->setupCache();
        $this->mergeConfig();
        $this->setupMetaData();
        $this->setupConnection();
        $this->registerManagerRegistry();
        $this->registerEntityManager();
        $this->registerClassMetaDataFactory();
        $this->registerDriverChain();
        $this->registerExtensions();
        $this->registerPresenceVerifier();
        $this->registerConsoleCommands();
        $this->registerCustomTypes();
    }

    /**
     * Merge config
     */
    protected function mergeConfig()
    {
        $this->mergeConfigFrom(
            $this->getConfigPath(), 'doctrine'
        );
    }

    /**
     * Setup the entity managers
     * @return array
     */
    protected function setUpEntityManagers()
    {
        $managers    = [];
        $connections = [];

        foreach ($this->app->config->get('doctrine.managers', []) as $manager => $settings) {
            $managerName    = IlluminateRegistry::getManagerNamePrefix() . $manager;
            $connectionName = IlluminateRegistry::getConnectionNamePrefix() . $manager;

            // Bind manager
            $this->app->singleton($managerName, function ($app) use ($settings) {
                return $app->make(EntityManagerFactory::class)->create($settings);
            });

            // Bind connection
            $this->app->singleton($connectionName, function ($app) use ($managerName) {
                $app->make($managerName)->getConnection();
            });

            $managers[$manager]    = $manager;
            $connections[$manager] = $manager;
        }

        return [$managers, $connections];
    }

    /**
     * Setup the entity manager
     */
    protected function registerEntityManager()
    {
        // Bind the default Entity Manager
        $this->app->singleton('em', function ($app) {
            return $app->make(ManagerRegistry::class)->getManager();
        });

        $this->app->alias('em', EntityManager::class);
        $this->app->alias('em', EntityManagerInterface::class);
    }

    /**
     * Register the manager registry
     */
    protected function registerManagerRegistry()
    {
        $this->app->singleton(IlluminateRegistry::class, function ($app) {

            list($managers, $connections) = $this->setUpEntityManagers();

            return new IlluminateRegistry(
                isset($managers['default']) ? $managers['default'] : head($managers),
                $connections,
                $managers,
                isset($connections['default']) ? $connections['default'] : head($connections),
                isset($managers['default']) ? $managers['default'] : head($managers),
                Proxy::class,
                $app
            );
        });

        $this->app->alias(IlluminateRegistry::class, ManagerRegistry::class);
    }

    /**
     * Register the connections
     * @return array
     */
    protected function setupConnection()
    {
        $this->app->singleton(ConnectionManager::class);
    }

    /**
     * Register the meta data drivers
     */
    protected function setupMetaData()
    {
        $this->app->singleton(MetaDataManager::class);
    }

    /**
     * Register the cache drivers
     */
    protected function setupCache()
    {
        $this->app->singleton(CacheManager::class);
    }

    /**
     * Setup the Class metadata factory
     */
    protected function registerClassMetaDataFactory()
    {
        $this->app->singleton(ClassMetadataFactory::class, function ($app) {
            return $app['em']->getMetadataFactory();
        });
    }

    /**
     * Register the driver chain
     */
    protected function registerDriverChain()
    {
        $this->app->singleton(DriverChain::class, function ($app) {

            $configuration = $app['em']->getConfiguration();

            $chain = new DriverChain(
                $configuration->getMetadataDriverImpl()
            );

            // Register namespaces
            $namespaces = array_merge($app->config->get('doctrine.meta.namespaces', ['App']), ['LaravelDoctrine']);
            foreach ($namespaces as $alias => $namespace) {
                if (is_string($alias)) {
                    $configuration->addEntityNamespace($alias, $namespace);
                }

                $chain->addNamespace($namespace);
            }

            // Register default paths
            $chain->addPaths(array_merge(
                $app->config->get('doctrine.meta.paths', []),
                [__DIR__ . '/Auth/Passwords']
            ));

            $configuration->setMetadataDriverImpl($chain->getChain());

            return $chain;
        });
    }

    /**
     * Register doctrine extensions
     */
    protected function registerExtensions()
    {
        // Bind extension manager as singleton,
        // so user can call it and add own extensions
        $this->app->singleton(ExtensionManager::class, function ($app) {

            $manager = new ExtensionManager(
                $this->app[ManagerRegistry::class],
                $this->app[DriverChain::class]
            );

            // Register the extensions
            foreach ($this->app->config->get('doctrine.extensions', []) as $extension) {
                if (!class_exists($extension)) {
                    throw new ExtensionNotFound("Extension {$extension} not found");
                }

                $manager->register(
                    $app->make($extension)
                );
            }

            return $manager;
        });
    }

    /**
     * Register the validation presence verifier
     */
    protected function registerPresenceVerifier()
    {
        $this->app->singleton('validation.presence', DoctrinePresenceVerifier::class);
    }

    /**
     * Register custom types
     */
    protected function registerCustomTypes()
    {
        (new CustomTypeManager())->addCustomTypes(config('doctrine.custom_types', []));
    }

    /**
     * Register console commands
     */
    protected function registerConsoleCommands()
    {
        $this->commands([
            InfoCommand::class,
            SchemaCreateCommand::class,
            SchemaUpdateCommand::class,
            SchemaDropCommand::class,
            SchemaValidateCommand::class,
            ClearMetadataCacheCommand::class,
            ClearResultCacheCommand::class,
            ClearQueryCacheCommand::class,
            EnsureProductionSettingsCommand::class,
            GenerateProxiesCommand::class,
            ConvertConfigCommand::class
        ]);
    }

    /**
     * Extend the auth manager
     */
    protected function extendAuthManager()
    {
        $this->app[AuthManager::class]->extend('doctrine', function ($app) {
            $model = $this->app['config']['auth.model'];

            return new DoctrineUserProvider($app[Hasher::class], $app[ManagerRegistry::class], $model);
        });
    }

    /**
     * @return string
     */
    protected function getConfigPath()
    {
        return __DIR__ . '/../config/doctrine.php';
    }

    /**
     * Get the services provided by the provider.
     * @return string[]
     */
    public function provides()
    {
        return [
            'auth',
            'em',
            'validation.presence',
            'migration.repository',
            DriverChain::class,
            AuthManager::class,
            EntityManager::class,
            ClassMetadataFactory::class,
            EntityManagerInterface::class,
            ExtensionManager::class,
            ManagerRegistry::class
        ];
    }
}
