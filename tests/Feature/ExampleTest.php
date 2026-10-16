<?php

namespace Tests\Feature;

use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** A fresh install shows the Light NC name and theme, light only, with no dark colours and no image request for the logo. */
    public function test_front_page_is_light_nc_share(): void
    {
        $this->withoutVite();
        $this->seed();

        $this->assertSame('Light NC', Theme::where('active', true)->first()->name);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Light NC Share</title>', false)
            ->assertDontSee('prefers-color-scheme', false)
            ->assertDontSee('seal-192.png', false)
            ->assertSee('/fonts/montserrat-latin-400-normal.woff2', false);
    }

    public function test_no_dark_media_query_or_logo_path_in_the_sources(): void
    {
        foreach (['resources/js/style.scss', 'resources/js/components/anonymousUploader.vue', 'resources/js/App.vue', 'resources/views/app.blade.php'] as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertStringNotContainsString('prefers-color-scheme', $source, $file);
            $this->assertStringNotContainsString('seal-192.png', $source, $file);
        }
        $this->assertFileDoesNotExist(base_path('resources/views/lnc-dark.blade.php'));
        $this->assertStringContainsString('data:image/png;base64,', file_get_contents(base_path('resources/js/lnc-seal.js')));
    }

    public function test_one_transfer_may_be_15_gb(): void
    {
        $this->seed();
        $this->assertSame(15 * 1073741824, config('anonymous.max_share_bytes'));
        $this->assertSame(45 * 1073741824, config('anonymous.total_bytes'));
        $this->assertSame(15 * 1073741824, app(\App\Services\SettingsService::class)->getMaxUploadSize());
    }

    public function test_licence_and_credit_are_kept(): void
    {
        $licence = file_get_contents(base_path('LICENSE'));
        $this->assertStringContainsString('MIT License', $licence);
        $this->assertStringContainsString('Copyright (c) 2025 Dean Ward', $licence);
        $this->assertStringContainsString('Based on <a href="https://erugo.app">Erugo</a> (MIT licence)', file_get_contents(base_path('resources/js/App.vue')));
    }
}
