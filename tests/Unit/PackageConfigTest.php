<?php

declare(strict_types=1);

namespace Webbycrown\LaraknowAi\Tests\Unit;

use Webbycrown\LaraknowAi\Tests\TestCase;

class PackageConfigTest extends TestCase
{
    public function test_package_configuration_is_loaded()
    {
        $this->assertSame('laraknow-ai', config('laraknow.route_prefix'));
        $this->assertSame('laraknow-ai', config('laraknow.route_name_prefix'));
        $this->assertSame('laraknow-ai', config('laraknow.rate_limiter_name'));
        $this->assertSame(30, config('laraknow.rate_limiter_max_attempts'));
        $this->assertSame(1, config('laraknow.rate_limiter_decay_minutes'));
        $this->assertSame('vendor/laraknow', config('laraknow.asset_path'));
        $this->assertSame('https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js', config('laraknow.assets.jquery_url'));
        $this->assertSame('auto', config('laraknow.ui.theme'));
    }

    public function test_ui_brand_and_response_settings_exist()
    {
        $this->assertIsArray(config('laraknow.ui.brand'));
        $this->assertArrayHasKey('personality', config('laraknow.ui.brand'));
        $this->assertIsArray(config('laraknow.ui.responses'));
        $this->assertSame(false, config('laraknow.ui.responses.auto_append_data_preview'));
        $this->assertSame(true, config('laraknow.ui.responses.append_data_preview_when_requested'));
    }

    public function test_default_allowed_tables_are_empty()
    {
        $this->assertSame([], config('laraknow.allowed_tables'));
        $this->assertSame([], config('laraknow.blocked_table_columns'));
    }
}
