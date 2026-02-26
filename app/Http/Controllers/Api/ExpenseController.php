<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    public function index()
    {
        return Expense::orderBy('expense_date', 'desc')->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required',
            'amount' => 'required|numeric',
            'expense_date' => 'required|date',
        ]);

        $expense = Expense::create([
            'title' => $request->title,
            'amount' => $request->amount,
            'expense_date' => $request->expense_date,
            'description' => $request->description,
            'user_id' => Auth::id(), // L'utilisateur connecté
            // Department géré automatiquement
        ]);

        return response()->json($expense, 201);
    }

    public function destroy($id)
    {
        Expense::findOrFail($id)->delete();
        return response()->json(['message' => 'Dépense supprimée']);
    }
}
