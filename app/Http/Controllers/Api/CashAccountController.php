<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CashAccountController extends Controller
{
    public function index(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $accounts = $outlet->cashAccounts()->get()->map(function ($account) {
            $totalIn = $account->cashTransactions()->where('type', 'in')->sum('amount');
            $totalOut = $account->cashTransactions()->where('type', 'out')->sum('amount');

            $account->balance = $totalIn - $totalOut;

            return $account;
        });

        return response()->json($accounts);
    }

    public function store(Request $request, string $outletId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'type' => 'required|in:cash,bank',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $account = $outlet->cashAccounts()->create([
            'name' => $request->name,
            'type' => $request->type,
        ]);

        return response()->json([
            'message' => 'Cash account created successfully',
            'cash_account' => $account,
        ], 201);
    }

    public function update(Request $request, string $outletId, string $cashAccountId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $account = $outlet->cashAccounts()->find($cashAccountId);

        if (!$account) {
            return response()->json(['message' => 'Cash account not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'type' => 'required|in:cash,bank',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $account->update([
            'name' => $request->name,
            'type' => $request->type,
        ]);

        return response()->json([
            'message' => 'Cash account updated successfully',
            'cash_account' => $account,
        ]);
    }

    public function destroy(Request $request, string $outletId, string $cashAccountId)
    {
        $outlet = $this->findOwnedOutlet($request, $outletId);

        if (!$outlet) {
            return response()->json(['message' => 'Outlet not found'], 404);
        }

        $account = $outlet->cashAccounts()->find($cashAccountId);

        if (!$account) {
            return response()->json(['message' => 'Cash account not found'], 404);
        }

        if ($account->cashTransactions()->exists() || $account->reconciliations()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a cash account that already has transactions or reconciliations. Archive it instead.',
            ], 422);
        }

        $account->delete();

        return response()->json([
            'message' => 'Cash account deleted successfully',
        ]);
    }

    private function findOwnedOutlet(Request $request, string $outletId): ?Outlet
    {
        return Outlet::where('tenant_id', $request->user()->tenant_id)->find($outletId);
    }
}
