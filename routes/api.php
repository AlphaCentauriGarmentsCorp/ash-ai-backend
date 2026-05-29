<?php

use App\Http\Controllers\Api\AccountController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\PatternTypeController;
use App\Http\Controllers\Api\ApparelTypeController;
use App\Http\Controllers\Api\ApparelPartController;
use App\Http\Controllers\Api\ServiceTypeController;
use App\Http\Controllers\Api\PrintMethodController;
use App\Http\Controllers\Api\SizeLabelController;
use App\Http\Controllers\Api\PrintLabelPlacementController;
use App\Http\Controllers\Api\FreebieController;
use App\Http\Controllers\Api\PlacementMeasurementController;
use App\Http\Controllers\Api\AdditionalOptionController;
use App\Http\Controllers\Api\AddonCategoriesController;
use App\Http\Controllers\Api\AddonsController;
use App\Http\Controllers\Api\EquipmentLocationController;
use App\Http\Controllers\Api\EquipmentInventoryController;
use App\Http\Controllers\Api\DownloadController;
use App\Http\Controllers\Api\GraphicDesignController;
use App\Http\Controllers\Api\MaterialsController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\ScreenController;
use App\Http\Controllers\Api\OrderStagesController;
use App\Http\Controllers\Api\ScreenCheckingController;
use App\Http\Controllers\Api\ScreenMakingController;
use App\Http\Controllers\Api\ScreenMaintenanceController;
use App\Http\Controllers\Api\SewingSubcontractorController;
use App\Http\Controllers\Api\PaymentMethodsController;
use App\Http\Controllers\Api\CourierListController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\QuotationLabelOptionsController;
use App\Http\Controllers\Api\QuotationReviewController;
use App\Http\Controllers\Api\QuotationShareController;
use App\Http\Controllers\Api\PublicQuotationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ShippingMethodController;
use App\Http\Controllers\Api\ApparelPatternPriceController;
use App\Http\Controllers\Api\ApparelNecklineController;
use App\Http\Controllers\Api\PricingSettingController;
use App\Http\Controllers\Api\PantoneController;

// Phase 3/4/5 — workflow, MR/PR, stage inputs, reports, portals
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\MaterialRequestsController;
use App\Http\Controllers\Api\PurchaseRequestsController;
use App\Http\Controllers\Api\StageInputsController;
use App\Http\Controllers\Api\SubcontractController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\CutterPortalController;
use App\Http\Controllers\Api\PrinterPortalController;
use App\Http\Controllers\Api\SewerPortalController;
use App\Http\Controllers\Api\ScreenMakerPortalController;
use App\Http\Controllers\Api\MaterialPrepPortalController;
use App\Http\Controllers\Api\GraphicArtistPortalController;
use App\Http\Controllers\Api\LogisticsPortalController;
use App\Http\Controllers\Api\CsrDashboardController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\OrderPaymentController;
use App\Http\Controllers\Api\ClientApprovalController;
use App\Http\Controllers\Api\FabricSwatchController;
use App\Http\Controllers\Api\QaPackerPortalController;

// example usage: localhost:8000/api/v1/user
// Route::prefix('v1')->group(function () {
//     Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('clients', ClientController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('fabric-types', FabricTypeController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('type-sizes', TypeSizeController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('warehouse-materials', WarehouseMaterialsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('type-garments', TypeGarmentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('type-printing-methods', TypePrintingMethodController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('orders', OrdersController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('order-processes', OrderProcessesController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('orders-payment', OrdersPaymentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('po-statuses', PoStatusController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('po-items', PoItemsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('designs', DesignController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
// });

// example usage: localhost:8000/api/v2/user
Route::prefix('v2')->group(function () {
    Route::post('login/reefer', [AuthController::class, 'loginReefer']);
    Route::post('register/reefer', [AuthController::class, 'registerReefer']);

    Route::post('login/sorbetes', [AuthController::class, 'loginSorbetes']);
    Route::post('register/sorbetes', [AuthController::class, 'registerSorbetes']);


    Route::post('login/ash', [AuthController::class, 'loginAsh']);
    Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('login', [AuthController::class, 'login']);


    Route::middleware('auth:sanctum')->group(function () {

        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

    });

    Route::middleware(['auth:sanctum', 'frontend.access:ash'])->group(function () {
        Route::prefix('/download')->middleware('permission:access.download')->controller(DownloadController::class)->group(function () {
            Route::get('/', 'download');
        });

        Route::prefix('/employee')->middleware('permission:access.employees')->controller(AccountController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::post('/{id}', 'update'); // multipart/form-data edits (PUT cannot carry files) use POST + _method spoofing
            Route::delete('/{id}', 'destroy');
            Route::patch('/{id}/restore', 'restore');
        });

        Route::prefix('/rbac')->middleware('permission:access.rbac')->group(function () {
            Route::prefix('/roles')->controller(RoleController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{id}', 'show');
                Route::put('/{id}', 'update');
                Route::delete('/{id}', 'destroy');
            });

            Route::prefix('/permissions')->controller(PermissionController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{id}', 'show');
                Route::put('/{id}', 'update');
                Route::delete('/{id}', 'destroy');
            });
        });

        Route::prefix('/clients')->middleware('permission:access.clients')->controller(ClientController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
            // Issue 1 — add a brand to a client on the fly (from the quotation form).
            Route::post('/{id}/brands', 'storeBrand')->whereNumber('id');
        });

        Route::prefix('/orders')->middleware('permission:access.orders')->controller(OrdersController::class)->group(function () {
            Route::get('/', 'index');
            // Phase 3 — lightweight picker for MR creation; only orders
            // with an active stage. Falls inside the same access.orders
            // gate since the user needs to see orders to pick from.
            Route::get('/with-active-stage', 'withActiveStage');
            Route::post('/', 'store');
            Route::get('/details/{po_code}', 'show');
            // Soft-delete an order (sets deleted_at; recoverable). Matches the
            // frontend orderApi.delete() call: DELETE /orders/{id}.
            Route::delete('/{id}', 'destroy')->whereNumber('id');
        });

        Route::prefix('/tickets')->middleware('permission:access.tickets')->controller(TicketController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/from-roles', 'getFromRoles');
            Route::get('/to-roles', 'getToRoles');
            Route::get('/by-role/{role}', 'getByRole');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/pattern-type')->middleware('permission:access.dropdown-settings')->controller(PatternTypeController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/apparel-type')->middleware('permission:access.dropdown-settings')->controller(ApparelTypeController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/apparel-parts')->middleware('permission:access.dropdown-settings')->controller(ApparelPartController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/service-type')->middleware('permission:access.dropdown-settings')->controller(ServiceTypeController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/print-method')->middleware('permission:access.dropdown-settings')->controller(PrintMethodController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/size-label')->middleware('permission:access.dropdown-settings')->controller(SizeLabelController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/print-label-placement')->middleware('permission:access.dropdown-settings')->controller(PrintLabelPlacementController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/freebie')->middleware('permission:access.dropdown-settings')->controller(FreebieController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/placement-measurement')->middleware('permission:access.dropdown-settings')->controller(PlacementMeasurementController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/additional-option')->middleware('permission:access.dropdown-settings')->controller(AdditionalOptionController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/equipment-location')->middleware('permission:access.equipment')->controller(EquipmentLocationController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/equipment-inventory')->middleware('permission:access.equipment')->controller(EquipmentInventoryController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}/contents', 'getByLocation');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/supplier')->middleware('permission:access.suppliers')->controller(SupplierController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/materials')->middleware('permission:access.materials')->controller(MaterialsController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}/supplier', 'getBySupplier');
            Route::get('/type/{type}', 'getByType');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/screens')->middleware('permission:access.screens')->controller(ScreenController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/payment-methods')->middleware('permission:access.payment-methods')->controller(PaymentMethodsController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/shipping-methods')->middleware('permission:access.shipping-methods')->controller(ShippingMethodController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/pantone')->middleware('permission:access.pantone')->controller(PantoneController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/courier-list')->middleware('permission:access.courier-list')->controller(CourierListController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/sewing-subcontractor')->middleware('permission:access.sewing-subcontractor')->controller(SewingSubcontractorController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        // ── Order Stages (Phase 1) ─────────────────────────────────────
        // Read-only stage data is accessible to anyone with access.orders
        // (every production role has this). Mutations require the
        // action.advance-stage permission.
        Route::prefix('/order-stages')->controller(OrderStagesController::class)->group(function () {

            // Read endpoints
            Route::middleware('permission:access.orders')->group(function () {
                Route::get('/workflow', 'workflow');
                Route::get('/order/{orderId}', 'indexForOrder');

                // Legacy "ensure initialized" call. Treated as read-ish – idempotent.
                Route::post('/', 'store');
            });

            // Mutation endpoints – any role that owns a stage can advance it.
            Route::middleware('permission:action.advance-stage')->group(function () {
                Route::post('/{id}/complete', 'complete');
                Route::post('/{id}/for-approval', 'forApproval');
                Route::post('/{id}/delay', 'delay');
                Route::post('/{id}/hold', 'hold');
                Route::post('/{id}/resume', 'resume');
                Route::post('/{id}/notes', 'note');
            });

            // Assignment is reserved for managers only.
            Route::middleware('permission:action.assign-stages')->group(function () {
                Route::post('/{id}/assign', 'assign');
            });

            // Phase 5-D — Service type switching (in-house ↔ subcontract).
            // Reserved for Admin / Super Admin / GM / CSR.
            Route::middleware('permission:action.switch-service-type')->group(function () {
                Route::patch('/{id}/service-type', 'switchServiceType')->whereNumber('id');
            });
        });

        // ── Notifications (Phase 2) ────────────────────────────────────
        // All endpoints are scoped to the current user automatically.
        // No special permission required – every authenticated user can
        // read & manage their own notifications.
        Route::prefix('/notifications')->controller(NotificationsController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/recent', 'recent');
            Route::get('/unread-count', 'unreadCount');
            Route::post('/{id}/read', 'markRead')->whereNumber('id');
            Route::post('/read-all', 'markAllRead');
            Route::delete('/{id}', 'destroy')->whereNumber('id');
        });

        // ── Material Requests (Phase 3) ────────────────────────────────
        // Production roles create MRs against their order's active stage;
        // managers approve/reject. Permissions are checked per-route so a
        // creator can list+view their own without holding the manager
        // approve permission.
        Route::prefix('/material-requests')
            ->middleware('permission:access.material-requests')
            ->controller(MaterialRequestsController::class)
            ->group(function () {
                Route::get('/',      'index')->middleware('permission:material_requests.view');
                Route::get('/{id}',  'show')->whereNumber('id')->middleware('permission:material_requests.view');
                Route::post('/',     'store')->middleware('permission:material_requests.create');
                Route::post('/{id}/approve', 'approve')->whereNumber('id')->middleware('permission:material_requests.approve');
                Route::post('/{id}/reject',  'reject')->whereNumber('id')->middleware('permission:material_requests.reject');
            });

        // ── Purchase Requests (Phase 3) ────────────────────────────────
        // Auto-spawned by MR approval when stock is short, OR ad-hoc
        // created by purchasing/manager. Lifecycle: pending → approved →
        // ordered → received (each transition is its own endpoint).
        Route::prefix('/purchase-requests')
            ->middleware('permission:access.purchase-requests')
            ->controller(PurchaseRequestsController::class)
            ->group(function () {
                Route::get('/',      'index')->middleware('permission:purchase_requests.view');
                Route::get('/{id}',  'show')->whereNumber('id')->middleware('permission:purchase_requests.view');
                Route::post('/',     'store')->middleware('permission:purchase_requests.create');
                Route::post('/{id}/approve',       'approve')->whereNumber('id')->middleware('permission:purchase_requests.approve');
                Route::post('/{id}/mark-ordered',  'markOrdered')->whereNumber('id')->middleware('permission:purchase_requests.mark_ordered');
                Route::post('/{id}/mark-received', 'markReceived')->whereNumber('id')->middleware('permission:purchase_requests.mark_received');
                Route::post('/{id}/cancel',        'cancel')->whereNumber('id')->middleware('permission:purchase_requests.cancel');
            });

        // ── Stage Inputs (Phase 4) ─────────────────────────────────────
        // Waste and reject logging against an order_stage. Photo upload
        // accepted as multipart/form-data on POST.
        Route::prefix('/stage-inputs')
            ->controller(StageInputsController::class)
            ->group(function () {
                // Waste — production roles + managers
                Route::get('/waste',           'indexWaste')->middleware('permission:stage_inputs.view');
                Route::post('/waste',          'storeWaste')->middleware('permission:stage_inputs.log_waste');
                Route::delete('/waste/{id}',   'destroyWaste')->whereNumber('id')->middleware('permission:stage_inputs.delete');

                // Reject — QA only (+ managers)
                Route::get('/reject',          'indexReject')->middleware('permission:stage_inputs.view');
                Route::post('/reject',         'storeReject')->middleware('permission:stage_inputs.log_reject');
                Route::delete('/reject/{id}',  'destroyReject')->whereNumber('id')->middleware('permission:stage_inputs.delete');
            });

        // ── Subcontract Assignments (Phase 4) ─────────────────────────
        // Lifecycle: pending → out → returned (or cancelled before return).
        Route::prefix('/subcontract-assignments')
            ->controller(SubcontractController::class)
            ->group(function () {
                Route::get('/',                  'index')->middleware('permission:stage_inputs.view');
                Route::get('/{id}',              'show')->whereNumber('id')->middleware('permission:stage_inputs.view');
                Route::post('/',                 'store')->middleware('permission:stage_inputs.log_subcontract');
                Route::post('/{id}/mark-sent',   'markSent')->whereNumber('id')->middleware('permission:stage_inputs.log_subcontract');
                Route::post('/{id}/mark-returned', 'markReturned')->whereNumber('id')->middleware('permission:stage_inputs.log_subcontract');
                Route::post('/{id}/cancel',      'cancel')->whereNumber('id')->middleware('permission:stage_inputs.log_subcontract');
            });

        // ── Reports (Phase 4) ──────────────────────────────────────────
        // Production-summary (aggregated counts + cycle times) and
        // per-order timeline. Phase 6 dashboards consume these.
        Route::prefix('/reports')
            ->middleware('permission:access.reports')
            ->controller(ReportsController::class)
            ->group(function () {
                Route::get('/production-summary', 'productionSummary');
            });

        // Per-order timeline — under the orders prefix so it inherits
        // existing access.orders + reads naturally as
        // /api/v2/orders/{id}/production-timeline.
        Route::get('/orders/{id}/production-timeline', [ReportsController::class, 'orderTimeline'])
            ->whereNumber('id')
            ->middleware(['permission:access.orders', 'permission:access.reports']);

        // ── Role Portals (Phase 5-A) ───────────────────────────────────
        // Each portal calls /portal/{role}/my-active on mount to find
        // its currently-active assignment. The portal.{role} permission
        // gates access to the FRONTEND route via permissionAccessMap.
        // Slugs accept either underscores (cutter, screen_maker) or
        // hyphens (graphic-artist, screen-maker) — both map to the
        // same backend role.
        Route::get('/portal/{role}/my-active', [PortalController::class, 'myActive'])
            ->where('role', '[a-z_-]+');

        // ── Cutter Portal (Phase 5-B) ─────────────────────────────────
        // Per-portal endpoints for fabric tracking, sample uploads, and
        // the aggregated portal-context fetch. All gated by portal.cutter.
        Route::prefix('/portal/cutter')
            ->middleware('permission:portal.cutter')
            ->controller(CutterPortalController::class)
            ->group(function () {
                Route::get('/context/{orderStageId}',  'showContext')->whereNumber('orderStageId');

                // Fabric logs — JSON
                Route::post('/fabric-logs',            'storeFabricLog');
                Route::delete('/fabric-logs/{id}',     'destroyFabricLog')->whereNumber('id');

                // Sample uploads — multipart
                Route::post('/sample-uploads',         'storeSampleUpload');
                Route::patch('/sample-uploads/{id}',   'updateSampleUpload')->whereNumber('id');
                Route::delete('/sample-uploads/{id}',  'destroySampleUpload')->whereNumber('id');
            });

        // ── Printer Portal (Phase 5-C) ────────────────────────────────
        // Same shape as Cutter but for ink tracking. Sample uploads
        // reuse the shared SampleUploadService via PrinterPortalController.
        Route::prefix('/portal/printer')
            ->middleware('permission:portal.printer')
            ->controller(PrinterPortalController::class)
            ->group(function () {
                Route::get('/context/{orderStageId}',  'showContext')->whereNumber('orderStageId');

                // Ink logs — JSON
                Route::post('/ink-logs',               'storeInkLog');
                Route::delete('/ink-logs/{id}',        'destroyInkLog')->whereNumber('id');

                // Sample uploads — multipart
                Route::post('/sample-uploads',         'storeSampleUpload');
                Route::patch('/sample-uploads/{id}',   'updateSampleUpload')->whereNumber('id');
                Route::delete('/sample-uploads/{id}',  'destroySampleUpload')->whereNumber('id');
            });

        // ── Sewer Portal (Phase 5-E) ──────────────────────────────────
        // Material logs use stage_fabric_logs with material_type tagging
        // for multi-material tracking (main fabric, rib/trim, thread, etc.)
        Route::prefix('/portal/sewer')
            ->middleware('permission:portal.sewer')
            ->controller(SewerPortalController::class)
            ->group(function () {
                Route::get('/context/{orderStageId}',  'showContext')->whereNumber('orderStageId');

                // Material logs — JSON
                Route::post('/material-logs',          'storeMaterialLog');
                Route::delete('/material-logs/{id}',   'destroyMaterialLog')->whereNumber('id');

                // Sample uploads — multipart
                Route::post('/sample-uploads',         'storeSampleUpload');
                Route::patch('/sample-uploads/{id}',   'updateSampleUpload')->whereNumber('id');
                Route::delete('/sample-uploads/{id}',  'destroySampleUpload')->whereNumber('id');
            });
        
        // ── QA/Packer Portal (Phase 7-B) ──────────────────────────────
        // Unified portal for QA + Packer roles per QA/Packer spec doc.
        // Same person typically does both inspection and packing for
        // the same order at ACGC's scale, so the two roles share a
        // single portal gated by the unified portal.qa-packer permission.
        //
        // Bundle 1 endpoints: context fetch, reject/repair create+delete,
        // atomic submit. Final-photo + QR + checklist completion endpoints
        // land in Bundle 4.
        Route::prefix('/portal/qa-packer')
            ->middleware('permission:portal.qa-packer')
            ->controller(QaPackerPortalController::class)
            ->group(function () {
                Route::get('/context/{orderStageId}', 'showContext')->whereNumber('orderStageId');

                // Reject / Repair logs — JSON or multipart
                Route::post('/rejects',         'storeReject');
                Route::delete('/rejects/{id}',  'destroyReject')->whereNumber('id');

                // Phase 7-B Bundle 4a — Packing boxes + QR labels
                Route::post('/boxes/ensure-for-order/{orderId}', 'ensureFirstBox')->whereNumber('orderId');
                Route::patch('/boxes/{id}',                       'updateBoxContents')->whereNumber('id');
                Route::post('/boxes/{id}/seal',                   'sealBox')->whereNumber('id');
                Route::post('/boxes/{id}/unseal',                 'unsealBox')->whereNumber('id');
                Route::get('/boxes/{id}/qr-label.pdf',            'downloadBoxLabel')->whereNumber('id');

                // Phase 7-B Bundle 4a — Final photo uploads
                Route::post('/final-photos', 'uploadFinalPhoto');

                // Atomic SUBMIT COMPLETED — advances the workflow stage
                Route::post('/submit/{orderStageId}', 'submit')->whereNumber('orderStageId');
            });

        // ── Screen Maker Portal (Phase 5-F) ───────────────────────────
        // Mostly read-only. Notes + mark-as-done go through existing
        // OrderStagesController endpoints.
        Route::prefix('/portal/screen-maker')
            ->middleware('permission:portal.screen-maker')
            ->controller(ScreenMakerPortalController::class)
            ->group(function () {
                Route::get('/context/{orderStageId}', 'showContext')->whereNumber('orderStageId');
            });

        // ── Material Prep Portal (Phase 5-G) ──────────────────────────
        // PR-bound (NOT stage-bound). Purchaser sees active PRs across
        // all orders. mark-ordered/mark-received/cancel still route
        // through existing /purchase-requests/* endpoints.
        //
        // NOTE: The active-PR endpoint uses 'active-prs' rather than
        // 'my-active' to avoid collision with the generic stage-based
        // /portal/{role}/my-active wildcard route (Phase 5-A).
        Route::prefix('/portal/material-prep')
            ->middleware('permission:portal.material-prep')
            ->controller(MaterialPrepPortalController::class)
            ->group(function () {
                Route::get('/active-prs',          'myActive');
                Route::get('/context/{prId}',      'showContext')->whereNumber('prId');
                Route::patch('/{prId}/supplier',   'assignSupplier')->whereNumber('prId');
            });

        // ── Graphic Artist Portal (Phase 5-H) ─────────────────────────
        // graphic_artwork stage is a real workflow stage (not PR-based),
        // so /portal/graphic-artist/my-active resolves correctly through
        // the existing Phase 5-A wildcard. This group only adds the
        // portal-specific context/write endpoints.
        Route::prefix('/portal/graphic-artist')
            ->middleware('permission:portal.graphic-artist')
            ->controller(GraphicArtistPortalController::class)
            ->group(function () {
                Route::get('/context/{orderStageId}',  'showContext')->whereNumber('orderStageId');

                // Design files — multipart upload, hard-delete (file + row)
                Route::post('/design-files',           'storeDesignFile');
                Route::delete('/design-files/{id}',    'destroyDesignFile')->whereNumber('id');

                // Label assets — upsert (one per order_id + kind)
                Route::put('/label-assets',            'upsertLabelAsset');
                Route::delete('/label-assets/{id}',    'destroyLabelAsset')->whereNumber('id');

                // Sample uploads — multipart; reuses shared SampleUploadService
                Route::post('/sample-uploads',         'storeSampleUpload');
                Route::patch('/sample-uploads/{id}',   'updateSampleUpload')->whereNumber('id');
                Route::delete('/sample-uploads/{id}',  'destroySampleUpload')->whereNumber('id');
            });
        
        // ── Logistics Portal (Phase 5-I) ──────────────────────────────
        // The Logistics user works across multiple subcontract assignments
        // (not stage-bound), similar to Material Prep's PR-bound pattern.
        // Active-shipments / active-deliveries endpoints use explicit names
        // to avoid colliding with the Phase 5-A /portal/{role}/my-active wildcard.    
        Route::prefix('/portal/logistics')
            ->middleware('permission:portal.logistics')
            ->controller(LogisticsPortalController::class)
            ->group(function () {
                Route::get('/active-shipments',         'activeShipments');
                Route::get('/active-deliveries',        'activeDeliveries');

                Route::get('/shipment-context/{id}',    'shipmentContext')->whereNumber('id');
                Route::get('/assignment-context/{id}',  'assignmentContext')->whereNumber('id');

                Route::post('/shipments',               'storeShipment');
                Route::put('/shipments/{id}',           'updateShipment')->whereNumber('id');
                Route::patch('/shipments/{id}/status',  'updateShipmentStatus')->whereNumber('id');
                Route::post('/shipments/{id}/proof',    'uploadProof')->whereNumber('id');

                Route::post('/assignments/{id}/verify-return', 'verifyReturn')->whereNumber('id');
            });

        // ── CSR Hub (Phase 6-A, BUG-017-fixed) ────────────────────────
        // Main CSR routes — gated by portal.csr at the group level.
        Route::prefix('/csr')
            ->middleware('permission:portal.csr')
            ->group(function () {
                // Dashboard
                Route::get('/dashboard', [CsrDashboardController::class, 'show']);
                Route::get('/activity-log', [CsrDashboardController::class, 'activityLog']);

                // Inquiries
                Route::prefix('/inquiries')->controller(InquiryController::class)->group(function () {
                    Route::get('/',                                'index');
                    Route::post('/',                               'store');
                    Route::put('/{id}',                            'update')->whereNumber('id');
                    Route::post('/{id}/convert-to-quotation',      'convertToQuotation')
                        ->whereNumber('id')
                        ->name('csr.inquiries.convertToQuotation');
                });

                // Order Payments (LIST + UPLOAD only — verify is split below)
                Route::prefix('/payments')->controller(OrderPaymentController::class)->group(function () {
                    Route::get('/',                'index');
                    Route::post('/',               'store');
                });

                // Client Approvals
                Route::prefix('/approvals')->controller(ClientApprovalController::class)->group(function () {
                    Route::get('/',                'index');
                    Route::post('/',               'store');
                    Route::patch('/{id}/respond',  'respond')
                        ->whereNumber('id')
                        ->name('csr.approvals.respond');
                });

                // ── Phase 6-B: Fabric Swatch Catalog ────────────────
                Route::prefix('/fabric-swatches')->controller(FabricSwatchController::class)->group(function () {
                    Route::get('/',                'index');
                    Route::post('/',               'store');
                    Route::put('/{id}',            'update')->whereNumber('id');
                    Route::delete('/{id}',         'destroy')->whereNumber('id');
                });
            });

        // ── CSR Hub: Finance verify gate (Phase 6-A, BUG-017 split) ───
        // Separate group — gated by action.verify-payment ONLY.
        // Finance and Super Admin can reach this without needing portal.csr.
        // Same URL prefix (/csr/payments/.../verify) so frontend and Postman
        // collections do NOT need to change.
        Route::prefix('/csr')
            ->middleware('permission:action.verify-payment')
            ->group(function () {
                Route::patch('/payments/{id}/verify', [OrderPaymentController::class, 'verify'])
                    ->whereNumber('id')
                    ->name('csr.payments.verify');
            });

        Route::prefix('/graphic-design')->middleware('permission:access.graphic-design')->controller(GraphicDesignController::class)->group(function () {
            Route::post('/', 'store');
        });

        Route::prefix('/screen-making')->middleware('permission:access.screen-making')->controller(ScreenMakingController::class)->group(function () {
            Route::post('/', 'store');
        });

        Route::prefix('/screen-checking')->middleware('permission:access.screen-checking')->controller(ScreenCheckingController::class)->group(function () {
            Route::post('/', 'store');
        });


        Route::prefix('/screen-maintenance')->middleware('permission:access.screen-maintenance')->controller(ScreenMaintenanceController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}', 'show');
            Route::get('/user/{id}', 'getByUser');
            Route::post('/', 'store');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/apparel-pattern-prices')->middleware('permission:access.quotation-settings')->controller(ApparelPatternPriceController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/price/{apparelTypeName}/{patternTypeName}', 'getPrice');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/apparel-neckline')->middleware('permission:access.quotation-settings')->controller(ApparelNecklineController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        // Superadmin-editable pricing rates (silkscreen per-color, DTF per
        // sq inch, etc.). Edit-only: keys are fixed/seeded so the engine
        // never loses a rate it depends on — hence no store/destroy.
        Route::prefix('/quotation/settings/pricing')->middleware('permission:access.quotation-settings')->controller(PricingSettingController::class)->group(function () {
            Route::get('/', 'index');
            Route::put('/{id}', 'update');
        });

        Route::prefix('/quotation/settings/addon-categories')->middleware('permission:access.quotation-settings')->controller(AddonCategoriesController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/addons')->middleware('permission:access.quotation-settings')->controller(AddonsController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotations')->middleware('permission:access.quotations')->group(function () {

            // ── Quotation CRUD ────────────────────────────────────────────────
            Route::controller(QuotationController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                // Live price preview — computes totals without saving. Must be
                // declared before GET /{id} is irrelevant (this is POST), but
                // kept here next to store for clarity.
                Route::post('/preview', 'preview');
                // ── Issue 7: locked label option lists ────────────────────
                // Materials / methods / placements / measurements for the
                // Brand Label and Care/Size Label pickers. Read-only;
                // strictly-locked vocab. Declared before GET /{id} so the
                // literal path resolves first.
                Route::get('/label-options', [QuotationLabelOptionsController::class, 'index']);
                Route::get('/{id}', 'show');
                Route::get('/{id}/pdf', 'generatePDF');
                Route::post('/{id}/confirm', 'confirm');
                // ── Issue 12: lifecycle status transitions + audit history ────
                // PATCH changes status through the state machine (Sent also
                // emails the PDF). GET returns the immutable transition log.
                Route::patch('/{id}/status', 'changeStatus');
                Route::get('/{id}/status-log', 'statusLog');
                // Issue 8 — CSR sends the quotation design to the GA for review.
                Route::post('/{id}/request-design-review', 'requestDesignReview');
                Route::put('/{id}', 'update');
                Route::delete('/{id}', 'destroy');
            });

            // ── Share Token Management (authenticated) ────────────────────────
            // permission: 'view'  → read-only filtered public data
            // permission: 'edit'  → read + update items & print parts
            // allow_download: bool → independent PDF download toggle
            //
            // POST   /{id}/share          → generate token
            // GET    /{id}/share          → list tokens
            // DELETE /{id}/share          → revoke all tokens
            // DELETE /share/{token}       → revoke one token
            Route::controller(QuotationShareController::class)->group(function () {
                Route::post('/{id}/share', 'generate');
                Route::get('/{id}/share', 'index');
                Route::delete('/{id}/share', 'revokeAll');
                Route::delete('/share/{token}', 'revoke');
            });
        });

        // ── Issue 8: Graphic Artist design-review surface ─────────────────
        // Separate from access.quotations so a GA can review a design under
        // least privilege (no quotation CRUD). graphic_artist holds
        // access.quotation-review; superadmin passes via Gate::before.
        Route::prefix('/quotation-reviews')
            ->middleware('permission:access.quotation-review')
            ->controller(QuotationReviewController::class)
            ->group(function () {
                Route::get('/{id}', 'show');
                Route::patch('/{id}', 'update');
            });

    });

    // ── Public Dropdown Access (NO authentication required) ──────────────────
    // Read-only endpoints for public clients.
    Route::prefix('/public')->group(function () {
        Route::prefix('/pattern-type')->controller(PatternTypeController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}', 'show');
        });

        Route::prefix('/apparel-type')->controller(ApparelTypeController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}', 'show');
        });

        Route::prefix('/apparel-neckline')->controller(ApparelNecklineController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}', 'show');
        });
    });

    // ── Public Share Access (NO authentication required) ──────────────────────
    // These routes are intentionally OUTSIDE the auth middleware.
    // Access is governed solely by the validity of the share token.
    //
    // GET /v2/share/quotations/{token}        → filtered view (no prices/names)
    // PUT /v2/share/quotations/{token}        → update items & print parts (edit permission)
    // GET /v2/share/quotations/{token}/pdf    → download PDF (allow_download toggle)
    Route::prefix('/share/quotations')->controller(PublicQuotationController::class)->group(function () {
        Route::get('/{token}', 'show');
        Route::put('/{token}', 'update');
        Route::get('/{token}/pdf', 'pdf');
    });



});