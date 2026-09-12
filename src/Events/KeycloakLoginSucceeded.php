<?php

namespace Syifa\KeycloakSso\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dipicu setelah user berhasil login SSO dan berhasil diresolusi. Aplikasi
 * konsumen dapat mendaftarkan listener pada event ini untuk kebutuhan
 * tambahan (misalnya assign role Spatie, pencatatan audit log, dsb.) tanpa
 * perlu memodifikasi controller pada package ini.
 */
class KeycloakLoginSucceeded
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $claims  claim mentah dari token (id_token dan userinfo)
     * @param  array<string, \Illuminate\Support\Collection>  $orgUnits  hasil OrgResolver, dengan key berupa string 'level' (mis. 'department', 'division')
     * @param  array<int, string>  $roles  role dari resource_access.<client>.roles
     */
    public function __construct(
        public Model $user,
        public array $claims,
        public array $orgUnits,
        public array $roles,
    ) {}
}
