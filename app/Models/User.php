<?php

namespace App\Models;
use Laravel\Sanctum\HasApiTokens;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;


class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    //use HasFactory, Notifiable;
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',         // Ajouté pour votre projet
        'department',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    // app/Models/User.php
public function businesses()
{
    return $this->belongsToMany(\App\Models\Business::class, 'business_users')
        ->withPivot(['role','status','invited_at','joined_at','metadata'])
        ->withTimestamps();
}
// app/Models/User.php

public function currentBusinessRole(): string
{
    $m = app()->bound('currentMembership') ? app('currentMembership') : null;
    return $m?->role ?? 'staff';
}

public function canAbility(string $ability): bool
{
    $role = $this->currentBusinessRole();
    $map = config('role', config('roles', []));
    $abilities = $map[$role] ?? [];
    return in_array($ability, $abilities, true);
}


}
