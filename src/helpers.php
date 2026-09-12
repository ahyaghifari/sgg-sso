<?php

if (! function_exists('keycloak_groups')) {
    /** Mengembalikan path grup Keycloak milik user yang sedang login, misalnya ["/rsu-bjb/rsu-bjb-keperawatan"]. */
    function keycloak_groups(): array
    {
        return session('keycloak.groups', []);
    }
}

if (! function_exists('keycloak_has_role')) {
    /** Memeriksa apakah user yang sedang login memiliki role client Keycloak tertentu (resource_access.<client>.roles). */
    function keycloak_has_role(string $role): bool
    {
        return in_array($role, session('keycloak.roles', []), true);
    }
}

if (! function_exists('hris_employee')) {
    /**
     * Mengambil data karyawan resmi dari HRIS berdasarkan NIP (nama, email,
     * unit organisasi). Mengembalikan null apabila 'hris_directory' belum
     * dikonfigurasi atau NIP tidak ditemukan. Fungsi ini yang seharusnya
     * dipakai pada config('keycloak-sso.user.fill') — jangan mengambil
     * nama atau email dari claim token Keycloak.
     */
    function hris_employee(string $nip): ?array
    {
        return app(\Syifa\KeycloakSso\Support\HrisDirectoryClient::class)->find($nip);
    }
}
