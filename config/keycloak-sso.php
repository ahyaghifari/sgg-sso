<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kredensial Client Keycloak
    |--------------------------------------------------------------------------
    |
    | Gunakan client baru di Keycloak yang khusus dibuat untuk aplikasi ini
    | (jangan menggunakan client admin/HRIS). Aktifkan Standard Flow dan
    | Client Authentication (confidential client). Client scope yang
    | digunakan client ini wajib memiliki mapper "Group Membership" (Full
    | Group Path diaktifkan), karena inilah yang menyisipkan claim `groups`
    | ke dalam token dan dibaca oleh OrgResolver di bawah.
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
    | 'match_by' menentukan PRIORITAS pencarian user lokal berdasarkan claim
    | token — URUTAN ARRAY = URUTAN PRIORITAS. Field pertama yang punya nilai
    | DAN ketemu user-nya yang dipakai; field sesudahnya tidak dicoba lagi.
    | Host app tentukan sendiri mau utamakan NIP atau email cukup dengan
    | mengubah urutan ini, tidak ada default tersembunyi yang memaksa salah
    | satu.
    |
    | Default: ['keycloak_sub', 'nip', 'email'].
    | - 'keycloak_sub' → $claims['sub']. Paling pasti (sudah pernah login &
    |   ke-link sebelumnya) — dicoba duluan.
    | - 'nip' → $claims['nip'], fallback $claims['preferred_username'] kalau
    |   'nip' kosong (tergantung mapping client scope Keycloak). Kunci bisnis
    |   yang stabil, dipakai buat user yang sudah terdaftar SEBELUM pernah
    |   login SSO (mis. migrasi data lama) tapi belum ke-link ke Keycloak.
    | - 'email' → $claims['email']. Paling lemah (bisa beda/berubah), taruh
    |   TERAKHIR kecuali email memang kunci identitas utama di sistemmu.
    |
    | Field lain di luar 'keycloak_sub'/'nip' dicari langsung pada $claims
    | dengan nama yang sama (mis. 'username').
    |
    | 'provision' bernilai true secara default: user yang belum terdaftar
    | otomatis dibuat saat login SSO pertama kali. Set ke false apabila
    | sistem mengharuskan user sudah terdaftar lebih dulu (login ditolak
    | apabila tidak ditemukan, tidak ada data yang dibuat otomatis).
    |
    | 'fill' bersifat opsional. Apabila tidak diisi, digunakan pengisian
    | bawaan yang mengambil nama/email dari HRIS lewat helper
    | hris_employee($nip) (fallback ke claim token apabila HRIS tidak
    | dapat diakses) dan mengisi kolom unit organisasi mengikuti konvensi
    | `{level}_id`, dengan `level` diambil dari key 'level' pada
    | 'org_levels' di bawah (mis. 'department' menjadi kolom
    | 'department_id') — BUKAN ditebak dari nama model, karena nama
    | model/tabel bisa berbeda-beda antar sistem. Kolom yang tidak ada pada
    | model akan diabaikan otomatis. Isi 'fill' sendiri apabila konvensi
    | `{level}_id` tidak sesuai dengan skema aplikasi. Contoh (dengan
    | $orgUnits diakses lewat KEY 'level', bukan nama kelas model):
    |
    | 'fill' => function (array $claims, array $orgUnits): array {
    |     $nip = $claims['nip'] ?? $claims['preferred_username'];
    |     $employee = hris_employee($nip); // null jika HRIS_API belum dikonfigurasi atau NIP tidak ditemukan
    |
    |     return [
    |         'nip' => $nip,
    |         'name' => $employee['name'] ?? $claims['name'] ?? $nip, // fallback ke klaim jika HRIS tidak dapat diakses
    |         'email' => $employee['email'] ?? $claims['email'] ?? null,
    |         'department_id' => $orgUnits['department']->first()?->id,
    |         'division_id' => $orgUnits['division']->first()?->id,
    |         // Sub-divisi hanya terisi apabila grup Keycloak user mencapai level tersebut
    |         // dan 'org_levels' di bawah memang mengonfigurasi level 'sub_division'.
    |         'sub_division_id' => $orgUnits['sub_division']->first()?->id,
    |     ];
    | },
    |
    */
    'user' => [
        'model' => env('KEYCLOAK_USER_MODEL', \App\Models\User::class),
        'sub_column' => 'keycloak_sub',
        'match_by' => ['keycloak_sub', 'nip', 'email'],
        'provision' => true,
        'fill' => null, // Closure(array $claims, array $orgUnits): array — opsional, null memakai pengisian bawaan (lihat komentar di atas)
        'active_check' => null, // Closure(Model $user): bool — null berarti selalu diizinkan
    ],

    /*
    |--------------------------------------------------------------------------
    | Direktori Karyawan HRIS (digunakan oleh hris_employee() / HrisDirectoryClient)
    |--------------------------------------------------------------------------
    |
    | Merujuk ke endpoint HRIS Api\Integrasi\EmployeeDirectoryController,
    | yaitu sumber resmi data nama, email, dan unit organisasi karyawan
    | berdasarkan NIP, yang digunakan oleh config('user.fill') di atas.
    | Autentikasi menggunakan Bearer token yang unik per aplikasi konsumen —
    | dibuat dan dikelola oleh tim HRIS melalui panel Filament
    | /keycloak (menu "Aplikasi Terhubung"), termasuk untuk melakukan rotasi
    | token sewaktu-waktu tanpa memerlukan proses deploy ulang.
    |
    */
    'hris_directory' => [
        'base_url' => env('HRIS_API_BASE_URL'),
        'token' => env('HRIS_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Level Unit Organisasi (Departemen, Divisi, dan seterusnya)
    |--------------------------------------------------------------------------
    |
    | Setiap baris merepresentasikan satu model/level organisasi, dengan TIGA
    | key: 'model' (kelas Eloquent), 'code_column' (kolom kode), dan 'level'
    | (WAJIB diisi — string bebas seperti 'department', 'division',
    | 'sub_division', dipakai sebagai key hasil OrgResolver dan acuan
    | pengisian bawaan UserResolver). 'level' sengaja TIDAK ditebak dari
    | nama kelas model, karena nama model/tabel bisa berbeda-beda antar
    | sistem (mis. App\Models\Department, App\Models\Unit, atau
    | App\Models\Departemen bisa sama-sama merepresentasikan level
    | 'department') — 'level' adalah kosakata semantik yang tetap konsisten
    | terlepas dari penamaan itu.
    |
    | OrgResolver mencocokkan SELURUH segmen path grup Keycloak (bukan
    | hanya segmen terakhir) terhadap SETIAP model yang dikonfigurasi di
    | bawah ini, sehingga urutan array tidak perlu selaras dengan kedalaman
    | path dan tetap aman meskipun kedalaman struktur organisasi berbeda
    | antar cabang. Kolom kode ('code_column') harus cocok persis dengan
    | nama teknis grup Keycloak (bukan nama tampilan) — lihat konvensi
    | penamaan kode pada README.
    |
    | Resolusi hingga level Sub Divisi didukung sepenuhnya — struktur HRIS
    | sendiri terdiri atas tiga level (Departemen > Divisi > Sub Divisi,
    | lihat /publik/unit-organisasi). Tambahkan baris ketiga apabila aplikasi
    | memerlukan granularitas tersebut. Ketiga level tidak wajib digunakan
    | sekaligus — aplikasi yang hanya memerlukan Departemen cukup
    | mengonfigurasi satu baris saja.
    |
    | Contoh:
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
    | Dibaca dari claim `resource_access.<client_id>.roles` pada access
    | token. Role di-assign pada GRUP di Keycloak Admin Console (bukan di
    | sini) — lihat README. Role disimpan ke session dan dapat diperiksa
    | melalui helper `keycloak_has_role('nama-role')` atau event
    | KeycloakLoginSucceeded.
    |
    */
    'roles' => [
        'enabled' => true,
        'client_id' => null, // null berarti menggunakan 'client_id' di atas
    ],

];
