<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Section;
use App\Services\CustomFieldValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function index(Request $request, string $sectionId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        return response()->json($section->employees()->orderBy('start_date')->get());
    }

    public function store(Request $request, string $sectionId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'role' => ['required', 'string', 'max:50', 'regex:/^[A-Z][a-zA-Z\s]*$/'],
            'start_date' => 'required|date',
            'base_salary' => 'required|numeric|min:0',
            'custom_fields' => 'nullable|array',
        ], [
            'role.regex' => 'Role must start with a capital letter (e.g. "Barista", not "barista").',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $existingRole = $section->employees()->value('role');

        if ($existingRole !== null && $existingRole !== $request->role) {
            return response()->json([
                'message' => "This section already uses the role \"{$existingRole}\". All employees in a section must share the same role — create a new section if you need a different role.",
            ], 422);
        }

        try {
            $validatedCustomFields = CustomFieldValidator::validate(
                $request->user()->tenant_id,
                'employees',
                $request->input('custom_fields', [])
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Custom field validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $employee = $section->employees()->create([
            'name' => $request->name,
            'phone' => $request->phone,
            'role' => $request->role,
            'start_date' => $request->start_date,
            'base_salary' => $request->base_salary,
            'custom_fields' => $validatedCustomFields,
        ]);

        return response()->json([
            'message' => 'Employee created successfully',
            'employee' => $employee,
        ], 201);
    }

    public function update(Request $request, string $sectionId, string $employeeId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $employee = $section->employees()->find($employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'phone' => 'sometimes|string|max:20',
            'role' => ['sometimes', 'string', 'max:50', 'regex:/^[A-Z][a-zA-Z\s]*$/'],
            'base_salary' => 'sometimes|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'custom_fields' => 'nullable|array',
        ], [
            'role.regex' => 'Role must start with a capital letter (e.g. "Barista", not "barista").',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('role')) {
            $existingRole = $section->employees()
                ->where('id', '!=', $employee->id)
                ->value('role');

            if ($existingRole !== null && $existingRole !== $request->role) {
                return response()->json([
                    'message' => "This section already uses the role \"{$existingRole}\". All employees in a section must share the same role — create a new section if you need a different role.",
                ], 422);
            }
        }

        $updateData = $request->only(['name', 'phone', 'role', 'base_salary', 'is_active']);

        if ($request->has('custom_fields')) {
            try {
                $updateData['custom_fields'] = CustomFieldValidator::validate(
                    $request->user()->tenant_id,
                    'employees',
                    $request->input('custom_fields', [])
                );
            } catch (ValidationException $e) {
                return response()->json([
                    'message' => 'Custom field validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        }

        $employee->update($updateData);

        return response()->json([
            'message' => 'Employee updated successfully',
            'employee' => $employee,
        ]);
    }

    public function move(Request $request, string $sectionId, string $employeeId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $employee = $section->employees()->find($employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'target_section_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->target_section_id === $sectionId) {
            return response()->json([
                'message' => 'Employee is already in this section.',
            ], 422);
        }

        $targetSection = $this->findOwnedSection($request, $request->target_section_id);

        if (!$targetSection) {
            return response()->json(['message' => 'Target section not found'], 404);
        }

        $targetExistingRole = $targetSection->employees()->value('role');

        if ($targetExistingRole !== null && $targetExistingRole !== $employee->role) {
            return response()->json([
                'message' => "The target section already uses the role \"{$targetExistingRole}\", which doesn't match this employee's role (\"{$employee->role}\"). Update the employee's role first, or choose a different section.",
            ], 422);
        }

        $employee->update(['section_id' => $targetSection->id]);

        return response()->json([
            'message' => 'Employee moved successfully',
            'employee' => $employee,
        ]);
    }

    public function destroy(Request $request, string $sectionId, string $employeeId)
    {
        $section = $this->findOwnedSection($request, $sectionId);

        if (!$section) {
            return response()->json(['message' => 'Section not found'], 404);
        }

        $employee = $section->employees()->find($employeeId);

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $employee->delete();

        return response()->json(['message' => 'Employee deleted successfully']);
    }

    private function findOwnedSection(Request $request, string $sectionId): ?Section
    {
        return Section::whereHas('outlet', function ($query) use ($request) {
            $query->where('tenant_id', $request->user()->tenant_id);
        })->find($sectionId);
    }
}
