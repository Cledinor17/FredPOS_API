<?php

// app/Http/Controllers/Api/BusinessUserController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class BusinessUserController extends Controller
{
  public function index()
  {
    $business = app('currentBusiness');
    return $business->users()
      ->select('users.id','users.name','users.email','business_users.role','business_users.status','business_users.created_at')
      ->orderBy('users.name')
      ->paginate(25);
  }

  public function store(Request $request)
  {
    // rôle requis via route middleware ability:manage_users
    $business = app('currentBusiness');

    $data = $request->validate([
      'name' => ['required','string','max:190'],
      'email' => ['required','email','max:190'],
      'password' => ['nullable','string','min:8'],
      'role' => ['required', Rule::in(['owner','admin','manager','accountant','staff'])],
    ]);

    // Ne laisse pas créer owner via API (tu peux le garder réservé)
    if ($data['role'] === 'owner') abort(403, 'Owner role is restricted.');

    $user = User::where('email', $data['email'])->first();

    if (!$user) {
      $user = User::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => Hash::make($data['password'] ?? 'ChangeMe#12345'),
      ]);
    }

    $business->users()->syncWithoutDetaching([
      $user->id => [
        'role' => $data['role'],
        'status' => 'active',
        'joined_at' => now(),
      ]
    ]);

    return response()->json(['user_id'=>$user->id, 'email'=>$user->email, 'role'=>$data['role']]);
  }

  public function updateRole(Request $request, User $user)
  {
    $business = app('currentBusiness');

    $data = $request->validate([
      'role' => ['required', Rule::in(['admin','manager','accountant','staff'])],
      'status' => ['nullable', Rule::in(['active','disabled'])],
    ]);

    // Empêcher de modifier l'owner
    $pivot = $business->users()->where('users.id',$user->id)->first()?->pivot;
    if (!$pivot) abort(404, 'User not in business.');
    if ($pivot->role === 'owner') abort(403, 'Cannot modify owner.');

    $business->users()->updateExistingPivot($user->id, [
      'role' => $data['role'],
      'status' => $data['status'] ?? $pivot->status,
    ]);

    return response()->json(['user_id'=>$user->id,'role'=>$data['role'],'status'=>$data['status'] ?? $pivot->status]);
  }

  public function destroy(User $user)
  {
    $business = app('currentBusiness');

    $pivot = $business->users()->where('users.id',$user->id)->first()?->pivot;
    if (!$pivot) abort(404, 'User not in business.');
    if ($pivot->role === 'owner') abort(403, 'Cannot remove owner.');

    $business->users()->detach($user->id);
    return response()->json(['message'=>'Detached']);
  }
}
