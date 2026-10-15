<?php

namespace Tests\Feature;

use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** A fresh install shows the Light NC name, theme and dark colours. */
    public function test_front_page_is_light_nc_share(): void
    {
        $this->withoutVite();
        $this->seed();

        $this->assertSame('Light NC', Theme::where('active', true)->first()->name);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Light NC Share</title>', false)
            ->assertSee('prefers-color-scheme: dark', false)
            ->assertSee('/fonts/montserrat-latin-400-normal.woff2', false);
    }

    public function test_licence_and_credit_are_kept(): void
    {
        $licence = file_get_contents(base_path('LICENSE'));
        $this->assertStringContainsString('MIT License', $licence);
        $this->assertStringContainsString('Copyright (c) 2025 Dean Ward', $licence);
        $this->assertStringContainsString('Based on <a href="https://erugo.app">Erugo</a> (MIT licence)', file_get_contents(base_path('resources/js/App.vue')));
    }
}
