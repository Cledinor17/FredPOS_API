<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relation : Une categorie appartient a un business
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    // Relation : Une categorie a plusieurs produits
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
