<?php

namespace Waavi\Translation;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Translation\FileLoader as LaravelFileLoader;
use Illuminate\Translation\TranslationServiceProvider as LaravelTranslationServiceProvider;
use Waavi\Translation\Cache\RepositoryFactory as CacheRepositoryFactory;
use Waavi\Translation\Commands\CacheFlushCommand;
use Waavi\Translation\Commands\FileLoaderCommand;
use Waavi\Translation\Loaders\CacheLoader;
use Waavi\Translation\Loaders\DatabaseLoader;
use Waavi\Translation\Loaders\FileLoader;
use Waavi\Translation\Loaders\MixedLoader;
use Waavi\Translation\Middleware\TranslationMiddleware;
use Waavi\Translation\Repositories\LanguageRepository;
use Waavi\Translation\Repositories\TranslationRepository;
use Waavi\Translation\Routes\ResourceRegistrar;

class TranslationServiceProvider extends ServiceProvider
{
    /**
     * Register bindings ONLY.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/translator.php', 'translator');

        // Preserve Laravel's translator binding before replacing its loader.
        (new LaravelTranslationServiceProvider($this->app))->register();

        $this->registerCacheRepository();
        $this->registerTranslationLoader();
        $this->registerUriLocalizer();

        $this->app->singleton(FileLoaderCommand::class, function ($app) {
            return new FileLoaderCommand(
                $app->make(LanguageRepository::class),
                $app->make(TranslationRepository::class),
                $app['files'],
                $app->langPath(),
                $app['config']->get('app.locale')
            );
        });
        $this->app->singleton(CacheFlushCommand::class, function ($app) {
            return new CacheFlushCommand(
                $app['translation.cache.repository'],
                $app['config']->get('translator.cache.enabled')
            );
        });
    }

    /**
     * Boot AFTER container is ready.
     */
    public function boot(Router $router): void
    {
        // Config
        $this->publishes([
            __DIR__ . '/../config/translator.php' => config_path('translator.php'),
        ], 'translation-config');

        // Migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Middleware
        $router->aliasMiddleware('localize', TranslationMiddleware::class);

        // Resource registrar override
        $this->app->bind(
            \Illuminate\Routing\ResourceRegistrar::class,
            ResourceRegistrar::class
        );

        // Commands (console only)
        if ($this->app->runningInConsole()) {
            $this->commands([
                FileLoaderCommand::class,
                CacheFlushCommand::class,
            ]);
        }
    }

    /**
     * Translation loader binding.
     */
    protected function registerTranslationLoader(): void
    {
        $this->app->singleton('translation.loader', function ($app) {
            $defaultLocale = $app['config']->get('app.locale');
            $source        = $app['config']->get('translator.source');

            switch ($source) {
                case 'mixed':
                    $laravelFileLoader = $this->makeLaravelFileLoader($app);
                    $fileLoader        = new FileLoader($defaultLocale, $laravelFileLoader);
                    $databaseLoader    = new DatabaseLoader($defaultLocale, $app->make(TranslationRepository::class));
                    $loader            = new MixedLoader($defaultLocale, $fileLoader, $databaseLoader);
                    break;
                case 'mixed_db':
                    $laravelFileLoader = $this->makeLaravelFileLoader($app);
                    $fileLoader        = new FileLoader($defaultLocale, $laravelFileLoader);
                    $databaseLoader    = new DatabaseLoader($defaultLocale, $app->make(TranslationRepository::class));
                    $loader            = new MixedLoader($defaultLocale, $databaseLoader, $fileLoader);
                    break;
                case 'database':
                    $loader = new DatabaseLoader($defaultLocale, $app->make(TranslationRepository::class));
                    break;
                default:case 'files':
                    $laravelFileLoader = $this->makeLaravelFileLoader($app);
                    $loader            = new FileLoader($defaultLocale, $laravelFileLoader);
                    break;
            }
            if ($app['config']->get('translator.cache.enabled')) {
                $loader = new CacheLoader(
                    $defaultLocale,
                    $app['translation.cache.repository'],
                    $loader,
                    $app['config']->get('translator.cache.timeout')
                );
            }

            return $loader;
        });
    }

    /**
     * Include Laravel's built-in messages and the application's configured language path.
     */
    protected function makeLaravelFileLoader($app)
    {
        $frameworkPath = dirname((new \ReflectionClass(LaravelTranslationServiceProvider::class))->getFileName());

        return new LaravelFileLoader($app['files'], [
            $frameworkPath . '/lang',
            $app->langPath(),
        ]);
    }

    /**
     * Cache repository binding.
     */
    protected function registerCacheRepository(): void
    {
        $this->app->singleton('translation.cache.repository', function ($app) {
            return CacheRepositoryFactory::make(
                $app['cache']->getStore(),
                $app['config']->get('translator.cache.suffix')
            );
        });
    }

    /**
     * URI localizer.
     */
    protected function registerUriLocalizer(): void
    {
        $this->app->singleton(
            'translation.uri.localizer',
            UriLocalizer::class
        );
    }
}
