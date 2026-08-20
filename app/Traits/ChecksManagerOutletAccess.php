<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ChecksManagerOutletAccess
{
    protected function authorizeManagerOutlet(Request $request, string $outletId): ?JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'manager' && $user->outlet_id !== $outletId) {
            return response()->json([
                'message' => 'You do not have access to this outlet.',
            ], 403);
        }

        return null;
    }
}
