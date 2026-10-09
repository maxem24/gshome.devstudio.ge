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
