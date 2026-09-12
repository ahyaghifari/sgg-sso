<?php

namespace Syifa\KeycloakSso\Support;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Mencocokkan unit organisasi (departemen, divisi, dst. — lihat
 * config('keycloak-sso.org_levels')) terhadap model lokal, dari SALAH SATU
 * dari dua sumber:
 *
 * 1. resolveFromLevelCodes() — dari `org_unit` hasil hris_employee($nip)
 *    (API HRIS). Ini sumber UTAMA: kode sudah datang per-level langsung
 *    dari HRIS (department/division/sub_division), jadi tidak perlu
 *    parsing/tebak-tebak, dan tidak bergantung Keycloak group sama sekali
 *    (tidak perlu mapper Group Membership, tidak perlu assign user ke grup
 *    manual di Keycloak — sumber datanya jabatan aktif karyawan di HRIS).
 *
 * 2. resolve() — dari claim `groups` (path grup Keycloak). Dipakai sebagai
 *    FALLBACK kalau API HRIS tidak terjangkau/tidak dikonfigurasi (lihat
 *    KeycloakSsoController::callback()), supaya provisioning tetap dapat
 *    info unit organisasi walau hris_directory sedang bermasalah.
 *
 * 'level' pada tiap baris 'org_levels' WAJIB diisi eksplisit — BUKAN
 * ditebak dari nama kelas model, karena nama model/tabel bisa berbeda-beda
 * antar sistem (mis. Department, Unit, Departemen), sementara 'level'
 * adalah kosakata semantik yang tetap sama di kedua sumber di atas.
 */
class OrgResolver
{
    /**
     * Sumber UTAMA — kode per level langsung dari HRIS, tidak perlu Keycloak
     * group sama sekali.
     *
     * @param  array<string, ?string>  $levelCodes  'org_unit' dari respons hris_employee($nip), mis. ['department' => 'rsu-bjb-keperawatan', 'division' => 'rsu-bjb-keperawatan-icu', 'sub_division' => null]
     * @return array<string, Collection<int, \Illuminate\Database\Eloquent\Model>>
     */
    public function resolveFromLevelCodes(array $levelCodes): array
    {
        $result = [];

        foreach (config('keycloak-sso.org_levels', []) as $levelConfig) {
            $level = $this->requireLevel($levelConfig);
            $code = $levelCodes[$level] ?? null;

            if (blank($code)) {
                continue;
            }

            $modelClass = $levelConfig['model'];
            $column = $levelConfig['code_column'] ?? 'keycloak_code';

            $match = $modelClass::query()->where($column, $code)->first();

            if ($match) {
                $result[$level] = collect([$match]);
            }
        }

        return $result;
    }

    /**
     * Fallback — dipakai kalau API HRIS tidak terjangkau/tidak dikonfigurasi.
     * Path Keycloak: "/rsu-bjb/rsu-bjb-keperawatan/rsu-bjb-keperawatan-icu".
     * Setiap segmen merupakan kode teknis (bukan nama tampilan) yang dicocokkan
     * terhadap kolom `code_column` pada masing-masing model. Seluruh segmen
     * dari seluruh path dicoba terhadap SEMUA level yang dikonfigurasi (bukan
     * segmen[0] -> level[0] dan seterusnya), sehingga resolusi tidak bergantung
     * pada keseragaman kedalaman path.
     *
     * @param  array<int, string>  $groupPaths  isi claim 'groups' dari token
     * @return array<string, Collection<int, \Illuminate\Database\Eloquent\Model>>
     */
    public function resolve(array $groupPaths): array
    {
        $codes = $this->extractCodes($groupPaths);

        if ($codes->isEmpty()) {
            return [];
        }

        $result = [];

        foreach (config('keycloak-sso.org_levels', []) as $levelConfig) {
            $level = $this->requireLevel($levelConfig);
            $modelClass = $levelConfig['model'];
            $column = $levelConfig['code_column'] ?? 'keycloak_code';

            $matches = $modelClass::query()->whereIn($column, $codes)->get();

            if ($matches->isNotEmpty()) {
                $result[$level] = $matches;
            }
        }

        return $result;
    }

    private function requireLevel(array $levelConfig): string
    {
        return $levelConfig['level'] ?? throw new InvalidArgumentException(
            "keycloak-sso: setiap baris 'org_levels' wajib punya key 'level' (mis. 'department', 'division', 'sub_division') — tidak bisa ditebak dari nama model karena penamaan model/tabel berbeda-beda antar sistem."
        );
    }

    /** @return Collection<int, string> */
    private function extractCodes(array $groupPaths): Collection
    {
        return collect($groupPaths)
            ->flatMap(fn (string $path) => explode('/', trim($path, '/')))
            ->filter()
            ->unique()
            ->values();
    }
}
