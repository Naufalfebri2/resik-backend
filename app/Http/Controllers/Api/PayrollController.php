<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|date_format:Y-m',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $monthStart = $request->month . '-01';

        $periods = PayrollPeriod::whereHas('employee.section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })
            ->where('month', $monthStart)
            ->with('employee')
            ->orderBy('created_at')
            ->get();

        return response()->json($periods);
    }

    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|date_format:Y-m',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $employees = Employee::whereHas('section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->where('is_active', true)->get();

        $generated = $employees->map(function ($employee) use ($request) {
            return PayrollService::generateForEmployee($employee, $request->month . '-01');
        });

        return response()->json([
            'message' => "Payroll generated for {$generated->count()} employee(s)",
            'payroll_periods' => $generated,
        ], 201);
    }

    public function show(Request $request, string $payrollPeriodId)
    {
        $period = $this->findOwnedPeriod($request, $payrollPeriodId);

        if (!$period) {
            return response()->json(['message' => 'Payroll period not found'], 404);
        }

        return response()->json($period->load('employee'));
    }

    public function updateStatus(Request $request, string $payrollPeriodId)
    {
        $period = $this->findOwnedPeriod($request, $payrollPeriodId);

        if (!$period) {
            return response()->json(['message' => 'Payroll period not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,final,paid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $statusOrder = ['draft', 'final', 'paid'];
        $currentIndex = array_search($period->status, $statusOrder);
        $newIndex = array_search($request->status, $statusOrder);

        if ($newIndex < $currentIndex) {
            return response()->json([
                'message' => 'Cannot move payroll status backward',
                'errors' => [
                    'status' => [
                        "Status cannot move from '{$period->status}' back to '{$request->status}'.",
                    ],
                ],
            ], 422);
        }

        $period->update(['status' => $request->status]);

        return response()->json([
            'message' => 'Payroll status updated successfully',
            'payroll_period' => $period,
        ]);
    }

    private function findOwnedPeriod(Request $request, string $payrollPeriodId): ?PayrollPeriod
    {
        return PayrollPeriod::whereHas('employee.section.outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($payrollPeriodId);
    }
}
