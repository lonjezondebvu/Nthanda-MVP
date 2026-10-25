<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_offline_shell_is_public_and_has_no_account_data(): void
    {
        $this->get('/offline')->assertOk()->assertSee('offline-root')->assertDontSee('csrf-token');
        $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', '/offline')->assertJsonPath('display', 'standalone');
        $this->getJson('/offline-api/session')->assertUnauthorized();
    }
}
