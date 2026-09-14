<?php

namespace Syifa\KeycloakSso\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class TestUser extends Authenticatable
{
    protected $table = 'kc_users';

    protected $fillable = ['name', 'email', 'keycloak_sub', 'is_active', 'department_id', 'nip'];

    public $timestamps = false;

    public function profile()
    {
        return $this->hasOne(TestUserProfile::class, 'user_id');
    }
}
