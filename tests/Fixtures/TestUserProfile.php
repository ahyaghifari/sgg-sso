<?php

namespace Syifa\KeycloakSso\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Simulasi NIP yang disimpan di tabel TERPISAH dari user (relasi hasOne),
 * bukan kolom langsung di tabel user — lihat
 * UserResolverTest::test_match_by_closure_can_look_up_via_related_model.
 */
class TestUserProfile extends Model
{
    protected $table = 'kc_user_profiles';

    protected $fillable = ['user_id', 'nip'];

    public $timestamps = false;
}
