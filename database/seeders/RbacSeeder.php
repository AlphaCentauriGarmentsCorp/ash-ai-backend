<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RBAC Seeder for ASH AI – Apparel Smart Hub
 *
 * Seeds the complete role + permission matrix for all 16 production roles
 * defined in the ASH AI master brief (see ASH-AI-Process.pdf §7 + §9).
 *
 * Permission naming conventions:
 *   access.*  – legacy/admin module access (clients, orders, suppliers, etc.)
 *   portal.*  – role-specific portal landing pages (sewer, cutter, printer, …)
 *   action.*  – cross-cutting capabilities (request materials, advance stages, …)
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // -------------------------------------------------------------------
        // 1. PERMISSIONS
        // -------------------------------------------------------------------
        $accessPermissions = [
            'access.clients',
            'access.orders',
            'access.quotations',
            'access.dropdown-settings',
            'access.quotation-settings',
            'access.download',
            'access.employees',
            'access.rbac',
            'access.equipment',
            'access.suppliers',
            'access.materials',
            'access.screens',
            'access.payment-methods',
            'access.shipping-methods',
            'access.courier-list',
            'access.sewing-subcontractor',
            'access.order-stages',
            'access.graphic-design',
            'access.screen-making',
            'access.screen-checking',
            'access.screen-maintenance',
            'access.pantone',
            'access.tickets',
            'access.notifications',
            'access.reports',
        ];

        $portalPermissions = [
            'portal.csr',
            'portal.graphic-artist',
            'portal.screen-maker',
            'portal.material-prep',     // a.k.a. Purchaser
            'portal.cutter',
            'portal.printer',
            'portal.sewer',
            'portal.qa',
            'portal.packer',
            'portal.logistics',
            'portal.finance',
            'portal.warehouse',
            'portal.driver',
            'portal.subcontract',
        ];

        $actionPermissions = [
            'action.request-materials',     // any production role
            'action.advance-stage',         // mark a stage complete
            'action.upload-photos',         // upload reject/output photos
            'action.approve-samples',       // CSR/QA – approve samples
            'action.approve-quotation',     // CSR/Admin
            'action.verify-payment',        // Finance
            'action.assign-stages',         // Admin – override stage assignments
            'action.create-orders',         // CSR
            'action.manage-subcontract',    // assign + track subcontract jobs
            'action.process-purchase',      // Purchaser – buy materials, pay supplier
        ];

        $allPermissions = array_merge(
            $accessPermissions,
            $portalPermissions,
            $actionPermissions
        );

        foreach ($allPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // -------------------------------------------------------------------
        // 2. ROLES + PERMISSION ASSIGNMENT
        // -------------------------------------------------------------------
        $roleMatrix = [
            // ============ FULL-ACCESS ROLES ============
            'superadmin' => $allPermissions,

            'admin' => $allPermissions,

            'general_manager' => array_merge(
                $accessPermissions,
                $portalPermissions,
                [
                    'action.advance-stage',
                    'action.approve-samples',
                    'action.approve-quotation',
                    'action.assign-stages',
                    'action.manage-subcontract',
                ]
            ),

            // ============ CUSTOMER SUPPORT (CSR) ============
            'csr' => [
                'access.clients',
                'access.orders',
                'access.quotations',
                'access.dropdown-settings',
                'access.quotation-settings',
                'access.tickets',
                'access.notifications',
                'access.order-stages',
                'portal.csr',
                'action.create-orders',
                'action.approve-quotation',
                'action.advance-stage',
                'action.upload-photos',
            ],

            // ============ FINANCE ============
            'finance' => [
                'access.orders',
                'access.quotations',
                'access.payment-methods',
                'access.tickets',
                'access.notifications',
                'access.reports',
                'portal.finance',
                'action.advance-stage',
                'action.verify-payment',
            ],

            // ============ GRAPHIC ARTIST ============
            'graphic_artist' => [
                'access.orders',
                'access.graphic-design',
                'access.pantone',
                'access.notifications',
                'portal.graphic-artist',
                'action.upload-photos',
                'action.advance-stage',
                'action.request-materials',
            ],

            // ============ SCREEN MAKER ============
            'screen_maker' => [
                'access.orders',
                'access.screens',
                'access.screen-making',
                'access.screen-checking',
                'access.screen-maintenance',
                'access.notifications',
                'portal.screen-maker',
                'action.upload-photos',
                'action.advance-stage',
                'action.request-materials',
            ],

            // ============ PURCHASING / MATERIAL PREP ============
            'purchasing' => [
                'access.orders',
                'access.suppliers',
                'access.materials',
                'access.payment-methods',
                'access.notifications',
                'portal.material-prep',
                'action.process-purchase',
                'action.advance-stage',
            ],

            // ============ WAREHOUSE MANAGER ============
            'warehouse_manager' => [
                'access.orders',
                'access.suppliers',
                'access.materials',
                'access.equipment',
                'access.notifications',
                'portal.warehouse',
                'action.upload-photos',
                'action.advance-stage',
            ],

            // ============ CUTTER (Sample + Mass) ============
            'cutter' => [
                'access.orders',
                'access.materials',
                'access.notifications',
                'portal.cutter',
                'action.upload-photos',
                'action.advance-stage',
                'action.request-materials',
            ],

            // ============ PRINTER (Sample + Mass) ============
            'printer' => [
                'access.orders',
                'access.materials',
                'access.screens',
                'access.notifications',
                'portal.printer',
                'action.upload-photos',
                'action.advance-stage',
                'action.request-materials',
            ],

            // ============ SEWER (Sample + Mass) ============
            'sewer' => [
                'access.orders',
                'access.materials',
                'access.sewing-subcontractor',
                'access.notifications',
                'portal.sewer',
                'action.upload-photos',
                'action.advance-stage',
                'action.request-materials',
                'action.manage-subcontract',
            ],

            // ============ QUALITY ASSURANCE ============
            'quality_assurance' => [
                'access.orders',
                'access.notifications',
                'portal.qa',
                'action.upload-photos',
                'action.advance-stage',
                'action.approve-samples',
            ],

            // ============ PACKER ============
            'packer' => [
                'access.orders',
                'access.notifications',
                'portal.packer',
                'action.upload-photos',
                'action.advance-stage',
            ],

            // ============ DRIVER (in-house delivery) ============
            'driver' => [
                'access.orders',
                'access.notifications',
                'portal.driver',
                'action.upload-photos',
                'action.advance-stage',
            ],

            // ============ LOGISTICS (send to subcontract / dispatch) ============
            // Combines courier coordination + driver dispatch.
            // NOTE: separate from `driver` to allow office-side logistics staff.
            'logistics' => [
                'access.orders',
                'access.courier-list',
                'access.shipping-methods',
                'access.sewing-subcontractor',
                'access.notifications',
                'portal.logistics',
                'action.upload-photos',
                'action.advance-stage',
                'action.manage-subcontract',
            ],

            // ============ SAMPLE MAKER (legacy umbrella role) ============
            // Kept for backwards compatibility with existing frontend roleAccess
            // map. Treat as a multi-portal staffer who can do cutting/printing/sewing
            // for samples only.
            'sample_maker' => [
                'access.orders',
                'access.materials',
                'access.notifications',
                'portal.cutter',
                'portal.printer',
                'portal.sewer',
                'action.upload-photos',
                'action.advance-stage',
                'action.request-materials',
            ],

            // ============ EXTERNAL SUBCONTRACT PARTNER ============
            // Limited read access – they can see orders sent to them and
            // upload completed photos.
            'subcontract' => [
                'access.orders',
                'access.notifications',
                'portal.subcontract',
                'action.upload-photos',
            ],

            // ============ END CUSTOMER ============
            // Only used for public quotation/order-status views.
            'customer' => [],
        ];

        foreach ($roleMatrix as $roleName => $perms) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($perms);
        }

        // -------------------------------------------------------------------
        // 3. CACHE RESET
        // -------------------------------------------------------------------
        // Spatie caches the permission map – clear it after a fresh seed.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
