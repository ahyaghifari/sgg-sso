<?php

namespace Syifa\KeycloakSso\Tests;

use InvalidArgumentException;
use Syifa\KeycloakSso\Support\OrgResolver;
use Syifa\KeycloakSso\Tests\Fixtures\TestDepartment;
use Syifa\KeycloakSso\Tests\Fixtures\TestDivision;
use Syifa\KeycloakSso\Tests\Fixtures\TestSubDivision;

class OrgResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('keycloak-sso.org_levels', [
            ['model' => TestDepartment::class, 'code_column' => 'keycloak_code', 'level' => 'department'],
            ['model' => TestDivision::class, 'code_column' => 'keycloak_code', 'level' => 'division'],
            ['model' => TestSubDivision::class, 'code_column' => 'keycloak_code', 'level' => 'sub_division'],
        ]);
    }

    public function test_resolves_department_and_division_regardless_of_path_depth(): void
    {
        TestDepartment::create(['name' => 'Keperawatan', 'keycloak_code' => 'rsu-bjb-keperawatan']);
        TestDivision::create(['name' => 'ICU', 'keycloak_code' => 'rsu-bjb-keperawatan-icu']);

        $result = (new OrgResolver)->resolve([
            '/rsu-bjb/rsu-bjb-keperawatan/rsu-bjb-keperawatan-icu',
        ]);

        $this->assertArrayHasKey('department', $result);
        $this->assertArrayHasKey('division', $result);
        $this->assertSame('Keperawatan', $result['department']->first()->name);
        $this->assertSame('ICU', $result['division']->first()->name);
    }

    public function test_resolves_down_to_sub_division(): void
    {
        TestDepartment::create(['name' => 'Keperawatan', 'keycloak_code' => 'rsu-bjb-keperawatan']);
        TestDivision::create(['name' => 'ICU', 'keycloak_code' => 'rsu-bjb-keperawatan-icu']);
        TestSubDivision::create(['name' => 'ICU Malam', 'keycloak_code' => 'rsu-bjb-keperawatan-icu-malam']);

        $result = (new OrgResolver)->resolve([
            '/rsu-bjb/rsu-bjb-keperawatan/rsu-bjb-keperawatan-icu/rsu-bjb-keperawatan-icu-malam',
        ]);

        $this->assertSame('Keperawatan', $result['department']->first()->name);
        $this->assertSame('ICU', $result['division']->first()->name);
        $this->assertSame('ICU Malam', $result['sub_division']->first()->name);
    }

    public function test_can_be_configured_for_division_only(): void
    {
        // Department dan SubDivision tersedia di database maupun di path, tetapi sistem ini
        // hanya mendaftarkan Divisi pada org_levels — memastikan hanya Divisi yang dikembalikan
        // dan kedua level lainnya diabaikan.
        TestDepartment::create(['name' => 'Keperawatan', 'keycloak_code' => 'rsu-bjb-keperawatan']);
        $division = TestDivision::create(['name' => 'ICU', 'keycloak_code' => 'rsu-bjb-keperawatan-icu']);
        TestSubDivision::create(['name' => 'ICU Malam', 'keycloak_code' => 'rsu-bjb-keperawatan-icu-malam']);

        config(['keycloak-sso.org_levels' => [
            ['model' => TestDivision::class, 'code_column' => 'keycloak_code', 'level' => 'division'],
        ]]);

        $result = (new OrgResolver)->resolve([
            '/rsu-bjb/rsu-bjb-keperawatan/rsu-bjb-keperawatan-icu/rsu-bjb-keperawatan-icu-malam',
        ]);

        $this->assertSame(['division'], array_keys($result));
        $this->assertSame($division->id, $result['division']->first()->id);
    }

    public function test_level_key_is_used_even_when_model_name_differs_from_level(): void
    {
        // Membuktikan 'level' tidak ditebak dari nama kelas model — di sini modelnya tetap
        // TestDepartment tapi diberi label level 'unit', hasilnya harus diakses via 'unit'.
        TestDepartment::create(['name' => 'Keperawatan', 'keycloak_code' => 'rsu-bjb-keperawatan']);

        config(['keycloak-sso.org_levels' => [
            ['model' => TestDepartment::class, 'code_column' => 'keycloak_code', 'level' => 'unit'],
        ]]);

        $result = (new OrgResolver)->resolve(['/rsu-bjb/rsu-bjb-keperawatan']);

        $this->assertSame(['unit'], array_keys($result));
        $this->assertSame('Keperawatan', $result['unit']->first()->name);
    }

    public function test_missing_level_key_throws(): void
    {
        config(['keycloak-sso.org_levels' => [
            ['model' => TestDepartment::class, 'code_column' => 'keycloak_code'], // tanpa 'level'
        ]]);

        $this->expectException(InvalidArgumentException::class);

        (new OrgResolver)->resolve(['/rsu-bjb-keperawatan']);
    }

    public function test_resolves_from_level_codes_directly_without_path_parsing(): void
    {
        // Ini jalur UTAMA (dari org_unit hasil hris_employee()) — kode sudah per-level,
        // tidak perlu parsing path grup Keycloak sama sekali.
        $department = TestDepartment::create(['name' => 'Keperawatan', 'keycloak_code' => 'rsu-bjb-keperawatan']);
        $division = TestDivision::create(['name' => 'ICU', 'keycloak_code' => 'rsu-bjb-keperawatan-icu']);

        $result = (new OrgResolver)->resolveFromLevelCodes([
            'department' => 'rsu-bjb-keperawatan',
            'division' => 'rsu-bjb-keperawatan-icu',
            'sub_division' => null, // karyawan belum sampai level itu — harus diabaikan, bukan error
        ]);

        $this->assertSame($department->id, $result['department']->first()->id);
        $this->assertSame($division->id, $result['division']->first()->id);
        $this->assertArrayNotHasKey('sub_division', $result);
    }

    public function test_resolve_from_level_codes_ignores_unmatched_code(): void
    {
        $result = (new OrgResolver)->resolveFromLevelCodes(['department' => 'kode-tidak-terdaftar']);

        $this->assertSame([], $result);
    }

    public function test_resolve_from_level_codes_empty_input_returns_empty(): void
    {
        $this->assertSame([], (new OrgResolver)->resolveFromLevelCodes([]));
    }

    public function test_resolve_from_level_codes_missing_level_key_throws(): void
    {
        config(['keycloak-sso.org_levels' => [
            ['model' => TestDepartment::class, 'code_column' => 'keycloak_code'], // tanpa 'level'
        ]]);

        $this->expectException(InvalidArgumentException::class);

        (new OrgResolver)->resolveFromLevelCodes(['department' => 'rsu-bjb-keperawatan']);
    }

    public function test_unmatched_codes_are_silently_ignored(): void
    {
        $result = (new OrgResolver)->resolve(['/unit-yang-tidak-terdaftar']);

        $this->assertSame([], $result);
    }

    public function test_empty_groups_returns_empty(): void
    {
        $this->assertSame([], (new OrgResolver)->resolve([]));
    }
}
