<?php

namespace Syifa\KeycloakSso\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class TestDepartment extends Model
{
    protected $table = 'kc_departments';

    protected $fillable = ['name', 'keycloak_code'];

    public $timestamps = false;
}
