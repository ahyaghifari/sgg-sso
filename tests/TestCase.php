<?php

namespace Syifa\KeycloakSso\Tests;

use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Syifa\KeycloakSso\KeycloakSsoServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [KeycloakSsoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('kc_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('keycloak_sub')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('nip')->nullable();
        });

        Schema::create('kc_departments', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('keycloak_code')->unique();
        });

        Schema::create('kc_divisions', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('keycloak_code')->unique();
        });

        Schema::create('kc_subdivisions', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('keycloak_code')->unique();
        });

        // Tanpa kolom 'nip'/'department_id' — mewakili sistem yang tidak menyimpan NIP.
        Schema::create('kc_guarded_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('keycloak_sub')->nullable();
        });

        // NIP disimpan di sini, TERPISAH dari kc_users — mewakili sistem dengan tabel
        // profil/kepegawaian sendiri (relasi hasOne ke user), bukan kolom langsung di users.
        Schema::create('kc_user_profiles', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('nip')->nullable();
        });
    }
}
