<?php

namespace Syifa\KeycloakSso\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class TestDivision extends Model
{
    protected $table = 'kc_divisions';

    protected $fillable = ['name', 'keycloak_code'];

    public $timestamps = false;
}
