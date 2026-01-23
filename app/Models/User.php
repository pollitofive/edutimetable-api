<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
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
        'default_business_id',
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

    /**
     * Get all businesses that this user belongs to
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the user's default business
     */
    public function defaultBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'default_business_id');
    }

    /**
     * Check if user has access to a specific business
     */
    public function hasAccessToBusiness(int $businessId): bool
    {
        return $this->businesses()->where('business_id', $businessId)->exists();
    }

    /**
     * Get user's role in a specific business
     */
    public function getRoleInBusiness(int $businessId): ?string
    {
        $business = $this->businesses()->where('business_id', $businessId)->first();

        return $business?->pivot->role;
    }
}
