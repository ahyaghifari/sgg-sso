<?php

namespace Syifa\KeycloakSso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Syifa\KeycloakSso\Events\KeycloakLoginSucceeded;
use Syifa\KeycloakSso\Support\OrgResolver;
use Syifa\KeycloakSso\Support\UserResolver;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KeycloakSsoController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('keycloak')->redirect();
    }

    public function callback(Request $request, UserResolver $users, OrgResolver $orgs): RedirectResponse
    {
        try {
            $kc = Socialite::driver('keycloak')->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->guest('/')->withErrors(['keycloak' => 'Login SSO gagal. Silakan coba lagi.']);
        }

        // getRaw() mengembalikan respons endpoint /userinfo. Claim 'resource_access' (role)
        // harus diaktifkan "Add to userinfo" pada mapper Keycloak, tidak cukup hanya "Add to
        // access/ID token" — lihat README. Dengan pendekatan ini, package tidak perlu melakukan
        // dekode atau verifikasi JWT secara manual.
        $claims = $kc->getRaw();
        $claims['sub'] ??= $kc->getId();

        // Unit organisasi (departemen/divisi/dst.) di-resolve dari API HRIS DULU — itu sumber
        // UTAMA (org_unit hasil hris_employee(), sudah per-level langsung dari jabatan aktif
        // karyawan, tidak butuh setup grup Keycloak sama sekali). Claim `groups` cuma dipakai
        // sebagai FALLBACK kalau API HRIS tidak terjangkau/tidak dikonfigurasi — supaya
        // provisioning tetap dapat info unit organisasi walau HRIS sedang bermasalah.
        $nip = $claims['nip'] ?? $claims['preferred_username'] ?? null;
        $employee = ($nip && function_exists('hris_employee')) ? hris_employee($nip) : null;
        $employeeOrgUnit = array_filter($employee['org_unit'] ?? []);

        $orgUnits = $employeeOrgUnit
            ? $orgs->resolveFromLevelCodes($employeeOrgUnit)
            : $orgs->resolve($claims['groups'] ?? []);

        try {
            $user = $users->resolve($claims, $orgUnits, $employee);
        } catch (HttpException $e) {
            return redirect()->guest('/')->withErrors(['keycloak' => $e->getMessage()]);
        }

        $roles = $this->resolveRoles($claims);

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->put('keycloak.sso', true);
        $request->session()->put('keycloak.groups', $claims['groups'] ?? []);
        $request->session()->put('keycloak.roles', $roles);

        KeycloakLoginSucceeded::dispatch($user, $claims, $orgUnits, $roles);

        return redirect()->intended(config('keycloak-sso.routes.redirect_after_login', '/'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $wasSso = $request->session()->get('keycloak.sso');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! $wasSso || ! config('keycloak-sso.base_url')) {
            return redirect(config('keycloak-sso.routes.redirect_after_logout', '/'));
        }

        // Single logout: sesi pada Keycloak turut diakhiri, sehingga login "Masuk dengan SSO"
        // berikutnya tidak langsung berhasil tanpa proses autentikasi ulang. Dipakai method
        // bawaan provider (bukan bikin URL manual) karena sudah menangani perbedaan Keycloak
        // <18 (param redirect_uri) vs >=18 (post_logout_redirect_uri + client_id/id_token_hint).
        //
        // PENTING soal error "Invalid redirect uri"/"We are sorry...": redirect_after_logout
        // di bawah HARUS terdaftar persis di Keycloak Admin Console -> Clients -> client ini ->
        // Settings -> "Valid post logout redirect URIs". Field ini TERPISAH dari "Valid
        // redirect URIs" (dipakai buat login) — penyebab paling umum error ini adalah field
        // tersebut kosong atau tidak cocok persis (termasuk trailing slash) dengan URL di sini.
        $logoutUrl = Socialite::driver('keycloak')->getLogoutUrl(
            url(config('keycloak-sso.routes.redirect_after_logout', '/')),
            config('keycloak-sso.client_id'),
        );

        return redirect($logoutUrl);
    }

    /** @return array<int, string> */
    private function resolveRoles(array $claims): array
    {
        if (! config('keycloak-sso.roles.enabled')) {
            return [];
        }

        $clientId = config('keycloak-sso.roles.client_id') ?? config('keycloak-sso.client_id');

        return $claims['resource_access'][$clientId]['roles'] ?? [];
    }
}
