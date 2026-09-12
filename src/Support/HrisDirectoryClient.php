<?php

namespace Syifa\KeycloakSso\Support;

use Illuminate\Support\Facades\Http;

/**
 * Klien tipis untuk mengakses API direktori karyawan HRIS (nama, email, dan
 * unit organisasi berdasarkan NIP) — lihat
 * App\Http\Controllers\Api\Integrasi\EmployeeDirectoryController pada HRIS.
 * Digunakan pada config('keycloak-sso.user.fill') agar user baru terisi
 * dengan data resmi dari HRIS, bukan dari claim token Keycloak yang
 * berpotensi usang atau berbeda ejaan.
 */
class HrisDirectoryClient
{
    /** @return array{nip: string, name: string, email: ?string, is_active: bool, company: ?string, org_unit: array}|null */
    public function find(string $nip): ?array
    {
        $config = config('keycloak-sso.hris_directory');
        $base = $config['base_url'] ?? null;

        if (blank($base)) {
            return null;
        }

        $res = Http::withToken($config['token'] ?? '')
            ->get(rtrim($base, '/') . "/api/integrasi/karyawan/{$nip}");

        return $res->successful() ? $res->json('data') : null;
    }
}
