<?php

namespace Syifa\KeycloakSso\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Fixture user tanpa deklarasi $fillable (pakai $guarded, fillable kosong tapi
 * valid) dan TANPA kolom 'nip' di skemanya — mewakili sistem yang tidak
 * menyimpan NIP sama sekali. Lihat UserResolverTest::test_missing_column_on_guarded_model_is_dropped_via_schema_check.
 */
class TestGuardedUser extends Authenticatable
{
    protected $table = 'kc_guarded_users';

    protected $guarded = [];

    public $timestamps = false;
}
