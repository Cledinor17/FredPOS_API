<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required','string'],
            'password' => ['required','string','min:8','confirmed'], // attends password_confirmation
        ]);

        $user = $request->user();

        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mot de passe actuel incorrect.']);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        return response()->json(['message' => 'Password updated']);
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required','image','max:2048'], // 2MB
        ]);

        $file = $request->file('avatar');
        $ext = $file->extension() ?: 'jpg';
        $filename = Str::lower(Str::random(24)).'.'.$ext;
        $path = $file->storeAs('avatars', $filename, 'public');

        $user = $request->user();
        $oldPath = $user->avatar_path;
        $user->avatar_path = $path;
        $user->save();

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Avatar updated',
            'avatar_url' => asset('storage/'.$path),
        ]);
    }
}
