<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;

require __DIR__.'/web/public-portal/routes.php';
require __DIR__.'/web/reports/public.php';

// Disponibilidad técnica independiente de Redis y de las sesiones de usuario.
require __DIR__.'/web/infrastructure/routes.php';

Route::middleware('identity.headers')->group(function (): void {
    require __DIR__.'/web/auth/entry.php';
    Route::middleware('jwt.optional')->group(function (): void {
        require __DIR__.'/web/auth/notice.php';
        require __DIR__.'/web/invitations/accept.php';
    });
    Route::middleware(['jwt.cookie', 'identity.session'])->group(function (): void {
        require __DIR__.'/web/auth/verification.php';
        require __DIR__.'/web/profile/routes.php';

        Route::middleware(['identity.verified', 'tenant.share'])->group(function (): void {
            require __DIR__.'/web/catalogs/routes.php';
            require __DIR__.'/web/maps/routes.php';
            require __DIR__.'/web/reports/routes.php';
            require __DIR__.'/web/analytics/routes.php';
            require __DIR__.'/web/audit/routes.php';
            require __DIR__.'/web/territories/routes.php';
            require __DIR__.'/web/organizations/browse.php';
            require __DIR__.'/web/users/routes.php';
            Route::middleware('tenant.context')->group(function (): void {
                require __DIR__.'/web/evidences/routes.php';
                require __DIR__.'/web/inspections/routes.php';
                require __DIR__.'/web/forestry/dendrometry.php';
                require __DIR__.'/web/trees/routes.php';
                require __DIR__.'/web/forestry/coordinates.php';
                require __DIR__.'/web/inventories/routes.php';
                require __DIR__.'/web/maintenances/routes.php';
                require __DIR__.'/web/risk/routes.php';
                require __DIR__.'/web/carbon/routes.php';
                require __DIR__.'/web/imports/routes.php';
                require __DIR__.'/web/publication/routes.php';
                require __DIR__.'/web/organizations/tenant.php';
                require __DIR__.'/web/memberships/routes.php';
                require __DIR__.'/web/invitations/manage.php';
            });
        });
    });
});
