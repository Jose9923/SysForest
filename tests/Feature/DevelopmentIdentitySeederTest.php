<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DepartmentRoleAssignment;
use App\Models\NationalRoleAssignment;
use App\Models\OrganizationMembership;
use App\Models\OrganizationRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Repositories\UserRepository;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentIdentitySeeder;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\PostgresTestCase;

class DevelopmentIdentitySeederTest extends PostgresTestCase
{
    private const TEST_PASSWORD = 'Fixture-Only!Development-2026';

    private int $initialUserCount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialUserCount = User::query()->count();
        $accounts = [];

        foreach (['SUPER_ADMIN', 'DEPARTMENT_ADMIN', 'ORGANIZATION_ADMIN', 'OFFICIAL'] as $role) {
            $accounts[$role] = [
                'name' => 'Cuenta de prueba '.$role,
                'email' => 'dev-'.strtolower(str_replace('_', '-', $role)).'@sysforest.test',
                'password' => self::TEST_PASSWORD,
            ];
        }

        // Estas credenciales pertenecen al fixture y nunca se leen del entorno real.
        config(['development.accounts' => $accounts]);
    }

    public function test_testing_environment_creates_four_verified_accounts_with_correct_scopes(): void
    {
        $this->seed(DevelopmentIdentitySeeder::class);
        $this->assertSame($this->initialUserCount + 4, User::query()->count());

        foreach (config('development.accounts') as $account) {
            $user = app(UserRepository::class)->findByEmail($account['email']);
            $this->assertNotNull($user);
            $this->assertTrue((bool) $user->active);
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue(Hash::check(self::TEST_PASSWORD, $user->password));
        }

        $national = $this->account('SUPER_ADMIN');
        $department = $this->account('DEPARTMENT_ADMIN');
        $this->assertDatabaseHas('national_role_assignments', ['user_id' => $national->id, 'active' => true]);
        $departmentAssignment = DepartmentRoleAssignment::query()->with('department')->where('user_id', $department->id)->firstOrFail();
        $this->assertSame('50', $departmentAssignment->department->code);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $national->id]);
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $department->id]);

        foreach (['ORGANIZATION_ADMIN', 'OFFICIAL'] as $role) {
            $user = $this->account($role);
            $membership = OrganizationMembership::query()->with('organization')->where('user_id', $user->id)->firstOrFail();
            $assignment = OrganizationRoleAssignment::query()->with('role')->where('membership_id', $membership->id)->firstOrFail();
            $this->assertSame('INEM-VVC', $membership->organization->code);
            $this->assertSame($role, $assignment->role->name);
        }
    }

    public function test_local_environment_is_allowed_explicitly(): void
    {
        $this->app->instance('env', 'local');

        try {
            $this->seed(DevelopmentIdentitySeeder::class);
            $this->assertSame($this->initialUserCount + 4, User::query()->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_production_is_rejected_before_creating_accounts(): void
    {
        $this->app->instance('env', 'production');

        try {
            app(DevelopmentIdentitySeeder::class)->run();
            $this->fail('El seeder de desarrollo no debe ejecutarse en producción.');
        } catch (LogicException) {
            $this->assertSame($this->initialUserCount, User::query()->count());
            $this->assertSame(0, NationalRoleAssignment::query()->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_missing_credentials_fail_before_any_account_is_created(): void
    {
        config(['development.accounts.OFFICIAL.password' => null]);

        try {
            app(DevelopmentIdentitySeeder::class)->run();
            $this->fail('Las cuatro configuraciones deben estar completas.');
        } catch (LogicException) {
            $this->assertSame($this->initialUserCount, User::query()->count());
        }
    }

    public function test_weak_password_is_rejected_without_exposing_its_value(): void
    {
        config(['development.accounts.SUPER_ADMIN.password' => 'weak-fixture']);

        try {
            app(DevelopmentIdentitySeeder::class)->run();
            $this->fail('La contraseña débil debía rechazarse.');
        } catch (LogicException $exception) {
            $this->assertStringNotContainsString('weak-fixture', $exception->getMessage());
            $this->assertSame($this->initialUserCount, User::query()->count());
        }
    }

    public function test_profiles_cannot_share_the_same_case_insensitive_email(): void
    {
        config(['development.accounts.OFFICIAL.email' => strtoupper(config('development.accounts.SUPER_ADMIN.email'))]);

        try {
            app(DevelopmentIdentitySeeder::class)->run();
            $this->fail('Los perfiles de desarrollo deben utilizar cuentas distintas.');
        } catch (LogicException) {
            $this->assertSame($this->initialUserCount, User::query()->count());
        }
    }

    public function test_repeated_seeding_preserves_accounts_roles_and_existing_passwords(): void
    {
        $this->seed(DevelopmentIdentitySeeder::class);
        $before = User::query()->whereIn('email', array_column(config('development.accounts'), 'email'))
            ->orderBy('id')->get()->keyBy('email');
        $membershipIds = OrganizationMembership::query()->orderBy('id')->pluck('id')->all();
        $assignmentIds = OrganizationRoleAssignment::query()->orderBy('id')->pluck('id')->all();

        foreach (array_keys(config('development.accounts')) as $role) {
            config(['development.accounts.'.$role.'.password' => 'Different!Fixture-Password-2026']);
        }

        $this->seed(DevelopmentIdentitySeeder::class);

        $this->assertSame($this->initialUserCount + 4, User::query()->count());
        $this->assertSame($membershipIds, OrganizationMembership::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($assignmentIds, OrganizationRoleAssignment::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, NationalRoleAssignment::query()->count());
        $this->assertSame(1, DepartmentRoleAssignment::query()->count());

        foreach ($before as $email => $previous) {
            $current = app(UserRepository::class)->findByEmail($email);
            $this->assertSame($previous->id, $current->id);
            $this->assertTrue(hash_equals($previous->password, $current->password));
            $this->assertTrue(Hash::check(self::TEST_PASSWORD, $current->password));
        }
    }

    public function test_an_existing_account_keeps_its_name_state_and_verification(): void
    {
        $existing = User::factory()->create([
            'name' => 'Nombre existente',
            'email' => config('development.accounts.SUPER_ADMIN.email'),
            'password' => 'Existing!Fixture-Password-2026',
            'active' => false,
            'email_verified_at' => null,
        ]);
        $previousHash = $existing->password;

        $this->seed(DevelopmentIdentitySeeder::class);
        $existing->refresh();

        $this->assertSame('Nombre existente', $existing->name);
        $this->assertFalse((bool) $existing->active);
        $this->assertNull($existing->email_verified_at);
        $this->assertTrue(hash_equals($previousHash, $existing->password));
        $this->assertDatabaseMissing('organization_memberships', ['user_id' => $existing->id]);
    }

    public function test_general_database_seeder_creates_catalog_but_no_development_accounts(): void
    {
        $this->app->instance('env', 'production');

        try {
            $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
            $this->assertSame(4, Role::query()->count());
            $this->assertSame($this->initialUserCount, User::query()->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    private function account(string $role): User
    {
        return app(UserRepository::class)->findByEmail(config('development.accounts.'.$role.'.email'));
    }
}
