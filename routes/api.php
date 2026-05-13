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
use App\Http\Controllers\Api\QuotationShareController;
use App\Http\Controllers\Api\PublicQuotationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ShippingMethodController;
use App\Http\Controllers\Api\ApparelPatternPriceController;
use App\Http\Controllers\Api\ApparelNecklineController;
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
        });

        Route::prefix('/orders')->middleware('permission:access.orders')->controller(OrdersController::class)->group(function () {
            Route::get('/', 'index');
            // Phase 3 — lightweight picker for MR creation; only orders
            // with an active stage. Falls inside the same access.orders
            // gate since the user needs to see orders to pick from.
            Route::get('/with-active-stage', 'withActiveStage');
            Route::post('/', 'store');
            Route::get('/details/{po_code}', 'show');
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
                Route::get('/{id}', 'show');
                Route::get('/{id}/pdf', 'generatePDF');
                Route::post('/{id}/confirm', 'confirm');
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