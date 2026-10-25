<?php

namespace App\Crm;

use App\Models\Client;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ClientLookup
{
    /** Архивные клиенты тоже видны: архив номер не освобождает. */
    public static function match(string $rawPhone, string $firstName, ?string $lastName): ClientMatch
    {
        $phone = PhoneNumber::normalize($rawPhone);

        $existing = Client::withTrashed()->where('phone_e164', $phone)->first();
        if ($existing === null) {
            return new ClientMatch('new', null, $phone);
        }

        $same = self::name($existing->first_name) === self::name($firstName)
            && (blank($existing->last_name) || blank($lastName)
                || self::name($existing->last_name) === self::name($lastName));

        return new ClientMatch($same ? 'same' : 'conflict', $existing, $phone);
    }

    /** @param array<string, mixed> $attrs */
    public static function create(ClientMatch $m, array $attrs, User $by): Client
    {
        if ($m->kind !== 'new') {
            throw new InvalidArgumentException('Создать можно только для нового номера.');
        }

        return DB::transaction(function () use ($m, $attrs, $by): Client {
            $client = self::make($m, $attrs, $by);
            Journal::record(Journal::CLIENT_CREATED, $client, [], $by);

            return $client;
        });
    }

    /**
     * Номер переходит новому клиенту; у старого остаётся вся история, но без номера.
     *
     * @param  array<string, mixed>  $attrs
     */
    public static function reassignPhone(ClientMatch $m, array $attrs, User $by): Client
    {
        $old = $m->existing;
        if ($m->kind !== 'conflict' || $old === null) {
            throw new InvalidArgumentException('Перенести номер можно только при конфликте.');
        }

        return DB::transaction(function () use ($m, $old, $attrs, $by): Client {
            // Сначала освобождаем номер: уникальный индекс не даёт держать его двоим.
            $old->phone_e164 = null;
            $old->save();

            $new = self::make($m, $attrs, $by);

            $props = ['old_client_id' => $old->id, 'new_client_id' => $new->id];
            Journal::record(Journal::CLIENT_PHONE_REASSIGNED, $old, $props, $by);
            Journal::record(Journal::CLIENT_PHONE_REASSIGNED, $new, $props, $by);

            return $new;
        });
    }

    /** @param array<string, mixed> $attrs */
    private static function make(ClientMatch $m, array $attrs, User $by): Client
    {
        $client = new Client($attrs);
        $client->phone_e164 = $m->phoneE164;
        $client->created_by = $by->id;
        $client->save();

        return $client;
    }

    private static function name(?string $value): string
    {
        return Str::lower(Str::squish((string) $value));
    }
}
