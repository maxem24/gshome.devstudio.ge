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
