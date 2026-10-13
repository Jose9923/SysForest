<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    public function up(): void
    {
        $this->assignAuditPermission(['SUPER_ADMIN', 'DEPARTMENT_ADMIN']);
    }

    public function down(): void
    {
        $this->assignAuditPermission(['SUPER_ADMIN', 'ORGANIZATION_ADMIN']);
    }

    /** Modifica únicamente audit.view; no resincroniza los demás permisos ni las asignaciones de usuarios. */
    private function assignAuditPermission(array $roleNames): void
    {
        DB::table('permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'code' => 'audit.view',
            'description' => 'Consultar auditoría privada en el alcance administrativo autorizado.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('code', 'audit.view')->value('id');
        $roleIds = DB::table('roles')->whereIn('name', $roleNames)->pluck('id')->all();
        DB::table('role_permissions')->where('permission_id', $permissionId)->whereNotIn('role_id', $roleIds)->delete();

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }
};
