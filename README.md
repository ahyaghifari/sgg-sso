# syifa/keycloak-sso

Package login SSO Keycloak untuk aplikasi Laravel internal Syifa Global
Group. Instal, isi konfigurasi model user serta departemen/divisi, dan
package siap digunakan — aplikasi konsumen tidak perlu menulis sendiri
controller, route, maupun logika parsing JWT.

**Cakupan package ini:**
- Redirect dan callback OIDC (melalui `socialiteproviders/keycloak`, sudah teruji).
- Pencocokan user dari token terhadap user lokal (`UserResolver`), termasuk auto-buat akun baru.
- Resolusi unit organisasi (departemen/divisi/dst.) via **API HRIS** (`OrgResolver::resolveFromLevelCodes()`, sumber utama), dengan claim `groups` Keycloak sebagai fallback kalau HRIS tidak terjangkau.
- Pembacaan role dari `resource_access.<client>.roles`.
- Single logout ke Keycloak.

**Di luar cakupan** (dan memang tidak seharusnya di sini): pengelolaan atau
penulisan data ke Keycloak (pembuatan user, grup, dsb.) — itu tetap
dilakukan lewat panel admin HRIS (`/keycloak`), bukan lewat package ini.

---

## Ringkasan alur (baca ini dulu)

```
Aplikasi (package ini)  <── login SSO, tiap saat ──>  Keycloak
        │
        └── tiap login (bukan cuma user baru) ──>  HRIS
                nama/email resmi + kode unit organisasi (departemen/divisi/dst.)
```

- **Keycloak** = penyedia identitas. Semua login lewat sini, real-time.
- **HRIS** = sumber KEBENARAN data karyawan (nama/email resmi) DAN unit
  organisasi. Dipanggil aplikasi **tiap login** (bukan cuma sekali pas user
  baru dibuat) — supaya kalau karyawan pindah departemen di HRIS, event
  `KeycloakLoginSucceeded` juga dapat data terbaru di login berikutnya
  (lihat [Penggunaan](#penggunaan) kalau mau sinkron ulang otomatis).
- Unit organisasi **tidak lagi wajib** lewat grup Keycloak — kode
  departemen/divisi datang langsung dari respons API HRIS
  (`org_unit`), dicocokkan by kode ke tabel lokal. Grup Keycloak
  (`claim groups`) cuma dipakai kalau API HRIS sedang tidak terjangkau.
  Jadi **tidak perlu** assign user ke grup Keycloak manual demi keperluan
  ini — assign grup di Keycloak cuma relevan kalau kamu pakai fitur
  **role** (`resource_access`), itu beda mekanisme.
- Package ini dipasang di **aplikasi konsumen** (bukan di HRIS — HRIS pakai
  Socialite langsung, lihat catatan di bagian paling bawah).

---

## Quick start (checklist)

1. **Keycloak**: buat client baru khusus aplikasi ini → [detail](#2-konfigurasi-keycloak-sekali-per-aplikasi-baru).
2. **HRIS**: minta kode unit organisasi (`/publik/unit-organisasi`) dan, kalau perlu auto-provision, minta token API (panel `/keycloak` → Aplikasi Terhubung).
3. `composer require syifa/keycloak-sso` + `php artisan vendor:publish --tag=keycloak-sso-config`.
4. Migration kolom `keycloak_sub` (users) + `keycloak_code` (departemen/divisi) → [detail](#3-migration--kode-unit-organisasi).
5. Isi `.env` + `config/keycloak-sso.php` → [detail](#4-konfigurasi-configkeycloak-ssophp).
6. Pasang tombol login/logout → [detail](#penggunaan).
7. Coba login. Kalau kena error, langsung ke [Troubleshooting](#troubleshooting).

---

## 1. Instalasi

**Selama masih dikembangkan bersama HRIS** (repositori ini): gunakan path
repository pada `composer.json` aplikasi lain apabila repositorinya berada
pada server yang sama, atau salin `packages/syifa/keycloak-sso` ke
repositori Git tersendiri, kemudian:

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:syifa/keycloak-sso.git" }
  ],
  "require": {
    "syifa/keycloak-sso": "^1.0"
  }
}
```

```bash
composer require syifa/keycloak-sso
php artisan vendor:publish --tag=keycloak-sso-config
```

## 2. Konfigurasi Keycloak (sekali per aplikasi baru)

1. **Clients** → buat client baru khusus untuk aplikasi ini (`client_id`
   unik, **jangan** memakai client admin/HRIS). Aktifkan **Standard Flow**
   dan **Client Authentication**.
2. **Valid Redirect URIs** (dipakai saat login) → isi persis
   `https://app-ini.domain/auth/keycloak/callback` (atau pakai wildcard
   `https://app-ini.domain/*`). Salah isi field ini = penyebab paling umum
   error "Invalid redirect uri" saat login — lihat [Troubleshooting](#troubleshooting).
3. **Valid post logout redirect URIs** (dipakai saat logout, field
   **terpisah** dari nomor 2) → isi URL tujuan setelah logout, sesuai
   `redirect_after_logout` di config (default `/`). Sering terlewat diisi
   — kalau kosong, logout akan gagal dengan error yang sama persis seperti
   nomor 2, meski penyebabnya field yang berbeda.
4. Client scope yang dipakai client ini (umumnya `profile`, bawaan) harus
   punya mapper:
   - **Group Membership** — Full Group Path aktif, **Add to userinfo aktif**.
   - **Client Roles** (built-in scope `roles`) — **Add to userinfo aktif**.

   Package membaca claim dari respons `/userinfo` (`getRaw()` pada
   Socialite), bukan dengan mendekode JWT manual — jadi "Add to userinfo"
   **wajib** aktif, tidak cukup cuma "Add to access token".

## 3. Migration & kode unit organisasi

Kolom yang perlu ditambahkan di aplikasi konsumen (dijalankan sekali):
```php
Schema::table('users', fn ($t) => $t->string('keycloak_sub')->nullable()->unique());
Schema::table('departments', fn ($t) => $t->string('keycloak_code')->nullable()->unique());
Schema::table('divisions', fn ($t) => $t->string('keycloak_code')->nullable()->unique());
// + sub_divisions kalau butuh granularitas sampai situ
```

**Isi `keycloak_code` manual, sekali per unit**, dicocokkan ke kode resmi
dari HRIS — buka `https://hris.syifa.../publik/unit-organisasi` (halaman
publik, tanpa login; ada juga versi mesin `/publik/unit-organisasi.json`),
lalu salin kode Departemen/Divisi/Sub Divisi yang sesuai. Kode diatur dan
dikelola oleh HRIS (menu Master → Departemen/Divisi/Sub Divisi), **bukan**
ditentukan sendiri oleh masing-masing sistem — konvensi penamaannya
`{perusahaan}-{departemen}-{divisi}`, kebab-case huruf kecil.

Grup Keycloak untuk unit tersebut sebaiknya diberi nama teknis yang sama
persis dengan kode ini, sehingga path grup pada claim `groups` langsung
cocok tanpa perlu pemetaan tambahan.

## 4. Konfigurasi (`config/keycloak-sso.php`)

### 4.1 User lokal

```php
'user' => [
    'model' => \App\Models\User::class,
    'sub_column' => 'keycloak_sub',      // kolom penghubung, mengikuti pola users.keycloak_sub pada HRIS

    // Urutan = prioritas. Entri pertama yang punya nilai DAN ketemu user-nya yang menang.
    // 'nip' otomatis fallback ke claim 'preferred_username' kalau claim 'nip' kosong.
    // Taruh field paling dipercaya duluan — mis. kalau email bisa berubah/tidak unik di
    // sistemmu, jangan taruh sebelum 'nip'.
    'match_by' => ['keycloak_sub', 'nip', 'email'],

    // Kalau NIP (atau field lain) disimpan di TABEL LAIN yang relasi ke user (bukan
    // kolom langsung di tabel user), ganti entrinya jadi closure:
    // 'match_by' => ['keycloak_sub', fn (array $claims, string $modelClass) =>
    //     $modelClass::whereHas('profile', fn ($q) => $q->where('nip', $claims['nip'] ?? null))->first(),
    // ],

    'provision' => true,                  // default: user baru dibuat otomatis apabila belum terdaftar; false = login ditolak jika belum ada

    // Opsional. Apabila null, dipakai pengisian bawaan: nama/email diambil dari
    // hris_employee($nip) (fallback ke $claims apabila HRIS tidak dapat diakses),
    // kolom unit organisasi diisi dari konvensi `{level}_id`, dengan `level` berasal
    // dari key 'level' pada 'org_levels' di bawah — BUKAN ditebak dari nama model,
    // karena nama model/tabel bisa berbeda-beda antar sistem. Isi 'fill' sendiri
    // untuk kontrol penuh — mis. skema kolom yang berbeda dari konvensi tersebut:
    'fill' => function (array $claims, array $orgUnits): array {
        $nip = $claims['nip'] ?? $claims['preferred_username'];
        $employee = hris_employee($nip); // null apabila HRIS_API_* belum dikonfigurasi atau NIP tidak dikenali

        return [
            'nip' => $nip,
            'name' => $employee['name'] ?? $claims['name'] ?? $nip,
            'email' => $employee['email'] ?? $claims['email'] ?? null,
            // $orgUnits diakses via KEY 'level' (string bebas dari config di bawah),
            // bukan via nama kelas model.
            'department_id' => $orgUnits['department']->first()?->id,
            'division_id' => $orgUnits['division']->first()?->id,
        ];
    },
],
```

> **NIP bersifat opsional** — tidak semua sistem konsumen menyimpan kolom
> `nip`. Aman dibiarkan default (`match_by` termasuk `'nip'`) walau
> tabelnya tidak punya kolom itu sama sekali: baik saat MENCARI user
> (`match_by`) maupun saat MEMBUAT user baru (`defaultFill()`), package
> cek dulu ke skema tabel (`Schema::hasColumn()`) sebelum menyentuh kolom
> `nip` — kalau tidak ada, field itu dilewati diam-diam (bukan
> `QueryException` "unknown column" ataupun `MassAssignmentException`).
> Tidak perlu konfigurasi tambahan apa pun.

### 4.2 Direktori karyawan HRIS

```php
// Digunakan closure 'fill' di atas lewat helper hris_employee(). Token
// diperoleh dari tim HRIS (dibuat lewat panel Filament /keycloak, menu
// "Aplikasi Terhubung") — bukan dibuat sendiri.
'hris_directory' => [
    'base_url' => env('HRIS_API_BASE_URL'),
    'token' => env('HRIS_API_TOKEN'),
],
```

```
# .env
HRIS_API_BASE_URL=https://hris.syifa.../
HRIS_API_TOKEN=...
```

Endpoint tersebut (`GET /api/integrasi/karyawan/{nip}`, autentikasi Bearer
token) mengembalikan data resmi: `nip`, `name`, `email`, `is_active`,
`company`, dan `org_unit` (kode Departemen/Divisi/Sub Divisi tempat
karyawan ditempatkan). Apabila `HRIS_API_BASE_URL` kosong, token tidak
valid, atau NIP tidak ditemukan, `hris_employee($nip)` mengembalikan
`null` dan pengisian bawaan beralih ke klaim token sebagai fallback —
login tidak sepenuhnya gagal ketika HRIS sedang tidak dapat diakses,
meskipun data yang tersimpan menjadi kurang lengkap.

### 4.3 Level unit organisasi

```php
// 'level' WAJIB diisi di tiap baris — string bebas ('department', 'division',
// 'sub_division', atau apa pun) yang jadi key hasil OrgResolver & acuan pengisian
// bawaan UserResolver. HARUS SAMA PERSIS dengan key 'org_unit' pada respons
// hris_employee() (department/division/sub_division) — itu yang dipakai
// OrgResolver::resolveFromLevelCodes() (sumber utama). Sengaja terpisah dari
// 'model': nama model/tabel boleh apa saja (Department, Unit, Departemen, dst.)
// dan berbeda-beda antar sistem, tapi 'level' tetap kosakata yang sama.
'org_levels' => [
    ['model' => \App\Models\Department::class, 'code_column' => 'keycloak_code', 'level' => 'department'],
    ['model' => \App\Models\Division::class,   'code_column' => 'keycloak_code', 'level' => 'division'],
    ['model' => \App\Models\SubDivision::class, 'code_column' => 'keycloak_code', 'level' => 'sub_division'], // opsional, hapus apabila tidak diperlukan
],
```

Dua cara `OrgResolver` mengisi ini, otomatis dipilih di `KeycloakSsoController`:

1. **`resolveFromLevelCodes()`** (utama) — dari `org_unit` hasil `hris_employee($nip)`.
   Kode sudah per-level langsung dari HRIS (`{'department': 'kode', 'division': 'kode', ...}`),
   tinggal dicocokkan `where($code_column, $kode)` — tidak perlu Keycloak group sama sekali.
2. **`resolve()`** (fallback) — dari claim `groups` (path grup Keycloak), dipakai HANYA kalau
   `hris_directory` tidak dikonfigurasi atau API HRIS sedang tidak terjangkau. Mencocokkan
   seluruh segmen path terhadap seluruh level yang dikonfigurasi (bukan segmen[0] ↔ level[0]),
   sehingga tetap berfungsi meskipun kedalaman struktur organisasi berbeda antar cabang.

Di kedua cara, aplikasi boleh hanya mengonfigurasi satu level saja (mis. Divisi) tanpa
Departemen/Sub Divisi.

## Penggunaan

**Tombol login** (Blade):
```blade
<a href="{{ route('keycloak-sso.redirect') }}">Masuk dengan SSO</a>
```

**Logout**:
```blade
<form method="POST" action="{{ route('keycloak-sso.logout') }}">@csrf</form>
```

**Membaca grup/role user yang sedang login** (helper, tersedia secara global):
```php
keycloak_groups();          // ['/rsu-bjb/rsu-bjb-keperawatan/rsu-bjb-keperawatan-icu']
keycloak_has_role('hr-admin');
```

**Middleware role**:
```php
Route::middleware('keycloak.role:hr-admin')->group(...);
```

**Event** — titik hook tambahan setelah login berhasil (assign role
Spatie, pencatatan audit log, dsb.), tanpa perlu memodifikasi controller
pada package:
```php
// AppServiceProvider::boot()
Event::listen(\Syifa\KeycloakSso\Events\KeycloakLoginSucceeded::class, function ($e) {
    // $e->user, $e->claims, $e->orgUnits (dengan key berupa string 'level'), $e->roles
    $department = $e->orgUnits['department']->first() ?? null;
    $e->user->update(['department_id' => $department?->id]);
});
```

## Troubleshooting

**User yang sudah terdaftar duluan (sebelum pakai SSO) tidak ketemu / malah dibuatkan akun baru dobel**
→ Cek `match_by` di config — pastikan field yang jadi kunci identitas user
lama itu (biasanya `nip`) ada di array-nya, dan taruh di posisi yang
diprioritaskan. Default package: `['keycloak_sub', 'nip', 'email']`. Kalau
sistemmu tidak simpan `nip` sama sekali, pastikan `email` di data lama
sama persis dengan email di Keycloak — beda dikit (typo/domain lama) juga
gagal cocok. Kalau NIP-nya ada tapi di **tabel lain** (bukan kolom
langsung di tabel user, mis. tabel profil kepegawaian terpisah), `match_by`
string biasa tidak bisa menjangkau itu — ganti entrinya jadi closure
(lihat contoh di [4.1](#41-user-lokal)).

**"We are sorry... Invalid redirect uri" saat KLIK LOGIN / setelah isi form login Keycloak**
→ URL `redirect_uri` yang dikirim aplikasi tidak cocok persis dengan
**Valid Redirect URIs** di client Keycloak. Bandingkan karakter per
karakter: skema `http` vs `https`, trailing slash, port, dan pastikan
wildcard (`*`) masih ada kalau memang dipakai. Nilai yang dikirim
aplikasi = `KEYCLOAK_REDIRECT_URI` di `.env`, atau fallback
`APP_URL + /auth/keycloak/callback` kalau env itu kosong.

**"We are sorry... Invalid redirect uri" saat LOGOUT**
→ Sama persis pesannya, tapi field-nya beda: **Valid post logout redirect
URIs** (bukan Valid Redirect URIs) belum diisi atau tidak cocok dengan
`redirect_after_logout` di config. Dua field ini terpisah di Keycloak,
mudah salah satu kelewatan.

**`Class "Laravel\Socialite\Facades\Socialite" not found`**
→ `vendor/laravel/socialite` hilang/tidak sinkron dari `vendor/`. Jalankan
`composer install` di root aplikasi.

**`InvalidArgumentException: setiap baris 'org_levels' wajib punya key 'level'`**
→ Ada baris di `org_levels` yang belum diisi `'level' => '...'`. Wajib
sejak versi ini (dulu ditebak dari nama model, sekarang eksplisit karena
nama model beda-beda tiap sistem).

**Login sukses tapi `keycloak_groups()`/role selalu kosong**
→ Mapper "Group Membership" / "Client Roles" di client scope belum
dicentang **Add to userinfo** (lihat [langkah 2.4](#2-konfigurasi-keycloak-sekali-per-aplikasi-baru)). Package baca dari
`/userinfo`, bukan decode token — kalau cuma "Add to access/ID token",
claim tidak akan terbawa. Ini beda kasus dari poin di bawah — cuma
mempengaruhi `groups`/role, TIDAK mempengaruhi unit organisasi (yang
sekarang utamanya dari API HRIS, bukan claim ini).

**User berhasil dibuat, tapi kolom departemen/divisi tetap kosong**
Urut dari yang paling sering:
1. `hris_directory.base_url`/`token` belum diisi di `.env` aplikasi — cek
   `HRIS_API_BASE_URL`/`HRIS_API_TOKEN`. Tanpa ini, `hris_employee()` selalu
   `null`, otomatis fallback ke Keycloak groups (kalau itu juga kosong, ya
   tidak ke-isi).
2. Karyawan itu di HRIS memang belum punya jabatan aktif, atau unit
   jabatannya belum diisi `code` (menu Master → Departemen/Divisi/Sub
   Divisi di HRIS) — cek lewat `GET /api/integrasi/karyawan/{nip}`
   langsung, lihat isi `org_unit`-nya.
3. `level` di `org_levels` aplikasi tidak sama persis dengan key `org_unit`
   dari HRIS (`department`/`division`/`sub_division`) — typo atau beda
   bahasa (mis. `'level' => 'divisi'`) bikin tidak pernah cocok.
4. **User itu sudah ada SEBELUM data org di HRIS lengkap.** Unit
   organisasi cuma di-isi sekali, saat user PERTAMA KALI dibuat
   (`provision`). Kalau user sudah lebih dulu ada (dibuat manual, atau
   login pertama terjadi sebelum jabatannya lengkap di HRIS), login
   berikutnya TIDAK otomatis mengupdate `department_id`/`division_id` —
   `UserResolver` cuma menemukan user lama dan memakainya apa adanya.
   Kalau mau selalu sinkron ulang tiap login (bukan cuma sekali di awal),
   dengarkan event `KeycloakLoginSucceeded` (lihat [Penggunaan](#penggunaan))
   — event ini dapat `$orgUnits` segar setiap login, termasuk untuk user
   yang sudah ada, jadi listener bisa `update()` manual di situ.

**Path repository (`@dev`) sudah `composer require`, tapi perubahan
terbaru package belum kerasa**
→ Di Windows, path repository tidak selalu symlink (kadang di-copy).
Cek `Get-Item vendor\syifa\keycloak-sso | Select-Object LinkType` — kalau
kosong (bukan symlink), jalankan `composer update syifa/keycloak-sso`.
Kalau sebelumnya sudah `vendor:publish`, `config/keycloak-sso.php` di
aplikasi adalah **salinan** yang tidak ikut ter-update otomatis — edit
manual sesuai perubahan terbaru.

## Pengujian

```bash
composer install
vendor/bin/phpunit
```

Terdapat 28 pengujian (`OrgResolverTest`, `UserResolverTest`,
`HrisDirectoryClientTest`) yang dijalankan menggunakan Orchestra Testbench
dan SQLite in-memory, tanpa memerlukan instans Keycloak sungguhan.

---

**Catatan buat HRIS sendiri:** HRIS **tidak** memakai package ini. HRIS
pakai `socialiteproviders/keycloak` langsung lewat
`App\Http\Controllers\Auth\KeycloakController` — logikanya spesifik
(match by NIP dulu, tanpa auto-provision) dan sudah lama berjalan. Package
ini ditujukan untuk sistem lain di luar HRIS.
