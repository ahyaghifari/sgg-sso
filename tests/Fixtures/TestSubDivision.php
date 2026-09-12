<?php

namespace Syifa\KeycloakSso\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class TestSubDivision extends Model
{
    protected $table = 'kc_subdivisions';

    protected $fillable = ['name', 'keycloak_code'];

    public $timestamps = false;
}
