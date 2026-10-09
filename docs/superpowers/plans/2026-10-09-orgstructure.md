# Оргструктура GS Home — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Компании группы, люди с ролями и командами, номера телефонов компании, пары обмена и единый класс правил видимости `OrgAccess` с экранами Filament для владельца.

**Architecture:** Плоская модель: роль, компания, команда, тимлид и «оба направления» — поля `users`; номера — `phones` (принадлежат компании, сотрудник — держатель); пары — `company_exchanges`. Инварианты дублируются CHECK-ограничениями PostgreSQL и хуками моделей. Все правила видимости — в `App\Access\OrgAccess`; политики и ресурсы Filament только спрашивают его.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5.10, PostgreSQL 17, PHPUnit 12 + paratest, `ysfkaya/filament-phone-input` ^4.2, `giggsey/libphonenumber-for-php-lite` ^9.0.

**Spec:** `docs/superpowers/specs/2026-10-09-orgstructure-design.md`

## Global Constraints

- Всё в Docker: artisan/composer — `docker compose exec app …`; тесты — только `docker compose run --rm test …`. Composer MAMP-ом с хоста не звать.
- Один тест/класс: `docker compose run --rm test php artisan test --filter=<Имя>`; весь сьют: `docker compose run --rm test composer test`.
- После каждой задачи: `docker compose exec app composer analyse` — 0 ошибок, baseline не заводить; `docker compose exec app vendor/bin/pint --dirty`.
- Роли фиксированные: `owner`, `company_owner`, `team_lead`, `agent`. Никаких Shield / `spatie/laravel-permission`.
- Направления: `sale` («Продажа»), `rent` («Помесячная сдача»).
- Любая проверка «кто что видит» — через `OrgAccess` или политики, которые его зовут; в ресурсах свою логику прав не писать.
- Телефоны — только Грузия, хранение E.164 (`+995…`), нормализация `App\Support\PhoneNumber` при любой записи.
- Подписи интерфейса — по-русски (язык интерфейса — открытый вопрос, см. Task 12).
- Коммиты — в ветке `feature/orgstructure`; сообщение заканчивается строками:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` и
  `Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg`.

## Review Focus

1. **Увольнение тимлида, у которого есть сотрудники** — у сотрудника тимлид обязателен (CHECK), поэтому действие требует выбрать нового тимлида и переводит к нему всех. Тест — Task 9.
2. **Email существующего человека, набранный в другом регистре** — ошибка формы «уже есть», а не 500 от уникального индекса. Тест — Task 8.
3. **Номер, уже заведённый, но набранный в другой записи** (`555 12 34 56` против `+995555123456`) — ошибка формы, а не 500. Тесты — Task 2 (модель) и Task 10 (форма).
4. **Смена роли при редактировании** (сотрудник → владелец компании) — команда, тимлид и «оба направления» очищаются, а не остаются висеть. Тесты — Task 1 (модель) и Task 8 (форма).
5. **Сотрудник архивной компании пытается войти** — вход запрещён, как для выключенного. Тест — Task 6.

Дополнительно (не в пятёрке, но покрыто): человек переведён в другую компанию — номера прежней компании с него снимаются (Task 2); мусор вместо телефона в `ownership()` — «другая компания» без исключения (Task 5).

---

### Task 1: Роли, направления, компании и поля пользователя

**Files:**
- Create: `app/Enums/Role.php`, `app/Enums/Direction.php`
- Create: `database/migrations/2026_10_09_100000_create_companies_table.php`
- Create: `database/migrations/2026_10_09_100100_add_org_columns_to_users_table.php`
- Create: `app/Models/Company.php`, `database/factories/CompanyFactory.php`
- Create: `app/Org/UserPlacement.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`
- Test: `tests/Feature/Org/UserPlacementTest.php`

**Interfaces:**
- Produces: `Role` (`Owner|CompanyOwner|TeamLead|Agent`, `hasTeam(): bool`, `getLabel(): string`); `Direction` (`Sale|Rent`, `getLabel()`); `Company` (`users()`, `phones()` — добавится в Task 2, `isArchived(): bool`, scope `active()`); `User` поля `role: Role`, `company_id: ?int`, `direction: ?Direction`, `team_lead_id: ?int`, `both_directions: bool`, `is_active: bool`, связи `company()`, `teamLead()`, `agents()`; фабрики `UserFactory::owner()`, `companyOwner(?Company)`, `teamLead(?Company, Direction = Sale)`, `agent(User $lead)`, `inactive()`; `UserPlacement::apply(User): void`.

- [ ] **Step 0: Ветка**

```bash
cd ~/Sites/gshome && git switch -c feature/orgstructure
```

- [ ] **Step 1: Написать падающие тесты**

`tests/Feature/Org/UserPlacementTest.php`:

```php
<?php

namespace Tests\Feature\Org;

use App\Enums\Direction;
use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class UserPlacementTest extends TestCase
{
    use RefreshDatabase;

    /** Вставка мимо модели: проверяем CHECK-ограничения базы, а не хуки. */
    private function insertUser(array $attributes): void
    {
        DB::table('users')->insert(array_merge([
            'name' => 'X',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'x',
            'role' => 'agent',
            'company_id' => null,
            'direction' => null,
            'team_lead_id' => null,
            'both_directions' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public function test_database_rejects_owner_with_company(): void
    {
        $company = Company::factory()->create();
        $this->expectException(QueryException::class);
        $this->insertUser(['role' => 'owner', 'company_id' => $company->id]);
    }

    public function test_database_rejects_team_lead_without_direction(): void
    {
        $company = Company::factory()->create();
        $this->expectException(QueryException::class);
        $this->insertUser(['role' => 'team_lead', 'company_id' => $company->id]);
    }

    public function test_database_rejects_agent_without_team_lead(): void
    {
        $company = Company::factory()->create();
        $this->expectException(QueryException::class);
        $this->insertUser(['role' => 'agent', 'company_id' => $company->id, 'direction' => 'sale']);
    }

    public function test_database_rejects_team_lead_id_on_non_agent(): void
    {
        $lead = User::factory()->teamLead()->create();
        $this->expectException(QueryException::class);
        $this->insertUser([
            'role' => 'company_owner',
            'company_id' => $lead->company_id,
            'team_lead_id' => $lead->id,
        ]);
    }

    public function test_role_change_clears_fields_the_new_role_does_not_have(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create(['both_directions' => true]);

        $agent->update(['role' => Role::CompanyOwner]);

        $agent->refresh();
        $this->assertSame(Role::CompanyOwner, $agent->role);
        $this->assertNull($agent->direction);
        $this->assertNull($agent->team_lead_id);
        $this->assertFalse($agent->both_directions);
        $this->assertSame($lead->company_id, $agent->company_id);
    }

    public function test_owner_loses_company(): void
    {
        $user = User::factory()->companyOwner()->create();

        $user->update(['role' => Role::Owner]);

        $this->assertNull($user->refresh()->company_id);
    }

    public function test_agent_team_lead_must_be_from_same_company(): void
    {
        $lead = User::factory()->teamLead()->create();
        $other = Company::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        User::factory()->create([
            'role' => Role::Agent,
            'company_id' => $other->id,
            'direction' => Direction::Sale,
            'team_lead_id' => $lead->id,
        ]);
    }

    public function test_agent_team_lead_must_be_from_same_direction(): void
    {
        $lead = User::factory()->teamLead(direction: Direction::Rent)->create();

        $this->expectException(InvalidArgumentException::class);
        User::factory()->create([
            'role' => Role::Agent,
            'company_id' => $lead->company_id,
            'direction' => Direction::Sale,
            'team_lead_id' => $lead->id,
        ]);
    }

    public function test_agent_team_lead_must_be_a_team_lead(): void
    {
        $owner = User::factory()->companyOwner()->create();

        $this->expectException(InvalidArgumentException::class);
        User::factory()->create([
            'role' => Role::Agent,
            'company_id' => $owner->company_id,
            'direction' => Direction::Sale,
            'team_lead_id' => $owner->id,
        ]);
    }

    public function test_email_and_name_are_normalized(): void
    {
        $user = User::factory()->create(['email' => '  Max+CL@AbsoluteWeb.com ', 'name' => "  Max \t  CL "]);

        $this->assertSame('max+cl@absoluteweb.com', $user->refresh()->email);
        $this->assertSame('Max CL', $user->name);
    }

    public function test_company_name_is_squished(): void
    {
        $company = Company::factory()->create(['name' => '  GS   Home  ']);

        $this->assertSame('GS Home', $company->refresh()->name);
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `docker compose run --rm test php artisan test --filter=UserPlacementTest`
Expected: FAIL — `Class "App\Models\Company" not found` / нет enum.

- [ ] **Step 3: Enum-ы**

`app/Enums/Role.php`:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Owner = 'owner';
    case CompanyOwner = 'company_owner';
    case TeamLead = 'team_lead';
    case Agent = 'agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Владелец',
            self::CompanyOwner => 'Владелец компании',
            self::TeamLead => 'Тимлид',
            self::Agent => 'Сотрудник',
        };
    }

    /** Роли, которые состоят в команде (продажа или сдача). */
    public function hasTeam(): bool
    {
        return $this === self::TeamLead || $this === self::Agent;
    }
}
```

`app/Enums/Direction.php`:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Direction: string implements HasLabel
{
    case Sale = 'sale';
    case Rent = 'rent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sale => 'Продажа',
            self::Rent => 'Помесячная сдача',
        };
    }
}
```

- [ ] **Step 4: Миграции**

`database/migrations/2026_10_09_100000_create_companies_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Не пусто — компания вне группы: её объявления считаются чужими.
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
```

`database/migrations/2026_10_09_100100_add_org_columns_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('direction')->nullable();
            $table->foreignId('team_lead_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('both_directions')->default(false);
            $table->boolean('is_active')->default(true);
        });

        // Уже заведённые аккаунты (локальный админ) — владельцы: иначе NOT NULL
        // и CHECK ниже не прошли бы на существующих строках.
        DB::table('users')->whereNull('role')->update(['role' => 'owner']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable(false)->change();
        });

        // Инварианты оргструктуры — в базе, а не только в формах: ошибка в коде
        // не сохранит сотрудника без команды или владельца с компанией.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('owner','company_owner','team_lead','agent'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_direction_check CHECK (direction IS NULL OR direction IN ('sale','rent'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_owner_company_check CHECK ((role = 'owner') = (company_id IS NULL))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_team_direction_check CHECK ((role IN ('team_lead','agent')) = (direction IS NOT NULL))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_team_lead_check CHECK ((role = 'agent') = (team_lead_id IS NOT NULL))");
    }

    public function down(): void
    {
        foreach (['users_role_check', 'users_direction_check', 'users_owner_company_check', 'users_team_direction_check', 'users_team_lead_check'] as $constraint) {
            DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_lead_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'direction', 'both_directions', 'is_active']);
        });
    }
};
```

- [ ] **Step 5: Модель Company и фабрика**

`app/Models/Company.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'archived_at'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::squish($value));
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<Company> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
```

`database/factories/CompanyFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
```

- [ ] **Step 6: UserPlacement**

`app/Org/UserPlacement.php`:

```php
<?php

namespace App\Org;

use App\Enums\Role;
use App\Models\User;
use InvalidArgumentException;

/**
 * Приводит место человека в структуре к его роли перед сохранением.
 * CHECK-ограничения в базе — последний рубеж; здесь — понятная ошибка и
 * очистка полей, которые новой роли не положены (смена роли в форме).
 */
final class UserPlacement
{
    public static function apply(User $user): void
    {
        $role = $user->role;
        if ($role === null) {
            return; // NOT NULL в базе даст свою ошибку
        }

        if ($role === Role::Owner) {
            $user->company_id = null;
        }

        if (! $role->hasTeam()) {
            $user->direction = null;
            $user->both_directions = false;
        }

        if ($role !== Role::Agent) {
            $user->team_lead_id = null;
        }

        if ($role === Role::Agent) {
            self::assertTeamLead($user);
        }
    }

    private static function assertTeamLead(User $user): void
    {
        $lead = $user->team_lead_id !== null ? User::query()->find($user->team_lead_id) : null;

        if ($lead === null
            || $lead->role !== Role::TeamLead
            || $lead->company_id !== $user->company_id
            || $lead->direction !== $user->direction) {
            throw new InvalidArgumentException('Тимлид сотрудника должен быть тимлидом той же компании и команды.');
        }
    }
}
```

- [ ] **Step 7: Модель User**

Заменить содержимое `app/Models/User.php` (сохранив `canAccessPanel` — его перепишет Task 6):

```php
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

    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::lower(trim($value)));
    }

    protected function name(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::squish($value));
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
```

- [ ] **Step 8: UserFactory**

В `database/factories/UserFactory.php` добавить в `definition()` ключи и состояния (импорты `App\Enums\Direction`, `App\Enums\Role`, `App\Models\Company`, `App\Models\User`):

```php
            // в definition(), рядом с остальными ключами:
            'role' => Role::Owner,
            'is_active' => true,
```

```php
    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => Role::Owner, 'company_id' => null]);
    }

    public function companyOwner(?Company $company = null): static
    {
        return $this->state(fn (): array => [
            'role' => Role::CompanyOwner,
            'company_id' => $company?->id ?? Company::factory(),
        ]);
    }

    public function teamLead(?Company $company = null, Direction $direction = Direction::Sale): static
    {
        return $this->state(fn (): array => [
            'role' => Role::TeamLead,
            'company_id' => $company?->id ?? Company::factory(),
            'direction' => $direction,
        ]);
    }

    public function agent(User $lead): static
    {
        return $this->state(fn (): array => [
            'role' => Role::Agent,
            'company_id' => $lead->company_id,
            'direction' => $lead->direction,
            'team_lead_id' => $lead->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
```

- [ ] **Step 9: Миграции и тесты**

Run: `docker compose exec app php artisan migrate` — Expected: две миграции DONE.
Run: `docker compose run --rm test php artisan test --filter=UserPlacementTest`
Expected: PASS (11 tests).
Run: `docker compose run --rm test composer test` — Expected: весь сьют зелёный (`AdminPanelAccessTest` использует фабрику по умолчанию — владельца).

- [ ] **Step 10: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Enums app/Org app/Models database tests/Feature/Org
git commit -m "feat(org): roles, directions, companies and user placement with DB checks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 2: Телефоны: нормализация и номера компании

**Files:**
- Create: `app/Support/PhoneNumber.php`, `app/Support/InvalidPhoneNumber.php`
- Create: `app/Enums/PhoneKind.php`
- Create: `database/migrations/2026_10_09_100200_create_phones_table.php`
- Create: `app/Models/Phone.php`, `database/factories/PhoneFactory.php`
- Modify: `app/Models/Company.php` (связь `phones()`), `app/Models/User.php` (связь `phones()`), `app/Org/UserPlacement.php` (снятие номеров при смене компании)
- Test: `tests/Unit/PhoneNumberTest.php`, `tests/Feature/Org/PhoneTest.php`

**Interfaces:**
- Consumes: `Company`, `User`, `Role`, `UserPlacement` (Task 1).
- Produces: `PhoneNumber::normalize(string): string` (бросает `InvalidPhoneNumber`), `PhoneNumber::tryNormalize(?string): ?string`; `PhoneKind` (`Personal|Office`); `Phone` (поля `number`, `kind`, `company_id`, `user_id`; связи `company()`, `holder()`); `Company::phones()`, `User::phones()`; `PhoneFactory` (состояния `office()`, `heldBy(User)`).

- [ ] **Step 1: Зависимости**

```bash
docker compose exec app composer require ysfkaya/filament-phone-input:^4.2 giggsey/libphonenumber-for-php-lite:^9.0 --no-interaction
```

Expected: оба пакета установлены (`libphonenumber-lite` и так приходит через `propaganistas/laravel-phone`; прямой require — потому что `PhoneNumber` зовёт его напрямую).

- [ ] **Step 2: Падающие тесты**

`tests/Unit/PhoneNumberTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\InvalidPhoneNumber;
use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function sameNumberWrittenDifferently(): array
    {
        return [
            'национальный с пробелами' => ['555 12 34 56'],
            'международный с плюсом' => ['+995 555 123 456'],
            'с кодом без плюса' => ['995555123456'],
            'с ведущим нулём' => ['0555123456'],
            'со скобками и дефисами' => ['(555) 12-34-56'],
        ];
    }

    #[DataProvider('sameNumberWrittenDifferently')]
    public function test_any_georgian_writing_gives_one_e164(string $raw): void
    {
        $this->assertSame('+995555123456', PhoneNumber::normalize($raw));
    }

    public function test_tbilisi_landline_is_accepted(): void
    {
        $this->assertSame('+995322123456', PhoneNumber::normalize('32 2 12 34 56'));
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return [
            'российский' => ['+7 916 123 45 67'],
            'короткий' => ['123'],
            'обрезанный' => ['555 12 34 5'],
            'мусор' => ['позвоните мне'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_non_georgian_or_invalid_is_rejected(string $raw): void
    {
        $this->expectException(InvalidPhoneNumber::class);
        PhoneNumber::normalize($raw);
    }

    public function test_try_normalize_returns_null_instead_of_throwing(): void
    {
        $this->assertNull(PhoneNumber::tryNormalize('мусор'));
        $this->assertNull(PhoneNumber::tryNormalize(null));
        $this->assertNull(PhoneNumber::tryNormalize('   '));
        $this->assertSame('+995555123456', PhoneNumber::tryNormalize('555123456'));
    }
}
```

`tests/Feature/Org/PhoneTest.php`:

```php
<?php

namespace Tests\Feature\Org;

use App\Models\Company;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_number_is_normalized_on_save(): void
    {
        $phone = Phone::factory()->create(['number' => '555 12 34 56']);

        $this->assertSame('+995555123456', $phone->refresh()->number);
    }

    public function test_same_number_in_other_writing_is_a_duplicate(): void
    {
        Phone::factory()->create(['number' => '+995555123456']);

        $this->expectException(QueryException::class);
        Phone::factory()->create(['number' => '0555 12 34 56']);
    }

    public function test_holder_must_be_from_the_same_company(): void
    {
        $lead = User::factory()->teamLead()->create();

        $this->expectException(InvalidArgumentException::class);
        Phone::factory()->create(['company_id' => Company::factory(), 'user_id' => $lead->id]);
    }

    public function test_owner_cannot_hold_a_number(): void
    {
        $owner = User::factory()->owner()->create();

        $this->expectException(InvalidArgumentException::class);
        Phone::factory()->create(['user_id' => $owner->id]);
    }

    public function test_inactive_user_cannot_receive_a_number(): void
    {
        $lead = User::factory()->teamLead()->inactive()->create();

        $this->expectException(InvalidArgumentException::class);
        Phone::factory()->heldBy($lead)->create();
    }

    public function test_moving_user_to_another_company_releases_old_company_numbers(): void
    {
        $lead = User::factory()->teamLead()->create();
        $phone = Phone::factory()->heldBy($lead)->create();
        $other = Company::factory()->create();

        $lead->update(['company_id' => $other->id]);

        $this->assertNull($phone->refresh()->user_id);
        $this->assertNotSame($other->id, $phone->company_id);
    }
}
```

- [ ] **Step 3: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter="PhoneNumberTest|PhoneTest"`
Expected: FAIL — `Class "App\Support\PhoneNumber" not found`.

- [ ] **Step 4: Нормализатор**

`app/Support/InvalidPhoneNumber.php`:

```php
<?php

namespace App\Support;

use InvalidArgumentException;

final class InvalidPhoneNumber extends InvalidArgumentException
{
    public static function for(string $raw, ?\Throwable $previous = null): self
    {
        return new self("Не грузинский или неверный номер: «{$raw}»", 0, $previous);
    }
}
```

`app/Support/PhoneNumber.php`:

```php
<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Один вид для любого грузинского номера: E.164 (+995…).
 * Тем же правилом должен отдавать телефоны объявлений API Real Estate —
 * иначе номер сотрудника не совпадёт с номером на объявлении.
 */
final class PhoneNumber
{
    private const REGION = 'GE';

    public static function normalize(string $raw): string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($raw, self::REGION);
        } catch (NumberParseException $e) {
            throw InvalidPhoneNumber::for($raw, $e);
        }

        if (! $util->isValidNumber($number) || $util->getRegionCodeForNumber($number) !== self::REGION) {
            throw InvalidPhoneNumber::for($raw);
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }

    public static function tryNormalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            return self::normalize($raw);
        } catch (InvalidPhoneNumber) {
            return null;
        }
    }
}
```

- [ ] **Step 5: Enum, миграция, модель, фабрика**

`app/Enums/PhoneKind.php`:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Подпись номера; на привязку объявлений не влияет. */
enum PhoneKind: string implements HasLabel
{
    case Personal = 'personal';
    case Office = 'office';

    public function getLabel(): string
    {
        return match ($this) {
            self::Personal => 'Личный',
            self::Office => 'Офис',
        };
    }
}
```

`database/migrations/2026_10_09_100200_create_phones_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phones', function (Blueprint $table) {
            $table->id();
            // E.164: один номер — одна строка во всей системе.
            $table->string('number', 20)->unique();
            $table->string('kind');
            // Номер принадлежит компании; сотрудник — текущий держатель.
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE phones ADD CONSTRAINT phones_kind_check CHECK (kind IN ('personal','office'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('phones');
    }
};
```

`app/Models/Phone.php`:

```php
<?php

namespace App\Models;

use App\Enums\PhoneKind;
use App\Enums\Role;
use App\Support\PhoneNumber;
use Database\Factories\PhoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['number', 'kind', 'company_id', 'user_id'])]
class Phone extends Model
{
    /** @use HasFactory<PhoneFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Phone $phone): void {
            if ($phone->user_id === null) {
                return;
            }

            $holder = User::query()->find($phone->user_id);
            if ($holder === null
                || $holder->role === Role::Owner
                || ! $holder->is_active
                || $holder->company_id !== $phone->company_id) {
                throw new InvalidArgumentException('Держатель номера — активный сотрудник той же компании.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => PhoneKind::class,
            'company_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    protected function number(): Attribute
    {
        return Attribute::set(fn (string $value): string => PhoneNumber::normalize($value));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

`database/factories/PhoneFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\PhoneKind;
use App\Models\Company;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Phone> */
class PhoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numerify('+995555######'),
            'kind' => PhoneKind::Personal,
            'company_id' => Company::factory(),
            'user_id' => null,
        ];
    }

    public function office(): static
    {
        return $this->state(fn (): array => ['kind' => PhoneKind::Office]);
    }

    public function heldBy(User $user): static
    {
        return $this->state(fn (): array => ['company_id' => $user->company_id, 'user_id' => $user->id]);
    }
}
```

В `app/Models/Company.php` добавить:

```php
    /** @return HasMany<Phone, $this> */
    public function phones(): HasMany
    {
        return $this->hasMany(Phone::class);
    }
```

В `app/Models/User.php` добавить:

```php
    /** @return HasMany<Phone, $this> */
    public function phones(): HasMany
    {
        return $this->hasMany(Phone::class);
    }
```

- [ ] **Step 6: Снятие номеров при переводе в другую компанию**

В `app/Org/UserPlacement::apply()` перед `if ($role === Role::Agent)` добавить (импорт `App\Models\Phone`):

```php
        // Номер принадлежит компании: при переводе человека в другую компанию
        // номера прежней остаются за ней, без держателя.
        if ($user->exists && $user->isDirty('company_id')) {
            Phone::query()->where('user_id', $user->getKey())->update(['user_id' => null]);
        }
```

- [ ] **Step 7: Миграция и тесты**

Run: `docker compose exec app php artisan migrate`
Run: `docker compose run --rm test php artisan test --filter="PhoneNumberTest|PhoneTest|UserPlacementTest"`
Expected: PASS.

- [ ] **Step 8: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add composer.json composer.lock app database tests
git commit -m "feat(org): company phone numbers with Georgian E.164 normalization

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 3: Пары обмена между компаниями

**Files:**
- Create: `database/migrations/2026_10_09_100300_create_company_exchanges_table.php`
- Create: `app/Models/CompanyExchange.php`
- Test: `tests/Feature/Org/CompanyExchangeTest.php`

**Interfaces:**
- Consumes: `Company` (Task 1).
- Produces: `CompanyExchange` (поля `company_a_id`, `company_b_id`, `enabled`; связи `companyA()`, `companyB()`), `CompanyExchange::enabledBetween(int $x, int $y): bool`, `CompanyExchange::existsBetween(int $x, int $y, ?int $ignoreId = null): bool`.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Org/CompanyExchangeTest.php`:

```php
<?php

namespace Tests\Feature\Org;

use App\Models\Company;
use App\Models\CompanyExchange;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CompanyExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pair_is_stored_ordered(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->sortBy('id')->values()->all();

        $pair = CompanyExchange::create(['company_a_id' => $b->id, 'company_b_id' => $a->id, 'enabled' => true]);

        $this->assertSame($a->id, $pair->refresh()->company_a_id);
        $this->assertSame($b->id, $pair->company_b_id);
    }

    public function test_reversed_duplicate_is_rejected(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);

        $this->expectException(QueryException::class);
        CompanyExchange::create(['company_a_id' => $b->id, 'company_b_id' => $a->id, 'enabled' => false]);
    }

    public function test_company_cannot_pair_with_itself(): void
    {
        $a = Company::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $a->id, 'enabled' => true]);
    }

    public function test_enabled_between_works_in_both_orders_and_respects_flag(): void
    {
        [$a, $b, $c] = Company::factory()->count(3)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $c->id, 'enabled' => false]);

        $this->assertTrue(CompanyExchange::enabledBetween($a->id, $b->id));
        $this->assertTrue(CompanyExchange::enabledBetween($b->id, $a->id));
        $this->assertFalse(CompanyExchange::enabledBetween($a->id, $c->id));
        $this->assertFalse(CompanyExchange::enabledBetween($b->id, $c->id));
        $this->assertTrue(CompanyExchange::existsBetween($c->id, $a->id));
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter=CompanyExchangeTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Миграция и модель**

`database/migrations/2026_10_09_100300_create_company_exchanges_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_a_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('company_b_id')->constrained('companies')->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['company_a_id', 'company_b_id']);
        });

        // Пара хранится упорядоченно: A–B и B–A физически одна строка.
        DB::statement('ALTER TABLE company_exchanges ADD CONSTRAINT company_exchanges_order_check CHECK (company_a_id < company_b_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_exchanges');
    }
};
```

`app/Models/CompanyExchange.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/** Переключатель видимости сотрудников между двумя компаниями; действует в обе стороны. */
#[Fillable(['company_a_id', 'company_b_id', 'enabled'])]
class CompanyExchange extends Model
{
    protected static function booted(): void
    {
        static::saving(function (CompanyExchange $pair): void {
            if ($pair->company_a_id === $pair->company_b_id) {
                throw new InvalidArgumentException('Пара обмена — две разные компании.');
            }

            if ($pair->company_a_id > $pair->company_b_id) {
                [$pair->company_a_id, $pair->company_b_id] = [$pair->company_b_id, $pair->company_a_id];
            }
        });
    }

    protected function casts(): array
    {
        return [
            'company_a_id' => 'integer',
            'company_b_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function companyA(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_a_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function companyB(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_b_id');
    }

    public static function enabledBetween(int $x, int $y): bool
    {
        return static::query()
            ->where('company_a_id', min($x, $y))
            ->where('company_b_id', max($x, $y))
            ->where('enabled', true)
            ->exists();
    }

    public static function existsBetween(int $x, int $y, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('company_a_id', min($x, $y))
            ->where('company_b_id', max($x, $y))
            ->when($ignoreId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }
}
```

- [ ] **Step 4: Миграция и тесты**

Run: `docker compose exec app php artisan migrate`
Run: `docker compose run --rm test php artisan test --filter=CompanyExchangeTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Models/CompanyExchange.php database tests/Feature/Org/CompanyExchangeTest.php
git commit -m "feat(org): company exchange pairs stored as ordered unique pairs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 4: OrgAccess — направления и видимые люди

**Files:**
- Create: `app/Access/OrgAccess.php`
- Test: `tests/Feature/Access/OrgAccessScopeTest.php`

**Interfaces:**
- Consumes: `User`, `Role`, `Direction` (Task 1).
- Produces: `OrgAccess::directions(User $user): array<int, Direction>` (порядок `[Sale, Rent]` для обоих); `OrgAccess::visibleUsers(User $viewer): Builder<User>`.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Access/OrgAccessScopeTest.php`:

```php
<?php

namespace Tests\Feature\Access;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_directions_by_role(): void
    {
        $company = Company::factory()->create();
        $rentLead = User::factory()->teamLead($company, Direction::Rent)->create();

        $this->assertSame([Direction::Sale, Direction::Rent], OrgAccess::directions(User::factory()->owner()->create()));
        $this->assertSame([Direction::Sale, Direction::Rent], OrgAccess::directions(User::factory()->companyOwner($company)->create()));
        $this->assertSame([Direction::Rent], OrgAccess::directions($rentLead));
        $this->assertSame([Direction::Rent], OrgAccess::directions(User::factory()->agent($rentLead)->create()));
    }

    public function test_both_directions_flag_opens_second_direction(): void
    {
        $lead = User::factory()->teamLead()->create(['both_directions' => true]);
        $agent = User::factory()->agent($lead)->create(['both_directions' => true]);

        $this->assertSame([Direction::Sale, Direction::Rent], OrgAccess::directions($lead));
        $this->assertSame([Direction::Sale, Direction::Rent], OrgAccess::directions($agent));
    }

    public function test_visible_users_by_role(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $owner = User::factory()->owner()->create();
        $ownerA = User::factory()->companyOwner($a)->create();
        $leadA1 = User::factory()->teamLead($a)->create();
        $leadA2 = User::factory()->teamLead($a)->create();
        $agentA1 = User::factory()->agent($leadA1)->create();
        $agentA2 = User::factory()->agent($leadA2)->create();
        $leadB = User::factory()->teamLead($b)->create();

        $ids = fn (User $viewer): array => OrgAccess::visibleUsers($viewer)->orderBy('id')->pluck('id')->all();

        $this->assertSame(User::query()->orderBy('id')->pluck('id')->all(), $ids($owner));
        $this->assertSame([$ownerA->id, $leadA1->id, $leadA2->id, $agentA1->id, $agentA2->id], $ids($ownerA));
        $this->assertSame([$leadA1->id, $agentA1->id], $ids($leadA1));
        $this->assertSame([$agentA1->id], $ids($agentA1));
        $this->assertNotContains($leadB->id, $ids($ownerA));
    }

    public function test_both_directions_does_not_widen_people(): void
    {
        $lead = User::factory()->teamLead()->create(['both_directions' => true]);
        $agent = User::factory()->agent($lead)->create();
        $otherLead = User::factory()->teamLead($lead->company, Direction::Rent)->create();

        $this->assertSame([$lead->id, $agent->id], OrgAccess::visibleUsers($lead)->orderBy('id')->pluck('id')->all());
        $this->assertNotContains($otherLead->id, OrgAccess::visibleUsers($lead)->pluck('id')->all());
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter=OrgAccessScopeTest`
Expected: FAIL — `Class "App\Access\OrgAccess" not found`.

- [ ] **Step 3: Реализация**

`app/Access/OrgAccess.php`:

```php
<?php

namespace App\Access;

use App\Enums\Direction;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Единственное место правил видимости оргструктуры (docs/GSHome, спецификация
 * docs/superpowers/specs/2026-10-09-orgstructure-design.md). Политики, ресурсы
 * и будущие экраны объявлений только спрашивают его.
 */
final class OrgAccess
{
    /**
     * Направления объектов, которые видит человек. Передаются в запрос к API
     * Real Estate — чужое направление не приходит вовсе.
     *
     * @return array<int, Direction>
     */
    public static function directions(User $user): array
    {
        if (! $user->role->hasTeam() || $user->both_directions) {
            return Direction::cases();
        }

        return [$user->direction];
    }

    /**
     * Люди, которых человек видит на экранах команды и контроля.
     * «Оба направления» список людей не расширяет (ТЗ 5.6).
     *
     * @return Builder<User>
     */
    public static function visibleUsers(User $viewer): Builder
    {
        $query = User::query();

        return match ($viewer->role) {
            Role::Owner => $query,
            Role::CompanyOwner => $query->where('company_id', $viewer->company_id),
            Role::TeamLead => $query->where(fn (Builder $q) => $q
                ->whereKey($viewer->getKey())
                ->orWhere('team_lead_id', $viewer->getKey())),
            Role::Agent => $query->whereKey($viewer->getKey()),
        };
    }
}
```

- [ ] **Step 4: Тесты**

Run: `docker compose run --rm test php artisan test --filter=OrgAccessScopeTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Access tests/Feature/Access
git commit -m "feat(access): OrgAccess directions and visible users

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 5: OrgAccess — метка принадлежности объявления

**Files:**
- Create: `app/Enums/OwnershipLabel.php`, `app/Access/Ownership.php`
- Modify: `app/Access/OrgAccess.php`
- Test: `tests/Feature/Access/OwnershipTest.php`

**Interfaces:**
- Consumes: `PhoneNumber::tryNormalize` (Task 2), `Phone::company()`, `Phone::holder()` (Task 2), `CompanyExchange::enabledBetween` (Task 3).
- Produces: `OwnershipLabel` (`OurEmployee|LinkedCompany|GroupCompany|OtherCompany`); `Ownership` (readonly: `label`, `companyName`, `contactName`, `contactPhone`; `Ownership::other()`); `OrgAccess::ownership(User $viewer, ?string $rawPhone): Ownership`.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Access/OwnershipTest.php`:

```php
<?php

namespace Tests\Feature\Access;

use App\Access\OrgAccess;
use App\Access\Ownership;
use App\Enums\OwnershipLabel;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Каждая клетка таблицы из спецификации (раздел «Метка объявления»).
 * Компания A — своя для смотрящего, B — в паре с A с включённым обменом,
 * C — в паре с A с выключенным обменом.
 */
class OwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Company $c;

    private User $agentA;

    private User $leadA;

    private User $ownerA;

    private User $owner;

    private const PHONE_A = '+995555000101';

    private const PHONE_B = '+995555000202';

    private const PHONE_C = '+995555000303';

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Company::factory()->create(['name' => 'Компания A']);
        $this->b = Company::factory()->create(['name' => 'Компания B']);
        $this->c = Company::factory()->create(['name' => 'Компания C']);
        CompanyExchange::create(['company_a_id' => $this->a->id, 'company_b_id' => $this->b->id, 'enabled' => true]);
        CompanyExchange::create(['company_a_id' => $this->a->id, 'company_b_id' => $this->c->id, 'enabled' => false]);

        $this->owner = User::factory()->owner()->create();
        $this->ownerA = User::factory()->companyOwner($this->a)->create();
        $this->leadA = User::factory()->teamLead($this->a)->create(['name' => 'Лида A']);
        $this->agentA = User::factory()->agent($this->leadA)->create(['name' => 'Агент A']);
        $leadB = User::factory()->teamLead($this->b)->create(['name' => 'Лида B']);
        $leadC = User::factory()->teamLead($this->c)->create(['name' => 'Лида C']);

        Phone::factory()->heldBy($this->agentA)->create(['number' => self::PHONE_A]);
        Phone::factory()->heldBy($leadB)->create(['number' => self::PHONE_B]);
        Phone::factory()->heldBy($leadC)->create(['number' => self::PHONE_C]);
    }

    private function assertOwnership(Ownership $o, OwnershipLabel $label, ?string $company, ?string $name, ?string $phone): void
    {
        $this->assertSame($label, $o->label);
        $this->assertSame($company, $o->companyName);
        $this->assertSame($name, $o->contactName);
        $this->assertSame($phone, $o->contactPhone);
    }

    public function test_agent_and_team_lead_see_own_company_employee(): void
    {
        foreach ([$this->agentA, $this->leadA] as $viewer) {
            $this->assertOwnership(OrgAccess::ownership($viewer, self::PHONE_A), OwnershipLabel::OurEmployee, 'Компания A', 'Агент A', self::PHONE_A);
        }
    }

    public function test_agent_and_team_lead_see_linked_company_when_exchange_enabled(): void
    {
        foreach ([$this->agentA, $this->leadA] as $viewer) {
            $this->assertOwnership(OrgAccess::ownership($viewer, self::PHONE_B), OwnershipLabel::LinkedCompany, 'Компания B', 'Лида B', self::PHONE_B);
        }
    }

    public function test_agent_and_team_lead_see_other_company_when_exchange_disabled(): void
    {
        foreach ([$this->agentA, $this->leadA] as $viewer) {
            $this->assertOwnership(OrgAccess::ownership($viewer, self::PHONE_C), OwnershipLabel::OtherCompany, null, null, null);
        }
    }

    public function test_agent_sees_other_company_for_group_company_without_any_pair(): void
    {
        $d = Company::factory()->create();
        $leadD = User::factory()->teamLead($d)->create();
        Phone::factory()->heldBy($leadD)->create(['number' => '+995555000404']);

        $this->assertOwnership(OrgAccess::ownership($this->agentA, '+995555000404'), OwnershipLabel::OtherCompany, null, null, null);
    }

    public function test_company_owner_sees_any_group_company_regardless_of_exchange(): void
    {
        $this->assertOwnership(OrgAccess::ownership($this->ownerA, self::PHONE_A), OwnershipLabel::GroupCompany, 'Компания A', 'Агент A', self::PHONE_A);
        $this->assertOwnership(OrgAccess::ownership($this->ownerA, self::PHONE_B), OwnershipLabel::GroupCompany, 'Компания B', 'Лида B', self::PHONE_B);
        $this->assertOwnership(OrgAccess::ownership($this->ownerA, self::PHONE_C), OwnershipLabel::GroupCompany, 'Компания C', 'Лида C', self::PHONE_C);
    }

    public function test_owner_sees_every_group_company(): void
    {
        $this->assertOwnership(OrgAccess::ownership($this->owner, self::PHONE_C), OwnershipLabel::GroupCompany, 'Компания C', 'Лида C', self::PHONE_C);
    }

    public function test_unknown_number_is_other_company_for_everyone(): void
    {
        foreach ([$this->agentA, $this->leadA, $this->ownerA, $this->owner] as $viewer) {
            $this->assertOwnership(OrgAccess::ownership($viewer, '+995555999999'), OwnershipLabel::OtherCompany, null, null, null);
        }
    }

    public function test_number_in_other_writing_is_recognised(): void
    {
        $this->assertSame(OwnershipLabel::OurEmployee, OrgAccess::ownership($this->agentA, '555 00 01 01')->label);
    }

    public function test_garbage_or_missing_phone_is_other_company_without_exception(): void
    {
        $this->assertSame(OwnershipLabel::OtherCompany, OrgAccess::ownership($this->agentA, 'звоните')->label);
        $this->assertSame(OwnershipLabel::OtherCompany, OrgAccess::ownership($this->agentA, null)->label);
    }

    public function test_archived_company_is_not_ours(): void
    {
        $this->b->update(['archived_at' => now()]);

        $this->assertOwnership(OrgAccess::ownership($this->owner, self::PHONE_B), OwnershipLabel::OtherCompany, null, null, null);
        $this->assertOwnership(OrgAccess::ownership($this->agentA, self::PHONE_B), OwnershipLabel::OtherCompany, null, null, null);
    }

    public function test_number_without_holder_shows_company_and_number(): void
    {
        Phone::query()->where('number', self::PHONE_A)->update(['user_id' => null]);

        $this->assertOwnership(OrgAccess::ownership($this->leadA, self::PHONE_A), OwnershipLabel::OurEmployee, 'Компания A', null, self::PHONE_A);
    }

    public function test_inactive_holder_name_is_hidden(): void
    {
        User::query()->whereKey($this->agentA->id)->update(['is_active' => false]);

        $this->assertOwnership(OrgAccess::ownership($this->leadA, self::PHONE_A), OwnershipLabel::OurEmployee, 'Компания A', null, self::PHONE_A);
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter=OwnershipTest`
Expected: FAIL — нет `App\Access\Ownership`.

- [ ] **Step 3: Реализация**

`app/Enums/OwnershipLabel.php`:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OwnershipLabel: string implements HasLabel
{
    case OurEmployee = 'our_employee';
    case LinkedCompany = 'linked_company';
    case GroupCompany = 'group_company';
    case OtherCompany = 'other_company';

    public function getLabel(): string
    {
        return match ($this) {
            self::OurEmployee => 'Наш сотрудник',
            self::LinkedCompany => 'Сотрудник связанной компании',
            self::GroupCompany => 'Одна из наших компаний',
            self::OtherCompany => 'Другая компания',
        };
    }
}
```

`app/Access/Ownership.php`:

```php
<?php

namespace App\Access;

use App\Enums\OwnershipLabel;

/** Что смотрящий видит в блоке принадлежности объявления. Пусто — не положено. */
final readonly class Ownership
{
    public function __construct(
        public OwnershipLabel $label,
        public ?string $companyName = null,
        public ?string $contactName = null,
        public ?string $contactPhone = null,
    ) {}

    public static function other(): self
    {
        return new self(OwnershipLabel::OtherCompany);
    }
}
```

В `app/Access/OrgAccess.php` добавить импорты `App\Enums\OwnershipLabel`, `App\Models\CompanyExchange`, `App\Models\Phone`, `App\Support\PhoneNumber` и методы:

```php
    /**
     * Метка принадлежности объявления по телефону с него (ТЗ 5.7).
     * Номер не наш, мусор или компания в архиве — «другая компания».
     */
    public static function ownership(User $viewer, ?string $rawPhone): Ownership
    {
        $number = PhoneNumber::tryNormalize($rawPhone);
        if ($number === null) {
            return Ownership::other();
        }

        $phone = Phone::query()->with(['company', 'holder'])->where('number', $number)->first();
        if ($phone === null || $phone->company->isArchived()) {
            return Ownership::other();
        }

        $label = self::labelFor($viewer, $phone->company_id);
        if ($label === OwnershipLabel::OtherCompany) {
            return Ownership::other();
        }

        // Уволенный или не назначенный держатель — имени нет; контакт — сам
        // номер: SIM осталась в компании, по нему ответят.
        $holder = $phone->holder;
        $contactName = $holder !== null && $holder->is_active ? $holder->name : null;

        return new Ownership($label, $phone->company->name, $contactName, $phone->number);
    }

    private static function labelFor(User $viewer, int $companyId): OwnershipLabel
    {
        return match ($viewer->role) {
            Role::Owner, Role::CompanyOwner => OwnershipLabel::GroupCompany,
            Role::TeamLead, Role::Agent => match (true) {
                $companyId === $viewer->company_id => OwnershipLabel::OurEmployee,
                CompanyExchange::enabledBetween((int) $viewer->company_id, $companyId) => OwnershipLabel::LinkedCompany,
                default => OwnershipLabel::OtherCompany,
            },
        };
    }
```

- [ ] **Step 4: Тесты**

Run: `docker compose run --rm test php artisan test --filter=OwnershipTest`
Expected: PASS (12 tests).

- [ ] **Step 5: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Access app/Enums/OwnershipLabel.php tests/Feature/Access/OwnershipTest.php
git commit -m "feat(access): listing ownership label by phone per spec table

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 6: Вход в панель и политики

**Files:**
- Modify: `app/Models/User.php` (`canAccessPanel`)
- Create: `app/Policies/CompanyPolicy.php`, `app/Policies/UserPolicy.php`, `app/Policies/PhonePolicy.php`, `app/Policies/CompanyExchangePolicy.php`
- Test: `tests/Feature/Access/PanelAccessTest.php`, `tests/Feature/Access/OrgPoliciesTest.php`

**Interfaces:**
- Consumes: модели и роли (Tasks 1–3).
- Produces: политики с методами `viewAny`, `view`, `create`, `update`, `delete`, `deleteAny` (Laravel находит их по имени `App\Policies\{Model}Policy`); `User::canAccessPanel()` — активен и (владелец или компания не в архиве).

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Access/PanelAccessTest.php`:

```php
<?php

namespace Tests\Feature\Access;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_users_of_every_role_enter(): void
    {
        $lead = User::factory()->teamLead()->create();
        foreach ([User::factory()->owner()->create(), User::factory()->companyOwner()->create(), $lead, User::factory()->agent($lead)->create()] as $user) {
            $this->actingAs($user)->get('/admin')->assertOk();
        }
    }

    public function test_inactive_user_is_refused(): void
    {
        $this->actingAs(User::factory()->teamLead()->inactive()->create())->get('/admin')->assertForbidden();
    }

    public function test_employee_of_archived_company_is_refused(): void
    {
        $lead = User::factory()->teamLead(Company::factory()->archived()->create())->create();

        $this->actingAs($lead)->get('/admin')->assertForbidden();
    }
}
```

`tests/Feature/Access/OrgPoliciesTest.php`:

```php
<?php

namespace Tests\Feature\Access;

use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_manages_everything_but_never_deletes_companies_or_users(): void
    {
        $owner = User::factory()->owner()->create();
        $company = Company::factory()->create();
        $lead = User::factory()->teamLead($company)->create();
        $phone = Phone::factory()->heldBy($lead)->create();
        $pair = CompanyExchange::create(['company_a_id' => $company->id, 'company_b_id' => Company::factory()->create()->id, 'enabled' => true]);

        foreach ([[Company::class, $company], [User::class, $lead], [Phone::class, $phone], [CompanyExchange::class, $pair]] as [$class, $record]) {
            $this->assertTrue($owner->can('viewAny', $class));
            $this->assertTrue($owner->can('view', $record));
            $this->assertTrue($owner->can('create', $class));
            $this->assertTrue($owner->can('update', $record));
        }

        $this->assertFalse($owner->can('delete', $company));
        $this->assertFalse($owner->can('delete', $lead));
        $this->assertTrue($owner->can('delete', $pair));
    }

    public function test_company_owner_reads_only_own_company(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $ownerA = User::factory()->companyOwner($a)->create();
        $leadA = User::factory()->teamLead($a)->create();
        $leadB = User::factory()->teamLead($b)->create();
        $phoneA = Phone::factory()->heldBy($leadA)->create();
        $phoneB = Phone::factory()->heldBy($leadB)->create();

        $this->assertTrue($ownerA->can('viewAny', Company::class));
        $this->assertTrue($ownerA->can('view', $a));
        $this->assertFalse($ownerA->can('view', $b));
        $this->assertTrue($ownerA->can('view', $leadA));
        $this->assertFalse($ownerA->can('view', $leadB));
        $this->assertTrue($ownerA->can('view', $phoneA));
        $this->assertFalse($ownerA->can('view', $phoneB));

        $this->assertFalse($ownerA->can('create', User::class));
        $this->assertFalse($ownerA->can('update', $a));
        $this->assertFalse($ownerA->can('update', $leadA));
        $this->assertFalse($ownerA->can('viewAny', CompanyExchange::class));
    }

    public function test_team_lead_and_agent_see_no_org_screens(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();

        foreach ([$lead, $agent] as $user) {
            foreach ([Company::class, User::class, Phone::class, CompanyExchange::class] as $class) {
                $this->assertFalse($user->can('viewAny', $class), $class);
            }
        }
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter="PanelAccessTest|OrgPoliciesTest"`
Expected: FAIL — выключенный входит (200 вместо 403); политик нет (`can` даёт false у владельца).

- [ ] **Step 3: Вход в панель**

В `app/Models/User.php` заменить `canAccessPanel` и его комментарий:

```php
    /**
     * Выключенный (уволенный) не входит. Сотрудники архивной компании — тоже:
     * компания вне группы, её люди в CRM не работают. Регистрации нет —
     * аккаунты заводит владелец.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->role === Role::Owner || ! ($this->company?->isArchived() ?? true);
    }
```

- [ ] **Step 4: Политики**

`app/Policies/CompanyPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;

/** Компании: управляет владелец; владелец компании читает свою. Удалять нельзя — только архив. */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::CompanyOwner], true);
    }

    public function view(User $user, Company $company): bool
    {
        return $user->role === Role::Owner
            || ($user->role === Role::CompanyOwner && $user->company_id === $company->id);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, Company $company): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, Company $company): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
```

`app/Policies/UserPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/** Люди: управляет владелец; владелец компании читает свою. Удалять нельзя — только увольнение. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::CompanyOwner], true);
    }

    public function view(User $user, User $model): bool
    {
        return $user->role === Role::Owner
            || ($user->role === Role::CompanyOwner && $user->company_id === $model->company_id);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, User $model): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
```

`app/Policies/PhonePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Phone;
use App\Models\User;

class PhonePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::CompanyOwner], true);
    }

    public function view(User $user, Phone $phone): bool
    {
        return $user->role === Role::Owner
            || ($user->role === Role::CompanyOwner && $user->company_id === $phone->company_id);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, Phone $phone): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, Phone $phone): bool
    {
        return $user->role === Role::Owner;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
```

`app/Policies/CompanyExchangePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CompanyExchange;
use App\Models\User;

/** Пары обмена — только владелец группы. */
class CompanyExchangePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function view(User $user, CompanyExchange $pair): bool
    {
        return $user->role === Role::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Owner;
    }

    public function update(User $user, CompanyExchange $pair): bool
    {
        return $user->role === Role::Owner;
    }

    public function delete(User $user, CompanyExchange $pair): bool
    {
        return $user->role === Role::Owner;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
```

- [ ] **Step 5: Тесты**

Run: `docker compose run --rm test php artisan test --filter="PanelAccessTest|OrgPoliciesTest|AdminPanelAccessTest"`
Expected: PASS.

- [ ] **Step 6: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Models/User.php app/Policies tests/Feature/Access
git commit -m "feat(access): panel entry for active users only, org policies

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 7: Экран «Компании»

**Files:**
- Create: `app/Filament/Resources/Companies/CompanyResource.php`
- Create: `app/Filament/Resources/Companies/Pages/ListCompanies.php`, `CreateCompany.php`, `EditCompany.php`, `ViewCompany.php`
- Create: `app/Filament/Resources/Companies/RelationManagers/UsersRelationManager.php`, `PhonesRelationManager.php`
- Modify: `.env.example` (`APP_LOCALE=ru`, `APP_FALLBACK_LOCALE=ru`)
- Test: `tests/Feature/Filament/CompanyResourceTest.php`

**Interfaces:**
- Consumes: `Company`, `CompanyPolicy`, `Role` (Tasks 1, 6).
- Produces: группа навигации `'Оргструктура'`; маршрут `/admin/companies`; `CompanyResource::getEloquentQuery()` — владельцу все, владельцу компании — своя.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Filament/CompanyResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_all_companies(): void
    {
        $companies = Company::factory()->count(2)->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListCompanies::class)->assertCanSeeTableRecords($companies);
    }

    public function test_company_owner_sees_only_own_company(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $this->actingAs(User::factory()->companyOwner($a)->create());

        Livewire::test(ListCompanies::class)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);
    }

    public function test_agent_has_no_access(): void
    {
        $lead = User::factory()->teamLead()->create();

        $this->actingAs(User::factory()->agent($lead)->create())->get('/admin/companies')->assertForbidden();
    }

    public function test_owner_creates_company_and_name_is_unique(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'GS Home Vake'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('companies', ['name' => 'GS Home Vake']);

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'GS Home Vake'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_owner_archives_and_restores_company(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListCompanies::class)->callAction(TestAction::make('archive')->table($company));
        $this->assertTrue($company->refresh()->isArchived());

        Livewire::test(ListCompanies::class)->callAction(TestAction::make('restore')->table($company));
        $this->assertFalse($company->refresh()->isArchived());
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter=CompanyResourceTest`
Expected: FAIL — класс страницы не найден.

- [ ] **Step 3: Ресурс**

`app/Filament/Resources/Companies/CompanyResource.php`:

```php
<?php

namespace App\Filament\Resources\Companies;

use App\Enums\Role;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Companies\RelationManagers\PhonesRelationManager;
use App\Filament\Resources\Companies\RelationManagers\UsersRelationManager;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Оргструктура';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'компания';

    protected static ?string $pluralModelLabel = 'компании';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Название')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('users_count')->label('Людей')->counts('users'),
                TextColumn::make('phones_count')->label('Номеров')->counts('phones'),
                TextColumn::make('archived_at')->label('В архиве с')->date()->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('archived_at')->label('Архив')->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('archive')
                    ->label('В архив')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Объявления сотрудников компании станут «другой компанией», её люди не смогут войти.')
                    ->visible(fn (Company $record): bool => ! $record->isArchived() && (Auth::user()?->can('update', $record) ?? false))
                    ->action(fn (Company $record) => $record->update(['archived_at' => now()])),
                Action::make('restore')
                    ->label('Вернуть из архива')
                    ->visible(fn (Company $record): bool => $record->isArchived() && (Auth::user()?->can('update', $record) ?? false))
                    ->action(fn (Company $record) => $record->update(['archived_at' => null])),
            ]);
    }

    /** Владельцу — все компании, владельцу компании — своя. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        return $user instanceof User && $user->role === Role::Owner
            ? $query
            : $query->whereKey($user?->company_id);
    }

    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
            PhonesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
```

Страницы (`app/Filament/Resources/Companies/Pages/`):

```php
<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

```php
<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;
}
```

```php
<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;
}
```

```php
<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
```

`app/Filament/Resources/Companies/RelationManagers/UsersRelationManager.php`:

```php
<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Люди компании — только список; правка в разделе «Люди». */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Люди';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Имя')->searchable(),
                TextColumn::make('role')->label('Роль')->badge(),
                TextColumn::make('direction')->label('Команда')->placeholder('—'),
                TextColumn::make('teamLead.name')->label('Тимлид')->placeholder('—'),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ]);
    }
}
```

`app/Filament/Resources/Companies/RelationManagers/PhonesRelationManager.php`:

```php
<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Номера компании — только список; правка в разделе «Номера». */
class PhonesRelationManager extends RelationManager
{
    protected static string $relationship = 'phones';

    protected static ?string $title = 'Номера';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')->label('Номер'),
                TextColumn::make('kind')->label('Вид')->badge(),
                TextColumn::make('holder.name')->label('Держатель')->placeholder('без держателя'),
            ]);
    }
}
```

- [ ] **Step 4: Язык интерфейса**

В `.env.example` и локальном `.env` заменить `APP_LOCALE=en` и `APP_FALLBACK_LOCALE=en` на `ru` — встроенные подписи Filament тогда тоже по-русски. Затем `docker compose exec app php artisan config:clear`.

- [ ] **Step 5: Тесты**

Run: `docker compose run --rm test php artisan test --filter=CompanyResourceTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Filament .env.example tests/Feature/Filament
git commit -m "feat(filament): companies screen with archive and read-only company owner view

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 8: Экран «Люди»

**Files:**
- Create: `app/Rules/UniqueEmail.php`
- Create: `app/Filament/Resources/Users/UserResource.php`, `app/Filament/Resources/Users/Schemas/UserForm.php`, `app/Filament/Resources/Users/Tables/UsersTable.php`
- Create: `app/Filament/Resources/Users/Pages/ListUsers.php`, `CreateUser.php`, `EditUser.php`, `ViewUser.php`
- Test: `tests/Feature/Filament/UserResourceTest.php`

**Interfaces:**
- Consumes: `OrgAccess::visibleUsers` (Task 4), `UserPolicy` (Task 6), `Role`, `Direction`.
- Produces: маршрут `/admin/users`; `UsersTable::configure(Table): Table` — Task 9 добавит туда действия `fire` и `rehire`; `UniqueEmail(?int $ignoreId)`.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Filament/UserResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->create();
    }

    public function test_owner_creates_agent_under_team_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Нино',
                'email' => 'nino@example.ge',
                'password' => 'secret-pass',
                'role' => Role::Agent->value,
                'company_id' => $lead->company_id,
                'direction' => Direction::Sale->value,
                'team_lead_id' => $lead->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $agent = User::query()->where('email', 'nino@example.ge')->firstOrFail();
        $this->assertSame($lead->id, $agent->team_lead_id);
        $this->assertSame(Role::Agent, $agent->role);
    }

    public function test_team_lead_from_other_direction_is_not_an_allowed_option(): void
    {
        $rentLead = User::factory()->teamLead(direction: Direction::Rent)->create();
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Нино',
                'email' => 'nino@example.ge',
                'password' => 'secret-pass',
                'role' => Role::Agent->value,
                'company_id' => $rentLead->company_id,
                'direction' => Direction::Sale->value,
                'team_lead_id' => $rentLead->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['team_lead_id']);
    }

    public function test_existing_email_in_other_case_is_a_form_error_not_a_crash(): void
    {
        User::factory()->companyOwner()->create(['email' => 'giorgi@example.ge']);
        $company = Company::factory()->create();
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Гиорги',
                'email' => 'Giorgi@Example.GE',
                'password' => 'secret-pass',
                'role' => Role::CompanyOwner->value,
                'company_id' => $company->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_changing_role_on_edit_clears_team_fields(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create(['both_directions' => true]);
        $this->actingAs($this->owner);

        Livewire::test(EditUser::class, ['record' => $agent->getRouteKey()])
            ->fillForm(['role' => Role::CompanyOwner->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $agent->refresh();
        $this->assertSame(Role::CompanyOwner, $agent->role);
        $this->assertNull($agent->direction);
        $this->assertNull($agent->team_lead_id);
        $this->assertFalse($agent->both_directions);
    }

    public function test_password_is_kept_when_left_empty_on_edit(): void
    {
        $user = User::factory()->companyOwner()->create();
        $hash = $user->password;
        $this->actingAs($this->owner);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($hash, $user->refresh()->password);
    }

    public function test_company_owner_lists_only_own_company_people(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $ownerA = User::factory()->companyOwner($a)->create();
        $leadA = User::factory()->teamLead($a)->create();
        $leadB = User::factory()->teamLead($b)->create();
        $this->actingAs($ownerA);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$ownerA, $leadA])
            ->assertCanNotSeeTableRecords([$leadB, $this->owner]);
    }

    public function test_company_owner_cannot_open_edit(): void
    {
        $company = Company::factory()->create();
        $ownerA = User::factory()->companyOwner($company)->create();
        $lead = User::factory()->teamLead($company)->create();

        $this->actingAs($ownerA)->get("/admin/users/{$lead->id}/edit")->assertForbidden();
        $this->actingAs($ownerA)->get("/admin/users/{$lead->id}")->assertOk();
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter=UserResourceTest`
Expected: FAIL — нет `App\Filament\Resources\Users\Pages\CreateUser`.

- [ ] **Step 3: Правило уникального email**

`app/Rules/UniqueEmail.php`:

```php
<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Уникальность email с учётом нормализации модели (нижний регистр).
 * Обычный unique сравнил бы «Giorgi@…» с «giorgi@…» как разные, и вместо
 * ошибки формы был бы 500 от уникального индекса.
 */
final class UniqueEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = Str::lower(trim((string) $value));

        $taken = User::query()
            ->where('email', $email)
            ->when($this->ignoreId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail('Человек с таким email уже есть.');
        }
    }
}
```

- [ ] **Step 4: Форма**

`app/Filament/Resources/Users/Schemas/UserForm.php`:

```php
<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Direction;
use App\Enums\Role;
use App\Models\User;
use App\Rules\UniqueEmail;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Место человека в структуре. Поля, которые роли не положены, скрыты; при
 * сохранении их очищает UserPlacement. «Активен» здесь нет — только через
 * действия «Уволить» / «Вернуть» (Task 9), чтобы номера и сотрудники
 * тимлида не повисли.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Имя')->required()->maxLength(255),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->rule(fn (?User $record) => new UniqueEmail($record?->getKey())),
            TextInput::make('password')
                ->label('Пароль')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->hiddenOn('view'),
            Select::make('role')
                ->label('Роль')
                ->options(Role::class)
                ->required()
                ->live(),
            Select::make('company_id')
                ->label('Компания')
                ->relationship('company', 'name', fn (Builder $query) => $query->whereNull('archived_at'))
                ->visible(fn (Get $get): bool => self::role($get) !== null && self::role($get) !== Role::Owner)
                ->required(fn (Get $get): bool => self::role($get) !== null && self::role($get) !== Role::Owner)
                ->live(),
            Select::make('direction')
                ->label('Команда')
                ->options(Direction::class)
                ->visible(fn (Get $get): bool => self::role($get)?->hasTeam() ?? false)
                ->required(fn (Get $get): bool => self::role($get)?->hasTeam() ?? false)
                ->live(),
            Select::make('team_lead_id')
                ->label('Тимлид')
                ->options(fn (Get $get) => User::query()
                    ->where('role', Role::TeamLead)
                    ->where('is_active', true)
                    ->where('company_id', $get('company_id'))
                    ->where('direction', self::direction($get))
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->visible(fn (Get $get): bool => self::role($get) === Role::Agent)
                ->required(fn (Get $get): bool => self::role($get) === Role::Agent),
            Toggle::make('both_directions')
                ->label('Оба направления')
                ->helperText('Видит и продажу, и помесячную сдачу. Людей другой команды не открывает.')
                ->visible(fn (Get $get): bool => self::role($get)?->hasTeam() ?? false),
        ]);
    }

    /** Состояние Select бывает enum (из модели) или строкой (из формы). */
    private static function role(Get $get): ?Role
    {
        $value = $get('role');

        return $value instanceof Role ? $value : Role::tryFrom((string) $value);
    }

    private static function direction(Get $get): ?Direction
    {
        $value = $get('direction');

        return $value instanceof Direction ? $value : Direction::tryFrom((string) $value);
    }
}
```

- [ ] **Step 5: Таблица**

`app/Filament/Resources/Users/Tables/UsersTable.php`:

```php
<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Имя')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('role')->label('Роль')->badge(),
                TextColumn::make('company.name')->label('Компания')->placeholder('—')->sortable(),
                TextColumn::make('direction')->label('Команда')->placeholder('—'),
                TextColumn::make('teamLead.name')->label('Тимлид')->placeholder('—'),
                IconColumn::make('both_directions')->label('Оба направления')->boolean(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Роль')->options(Role::class),
                SelectFilter::make('company_id')->label('Компания')->relationship('company', 'name'),
                TernaryFilter::make('is_active')->label('Активен'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
```

- [ ] **Step 6: Ресурс и страницы**

`app/Filament/Resources/Users/UserResource.php`:

```php
<?php

namespace App\Filament\Resources\Users;

use App\Access\OrgAccess;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Оргструктура';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'человек';

    protected static ?string $pluralModelLabel = 'люди';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /** Кого видит смотрящий — решает OrgAccess. */
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        return $user instanceof User
            ? OrgAccess::visibleUsers($user)
            : parent::getEloquentQuery()->whereRaw('false');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
```

Страницы (`app/Filament/Resources/Users/Pages/`):

```php
<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

```php
<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
```

```php
<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;
}
```

```php
<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
```

- [ ] **Step 7: Тесты**

Run: `docker compose run --rm test php artisan test --filter=UserResourceTest`
Expected: PASS (7 tests).

Если `test_team_lead_from_other_direction_is_not_an_allowed_option` проходит без ошибки формы — у Select с `options(closure)` не сработала встроенная проверка «значение из списка». Тогда добавить к `team_lead_id` в `UserForm` и прогнать снова:

```php
                ->in(fn (Get $get): array => User::query()
                    ->where('role', Role::TeamLead)
                    ->where('is_active', true)
                    ->where('company_id', $get('company_id'))
                    ->where('direction', self::direction($get))
                    ->pluck('id')
                    ->all())
```

- [ ] **Step 8: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Rules app/Filament/Resources/Users tests/Feature/Filament/UserResourceTest.php
git commit -m "feat(filament): people screen with role-driven placement form

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 9: Увольнение и возврат

**Files:**
- Create: `app/Org/FireUser.php`
- Modify: `app/Filament/Resources/Users/Tables/UsersTable.php` (действия `fire`, `rehire`)
- Test: `tests/Feature/Org/FireUserTest.php`

**Interfaces:**
- Consumes: `User::phones()`, `User::agents()` (Tasks 1–2), `UsersTable` (Task 8).
- Produces: `FireUser::handle(User $user, ?int $newPhoneHolderId, ?int $newTeamLeadId): void` (бросает `InvalidArgumentException`); `FireUser::phoneHolderOptions(User): array<int,string>`; `FireUser::teamLeadOptions(User): array<int,string>`.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Org/FireUserTest.php`:

```php
<?php

namespace Tests\Feature\Org;

use App\Enums\Direction;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Phone;
use App\Models\User;
use App\Org\FireUser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class FireUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_firing_agent_passes_numbers_to_colleague(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $colleague = User::factory()->agent($lead)->create();
        $phone = Phone::factory()->heldBy($agent)->create();

        FireUser::handle($agent, $colleague->id, null);

        $this->assertFalse($agent->refresh()->is_active);
        $this->assertSame($colleague->id, $phone->refresh()->user_id);
    }

    public function test_firing_without_new_holder_leaves_numbers_with_company(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $phone = Phone::factory()->heldBy($agent)->create();

        FireUser::handle($agent, null, null);

        $this->assertNull($phone->refresh()->user_id);
        $this->assertSame($agent->company_id, $phone->company_id);
    }

    public function test_team_lead_with_agents_needs_a_new_team_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        User::factory()->agent($lead)->create();

        $this->expectException(InvalidArgumentException::class);
        FireUser::handle($lead, null, null);
    }

    public function test_firing_team_lead_moves_agents_to_new_team_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agents = User::factory()->count(2)->agent($lead)->create();
        $newLead = User::factory()->teamLead($lead->company)->create();

        FireUser::handle($lead, null, $newLead->id);

        foreach ($agents as $agent) {
            $this->assertSame($newLead->id, $agent->refresh()->team_lead_id);
        }
        $this->assertFalse($lead->refresh()->is_active);
    }

    public function test_new_team_lead_must_be_from_same_company_and_team(): void
    {
        $lead = User::factory()->teamLead()->create();
        User::factory()->agent($lead)->create();
        $rentLead = User::factory()->teamLead($lead->company, Direction::Rent)->create();

        $this->expectException(InvalidArgumentException::class);
        FireUser::handle($lead, null, $rentLead->id);
    }

    public function test_options_exclude_the_fired_person(): void
    {
        $lead = User::factory()->teamLead()->create();
        $other = User::factory()->teamLead($lead->company)->create();

        $this->assertArrayNotHasKey($lead->id, FireUser::teamLeadOptions($lead));
        $this->assertArrayHasKey($other->id, FireUser::teamLeadOptions($lead));
        $this->assertArrayNotHasKey($lead->id, FireUser::phoneHolderOptions($lead));
    }

    public function test_owner_fires_and_rehires_from_the_table(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListUsers::class)->callAction(TestAction::make('fire')->table($agent));
        $this->assertFalse($agent->refresh()->is_active);

        Livewire::test(ListUsers::class)->callAction(TestAction::make('rehire')->table($agent));
        $this->assertTrue($agent->refresh()->is_active);
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter=FireUserTest`
Expected: FAIL — нет `App\Org\FireUser`.

- [ ] **Step 3: Сервис увольнения**

`app/Org/FireUser.php`:

```php
<?php

namespace App\Org;

use App\Enums\Role;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Увольнение: человек выключается, не удаляется (встречи и история за ним).
 * Номера компании передаются коллеге или остаются без держателя; сотрудники
 * тимлида обязаны перейти к другому тимлиду — у сотрудника тимлид обязателен.
 */
final class FireUser
{
    public static function handle(User $user, ?int $newPhoneHolderId, ?int $newTeamLeadId): void
    {
        DB::transaction(function () use ($user, $newPhoneHolderId, $newTeamLeadId): void {
            if ($user->agents()->exists()) {
                $newLead = $newTeamLeadId !== null ? User::query()->find($newTeamLeadId) : null;
                if ($newLead === null || ! array_key_exists($newLead->id, self::teamLeadOptions($user))) {
                    throw new InvalidArgumentException('Сотрудникам тимлида нужен новый тимлид той же компании и команды.');
                }
                // Мимо модели: каждому сотруднику меняется только тимлид той же
                // компании и команды — инварианты UserPlacement не нарушаются.
                User::query()->where('team_lead_id', $user->id)->update(['team_lead_id' => $newLead->id]);
            }

            if ($newPhoneHolderId !== null && ! array_key_exists($newPhoneHolderId, self::phoneHolderOptions($user))) {
                throw new InvalidArgumentException('Номера можно передать только активному сотруднику той же компании.');
            }
            Phone::query()->where('user_id', $user->id)->update(['user_id' => $newPhoneHolderId]);

            $user->update(['is_active' => false]);
        });
    }

    /** @return array<int, string> активные люди той же компании, кроме увольняемого и владельца */
    public static function phoneHolderOptions(User $user): array
    {
        return User::query()
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->where('role', '!=', Role::Owner)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> активные тимлиды той же компании и команды, кроме увольняемого */
    public static function teamLeadOptions(User $user): array
    {
        return User::query()
            ->where('role', Role::TeamLead)
            ->where('company_id', $user->company_id)
            ->where('direction', $user->direction)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
```

- [ ] **Step 4: Действия в таблице**

В `app/Filament/Resources/Users/Tables/UsersTable.php` добавить импорты (`App\Models\User`, `App\Org\FireUser`, `Filament\Actions\Action`, `Filament\Forms\Components\Select`, `Illuminate\Support\Facades\Auth`) и в `recordActions([...])` после `EditAction::make()`:

```php
                Action::make('fire')
                    ->label('Уволить')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Человек не сможет войти. Встречи и история остаются за ним.')
                    ->visible(fn (User $record): bool => $record->is_active
                        && $record->role !== Role::Owner
                        && (Auth::user()?->can('update', $record) ?? false))
                    ->schema(fn (User $record): array => [
                        Select::make('phone_holder_id')
                            ->label('Кому передать номера')
                            ->options(FireUser::phoneHolderOptions($record))
                            ->placeholder('Оставить без держателя')
                            ->visible($record->phones()->exists()),
                        Select::make('team_lead_id')
                            ->label('Новый тимлид для его сотрудников')
                            ->options(FireUser::teamLeadOptions($record))
                            ->required()
                            ->visible($record->agents()->exists()),
                    ])
                    ->action(fn (User $record, array $data) => FireUser::handle(
                        $record,
                        isset($data['phone_holder_id']) ? (int) $data['phone_holder_id'] : null,
                        isset($data['team_lead_id']) ? (int) $data['team_lead_id'] : null,
                    )),
                Action::make('rehire')
                    ->label('Вернуть')
                    ->visible(fn (User $record): bool => ! $record->is_active && (Auth::user()?->can('update', $record) ?? false))
                    ->action(fn (User $record) => $record->update(['is_active' => true])),
```

- [ ] **Step 5: Тесты**

Run: `docker compose run --rm test php artisan test --filter=FireUserTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Org/FireUser.php app/Filament/Resources/Users tests/Feature/Org/FireUserTest.php
git commit -m "feat(org): fire with number hand-over and team lead replacement

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 10: Экран «Номера»

**Files:**
- Create: `app/Rules/UniquePhone.php`
- Create: `app/Filament/Resources/Phones/PhoneResource.php`
- Create: `app/Filament/Resources/Phones/Pages/ListPhones.php`, `CreatePhone.php`, `EditPhone.php`
- Test: `tests/Feature/Filament/PhoneResourceTest.php`

**Interfaces:**
- Consumes: `Phone`, `PhoneKind`, `PhoneNumber` (Task 2), `PhonePolicy` (Task 6).
- Produces: маршрут `/admin/phones`; `UniquePhone(?int $ignoreId)`.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Filament/PhoneResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Enums\PhoneKind;
use App\Filament\Resources\Phones\Pages\CreatePhone;
use App\Filament\Resources\Phones\Pages\ListPhones;
use App\Models\Company;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhoneResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_adds_number_in_national_format(): void
    {
        $lead = User::factory()->teamLead()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreatePhone::class)
            ->fillForm([
                'number' => '555 12 34 56',
                'kind' => PhoneKind::Personal->value,
                'company_id' => $lead->company_id,
                'user_id' => $lead->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('phones', ['number' => '+995555123456', 'user_id' => $lead->id]);
    }

    public function test_existing_number_in_other_writing_is_a_form_error_not_a_crash(): void
    {
        $company = Company::factory()->create();
        Phone::factory()->create(['number' => '+995555123456', 'company_id' => $company->id]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreatePhone::class)
            ->fillForm([
                'number' => '0555 12 34 56',
                'kind' => PhoneKind::Office->value,
                'company_id' => $company->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['number']);
    }

    public function test_foreign_number_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreatePhone::class)
            ->fillForm([
                'number' => '+7 916 123 45 67',
                'kind' => PhoneKind::Office->value,
                'company_id' => $company->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['number']);
    }

    public function test_holder_from_other_company_is_not_an_allowed_option(): void
    {
        $company = Company::factory()->create();
        $foreignLead = User::factory()->teamLead()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreatePhone::class)
            ->fillForm([
                'number' => '555 12 34 56',
                'kind' => PhoneKind::Personal->value,
                'company_id' => $company->id,
                'user_id' => $foreignLead->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['user_id']);
    }

    public function test_company_owner_sees_only_own_company_numbers(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $phoneA = Phone::factory()->create(['company_id' => $a->id]);
        $phoneB = Phone::factory()->create(['company_id' => $b->id]);
        $this->actingAs(User::factory()->companyOwner($a)->create());

        Livewire::test(ListPhones::class)
            ->assertCanSeeTableRecords([$phoneA])
            ->assertCanNotSeeTableRecords([$phoneB]);
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter=PhoneResourceTest`
Expected: FAIL — нет страницы.

- [ ] **Step 3: Правило уникального номера**

`app/Rules/UniquePhone.php`:

```php
<?php

namespace App\Rules;

use App\Models\Phone;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Номер — грузинский и ещё не заведён, с учётом нормализации: «0555…» и
 * «+995555…» — один номер. Без этого форма пропустила бы дубль до индекса (500).
 */
final class UniquePhone implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = PhoneNumber::tryNormalize(is_string($value) ? $value : null);

        if ($number === null) {
            $fail('Нужен грузинский номер.');

            return;
        }

        $taken = Phone::query()
            ->where('number', $number)
            ->when($this->ignoreId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail('Этот номер уже заведён.');
        }
    }
}
```

- [ ] **Step 4: Ресурс и страницы**

`app/Filament/Resources/Phones/PhoneResource.php`:

```php
<?php

namespace App\Filament\Resources\Phones;

use App\Enums\PhoneKind;
use App\Enums\Role;
use App\Filament\Resources\Phones\Pages\CreatePhone;
use App\Filament\Resources\Phones\Pages\EditPhone;
use App\Filament\Resources\Phones\Pages\ListPhones;
use App\Models\Phone;
use App\Models\User;
use App\Rules\UniquePhone;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

class PhoneResource extends Resource
{
    protected static ?string $model = Phone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = 'Оргструктура';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'номер';

    protected static ?string $pluralModelLabel = 'номера';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PhoneInput::make('number')
                ->label('Номер')
                ->required()
                // Только Грузия. initialCountry обязателен: без него пакет
                // спрашивает страну у ipinfo.io запросом С СЕРВЕРА — в BigMed
                // прод во Франкфурте показывал всем флаг Германии.
                ->onlyCountries(['GE'])
                ->initialCountry('GE')
                ->defaultCountry('GE')
                ->disableLookup()
                ->allowDropdown(false)
                ->formatAsYouType()
                ->displayNumberFormat(PhoneInputNumberType::INTERNATIONAL)
                ->inputNumberFormat(PhoneInputNumberType::E164)
                ->rule(fn (?Phone $record) => new UniquePhone($record?->getKey())),
            Select::make('kind')
                ->label('Вид')
                ->options(PhoneKind::class)
                ->default(PhoneKind::Personal->value)
                ->required(),
            Select::make('company_id')
                ->label('Компания')
                ->relationship('company', 'name', fn (Builder $query) => $query->whereNull('archived_at'))
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('user_id', null)),
            Select::make('user_id')
                ->label('Держатель')
                ->helperText('Для номера офиса — ответственный. Пусто — на объявлении будет компания и сам номер.')
                ->options(fn (Get $get) => User::query()
                    ->where('company_id', $get('company_id'))
                    ->where('is_active', true)
                    ->where('role', '!=', Role::Owner)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->placeholder('Без держателя'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Номер')->searchable(),
                TextColumn::make('kind')->label('Вид')->badge(),
                TextColumn::make('company.name')->label('Компания')->sortable(),
                TextColumn::make('holder.name')->label('Держатель')->placeholder('без держателя'),
            ])
            ->filters([
                SelectFilter::make('company_id')->label('Компания')->relationship('company', 'name'),
                SelectFilter::make('kind')->label('Вид')->options(PhoneKind::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** Владельцу — все номера, владельцу компании — своей компании. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        return $user instanceof User && $user->role === Role::Owner
            ? $query
            : $query->where('company_id', $user?->company_id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPhones::route('/'),
            'create' => CreatePhone::route('/create'),
            'edit' => EditPhone::route('/{record}/edit'),
        ];
    }
}
```

Страницы (`app/Filament/Resources/Phones/Pages/`):

```php
<?php

namespace App\Filament\Resources\Phones\Pages;

use App\Filament\Resources\Phones\PhoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPhones extends ListRecords
{
    protected static string $resource = PhoneResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

```php
<?php

namespace App\Filament\Resources\Phones\Pages;

use App\Filament\Resources\Phones\PhoneResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePhone extends CreateRecord
{
    protected static string $resource = PhoneResource::class;
}
```

```php
<?php

namespace App\Filament\Resources\Phones\Pages;

use App\Filament\Resources\Phones\PhoneResource;
use Filament\Resources\Pages\EditRecord;

class EditPhone extends EditRecord
{
    protected static string $resource = PhoneResource::class;
}
```

- [ ] **Step 5: Тесты**

Run: `docker compose run --rm test php artisan test --filter=PhoneResourceTest`
Expected: PASS (5 tests).

Если `test_owner_adds_number_in_national_format` падает на валидации самого `PhoneInput` (его встроенное правило отвергает национальную запись) — добавить `->validateFor(country: 'GE')`: валидация пакета пойдёт по Грузии, а не по стране из скрытого поля. Если `test_holder_from_other_company_is_not_an_allowed_option` проходит без ошибки формы — добавить к `user_id`:

```php
                ->in(fn (Get $get): array => User::query()
                    ->where('company_id', $get('company_id'))
                    ->where('is_active', true)
                    ->where('role', '!=', Role::Owner)
                    ->pluck('id')
                    ->all())
```

- [ ] **Step 6: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Rules/UniquePhone.php app/Filament/Resources/Phones tests/Feature/Filament/PhoneResourceTest.php
git commit -m "feat(filament): company phone numbers screen, Georgia only

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 11: Экран «Обмен между компаниями»

**Files:**
- Create: `app/Filament/Resources/CompanyExchanges/CompanyExchangeResource.php`
- Create: `app/Filament/Resources/CompanyExchanges/Pages/ManageCompanyExchanges.php`
- Test: `tests/Feature/Filament/CompanyExchangeResourceTest.php`

**Interfaces:**
- Consumes: `CompanyExchange::existsBetween` (Task 3), `CompanyExchangePolicy` (Task 6).
- Produces: маршрут `/admin/company-exchanges` (одна страница: таблица + создание в модалке).

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Filament/CompanyExchangeResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CompanyExchanges\Pages\ManageCompanyExchanges;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyExchangeResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_pair(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ManageCompanyExchanges::class)
            ->callAction('create', data: ['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true])
            ->assertHasNoFormErrors();

        $this->assertTrue(CompanyExchange::enabledBetween($a->id, $b->id));
    }

    public function test_reversed_existing_pair_is_a_form_error(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => false]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ManageCompanyExchanges::class)
            ->callAction('create', data: ['company_a_id' => $b->id, 'company_b_id' => $a->id, 'enabled' => true])
            ->assertHasFormErrors(['company_b_id']);
    }

    public function test_same_company_twice_is_a_form_error(): void
    {
        $a = Company::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ManageCompanyExchanges::class)
            ->callAction('create', data: ['company_a_id' => $a->id, 'company_b_id' => $a->id, 'enabled' => true])
            ->assertHasFormErrors(['company_b_id']);
    }

    public function test_owner_toggles_pair(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $pair = CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ManageCompanyExchanges::class)
            ->callAction(TestAction::make('toggle')->table($pair));

        $this->assertFalse($pair->refresh()->enabled);
    }

    public function test_company_owner_has_no_access(): void
    {
        $this->actingAs(User::factory()->companyOwner()->create())
            ->get('/admin/company-exchanges')
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter=CompanyExchangeResourceTest`
Expected: FAIL — нет страницы.

- [ ] **Step 3: Ресурс и страница**

`app/Filament/Resources/CompanyExchanges/CompanyExchangeResource.php`:

```php
<?php

namespace App\Filament\Resources\CompanyExchanges;

use App\Filament\Resources\CompanyExchanges\Pages\ManageCompanyExchanges;
use App\Models\CompanyExchange;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Обмен данными сотрудников между двумя компаниями группы (ТЗ 5.7).
 * Включён — сотрудники обеих видят друг друга на объявлениях и могут связаться.
 */
class CompanyExchangeResource extends Resource
{
    protected static ?string $model = CompanyExchange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Оргструктура';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'пара обмена';

    protected static ?string $pluralModelLabel = 'обмен между компаниями';

    protected static ?string $navigationLabel = 'Обмен между компаниями';

    public static function form(Schema $schema): Schema
    {
        $activeCompanies = fn (Builder $query) => $query->whereNull('archived_at');

        return $schema->components([
            Select::make('company_a_id')
                ->label('Компания')
                ->relationship('companyA', 'name', $activeCompanies)
                ->required()
                ->disabledOn('edit'),
            Select::make('company_b_id')
                ->label('Вторая компания')
                ->relationship('companyB', 'name', $activeCompanies)
                ->required()
                ->disabledOn('edit')
                ->different('company_a_id')
                ->rule(fn (Get $get, ?CompanyExchange $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    $a = (int) $get('company_a_id');
                    if ($a !== 0 && CompanyExchange::existsBetween($a, (int) $value, $record?->getKey())) {
                        $fail('Пара этих компаний уже есть.');
                    }
                }),
            Toggle::make('enabled')->label('Обмен включён')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('companyA.name')->label('Компания'),
                TextColumn::make('companyB.name')->label('Вторая компания'),
                IconColumn::make('enabled')->label('Обмен')->boolean(),
                TextColumn::make('updated_at')->label('Изменено')->dateTime(),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (CompanyExchange $record): string => $record->enabled ? 'Выключить' : 'Включить')
                    ->requiresConfirmation()
                    ->action(fn (CompanyExchange $record) => $record->update(['enabled' => ! $record->enabled])),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanyExchanges::route('/'),
        ];
    }
}
```

`app/Filament/Resources/CompanyExchanges/Pages/ManageCompanyExchanges.php`:

```php
<?php

namespace App\Filament\Resources\CompanyExchanges\Pages;

use App\Filament\Resources\CompanyExchanges\CompanyExchangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCompanyExchanges extends ManageRecords
{
    protected static string $resource = CompanyExchangeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

- [ ] **Step 4: Тесты**

Run: `docker compose run --rm test php artisan test --filter=CompanyExchangeResourceTest`
Expected: PASS (5 tests). Если ошибка формы ожидается в `company_b_id`, а приходит в `company_a_id` (порядок разрешения `different`) — оставить `different` на `company_b_id`, как в коде, и не трогать тест.

- [ ] **Step 5: Анализ и коммит**

```bash
docker compose exec app composer analyse
docker compose exec app vendor/bin/pint --dirty
git add app/Filament/Resources/CompanyExchanges tests/Feature/Filament/CompanyExchangeResourceTest.php
git commit -m "feat(filament): company exchange pairs screen for the owner

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```

---

### Task 12: Команда владельца, демо-структура, документация

**Files:**
- Create: `app/Console/Commands/MakeOwner.php`
- Create: `database/seeders/OrgDemoSeeder.php`
- Create: `docs/GSHome/05_Оргструктура.md`
- Modify: `docs/GSHome/00_Навигатор.md`, `docs/GSHome/02_Решения.md`, `docs/GSHome/03_Среда_разработки.md`, `CLAUDE.md`
- Test: `tests/Feature/Org/MakeOwnerTest.php`, `tests/Feature/Org/OrgDemoSeederTest.php`

**Interfaces:**
- Consumes: всё выше.
- Produces: `php artisan gshome:owner {--name=} {--email=} {--password=}`; `OrgDemoSeeder` (локально, пароль всех демо-людей `password`).

- [ ] **Step 1: Падающие тесты**

`tests/Feature/Org/MakeOwnerTest.php`:

```php
<?php

namespace Tests\Feature\Org;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_owner(): void
    {
        $this->artisan('gshome:owner', ['--name' => 'Max CL', '--email' => 'Max+CL@absoluteweb.com', '--password' => 'secret-pass'])
            ->assertSuccessful();

        $user = User::query()->where('email', 'max+cl@absoluteweb.com')->firstOrFail();
        $this->assertSame(Role::Owner, $user->role);
        $this->assertNull($user->company_id);
    }

    public function test_refuses_existing_email(): void
    {
        User::factory()->create(['email' => 'max+cl@absoluteweb.com']);

        $this->artisan('gshome:owner', ['--name' => 'Max CL', '--email' => 'MAX+CL@absoluteweb.com', '--password' => 'secret-pass'])
            ->assertFailed();
    }

    public function test_refuses_short_password(): void
    {
        $this->artisan('gshome:owner', ['--name' => 'Max CL', '--email' => 'max+cl@absoluteweb.com', '--password' => 'short'])
            ->assertFailed();
    }
}
```

`tests/Feature/Org/OrgDemoSeederTest.php`:

```php
<?php

namespace Tests\Feature\Org;

use App\Enums\Role;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Database\Seeders\OrgDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_full_structure(): void
    {
        $this->seed(OrgDemoSeeder::class);

        $this->assertSame(2, Company::query()->count());
        $this->assertSame(1, CompanyExchange::query()->where('enabled', true)->count());
        $this->assertSame(2, User::query()->where('role', Role::CompanyOwner)->count());
        // 2 компании × 2 команды × 2 тимлида
        $this->assertSame(8, User::query()->where('role', Role::TeamLead)->count());
        $this->assertSame(16, User::query()->where('role', Role::Agent)->count());
        $this->assertSame(2, Phone::query()->where('kind', 'office')->count());
        $this->assertSame(0, User::query()->where('role', Role::Agent)->whereNull('team_lead_id')->count());
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(\RuntimeException::class);
        $this->seed(OrgDemoSeeder::class);
    }
}
```

- [ ] **Step 2: Убедиться, что падают**

Run: `docker compose run --rm test php artisan test --filter="MakeOwnerTest|OrgDemoSeederTest"`
Expected: FAIL — команда и сидер не найдены.

- [ ] **Step 3: Команда**

`app/Console/Commands/MakeOwner.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** Первый владелец группы: регистрации нет, всех остальных он заводит сам. */
class MakeOwner extends Command
{
    protected $signature = 'gshome:owner {--name=} {--email=} {--password=}';

    protected $description = 'Создать владельца группы компаний';

    public function handle(): int
    {
        $name = $this->option('name') ?? text('Имя', required: true);
        $email = Str::lower(trim($this->option('email') ?? text('Email', required: true)));
        $password = $this->option('password') ?? password('Пароль (минимум 8 символов)', required: true);

        if (mb_strlen($password) < 8) {
            $this->error('Пароль короче 8 символов.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("Человек с email {$email} уже есть.");

            return self::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::Owner,
            'is_active' => true,
        ]);

        $this->info("Владелец {$email} создан.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Демо-сидер**

`database/seeders/OrgDemoSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Enums\Direction;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Демо-структура для локальной работы: docker compose exec app php artisan db:seed --class=OrgDemoSeeder
 * Пароль у всех — password. Почта — плюс-алиасы тестовой личности; из Docker
 * всё равно уходит в Mailpit.
 */
class OrgDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Демо-структура не для прода.');
        }

        $companies = [];
        foreach (['vake' => 'GS Home Vake', 'saburtalo' => 'GS Home Saburtalo'] as $slug => $name) {
            $company = Company::create(['name' => $name]);
            $companies[] = $company;

            $owner = User::factory()->companyOwner($company)->create([
                'name' => "Владелец {$name}",
                'email' => "max+cl.{$slug}.owner@absoluteweb.com",
            ]);
            Phone::factory()->office()->heldBy($owner)->create();

            foreach (Direction::cases() as $direction) {
                foreach ([1, 2] as $n) {
                    $lead = User::factory()->teamLead($company, $direction)->create([
                        'name' => "Тимлид {$direction->getLabel()} {$n} ({$slug})",
                        'email' => "max+cl.{$slug}.{$direction->value}.lead{$n}@absoluteweb.com",
                    ]);
                    Phone::factory()->heldBy($lead)->create();

                    foreach ([1, 2] as $m) {
                        $agent = User::factory()->agent($lead)->create([
                            'name' => "Сотрудник {$m} у тимлида {$n} ({$slug}, {$direction->getLabel()})",
                            'email' => "max+cl.{$slug}.{$direction->value}.lead{$n}.agent{$m}@absoluteweb.com",
                        ]);
                        Phone::factory()->heldBy($agent)->create();
                    }
                }
            }
        }

        CompanyExchange::create([
            'company_a_id' => $companies[0]->id,
            'company_b_id' => $companies[1]->id,
            'enabled' => true,
        ]);
    }
}
```

`UserFactory::definition()` уже даёт пароль `password` (через `static::$password ??= Hash::make('password')`) — проверить, что строка осталась из скелета.

- [ ] **Step 5: Тесты**

Run: `docker compose run --rm test php artisan test --filter="MakeOwnerTest|OrgDemoSeederTest"`
Expected: PASS (5 tests).

Локальная база: перевести существующего «Max CL» (роль `owner` проставила миграция) и залить демо:

```bash
docker compose exec app php artisan db:seed --class=OrgDemoSeeder
```

- [ ] **Step 6: Документация**

`docs/GSHome/05_Оргструктура.md`:

```markdown
---
tags: [gshome, org, access]
---

# Оргструктура и права

Спецификация — `docs/superpowers/specs/2026-10-09-orgstructure-design.md`, план —
`docs/superpowers/plans/2026-10-09-orgstructure.md`. Здесь — то, что нужно помнить при работе.

## Где что

| что | где |
|---|---|
| роли, направления | `App\Enums\Role`, `App\Enums\Direction` |
| место человека, очистка полей по роли | `App\Org\UserPlacement` (хук `saving` модели `User`) |
| увольнение с передачей номеров и сотрудников | `App\Org\FireUser` |
| нормализация телефона (только GE, E.164) | `App\Support\PhoneNumber` |
| **все правила видимости** | `App\Access\OrgAccess`: `directions()`, `visibleUsers()`, `ownership()` |
| кто что может в панели | `App\Policies\*Policy` |

## Правила, которые легко сломать

- Новая проверка «кто что видит» — только в `OrgAccess`. Ресурс или политика сами правил не придумывают.
- Номер принадлежит **компании**; сотрудник — держатель. Объявления следуют за номером сами.
- Инварианты (владелец без компании, у сотрудника тимлид и команда, пара A<B) стоят CHECK-ами в
  PostgreSQL. Обход модели (`DB::table`, `->update()` на запросе) их не обходит — и не должен.
- `ownership()` делает запрос на номер. Для списков объявлений понадобится пакетный вариант
  (номера страницы одним запросом) — делать вместе с экраном объявлений.
- Телефоны объявлений из API Real Estate должны приходить в E.164 (GE); GS Home всё равно
  нормализует их повторно через `PhoneNumber::tryNormalize()`.

## Команды

    docker compose exec app php artisan gshome:owner                              # первый владелец
    docker compose exec app php artisan db:seed --class=OrgDemoSeeder             # демо (локально)
```

В `docs/GSHome/00_Навигатор.md` добавить строку таблицы:

```markdown
| 05 | [[05_Оргструктура]] | Роли, компании, номера, обмен; где живут правила видимости |
```

В `docs/GSHome/02_Решения.md` добавить сверху (после строки `---` под вступлением) раздел:

```markdown
## 2026-10-09 — Оргструктура: фиксированные роли, номер принадлежит компании

**Решение.** Роли `owner`, `company_owner`, `team_lead`, `agent` — фиксированные, без Filament
Shield и матрицы прав. Людей заводит только владелец. Номер телефона принадлежит компании,
сотрудник — держатель; при увольнении номер передаётся. Номера офиса — такие же номера с
ответственным. Спецификация — `docs/superpowers/specs/2026-10-09-orgstructure-design.md`.

**Почему.** Права в ТЗ — видимость строк по месту в структуре; матрица действий её не выражает
и добавляет риск «сняли не ту галочку». Shield — когда появятся настраиваемые действия.

**Чем платим.** Человек ровно в одной компании и команде; переводы — без истории.

## 2026-10-09 — Нормализация полей

**Решение.** Нормализуем то, по чему ищем и сравниваем: телефоны (libphonenumber, только GE,
E.164), email (нижний регистр), имена и названия (обрезка, двойные пробелы). Свободный текст
(заметки, описания) храним как ввели.
```

В том же файле в разделе TODO удалить пункт «**Доступ в панель по ролям.** …» и добавить:

```markdown
- **Язык интерфейса.** Сейчас `APP_LOCALE=ru` и подписи по-русски. Сотрудники агентств —
  в Грузии: нужен ли грузинский (и английский) и переключатель, как в Real Estate?
- **API Real Estate отдаёт телефоны объявлений в E.164 (GE)** — требование к будущему API.
```

В `docs/GSHome/03_Среда_разработки.md` в конец раздела «Команды» добавить:

```markdown
Первый владелец — `docker compose exec app php artisan gshome:owner`; демо-структура для
локальной работы — `docker compose exec app php artisan db:seed --class=OrgDemoSeeder`
(пароль у всех `password`).
```

В `CLAUDE.md` в таблицу документов добавить строку `| 05_Оргструктура.md | роли, номера, обмен; **права — только через OrgAccess** |`.

- [ ] **Step 7: Полная проверка**

```bash
bin/ship.sh
```

Expected: `════ ЧИСТО ════`. Затем инспекции PhpStorm (`mcp__phpstorm__get_file_problems`, `errorsOnly: false`) по каждому созданному и изменённому PHP-файлу — ошибок быть не должно; слабые предупреждения «Multiple definitions … _ide_helper_models.php» — шум ide-helper, игнорировать.

Ручная проверка в браузере (https://gshome.local/admin): войти владельцем — видна группа «Оргструктура» с четырьмя разделами; войти `max+cl.vake.owner@absoluteweb.com` / `password` — видны компании, люди и номера только своей компании, без кнопок правки; войти тимлидом — раздела нет.

- [ ] **Step 8: Коммит**

```bash
git add app/Console database/seeders docs CLAUDE.md tests/Feature/Org
git commit -m "feat(org): owner command, demo structure, org docs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01AqhDHhd8GxYTsuB9uPEghg"
```
