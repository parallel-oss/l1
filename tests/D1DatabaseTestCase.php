<?php

namespace Parallel\L1\Test;

use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base for tests that need a D1-backed Laravel app without running
 * package migrations (e.g. when extending Laravel framework tests
 * that create their own schema).
 */
abstract class D1DatabaseTestCase extends Orchestra
{
    /**
     * {@inheritdoc}
     */
    protected function getPackageProviders($app)
    {
        return [
            \Parallel\L1\L1ServiceProvider::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'wslxrEFGWY6GfGhvN9L3wH3KSRJQQpBD');
        $app['config']->set('database.default', 'default');
        $d1Config = [
            'driver' => 'd1',
            'prefix' => '',
            'database' => 'DB1',
            'api' => 'http://127.0.0.1:8787/api/client/v4',
            'auth' => [
                'token' => env('CLOUDFLARE_TOKEN', getenv('CLOUDFLARE_TOKEN')),
                'account_id' => env('CLOUDFLARE_ACCOUNT_ID', getenv('CLOUDFLARE_ACCOUNT_ID')),
            ],
        ];
        $app['config']->set('database.connections.default', $d1Config);
        $app['config']->set('database.connections.second_connection', array_merge($d1Config, ['database' => 'DB2']));
    }

    /**
     * Expose the application (e.g. for tests that extend framework tests and need the D1 app).
     */
    public function getApp(): \Illuminate\Contracts\Foundation\Application
    {
        return $this->app;
    }
}
