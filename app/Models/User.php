<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Direction;
use App\Enums\Role;
use App\Org\UserPlacement;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'role', 'company_id', 'direction', 'team_lead_id', 'both_directions', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(fn (User $user) => UserPlacement::apply($user));
    }

    /**
     * Без FilamentUser вне local-окружения Filament отвечает 403 всем.
     * Регистрации нет, аккаунты заводит админ, поэтому пока пускаем любого
     * заведённого пользователя. Ограничение по ролям — вместе с оргструктурой.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

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
            'role' => Role::class,
            'direction' => Direction::class,
            'company_id' => 'integer',
            'team_lead_id' => 'integer',
            'both_directions' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => $value,
            set: fn (string $value): string => Str::lower(trim($value)),
        );
    }

    /** @return Attribute<string, string> */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => $value,
            set: fn (string $value): string => Str::squish($value),
        );
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_lead_id');
    }

    /** @return HasMany<User, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(User::class, 'team_lead_id');
    }
}
