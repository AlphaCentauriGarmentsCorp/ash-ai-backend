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
 *   access.*            – legacy/admin module access (clients, orders, …)
 *   portal.*            – role-specific portal landing pages (sewer, cutter, …)
 *   action.*            – cross-cutting capabilities (request materials, …)
 *   material_requests.* – Phase 3 fine-grained MR permissions
 *   purchase_requests.* – Phase 3 fine-grained PR permissions
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
            // Phase 3: MR/PR module access
            'access.material-requests',
            'access.purchase-requests',
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
            'action.switch-service-type',   // Phase 5-D — flip stage in-house ↔ subcontract
        ];

        // Phase 3 fine-grained MR permissions.
        $materialRequestPermissions = [
            'material_requests.view',
            'material_requests.create',     // production roles, on their active stage
            'material_requests.approve',    // managers + admin + super
            'material_requests.reject',     // managers + admin + super
        ];

        // Phase 3 fine-grained PR permissions.
        $purchaseRequestPermissions = [
            'purchase_requests.view',
            'purchase_requests.create',
            'purchase_requests.approve',
            'purchase_requests.mark_ordered',
            'purchase_requests.mark_received',
            'purchase_requests.cancel',
        ];

        // Phase 4 fine-grained Stage Inputs + Subcontract permissions.
        $stageInputPermissions = [
            'stage_inputs.view',
            'stage_inputs.log_waste',     // production roles
            'stage_inputs.log_reject',    // QA only
            'stage_inputs.log_subcontract', // sewer / cutter / printer / manager
            'stage_inputs.delete',        // managers only — for accidents
            // Reports endpoints (production summary, per-order timeline).
            'access.reports',
        ];

        $allPermissions = array_merge(
            $accessPermissions,
            $portalPermissions,
            $actionPermissions,
            $materialRequestPermissions,
            $purchaseRequestPermissions,
            $stageInputPermissions,
        );

        foreach ($allPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // -------------------------------------------------------------------
        // Permission bundles for Phase 3 — expressed as small reusable sets
        // so each role's intent stays readable below.
        // -------------------------------------------------------------------

        // Production roles get: view + create their own MRs.
        $productionMrBundle = [
            'access.material-requests',
            'material_requests.view',
            'material_requests.create',
        ];

        // Manager-tier MR bundle = production + approve/reject.
        $managerMrBundle = array_merge($productionMrBundle, [
            'material_requests.approve',
            'material_requests.reject',
        ]);

        // Purchasing role gets full PR lifecycle + read-only MR
        // (so they can see what triggered the PR).
        $purchasingPrBundle = [
            'access.purchase-requests',
            'purchase_requests.view',
            'purchase_requests.create',
            'purchase_requests.mark_ordered',
            'purchase_requests.mark_received',
            'purchase_requests.cancel',
            'access.material-requests',
            'material_requests.view',
        ];

        // Manager-tier PR bundle adds approval to the purchasing bundle.
        $managerPrBundle = array_merge($purchasingPrBundle, [
            'purchase_requests.approve',
        ]);

        // Phase 4 bundles ------------------------------------------------

        // Production roles can view + log waste + log subcontract on
        // their own stage. Reject is QA-only; delete is manager-only.
        $productionStageBundle = [
            'stage_inputs.view',
            'stage_inputs.log_waste',
            'stage_inputs.log_subcontract',
        ];

        // QA role: view + log reject (the QA-specific action).
        $qaStageBundle = [
            'stage_inputs.view',
            'stage_inputs.log_reject',
        ];

        // Manager-tier: everything plus delete + reports access.
        $managerStageBundle = [
            'stage_inputs.view',
            'stage_inputs.log_waste',
            'stage_inputs.log_reject',
            'stage_inputs.log_subcontract',
            'stage_inputs.delete',
            'access.reports',
        ];

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
                    'action.switch-service-type',
                ],
                $managerMrBundle,
                $managerPrBundle,
                $managerStageBundle,
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
                'action.switch-service-type',
            ],

            // ============ FINANCE ============
            'finance' => array_merge(
                [
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
                // Finance gets read-only MR/PR for visibility into spending.
                ['access.material-requests', 'material_requests.view'],
                ['access.purchase-requests', 'purchase_requests.view'],
            ),

            // ============ GRAPHIC ARTIST ============
            'graphic_artist' => array_merge(
                [
                    'access.orders',
                    'access.graphic-design',
                    'access.pantone',
                    'access.notifications',
                    'portal.graphic-artist',
                    'action.upload-photos',
                    'action.advance-stage',
                    'action.request-materials',
                ],
                $productionMrBundle,
                $productionStageBundle,
            ),

            // ============ SCREEN MAKER ============
            'screen_maker' => array_merge(
                [
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
                $productionMrBundle,
                $productionStageBundle,
            ),

            // ============ PURCHASING / MATERIAL PREP ============
            'purchasing' => array_merge(
                [
                    'access.orders',
                    'access.suppliers',
                    'access.materials',
                    'access.payment-methods',
                    'access.notifications',
                    'portal.material-prep',
                    'action.process-purchase',
                    'action.advance-stage',
                ],
                $purchasingPrBundle,
            ),

            // ============ WAREHOUSE MANAGER ============
            'warehouse_manager' => array_merge(
                [
                    'access.orders',
                    'access.suppliers',
                    'access.materials',
                    'access.equipment',
                    'access.notifications',
                    'portal.warehouse',
                    'action.upload-photos',
                    'action.advance-stage',
                ],
                // Warehouse needs to mark PR as received + decrement/increment stock.
                [
                    'access.purchase-requests',
                    'purchase_requests.view',
                    'purchase_requests.mark_received',
                    'access.material-requests',
                    'material_requests.view',
                ],
            ),

            // ============ CUTTER (Sample + Mass) ============
            'cutter' => array_merge(
                [
                    'access.orders',
                    'access.materials',
                    'access.notifications',
                    'portal.cutter',
                    'action.upload-photos',
                    'action.advance-stage',
                    'action.request-materials',
                ],
                $productionMrBundle,
                $productionStageBundle,
            ),

            // ============ PRINTER (Sample + Mass) ============
            'printer' => array_merge(
                [
                    'access.orders',
                    'access.materials',
                    'access.screens',
                    'access.notifications',
                    'portal.printer',
                    'action.upload-photos',
                    'action.advance-stage',
                    'action.request-materials',
                ],
                $productionMrBundle,
                $productionStageBundle,
            ),

            // ============ SEWER (Sample + Mass) ============
            'sewer' => array_merge(
                [
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
                $productionMrBundle,
                $productionStageBundle,
            ),

            // ============ QUALITY ASSURANCE ============
            'quality_assurance' => array_merge(
                [
                    'access.orders',
                    'access.notifications',
                    'portal.qa',
                    'action.upload-photos',
                    'action.advance-stage',
                    'action.approve-samples',
                ],
                $qaStageBundle,
            ),

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

            // ============ LOGISTICS ============
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

            // ============ SAMPLE MAKER ============
            'sample_maker' => array_merge(
                [
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
                $productionMrBundle,
                $productionStageBundle,
            ),

            // ============ EXTERNAL SUBCONTRACT PARTNER ============
            'subcontract' => [
                'access.orders',
                'access.notifications',
                'portal.subcontract',
                'action.upload-photos',
            ],

            // ============ END CUSTOMER ============
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
