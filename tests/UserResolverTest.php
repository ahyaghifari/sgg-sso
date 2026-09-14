<?php

namespace Syifa\KeycloakSso\Tests;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Syifa\KeycloakSso\Support\UserResolver;
use Syifa\KeycloakSso\Tests\Fixtures\TestDepartment;
use Syifa\KeycloakSso\Tests\Fixtures\TestDivision;
use Syifa\KeycloakSso\Tests\Fixtures\TestGuardedUser;
use Syifa\KeycloakSso\Tests\Fixtures\TestUser;
use Syifa\KeycloakSso\Tests\Fixtures\TestUserProfile;

class UserResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('keycloak-sso.user', [
            'model' => TestUser::class,
            'sub_column' => 'keycloak_sub',
            'match_by' => ['keycloak_sub', 'email'],
            'provision' => false,
            'fill' => null,
            'active_check' => null,
        ]);
    }

    public function test_matches_existing_user_by_sub(): void
    {
        $user = TestUser::create(['name' => 'Ahya', 'email' => 'a@x.com', 'keycloak_sub' => 'sub-1']);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-1']);

        $this->assertSame($user->id, $resolved->id);
    }

    public function test_falls_back_to_email_and_links_sub(): void
    {
        $user = TestUser::create(['name' => 'Ahya', 'email' => 'a@x.com']);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-new', 'email' => 'a@x.com']);

        $this->assertSame($user->id, $resolved->id);
        $this->assertSame('sub-new', $resolved->fresh()->keycloak_sub);
    }

    public function test_matches_existing_user_by_nip(): void
    {
        // User terdaftar duluan (mis. migrasi data lama), belum pernah login SSO — belum
        // ke-link keycloak_sub, dan emailnya beda dari yang di klaim Keycloak.
        $user = TestUser::create(['name' => 'Ahya', 'email' => 'lama@x.com', 'nip' => '12345']);

        config(['keycloak-sso.user.match_by' => ['keycloak_sub', 'nip', 'email']]);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-baru', 'nip' => '12345', 'email' => 'beda@x.com']);

        $this->assertSame($user->id, $resolved->id);
        $this->assertSame('sub-baru', $resolved->fresh()->keycloak_sub);
    }

    public function test_matches_existing_user_by_nip_via_preferred_username_fallback(): void
    {
        // Sebagian mapping client scope Keycloak taruh NIP di claim 'preferred_username',
        // bukan claim 'nip' terpisah — harus tetap kepakai buat matching.
        $user = TestUser::create(['name' => 'Ahya', 'nip' => '12345']);

        config(['keycloak-sso.user.match_by' => ['nip']]);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-x', 'preferred_username' => '12345']);

        $this->assertSame($user->id, $resolved->id);
    }

    public function test_match_by_order_decides_priority_when_multiple_fields_could_match(): void
    {
        // Dua user beda: satu sudah ke-link keycloak_sub, satu lagi cuma cocok by NIP.
        // Klaim login ini punya sub yang cocok user A DAN nip yang (kalau match_by-nya
        // taruh nip duluan) bisa cocok user B — 'keycloak_sub' harus menang karena
        // urutannya lebih dulu di match_by, bukan user B.
        $userA = TestUser::create(['name' => 'A', 'keycloak_sub' => 'sub-a', 'nip' => '999']);
        TestUser::create(['name' => 'B', 'nip' => '111']);

        config(['keycloak-sso.user.match_by' => ['keycloak_sub', 'nip']]);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-a', 'nip' => '111']);

        $this->assertSame($userA->id, $resolved->id);
    }

    public function test_package_default_match_by_includes_nip_between_sub_and_email(): void
    {
        // Sanity check config bawaan package (bukan override defineEnvironment() di kelas
        // ini) — pastikan NIP benar-benar termasuk secara default, bukan cuma opsional.
        $default = (require __DIR__ . '/../config/keycloak-sso.php')['user']['match_by'];

        $this->assertSame(['keycloak_sub', 'nip', 'email'], $default);
    }

    public function test_unmatched_user_without_provision_is_rejected(): void
    {
        $this->expectException(HttpException::class);

        (new UserResolver)->resolve(['sub' => 'ghost', 'email' => 'ghost@x.com']);
    }

    public function test_provision_creates_user_when_enabled(): void
    {
        config([
            'keycloak-sso.user.provision' => true,
            'keycloak-sso.user.fill' => fn (array $c, array $orgUnits) => ['name' => $c['name'], 'email' => $c['email']],
        ]);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-baru', 'name' => 'Budi', 'email' => 'budi@x.com']);

        $this->assertSame('Budi', $resolved->name);
        $this->assertSame('sub-baru', $resolved->keycloak_sub);
    }

    public function test_provision_assigns_org_unit_resolved_from_groups(): void
    {
        $department = TestDepartment::create(['name' => 'Keperawatan', 'keycloak_code' => 'rsu-bjb-keperawatan']);

        config([
            'keycloak-sso.user.provision' => true,
            'keycloak-sso.user.fill' => fn (array $c, array $orgUnits) => [
                'name' => $c['name'],
                'email' => $c['email'],
                'department_id' => $orgUnits['department']->first()?->id,
            ],
        ]);

        $orgUnits = ['department' => collect([$department])];

        $resolved = (new UserResolver)->resolve(
            ['sub' => 'sub-baru', 'name' => 'Budi', 'email' => 'budi@x.com'],
            $orgUnits,
        );

        $this->assertSame($department->id, $resolved->department_id);
    }

    public function test_provision_true_by_default_uses_fallback_fill_when_none_configured(): void
    {
        // Class default terpaket ini sengaja override ke provision=false; tes ini pastikan
        // nilai bawaan package sendiri (config/keycloak-sso.php) memang true dan closure
        // 'fill' dapat dikosongkan sepenuhnya — bukan asumsi dari override defineEnvironment().
        config(['keycloak-sso.user.provision' => true, 'keycloak-sso.user.fill' => null]);

        $department = TestDepartment::create(['name' => 'Keperawatan', 'keycloak_code' => 'rsu-bjb-keperawatan']);
        $division = TestDivision::create(['name' => 'ICU', 'keycloak_code' => 'rsu-bjb-keperawatan-icu']);

        $orgUnits = [
            // Kolom tebakan 'department_id' ADA di skema/fillable TestUser — harus terisi.
            'department' => collect([$department]),
            // Kolom tebakan 'division_id' sengaja TIDAK ada di skema/fillable TestUser —
            // harus diabaikan otomatis, bukan menimbulkan error.
            'division' => collect([$division]),
        ];

        $resolved = (new UserResolver)->resolve(
            ['sub' => 'sub-fallback', 'nip' => '12345', 'name' => 'Citra'],
            $orgUnits,
        );

        $this->assertSame('12345', $resolved->nip);
        $this->assertSame('Citra', $resolved->name); // hris_employee() null (belum dikonfigurasi) -> fallback ke klaim
        $this->assertSame($department->id, $resolved->department_id);
        $this->assertArrayNotHasKey('division_id', $resolved->getAttributes());
    }

    public function test_missing_column_on_guarded_model_is_dropped_via_schema_check(): void
    {
        // TestGuardedUser TIDAK deklarasikan $fillable (pakai $guarded = []) dan skema
        // tabelnya TIDAK punya kolom 'nip' sama sekali — mewakili sistem yang tidak
        // menyimpan NIP. Pengisian bawaan harus tetap sukses, kolom 'nip' diabaikan
        // lewat pengecekan skema (Schema::hasColumn), bukan cuma pengecekan $fillable.
        config([
            'keycloak-sso.user.model' => TestGuardedUser::class,
            'keycloak-sso.user.provision' => true,
            'keycloak-sso.user.fill' => null,
        ]);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-guarded', 'nip' => '99999', 'name' => 'Dewi', 'email' => 'dewi@x.com']);

        $this->assertSame('Dewi', $resolved->name);
        $this->assertSame('dewi@x.com', $resolved->email);
        $this->assertArrayNotHasKey('nip', $resolved->getAttributes());
    }

    public function test_match_by_skips_nip_when_column_does_not_exist_instead_of_erroring(): void
    {
        // TestGuardedUser TIDAK punya kolom 'nip' sama sekali di skemanya. match_by yang
        // menyertakan 'nip' harus dilewati diam-diam, BUKAN memicu query error
        // "unknown column" — user tetap harus ketemu lewat field berikutnya (email).
        $user = TestGuardedUser::create(['name' => 'Eka', 'email' => 'eka@x.com']);

        config([
            'keycloak-sso.user.model' => TestGuardedUser::class,
            'keycloak-sso.user.match_by' => ['keycloak_sub', 'nip', 'email'],
        ]);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-baru', 'nip' => '12345', 'email' => 'eka@x.com']);

        $this->assertSame($user->id, $resolved->id);
    }

    public function test_match_by_closure_can_look_up_via_related_model(): void
    {
        // NIP di sini TIDAK ada di tabel kc_users sama sekali — disimpan di
        // kc_user_profiles (relasi hasOne). Kolom string biasa tidak bisa menjangkau
        // ini, jadi match_by pakai closure yang query lewat relasi.
        $user = TestUser::create(['name' => 'Fajar', 'email' => 'fajar@x.com']);
        TestUserProfile::create(['user_id' => $user->id, 'nip' => '55555']);

        config(['keycloak-sso.user.match_by' => [
            'keycloak_sub',
            function (array $claims, string $modelClass) {
                $nip = $claims['nip'] ?? null;

                return $nip
                    ? $modelClass::whereHas('profile', fn ($q) => $q->where('nip', $nip))->first()
                    : null;
            },
        ]]);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-baru', 'nip' => '55555']);

        $this->assertSame($user->id, $resolved->id);
        // Closure ketemu -> tetap lanjut ke logic link keycloak_sub seperti biasa.
        $this->assertSame('sub-baru', $resolved->fresh()->keycloak_sub);
    }

    public function test_match_by_closure_returning_null_falls_through_to_next_entry(): void
    {
        $user = TestUser::create(['name' => 'Gita', 'email' => 'gita@x.com']);

        config(['keycloak-sso.user.match_by' => [
            fn (array $claims, string $modelClass) => null, // closure ini sengaja selalu gagal
            'email',
        ]]);

        $resolved = (new UserResolver)->resolve(['sub' => 'sub-x', 'email' => 'gita@x.com']);

        $this->assertSame($user->id, $resolved->id);
    }
}
