<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    private function resolveEmployeeOrFail(string $employee): Employee
    {
        if (!ctype_digit($employee)) {
            abort(404, 'Employee not found.');
        }

        $resolved = Employee::query()
            ->withSum('payments as total_paid_amount', 'amount')
            ->whereKey((int) $employee)
            ->first();

        if (!$resolved) {
            abort(404, 'Employee not found.');
        }

        return $resolved;
    }

    private function serializeEmployee(Employee $employee): array
    {
        return [
            'id' => (int) $employee->id,
            'name' => (string) ($employee->name ?? ''),
            'email' => (string) ($employee->email ?? ''),
            'phone' => (string) ($employee->phone ?? ''),
            'job_title' => (string) ($employee->job_title ?? ''),
            'salary_amount' => (float) ($employee->salary_amount ?? 0),
            'salary_currency' => (string) ($employee->salary_currency ?? ''),
            'pay_frequency' => (string) ($employee->pay_frequency ?? 'monthly'),
            'hired_at' => $employee->hired_at ? $employee->hired_at->toDateString() : null,
            'is_active' => (bool) $employee->is_active,
            'notes' => (string) ($employee->notes ?? ''),
            'total_paid_amount' => (float) ($employee->total_paid_amount ?? 0),
            'created_at' => $employee->created_at ? $employee->created_at->toISOString() : null,
            'updated_at' => $employee->updated_at ? $employee->updated_at->toISOString() : null,
        ];
    }

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $query = Employee::query()->withSum('payments as total_paid_amount', 'amount');

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('job_title', 'like', '%' . $search . '%');
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderBy('name')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (Employee $employee) => $this->serializeEmployee($employee))
            ->values();

        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'salary_amount' => ['nullable', 'numeric', 'min:0'],
            'salary_currency' => ['nullable', 'string', 'max:10'],
            'pay_frequency' => ['nullable', Rule::in(['monthly', 'biweekly', 'weekly', 'hourly'])],
            'hired_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (!array_key_exists('is_active', $data) || $data['is_active'] === null) {
            $data['is_active'] = true;
        }

        if (!array_key_exists('salary_amount', $data) || $data['salary_amount'] === null) {
            $data['salary_amount'] = 0;
        }

        if (!array_key_exists('pay_frequency', $data) || $data['pay_frequency'] === null) {
            $data['pay_frequency'] = 'monthly';
        }

        if (empty($data['salary_currency'])) {
            $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
            $data['salary_currency'] = (string) ($currentBusiness->currency ?? '');
        }

        $employee = Employee::create($data);
        $employee = Employee::query()
            ->withSum('payments as total_paid_amount', 'amount')
            ->whereKey($employee->id)
            ->firstOrFail();

        return response()->json($this->serializeEmployee($employee), 201);
    }

    public function show(string $business, string $employee)
    {
        $resolved = $this->resolveEmployeeOrFail($employee);
        return response()->json($this->serializeEmployee($resolved));
    }

    public function update(Request $request, string $business, string $employee)
    {
        $resolved = $this->resolveEmployeeOrFail($employee);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'salary_amount' => ['nullable', 'numeric', 'min:0'],
            'salary_currency' => ['nullable', 'string', 'max:10'],
            'pay_frequency' => ['nullable', Rule::in(['monthly', 'biweekly', 'weekly', 'hourly'])],
            'hired_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (array_key_exists('is_active', $data) && $data['is_active'] === null) {
            unset($data['is_active']);
        }

        $resolved->update($data);

        $fresh = Employee::query()
            ->withSum('payments as total_paid_amount', 'amount')
            ->whereKey($resolved->id)
            ->firstOrFail();

        return response()->json($this->serializeEmployee($fresh));
    }

    public function destroy(string $business, string $employee)
    {
        $resolved = $this->resolveEmployeeOrFail($employee);
        $resolved->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
