<?php

declare(strict_types=1);

namespace App\Auditing;

/** Catálogo común de pantalla y exportación; clasifica sin reescribir eventos históricos. */
class AuditCatalog
{
    private const MODULES = [
        'access' => ['Acceso y sesiones', ['auth', 'password', 'email']],
        'users' => ['Usuarios y permisos', ['user', 'membership', 'invitation', 'role']],
        'organizations' => ['Organizaciones', ['organization']],
        'territories' => ['Territorios', ['territory']],
        'catalogs' => ['Catálogos forestales', ['catalog', 'taxonomy', 'classification']],
        'inventories' => ['Inventarios', ['inventory', 'inventory_tree']],
        'trees' => ['Árboles', ['tree']],
        'carbon' => ['Carbono almacenado', ['carbon']],
        'risk' => ['Gestión del riesgo', ['risk']],
        'inspections' => ['Inspecciones', ['inspection']],
        'evidences' => ['Fotografías y evidencias', ['evidence']],
        'maintenances' => ['Mantenimientos', ['maintenance']],
        'publications' => ['Publicaciones', ['publication']],
        'imports' => ['Importaciones', ['import']],
        'reports' => ['Reportes', ['reports']],
        'audit' => ['Auditoría', ['audit']],
        'other' => ['Otros registros', []],
    ];

    public static function modules(): array
    {
        return array_map(fn (array $definition): string => $definition[0], self::MODULES);
    }

    public static function prefixes(string $module): array
    {
        return self::MODULES[$module][1] ?? [];
    }

    public static function knownPrefixes(): array
    {
        return array_merge(...array_column(self::MODULES, 1));
    }

    public static function moduleFor(string $event): string
    {
        $prefix = explode('.', $event)[0];
        foreach (self::MODULES as $module => [, $prefixes]) {
            if (in_array($prefix, $prefixes, true)) {
                return $module;
            }
        }

        return 'other';
    }

    public static function moduleLabel(string $event): string
    {
        return self::modules()[self::moduleFor($event)];
    }

    public static function eventLabel(string $event): string
    {
        $specific = [
            'carbon.references.seeded' => 'Precarga de referencias bibliográficas de carbono',
            'carbon.profile.published' => 'Publicación de referencia de carbono',
            'carbon.estimates.refreshed' => 'Actualización de estimaciones de carbono',
            'auth.login.succeeded' => 'Inicio de sesión', 'auth.login.failed' => 'Inicio de sesión fallido',
            'auth.session.revoked' => 'Cierre o revocación de sesión', 'auth.refresh.reused' => 'Reutilización de credencial detectada',
            'password.changed' => 'Cambio de contraseña', 'password.reset' => 'Restablecimiento de contraseña',
            'email.verified' => 'Verificación de correo', 'reports.export_prepared' => 'Exportación de reporte',
            'audit.export_prepared' => 'Exportación de auditoría', 'import.completed' => 'Importación completada',
            'inventory.tree_included' => 'Incorporación de árbol', 'password.development_default' => 'Configuración de contraseña de desarrollo', 'import.preview_created' => 'Vista previa de importación',
        ];
        if (isset($specific[$event])) {
            return $specific[$event];
        }
        $actions = ['created' => 'Creación', 'updated' => 'Actualización', 'voided' => 'Anulación', 'restored' => 'Restauración',
            'deleted' => 'Anulación', 'status_changed' => 'Cambio de estado', 'location_changed' => 'Cambio de ubicación',
            'changed' => 'Cambio', 'assigned' => 'Asignación', 'revoked' => 'Revocación', 'accepted' => 'Aceptación',
            'confirmed' => 'Confirmación', 'cancelled' => 'Cancelación', 'validated' => 'Validación', 'uploaded' => 'Carga de fotografía',
            'activated' => 'Activación', 'deactivated' => 'Desactivación', 'reordered' => 'Cambio de orden',
            'verified' => 'Verificación', 'included' => 'Incorporación', 'sealed' => 'Cierre de versión'];
        $parts = explode('.', $event);

        return $actions[end($parts)] ?? $event;
    }
}
