<?php

namespace App\Crm;

use App\Crm\Exceptions\CrmForbidden;
use App\Enums\Direction;

/** Анкета клиента по сделке: набор вопросов зависит от направления, ответы — в deals.intake. */
final class DealIntake
{
    private const FIELDS = [
        'rent' => ['occupants', 'term', 'prepay', 'pets', 'deposit_ok', 'notes'],
        'sale' => ['purpose', 'payment', 'bank_help', 'down_payment', 'down_payment_currency', 'wants_discount', 'notes'],
    ];

    private const OPTIONS = [
        'term' => ['6m', '1y', 'other'],
        'prepay' => ['1', '1.5', '2', '3', 'first_last'],
        'deposit_ok' => ['yes', 'no', 'not_discussed'],
        'purpose' => ['living', 'investment'],
        'payment' => ['cash', 'mortgage'],
    ];

    private const BOOLEANS = ['pets', 'bank_help', 'wants_discount'];

    private const MAX_LENGTH = ['occupants' => 255, 'notes' => 2000];

    /** Денежное правило: положительное число, до 12 цифр и 2 знаков после точки. */
    public static function isMoney(string $amount): bool
    {
        return (bool) preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount) && (float) $amount > 0;
    }

    /** Сумма в строке с двумя знаками, без float: «300» → «300.00». Только после isMoney. */
    public static function money(string $v): string
    {
        return bcadd($v, '0', 2);
    }

    /** Сумма для показа: «120000.00» → «120 000», «1200.5» → «1 200.50»; не сумма возвращается как есть. */
    public static function display(string $v): string
    {
        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) {
            return $v;
        }
        [$int, $frac] = explode('.', self::money($v));

        return preg_replace('/\B(?=(\d{3})+$)/', ' ', $int).($frac === '00' ? '' : '.'.$frac);
    }

    /** @return list<string> */
    public static function fields(Direction $direction): array
    {
        return self::FIELDS[$direction->value];
    }

    /** @return ?list<string> null — булево или свободный текст */
    public static function options(string $key): ?array
    {
        if ($key === 'down_payment_currency') {
            /** @var list<string> */
            return config('crm.currencies');
        }

        return self::OPTIONS[$key] ?? null;
    }

    /**
     * Проверить и привести ответы; лишнее и пустое отбрасывается, неверное — CrmForbidden.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|bool>
     */
    public static function clean(Direction $direction, array $input): array
    {
        $allowed = self::fields($direction);
        $out = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (! in_array($key, $allowed, true)) {
                throw new CrmForbidden("Unknown intake field {$key}.");
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (in_array($key, self::BOOLEANS, true)) {
                if (! is_bool($value)) {
                    throw new CrmForbidden("Field {$key} must be boolean.");
                }
                $out[$key] = $value;

                continue;
            }
            if (! is_string($value)) {
                throw new CrmForbidden("Field {$key} must be a string.");
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $options = self::options($key);
            if ($options !== null && ! in_array($value, $options, true)) {
                throw new CrmForbidden("Bad value for {$key}.");
            }
            if (mb_strlen($value) > (self::MAX_LENGTH[$key] ?? PHP_INT_MAX)) {
                throw new CrmForbidden("Field {$key} is too long.");
            }
            if ($key === 'down_payment' && ! self::isMoney($value)) {
                throw new CrmForbidden('Down payment must be a positive number.');
            }
            $out[$key] = $value;
        }

        // Зависимые поля: «помощь банка» — только при наличных, взнос — только при ипотеке.
        if (($out['payment'] ?? null) !== 'cash') {
            unset($out['bank_help']);
        }
        if (($out['payment'] ?? null) !== 'mortgage') {
            unset($out['down_payment'], $out['down_payment_currency']);
        }

        return $out;
    }

    /** Ключ перевода; для старых и неизвестных ключей вернётся сам ключ перевода — без ошибки. */
    public static function label(string $key, string|bool|null $value = null): string
    {
        if ($value === null) {
            return __('gshome.crm.intake.'.$key);
        }
        if (is_bool($value)) {
            return __('gshome.crm.intake.'.($value ? 'yes' : 'no'));
        }

        // Точка в значении («1.5») ломает ключ перевода: в файле ключ плоский, с «_».
        return __('gshome.crm.intake.'.$key.'_'.str_replace('.', '_', $value));
    }
}
