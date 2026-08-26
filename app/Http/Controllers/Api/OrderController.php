<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantOrder;
use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    use ResolvesTenantOrder;

    public function index(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $query = $outlet->orders()->with(['table', 'items.menu', 'payments']);

        if ($request->boolean('unacknowledged_only')) {
            $query->whereNull('acknowledged_at');
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        return response()->json($orders);
    }

    public function history(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:success,refund,cancelled',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'table_id' => 'nullable|uuid|exists:tables,id',
            'cashier_id' => 'nullable|uuid|exists:users,id',
            'payment_method' => 'nullable|in:cash,edc_bca,edc_bri,qr_bri,qr_gopay,qr_shopeepay,other',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $statusMap = [
            'success' => ['paid'],
            'refund' => ['refunded', 'partially_refunded'],
            'cancelled' => ['cancelled'],
        ];

        $query = $outlet->orders()
            ->with(['table', 'items.menu', 'payments', 'openedBy.employee'])
            ->whereIn('status', ['paid', 'refunded', 'partially_refunded', 'cancelled']);

        if ($request->filled('status')) {
            $query->whereIn('status', $statusMap[$request->input('status')]);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('table_id')) {
            $query->where('table_id', $request->input('table_id'));
        }

        if ($request->filled('cashier_id')) {
            $query->where('opened_by', $request->input('cashier_id'));
        }

        if ($request->filled('payment_method')) {
            $query->whereHas('payments', function ($paymentQuery) use ($request) {
                $paymentQuery->where('method', $request->input('payment_method'));
            });
        }

        $perPage = (int) $request->input('per_page', 20);

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($orders);
    }

    public function unacknowledgedCount(Request $request)
    {
        $count = Order::whereNull('acknowledged_at')
            ->whereHas('outlet', function ($query) use ($request) {
                $query->where('tenant_id', $request->user()->tenant_id);
            })
            ->count();

        return response()->json(['count' => $count]);
    }

    public function show(Request $request, string $outletId, string $orderId)
    {
        $order = $this->findOwnedOrder($request, $outletId, $orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        return response()->json($order->load(['table', 'items.menu', 'payments']));
    }

    public function store(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'table_id' => 'nullable|uuid|exists:tables,id',
            'customer_name' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.menu_id' => 'required|uuid|exists:menus,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $menus = Menu::where('outlet_id', $outlet->id)
            ->whereIn('id', collect($request->items)->pluck('menu_id'))
            ->get()
            ->keyBy('id');

        foreach ($request->items as $item) {
            if (!$menus->has($item['menu_id'])) {
                return response()->json([
                    'message' => 'One or more menus do not belong to this outlet',
                ], 422);
            }

            if (!$menus[$item['menu_id']]->is_active) {
                return response()->json([
                    'message' => "Menu '{$menus[$item['menu_id']]->name}' is currently inactive and cannot be ordered",
                ], 422);
            }
        }

        $order = DB::transaction(function () use ($request, $outlet, $menus) {
            $order = $outlet->orders()->create([
                'table_id' => $request->table_id,
                'order_number' => 'INV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'customer_name' => $request->customer_name,
                'order_type' => 'dine_in',
                'status' => 'open',
                'opened_by' => $request->user()->id,
                'acknowledged_at' => now(),
                'acknowledged_by' => $request->user()->id,
            ]);

            foreach ($request->items as $item) {
                $menu = $menus[$item['menu_id']];

                $order->items()->create([
                    'menu_id' => $menu->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $menu->price,
                ]);
            }

            return $order;
        });

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order->load(['table', 'items.menu']),
        ], 201);
    }

    public function acknowledge(Request $request, string $outletId, string $orderId)
    {
        $order = $this->findOwnedOrder($request, $outletId, $orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->acknowledged_at !== null) {
            return response()->json([
                'message' => 'This order has already been acknowledged',
            ], 422);
        }

        $order->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Order acknowledged successfully',
            'order' => $order->fresh(),
        ]);
    }
}