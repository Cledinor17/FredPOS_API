<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePayment;
use Illuminate\Http\Request;

class EmployeePaymentController extends Controller
{
    private function resolveEmployeeOrFail(string $employee): Employee
    {
        if (!ctype_digit($employee)) {
            abort(404, 'Employee not found.');
        }

        $resolved = Employee::query()
            ->whereKey((int) $employee)
            ->first();

        if (!$resolved) {
            abort(404, 'Employee not found.');
        }

        return $resolved;
    }

    private function serializePayment(EmployeePayment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'employee_id' => (int) $payment->employee_id,
            'amount' => (float) ($payment->amount ?? 0),
            'currency' => (string) ($payment->currency ?? ''),
            'paid_at' => $payment->paid_at ? $payment->paid_at->toDateString() : null,
            'method' => (string) ($payment->method ?? ''),
            'reference' => (string) ($payment->reference ?? ''),
            'notes' => (string) ($payment->notes ?? ''),
            'recorded_by' => $payment->recorded_by ? (int) $payment->recorded_by : null,
            'recorded_by_name' => (string) ($payment->recordedBy?->name ?? ''),
            'created_at' => $payment->created_at ? $payment->created_at->toISOString() : null,
            'updated_at' => $payment->updated_at ? $payment->updated_at->toISOString() : null,
        ];
    }

    public function index(Request $request, string $business, string $employee)
    {
        $resolvedEmployee = $this->resolveEmployeeOrFail($employee);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $query = EmployeePayment::query()
            ->where('employee_id', $resolvedEmployee->id)
            ->with('recordedBy:id,name,email');

        if ($request->filled('from')) {
            $query->whereDate('paid_at', '>=', (string) $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('paid_at', '<=', (string) $request->query('to'));
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (EmployeePayment $payment) => $this->serializePayment($payment))
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
            'employee' => [
                'id' => (int) $resolvedEmployee->id,
                'name' => (string) $resolvedEmployee->name,
            ],
        ]);
    }

    public function store(Request $request, string $business, string $employee)
    {
        $resolvedEmployee = $this->resolveEmployeeOrFail($employee);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:10'],
            'paid_at' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
        ]);

        $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;

        $data['employee_id'] = $resolvedEmployee->id;
        $data['paid_at'] = $data['paid_at'] ?? now()->toDateString();
        $data['currency'] = $data['currency']
            ?? $resolvedEmployee->salary_currency
            ?? ($currentBusiness->currency ?? '');
        $data['recorded_by'] = $request->user()?->id;

        $payment = EmployeePayment::create($data)->load('recordedBy:id,name,email');

        return response()->json($this->serializePayment($payment), 201);
    }
}
