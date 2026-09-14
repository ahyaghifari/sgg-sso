<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kredensial Client Keycloak
    |--------------------------------------------------------------------------
    |
    | Buat client BARU di Keycloak khusus aplikasi ini — jangan pakai client
    | admin/HRIS. Aktifkan Standard Flow + Client Authentication.
    |
    | Opsional: kalau mau baca role user (lihat 'roles' di bawah), client
    | scope-nya perlu mapper "Client Roles" dengan "Add to userinfo" ON.
    | Kalau mau fallback unit organisasi lewat grup Keycloak (jarang
    | terpakai — lihat 'org_levels'), perlu juga mapper "Group Membership"
    | dengan "Add to userinfo" ON. Tanpa keduanya, login tetap jalan normal.
    |
    */
    'client_id' => env('KEYCLOAK_CLIENT_ID'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
    'redirect_uri' => env('KEYCLOAK_REDIRECT_URI', env('APP_URL') . '/auth/keycloak/callback'),
    'base_url' => env('KEYCLOAK_BASE_URL'),
    'realm' => env('KEYCLOAK_REALM'),

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix' => 'auth/keycloak',
        'middleware' => ['web'],
        'redirect_after_login' => '/',
        'redirect_after_logout' => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Lokal
    |--------------------------------------------------------------------------
    |
    | match_by: urutan field yang dicoba buat CARI user lokal yang sudah ada.
    | Urutan = prioritas — entri pertama yang cocok yang dipakai, sisanya
    | tidak dicoba lagi. Field yang kolomnya tidak ada di tabel otomatis
    | dilewati (aman, tidak error), jadi boleh dibiarkan default walau
    | sistemmu tidak simpan NIP.
    |   - keycloak_sub → dari token, paling pasti (sudah pernah login).
    |   - nip           → dari token juga (fallback ke preferred_username),
    |                      buat user lama yang belum pernah login SSO.
    |   - email         → paling lemah, taruh terakhir.
    |
    | Entrinya boleh juga Closure(array $claims, string $modelClass): ?Model
    | — dipakai kalau NIP/kolom lain disimpan di tabel LAIN yang relasi ke
    | user (bukan kolom langsung di tabel user), mis.:
    |
    | fn (array $claims, string $modelClass) =>
    |     $modelClass::whereHas('profile', fn ($q) => $q->where('nip', $claims['nip'] ?? null))->first(),
    |
    | provision: true (default) = user baru otomatis dibuat kalau belum
    | ketemu. false = login ditolak kalau user belum terdaftar.
    |
    | fill: opsional. Kosongkan kalau mau pakai pengisian bawaan (ambil
    | nama/email dari HRIS via hris_employee(), isi kolom unit organisasi
    | `{level}_id` — level-nya dari 'org_levels' di bawah). Isi sendiri
    | kalau nama kolommu beda dari konvensi itu. Contoh:
    |
    | 'fill' => function (array $claims, array $orgUnits): array {
    |     $nip = $claims['nip'] ?? $claims['preferred_username'];
    |     $employee = hris_employee($nip); // null kalau HRIS_API_* kosong / NIP tidak ditemukan
    |
    |     return [
    |         'nip' => $nip,
    |         'name' => $employee['name'] ?? $claims['name'] ?? $nip,
    |         'email' => $employee['email'] ?? $claims['email'] ?? null,
    |         // $orgUnits diakses via key 'level' (bukan nama kelas model)
    |         'department_id' => $orgUnits['department']->first()?->id,
    |         'division_id' => $orgUnits['division']->first()?->id,
    |     ];
    | },
    |
    */
    'user' => [
        'model' => env('KEYCLOAK_USER_MODEL', \App\Models\User::class),
        'sub_column' => 'keycloak_sub',
        'match_by' => ['keycloak_sub', 'nip', 'email'],
        'provision' => true,
        'fill' => null, // Closure(array $claims, array $orgUnits): array — kosongkan buat pengisian bawaan
        'active_check' => null, // Closure(Model $user): bool — null = selalu boleh login
    ],

    /*
    |--------------------------------------------------------------------------
    | Direktori Karyawan HRIS
    |--------------------------------------------------------------------------
    |
    | Sumber utama nama/email/unit organisasi resmi karyawan (helper
    | hris_employee($nip)). Token dibuat lewat panel HRIS /keycloak →
    | Aplikasi Terhubung — bisa digenerate ulang kapan saja tanpa deploy.
    | Kosongkan kalau sistemmu tidak butuh data ini (fill sendiri, atau
    | provision = false).
    |
    */
    'hris_directory' => [
        'base_url' => env('HRIS_API_BASE_URL'),
        'token' => env('HRIS_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Level Unit Organisasi (Departemen, Divisi, dst.)
    |--------------------------------------------------------------------------
    |
    | Satu baris = satu level. 3 key per baris:
    |   - model        → kelas Eloquent-nya (bebas nama apa saja).
    |   - code_column   → kolom yang isinya kode unit (harus cocok persis
    |                     dengan kode dari HRIS, lihat /publik/unit-organisasi).
    |   - level         → WAJIB diisi, string bebas ('department', 'division',
    |                     'sub_division', ...). Ini KUNCI-nya, bukan nama
    |                     model — harus sama persis dengan key 'org_unit'
    |                     dari respons hris_employee() (department/division/
    |                     sub_division), supaya kecocokan otomatis kepakai.
    |
    | Boleh isi 1 baris saja kalau cuma butuh 1 level (mis. Divisi doang).
    |
    | 'org_levels' => [
    |     ['model' => \App\Models\Department::class, 'code_column' => 'keycloak_code', 'level' => 'department'],
    |     ['model' => \App\Models\Division::class,   'code_column' => 'keycloak_code', 'level' => 'division'],
    |     ['model' => \App\Models\SubDivision::class, 'code_column' => 'keycloak_code', 'level' => 'sub_division'],
    | ],
    |
    */
    'org_levels' => [],

    /*
    |--------------------------------------------------------------------------
    | Role (Keycloak Client Roles)
    |--------------------------------------------------------------------------
    |
    | Dibaca dari claim resource_access.<client_id>.roles. Role di-assign ke
    | GRUP di Keycloak Admin Console (bukan di sini) — lihat README. Dapat
    | diperiksa lewat helper keycloak_has_role('nama-role') atau event
    | KeycloakLoginSucceeded.
    |
    */
    'roles' => [
        'enabled' => true,
        'client_id' => null, // null = pakai 'client_id' di atas
    ],

];
