<?php

namespace Syifa\KeycloakSso\Tests;

use Illuminate\Support\Facades\Http;
use Syifa\KeycloakSso\Support\HrisDirectoryClient;

class HrisDirectoryClientTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('keycloak-sso.hris_directory', [
            'base_url' => 'https://hris.test',
            'token' => 'test-token-123',
        ]);
    }

    public function test_fetches_employee_by_nip_with_bearer_token(): void
    {
        Http::fake([
            'hris.test/api/integrasi/karyawan/12345' => Http::response([
                'success' => true,
                'data' => ['nip' => '12345', 'name' => 'Budi', 'email' => 'budi@x.com'],
            ]),
        ]);

        $employee = (new HrisDirectoryClient)->find('12345');

        $this->assertSame('Budi', $employee['name']);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer test-token-123')
            && str_starts_with($req->url(), 'https://hris.test/api/integrasi/karyawan/12345'));
    }

    public function test_returns_null_when_not_configured(): void
    {
        config(['keycloak-sso.hris_directory.base_url' => null]);

        $this->assertNull((new HrisDirectoryClient)->find('12345'));
    }

    public function test_returns_null_when_employee_not_found(): void
    {
        Http::fake([
            'hris.test/api/integrasi/karyawan/*' => Http::response(['success' => false], 404),
        ]);

        $this->assertNull((new HrisDirectoryClient)->find('ghost'));
    }
}
