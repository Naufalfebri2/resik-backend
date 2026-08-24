<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingEventController;
use App\Http\Controllers\Api\BookingStatusController;
use App\Http\Controllers\Api\CashAccountController;
use App\Http\Controllers\Api\CashflowReportController;
use App\Http\Controllers\Api\CashReconciliationController;
use App\Http\Controllers\Api\CashTransactionController;
use App\Http\Controllers\Api\ClosingSummaryController;
use App\Http\Controllers\Api\CustomFieldDefinitionController;
use App\Http\Controllers\Api\DailyStockController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderDeliveryController;
use App\Http\Controllers\Api\OrderItemController;
use App\Http\Controllers\Api\OrderPaymentController;
use App\Http\Controllers\Api\OrderPrepController;
use App\Http\Controllers\Api\OrderReceiptController;
use App\Http\Controllers\Api\OutletController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PublicOrderController;
use App\Http\Controllers\Api\PublicPickupOrderController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\ShiftScheduleController;
use App\Http\Controllers\Api\ShiftSwapRequestController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockOutflowController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TableBookingController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('throttle:30,1')->prefix('public')->group(function () {
    Route::get('/tables/{qrCode}/menu', [PublicOrderController::class, 'showMenu']);
    Route::post('/tables/{qrCode}/order', [PublicOrderController::class, 'store']);
    Route::get('/outlets/{outletId}/pickup-menu', [PublicPickupOrderController::class, 'showMenu']);
    Route::post('/outlets/{outletId}/pickup-order', [PublicPickupOrderController::class, 'store']);
    Route::get('/orders/{orderId}/status', [PublicOrderController::class, 'showStatus']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware('role:owner,admin')->group(function () {
        Route::get('/test-owner-admin-only', function () {
            return response()->json(['message' => 'You are owner/admin, access granted.']);
        });

        Route::apiResource('outlets', OutletController::class);

        Route::get('/outlets/{outletId}/sections', [SectionController::class, 'index']);
        Route::post('/outlets/{outletId}/sections', [SectionController::class, 'store']);
        Route::put('/outlets/{outletId}/sections/{sectionId}', [SectionController::class, 'update']);
        Route::delete('/outlets/{outletId}/sections/{sectionId}', [SectionController::class, 'destroy']);

        Route::get('/outlets/{outletId}/menus', [MenuController::class, 'index']);
        Route::post('/outlets/{outletId}/menus', [MenuController::class, 'store']);

        Route::get('/ingredients/low-stock', [IngredientController::class, 'lowStock']);
        Route::get('/ingredients/{ingredientId}', [IngredientController::class, 'show']);

        Route::get('/sections/{sectionId}/ingredients', [IngredientController::class, 'index']);
        Route::post('/sections/{sectionId}/ingredients', [IngredientController::class, 'store']);
        Route::put('/sections/{sectionId}/ingredients/{ingredientId}', [IngredientController::class, 'update']);
        Route::delete('/sections/{sectionId}/ingredients/{ingredientId}', [IngredientController::class, 'destroy']);

        Route::get('/ingredients/{ingredientId}/daily-stocks', [DailyStockController::class, 'index']);
        Route::post('/ingredients/{ingredientId}/daily-stocks', [DailyStockController::class, 'store']);
        Route::put('/ingredients/{ingredientId}/daily-stocks/{dailyStockId}', [DailyStockController::class, 'update']);
        Route::delete('/ingredients/{ingredientId}/daily-stocks/{dailyStockId}', [DailyStockController::class, 'destroy']);

        Route::get('/daily-stocks/{dailyStockId}/outflows', [StockOutflowController::class, 'index']);
        Route::post('/daily-stocks/{dailyStockId}/outflows', [StockOutflowController::class, 'store']);
        Route::put('/daily-stocks/{dailyStockId}/outflows/{outflowId}', [StockOutflowController::class, 'update']);
        Route::delete('/daily-stocks/{dailyStockId}/outflows/{outflowId}', [StockOutflowController::class, 'destroy']);
        Route::put('/daily-stocks/{dailyStockId}/close', [StockOutflowController::class, 'closeDailyStock']);

        Route::get('/ingredients/{ingredientId}/stock-adjustments', [StockAdjustmentController::class, 'index']);
        Route::post('/ingredients/{ingredientId}/stock-adjustments', [StockAdjustmentController::class, 'store']);
        Route::put('/ingredients/{ingredientId}/stock-adjustments/{adjustmentId}', [StockAdjustmentController::class, 'update']);
        Route::delete('/ingredients/{ingredientId}/stock-adjustments/{adjustmentId}', [StockAdjustmentController::class, 'destroy']);

        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{supplierId}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplierId}', [SupplierController::class, 'destroy']);

        Route::get('/outlets/{outletId}/purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::post('/outlets/{outletId}/purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::get('/outlets/{outletId}/purchase-orders/{purchaseOrderId}', [PurchaseOrderController::class, 'show']);
        Route::put('/outlets/{outletId}/purchase-orders/{purchaseOrderId}', [PurchaseOrderController::class, 'update']);
        Route::delete('/outlets/{outletId}/purchase-orders/{purchaseOrderId}', [PurchaseOrderController::class, 'destroy']);
        Route::put('/outlets/{outletId}/purchase-orders/{purchaseOrderId}/status', [PurchaseOrderController::class, 'updateStatus']);

        Route::post('/outlets/{outletId}/cash-accounts', [CashAccountController::class, 'store']);
        Route::get('/cash-accounts/{cashAccountId}/transactions', [CashTransactionController::class, 'index']);
        Route::post('/cash-accounts/{cashAccountId}/transactions', [CashTransactionController::class, 'store']);

        Route::get('/cashflow', [CashflowReportController::class, 'forTenant']);

        Route::get('/sections/{sectionId}/closing-summary', [ClosingSummaryController::class, 'section']);

        Route::post('/sections/{sectionId}/employees', [EmployeeController::class, 'store']);
        Route::put('/sections/{sectionId}/employees/{employeeId}', [EmployeeController::class, 'update']);
        Route::delete('/sections/{sectionId}/employees/{employeeId}', [EmployeeController::class, 'destroy']);
        Route::put('/sections/{sectionId}/employees/{employeeId}/move', [EmployeeController::class, 'move']);

        Route::post('/sections/{sectionId}/shifts', [ShiftController::class, 'store']);
        Route::put('/sections/{sectionId}/shifts/{shiftId}', [ShiftController::class, 'update']);
        Route::delete('/sections/{sectionId}/shifts/{shiftId}', [ShiftController::class, 'destroy']);

        Route::post('/employees/{employeeId}/shift-schedules', [ShiftScheduleController::class, 'store']);
        Route::delete('/employees/{employeeId}/shift-schedules/{scheduleId}', [ShiftScheduleController::class, 'destroy']);

        Route::get('/shift-swap-requests', [ShiftSwapRequestController::class, 'index']);
        Route::post('/shift-swap-requests', [ShiftSwapRequestController::class, 'store']);

        Route::post('/employees/{employeeId}/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/employees/{employeeId}/attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::post('/employees/{employeeId}/attendance/mark-status', [AttendanceController::class, 'markStatus']);

        Route::get('/payroll-periods', [PayrollController::class, 'index']);
        Route::post('/payroll-periods/generate', [PayrollController::class, 'generate']);
        Route::get('/payroll-periods/{payrollPeriodId}', [PayrollController::class, 'show']);
        Route::put('/payroll-periods/{payrollPeriodId}/status', [PayrollController::class, 'updateStatus']);

        Route::get('/sections/{sectionId}/tables', [TableController::class, 'index']);
        Route::post('/sections/{sectionId}/tables', [TableController::class, 'store']);
        Route::put('/sections/{sectionId}/tables/{tableId}', [TableController::class, 'update']);
        Route::put('/sections/{sectionId}/tables/{tableId}/regenerate-qr', [TableController::class, 'regenerateQrCode']);
        Route::delete('/sections/{sectionId}/tables/{tableId}', [TableController::class, 'destroy']);

        Route::get('/outlets/{outletId}/orders', [OrderController::class, 'index']);
        Route::get('/outlets/{outletId}/orders/history', [OrderController::class, 'history']);
        Route::get('/outlets/{outletId}/users', [UserManagementController::class, 'index']);
        Route::post('/outlets/{outletId}/orders', [OrderController::class, 'store']);
        Route::get('/outlets/{outletId}/orders/{orderId}', [OrderController::class, 'show']);
        Route::put('/outlets/{outletId}/orders/{orderId}/acknowledge', [OrderController::class, 'acknowledge']);
        Route::post('/outlets/{outletId}/orders/{orderId}/items', [OrderItemController::class, 'addItems']);
        Route::put('/outlets/{outletId}/orders/{orderId}/items/{orderItemId}/split-label', [OrderItemController::class, 'assignSplitLabel']);
        Route::put('/outlets/{outletId}/orders/{orderId}/items/{orderItemId}/prep-status', [OrderPrepController::class, 'updatePrepStatus']);
        Route::post('/outlets/{outletId}/orders/{orderId}/items/{orderItemId}/refund', [OrderPaymentController::class, 'refundItem']);
        Route::post('/outlets/{outletId}/orders/{orderId}/cancel-all', [OrderPaymentController::class, 'cancelAll']);
        Route::post('/outlets/{outletId}/orders/{orderId}/pay', [OrderPaymentController::class, 'pay']);
        Route::get('/outlets/{outletId}/orders/{orderId}/receipt', [OrderReceiptController::class, 'pdf']);

        Route::post('/outlets/{outletId}/delivery-orders', [OrderDeliveryController::class, 'store']);
        Route::put('/outlets/{outletId}/delivery-orders/{orderId}/courier-status', [OrderDeliveryController::class, 'updateCourierStatus']);

        Route::get('/outlets/{outletId}/bookings', [TableBookingController::class, 'index']);
        Route::post('/outlets/{outletId}/bookings', [TableBookingController::class, 'store']);
        Route::get('/outlets/{outletId}/bookings/{bookingId}', [TableBookingController::class, 'show']);
        Route::post('/outlets/{outletId}/bookings/event', [BookingEventController::class, 'store']);
        Route::put('/outlets/{outletId}/bookings/{bookingId}/advance', [BookingStatusController::class, 'advance']);
        Route::put('/outlets/{outletId}/bookings/{bookingId}/cancel', [BookingStatusController::class, 'cancel']);
        Route::put('/outlets/{outletId}/bookings/{bookingId}/no-show', [BookingStatusController::class, 'markNoShow']);

        Route::get('/tenant', [TenantController::class, 'show']);

        Route::get('/custom-field-definitions', [CustomFieldDefinitionController::class, 'index']);
        Route::post('/custom-field-definitions', [CustomFieldDefinitionController::class, 'store']);
        Route::delete('/custom-field-definitions/{id}', [CustomFieldDefinitionController::class, 'destroy']);
    });

    Route::middleware('role:owner,admin,manager')->group(function () {
        Route::get('/sections/{sectionId}/employees', [EmployeeController::class, 'index']);
        Route::get('/sections/{sectionId}/shifts', [ShiftController::class, 'index']);
        Route::get('/employees/{employeeId}/shift-schedules', [ShiftScheduleController::class, 'index']);
        Route::get('/employees/{employeeId}/attendance', [AttendanceController::class, 'index']);
        Route::put('/shift-swap-requests/{swapRequestId}/approve', [ShiftSwapRequestController::class, 'approve']);
        Route::put('/shift-swap-requests/{swapRequestId}/reject', [ShiftSwapRequestController::class, 'reject']);
    });

    Route::middleware(['role:owner,admin,manager', 'outlet.scope'])->group(function () {
        Route::get('/outlets/{outletId}/cash-accounts', [CashAccountController::class, 'index']);
        Route::get('/outlets/{outletId}/cashflow', [CashflowReportController::class, 'forOutlet']);

        Route::get('/cash-accounts/{cashAccountId}/cashflow', [CashflowReportController::class, 'forAccount']);
        Route::get('/cash-accounts/{cashAccountId}/reconciliations', [CashReconciliationController::class, 'index']);
        Route::post('/cash-accounts/{cashAccountId}/reconciliations', [CashReconciliationController::class, 'store']);
    });

    Route::middleware('role:owner')->group(function () {
        Route::put('/tenant/settings', [TenantController::class, 'updateSettings']);

        Route::post('/users/manager', [UserManagementController::class, 'createManager']);

        Route::put('/cash-accounts/{cashAccountId}/reconciliations/{reconciliationId}/approve', [CashReconciliationController::class, 'approve']);
        Route::put('/cash-accounts/{cashAccountId}/reconciliations/{reconciliationId}/reject', [CashReconciliationController::class, 'reject']);
    });
});
