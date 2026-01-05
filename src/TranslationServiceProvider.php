<?php

namespace Waavi\Translation;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Translation\FileLoader as LaravelFileLoader;
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

        $this->registerCacheRepository();
        $this->registerTranslationLoader();
        $this->registerUriLocalizer();
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

            $laravelFileLoader = new LaravelFileLoader(
                $app['files'],
                $app->basePath('lang')
            );

            $fileLoader     = new FileLoader($defaultLocale, $laravelFileLoader);
            $databaseLoader = new DatabaseLoader(
                $defaultLocale,
                $app->make(TranslationRepository::class)
            );

            $loader = match ($source) {
                'database' => $databaseLoader,
                'mixed_db' => new MixedLoader($defaultLocale, $databaseLoader, $fileLoader),
                'mixed'    => new MixedLoader($defaultLocale, $fileLoader, $databaseLoader),
                default    => $fileLoader,
            };

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
     * Cache repository binding.
     */
    protected function registerCacheRepository(): void
    {
        $this->app->singleton('translation.cache.repository', function ($app) {
            return CacheRepositoryFactory::make(
                $app['cache']->store(),
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
