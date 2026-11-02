<?php

namespace Waavi\Translation\Test;

use Waavi\Translation\Loaders\Loader;
use Waavi\Translation\Repositories\TranslationRepository;

class ServiceProviderTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app->useLangPath(__DIR__ . '/lang');
    }

    public function test_file_and_mixed_sources_use_the_configured_language_path(): void
    {
        foreach (['files', 'mixed', 'mixed_db'] as $source) {
            $this->app['config']->set('translator.source', $source);
            $this->app['config']->set('translator.cache.enabled', false);
            $this->app->forgetInstance('translation.loader');

            $loader = $this->app->make('translation.loader');

            $this->assertInstanceOf(Loader::class, $loader);
            $this->assertSame('Simple', $loader->load('en', 'auth', '*')['simple']);
            $this->assertSame([], $loader->namespaces());
            // Laravel's bundled validation translations remain available.
            $this->assertArrayHasKey('required', $loader->load('en', 'validation', '*'));
        }
    }

    public function test_registered_import_command_uses_the_configured_language_path(): void
    {
        $this->artisan('translator:load')->assertExitCode(0);

        $translation = $this->app->make(TranslationRepository::class)
            ->findByLangCode('en', 'auth.simple');

        $this->assertSame('Simple', $translation->text);
    }
}
