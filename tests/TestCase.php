<?php

declare(strict_types=1);

namespace Webbycrown\LaraknowAi\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Webbycrown\LaraknowAi\Providers\LaraKnowAiServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            LaraKnowAiServiceProvider::class,
        ];
    }
}
