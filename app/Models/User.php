<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Direction;
use App\Enums\Locale;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Org\UserPlacement;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
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

#[Fillable(['name', 'email', 'password', 'role', 'company_id', 'direction', 'team_lead_id', 'both_directions', 'status', 'locale', 'show_archived'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(fn (User $user) => UserPlacement::apply($user));
    }

    /**
     * Входит только активный: заблокированный и архивный — нет, и уже открытая
     * сессия обрывается на следующем запросе (Filament проверяет это на каждом).
     * Сотрудники архивной компании — тоже нет: компания вне группы.
     * Регистрации нет — аккаунты заводит владелец.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return $this->role === Role::Owner || ! ($this->company?->isArchived() ?? true);
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
            'status' => UserStatus::class,
            'locale' => Locale::class,
            'show_archived' => 'boolean',
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

    /** В меню аккаунта у администратора компании видно, какую компанию он представляет. */
    public function getFilamentName(): string
    {
        if ($this->role !== Role::CompanyOwner) {
            return $this->name;
        }

        $company = $this->company?->name;

        return $company !== null ? "{$this->name} · {$company}" : $this->name;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isArchived(): bool
    {
        return $this->status === UserStatus::Archived;
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

    /** @return HasMany<Phone, $this> */
    public function phones(): HasMany
    {
        return $this->hasMany(Phone::class);
    }

    /** @return HasMany<User, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(User::class, 'team_lead_id');
    }

    /** @return HasMany<SavedListing, $this> */
    public function savedListings(): HasMany
    {
        return $this->hasMany(SavedListing::class);
    }
}
