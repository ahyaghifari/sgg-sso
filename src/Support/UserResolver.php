<?php

namespace Syifa\KeycloakSso\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Mencocokkan claim token Keycloak terhadap user lokal aplikasi. Apabila
 * user tidak ditemukan, 'provision' (aktif secara default) membuat user
 * baru. Closure 'fill' pada konfigurasi mengontrol pengisian data user
 * baru tersebut, termasuk menerima $orgUnits (hasil OrgResolver, dengan key
 * berupa string 'level' seperti 'department'/'division') agar user dapat
 * langsung ditempatkan pada departemen, divisi, dan/atau sub-divisi yang
 * sesuai sejak awal dibuat. Apabila 'fill' tidak diisi, digunakan pengisian
 * bawaan (defaultFill()) yang mengambil nama/email dari HRIS dan mengisi
 * kolom unit organisasi mengikuti konvensi `{level}_id` — lihat catatan
 * pada defaultFill().
 *
 * Entri 'match_by' pada config biasanya nama kolom di tabel model utama,
 * tapi boleh juga Closure(array $claims, string $modelClass): ?Model —
 * dipakai kalau data pencocokannya (mis. NIP) disimpan di tabel/model lain
 * yang relasi ke user, bukan di tabel user itu sendiri.
 */
class UserResolver
{
    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, \Illuminate\Support\Collection>  $orgUnits  hasil OrgResolver, dengan key berupa string 'level' (mis. 'department', 'division')
     * @param  array<string, mixed>|null  $employee  hasil hris_employee($nip) yang SUDAH di-fetch controller (kalau ada) — dipakai ulang di defaultFill() supaya tidak fetch dobel ke API HRIS. Boleh dikosongkan (mis. dipanggil langsung tanpa lewat controller), defaultFill() akan fetch sendiri.
     */
    public function resolve(array $claims, array $orgUnits = [], ?array $employee = null): Model
    {
        $config = config('keycloak-sso.user');
        /** @var class-string<Model> $modelClass */
        $modelClass = $config['model'];
        $subColumn = $config['sub_column'] ?? 'keycloak_sub';
        $sub = $claims['sub'] ?? null;

        $user = null;
        $table = (new $modelClass)->getTable();

        // Urutan array 'match_by' = urutan prioritas: entri pertama yang ketemu user-nya
        // yang menang, sisanya tidak dicoba. Host app menentukan sendiri NIP atau email
        // yang diutamakan cukup dengan mengatur urutan ini di config.
        foreach (($config['match_by'] ?? [$subColumn]) as $field) {
            // Entri closure: data pencocokannya BUKAN kolom langsung di tabel model utama
            // (mis. NIP disimpan di tabel/model lain yang relasi ke user, bukan di tabel
            // user itu sendiri) — host app tulis sendiri cara carinya, dikasih $claims &
            // nama kelas model, balikin Model kalau ketemu atau null kalau tidak.
            if ($field instanceof Closure) {
                $user = $field($claims, $modelClass);

                if ($user) {
                    break;
                }

                continue;
            }

            // Field yang kolomnya memang tidak ada di tabel (mis. 'nip' di sistem yang
            // tidak menyimpan NIP) dilewati, BUKAN bikin query error "unknown column" —
            // sama filosofinya dengan onlyExistingColumns() di defaultFill().
            if (! Schema::hasColumn($table, $field)) {
                continue;
            }

            $value = match (true) {
                $field === $subColumn => $sub,
                // NIP kadang cuma ada di claim 'preferred_username' (tergantung mapping
                // client scope di Keycloak), bukan claim 'nip' terpisah — sama fallback-nya
                // dengan defaultFill() di bawah & KeycloakController HRIS sendiri.
                $field === 'nip' => $claims['nip'] ?? $claims['preferred_username'] ?? null,
                default => $claims[$field] ?? null,
            };

            if (blank($value)) {
                continue;
            }

            $user = $modelClass::where($field, $value)->first();

            if ($user) {
                break;
            }
        }

        if (! $user && ($config['provision'] ?? true)) {
            $fill = $config['fill'] ?? fn (array $c, array $o) => $this->defaultFill($c, $o, $modelClass, $employee);

            $user = $modelClass::create($fill($claims, $orgUnits));
        }

        if (! $user) {
            throw new HttpException(403, 'Akun SSO belum terdaftar di sistem ini. Hubungi administrator.');
        }

        if ($sub && blank($user->{$subColumn})) {
            $user->forceFill([$subColumn => $sub])->save();
        }

        $activeCheck = $config['active_check'] ?? null;

        if ($activeCheck && ! $activeCheck($user)) {
            throw new HttpException(403, 'Akun Anda sudah dinonaktifkan.');
        }

        return $user;
    }

    /**
     * Pengisian bawaan saat user baru dibuat ('provision' aktif) tanpa closure
     * 'fill' eksplisit di konfigurasi. Nama dan email diambil dari
     * hris_employee($nip) apabila 'hris_directory' dikonfigurasi, dengan
     * fallback ke claim token. Kolom unit organisasi diisi mengikuti
     * konvensi `{level}_id`, dengan `level` berasal dari key 'level' pada
     * config('keycloak-sso.org_levels') — BUKAN ditebak dari nama kelas
     * model, karena penamaan model/tabel berbeda-beda antar sistem
     * (mis. Department vs Unit vs Departemen), sedangkan 'level' adalah
     * kosakata semantik yang tetap sama di seluruh sistem.
     *
     * Kolom 'nip' turut disertakan dalam kandidat, tapi TIDAK semua sistem
     * menyimpan NIP — kolomnya mungkin memang tidak ada. Hasil karena itu
     * disaring lewat onlyExistingColumns(): kalau model mendeklarasikan
     * $fillable, dipakai itu (aturan mass-assignment aplikasi dihormati);
     * kalau tidak (mis. model pakai `$guarded = []`, fillable-nya kosong
     * tapi tetap valid), fallback cek langsung ke skema tabel via
     * Schema::hasColumn() — supaya kolom yang benar-benar tidak ada
     * (seperti 'nip' di sistem yang tidak menyimpannya) tidak memicu error
     * SQL "unknown column", bukan cuma menghindari
     * MassAssignmentException. Aplikasi dengan konvensi kolom berbeda
     * sebaiknya mengisi 'fill' sendiri di config/keycloak-sso.php.
     *
     * @param  array<string, \Illuminate\Support\Collection>  $orgUnits  key berupa string 'level'
     * @param  array<string, mixed>|null  $employee  hasil hris_employee($nip) dari controller — kalau null, di-fetch sendiri di sini (dipanggil langsung tanpa lewat controller, mis. dari test)
     * @return array<string, mixed>
     */
    private function defaultFill(array $claims, array $orgUnits, string $modelClass, ?array $employee = null): array
    {
        $nip = $claims['nip'] ?? $claims['preferred_username'] ?? null;
        $employee ??= ($nip && function_exists('hris_employee')) ? hris_employee($nip) : null;

        $candidate = [
            'nip' => $nip,
            'name' => $employee['name'] ?? $claims['name'] ?? $nip,
            'email' => $employee['email'] ?? $claims['email'] ?? null,
        ];

        foreach ($orgUnits as $level => $units) {
            $candidate["{$level}_id"] = $units->first()?->id;
        }

        return $this->onlyExistingColumns($candidate, $modelClass);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(array $candidate, string $modelClass): array
    {
        /** @var Model $model */
        $model = new $modelClass;
        $fillable = $model->getFillable();

        if ($fillable) {
            return array_intersect_key($candidate, array_flip($fillable));
        }

        // $fillable kosong (model pakai $guarded, bukan berarti semua kolom aman) — cek
        // langsung ke skema tabel supaya kolom yang memang tidak ada (mis. 'nip' pada
        // sistem yang tidak menyimpannya) tidak diteruskan ke query INSERT.
        $table = $model->getTable();

        return array_filter(
            $candidate,
            fn (string $column) => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }
}
