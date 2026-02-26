<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class BusinessController extends Controller
{
  public function index(Request $request)
  {
    // Si tu utilises business_users (pivot)
    $businesses = $request->user()
      ->businesses()
      ->select('businesses.id','businesses.name','businesses.slug')
      ->withPivot(['role','status'])
      ->orderBy('businesses.name')
      ->get()
      ->map(function($b){
        return [
          'id' => $b->id,
          'name' => $b->name,
          'slug' => $b->slug,
          'role' => $b->pivot->role,
          'status' => $b->pivot->status,
        ];
      });

    return response()->json(['data' => $businesses]);
  }

  public function show(Request $request, string $business)
  {
    $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
    if (!$currentBusiness) {
      abort(404, 'Business introuvable.');
    }

    return response()->json([
      'data' => $this->serializeBusiness($currentBusiness),
    ]);
  }

  public function update(Request $request, string $business)
  {
    $currentBusiness = app()->bound('currentBusiness') ? app('currentBusiness') : null;
    if (!$currentBusiness) {
      abort(404, 'Business introuvable.');
    }

    $input = $request->all();
    $nullableScalarFields = [
      'legal_name',
      'email',
      'phone',
      'website',
      'tax_number',
      'currency',
      'timezone',
      'invoice_footer',
    ];

    foreach ($nullableScalarFields as $field) {
      if (array_key_exists($field, $input) && is_string($input[$field])) {
        $input[$field] = trim($input[$field]);
        if ($input[$field] === '') {
          $input[$field] = null;
        }
      }
    }

    if (array_key_exists('name', $input) && is_string($input['name'])) {
      $input['name'] = trim($input['name']);
    }

    if (array_key_exists('address', $input) && is_array($input['address'])) {
      foreach (['line1', 'line2', 'city', 'state', 'zip', 'country'] as $field) {
        if (array_key_exists($field, $input['address']) && is_string($input['address'][$field])) {
          $input['address'][$field] = trim($input['address'][$field]);
          if ($input['address'][$field] === '') {
            $input['address'][$field] = null;
          }
        }
      }
    }

    $request->replace($input);

    $data = $request->validate([
      'name' => ['required', 'string', 'max:191'],
      'legal_name' => ['nullable', 'string', 'max:191'],
      'email' => ['nullable', 'email', 'max:191'],
      'phone' => ['nullable', 'string', 'max:191'],
      'website' => ['nullable', 'url', 'max:191'],
      'tax_number' => ['nullable', 'string', 'max:191'],
      'currency' => ['nullable', 'string', 'max:20'],
      'timezone' => ['nullable', 'string', 'max:191'],
      'invoice_footer' => ['nullable', 'string'],
      'address' => ['nullable', 'array'],
      'address.line1' => ['nullable', 'string', 'max:191'],
      'address.line2' => ['nullable', 'string', 'max:191'],
      'address.city' => ['nullable', 'string', 'max:191'],
      'address.state' => ['nullable', 'string', 'max:191'],
      'address.zip' => ['nullable', 'string', 'max:50'],
      'address.country' => ['nullable', 'string', 'max:191'],
      'logo' => ['nullable', 'image', 'max:3072'],
    ]);

    $address = Arr::get($data, 'address');
    if (is_array($address)) {
      $filteredAddress = array_filter($address, function ($value) {
        return is_string($value) ? trim($value) !== '' : !is_null($value);
      });
      $data['address'] = count($filteredAddress) > 0 ? $filteredAddress : null;
    }

    if ($request->hasFile('logo')) {
      if ($currentBusiness->logo_path) {
        Storage::disk('public')->delete($currentBusiness->logo_path);
      }
      $data['logo_path'] = $request->file('logo')->store('businesses/logos', 'public');
    }

    $currentBusiness->fill($data);
    $currentBusiness->save();

    return response()->json([
      'message' => 'Business mis a jour.',
      'data' => $this->serializeBusiness($currentBusiness->fresh()),
    ]);
  }

  private function serializeBusiness($business): array
  {
    $address = is_array($business->address) ? $business->address : [];

    return [
      'id' => (int) $business->id,
      'name' => (string) ($business->name ?? ''),
      'slug' => (string) ($business->slug ?? ''),
      'legal_name' => (string) ($business->legal_name ?? ''),
      'email' => (string) ($business->email ?? ''),
      'phone' => (string) ($business->phone ?? ''),
      'website' => (string) ($business->website ?? ''),
      'tax_number' => (string) ($business->tax_number ?? ''),
      'currency' => (string) ($business->currency ?? ''),
      'timezone' => (string) ($business->timezone ?? ''),
      'logo_path' => (string) ($business->logo_path ?? ''),
      'logo_url' => $business->logo_path ? asset('storage/'.$business->logo_path) : '',
      'invoice_footer' => (string) ($business->invoice_footer ?? ''),
      'address' => [
        'line1' => (string) ($address['line1'] ?? ''),
        'line2' => (string) ($address['line2'] ?? ''),
        'city' => (string) ($address['city'] ?? ''),
        'state' => (string) ($address['state'] ?? ''),
        'zip' => (string) ($address['zip'] ?? ''),
        'country' => (string) ($address['country'] ?? ''),
      ],
    ];
  }
}
