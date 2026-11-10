<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Decimal;
use App\Support\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testUnknownFieldsAreRejected(): void
    {
        // Protection contre le mass assignment : organization_id ne peut jamais venir du navigateur.
        $v = Validator::make(['name' => 'Lumen', 'organization_id' => 2], ['name' => ['required', 'string', 'max:150']]);
        self::assertTrue($v->fails());
        self::assertSame(['organization_id' => 'unknown_field'], $v->errors());
    }

    public function testDecimalsMustBeStrings(): void
    {
        $v = Validator::make(['revenue' => 180000.5], ['revenue' => ['required', 'decimal']]);
        self::assertSame(['revenue' => 'decimal_must_be_string'], $v->errors());
    }

    public function testDecimalIsCastExactly(): void
    {
        $v = Validator::make(['revenue' => '180000.50'], ['revenue' => ['required', 'decimal', 'min:0', 'max:99999999.99']]);
        self::assertFalse($v->fails());
        $value = $v->validated()['revenue'];
        self::assertInstanceOf(Decimal::class, $value);
        self::assertSame('180000.5', $value->toString());
    }

    public function testPercentIsConvertedToFractionByServer(): void
    {
        $v = Validator::make(['margin' => '30.00'], ['margin' => ['required', 'percent', 'min:0', 'max:90']]);
        $value = $v->validated()['margin'];
        self::assertInstanceOf(Decimal::class, $value);
        self::assertSame('0.3', $value->toString());
    }

    public function testBounds(): void
    {
        $v = Validator::make(['margin' => '95'], ['margin' => ['required', 'percent', 'min:0', 'max:90']]);
        self::assertSame(['margin' => 'too_large'], $v->errors());
    }

    public function testInvalidFormatsAreRejected(): void
    {
        $v = Validator::make(
            ['a' => '12,5', 'b' => 'nobody', 'c' => '2026-02-30', 'd' => 'abc'],
            ['a' => ['decimal'], 'b' => ['email'], 'c' => ['date'], 'd' => ['public_id']],
        );
        self::assertSame(['a' => 'invalid_decimal', 'b' => 'invalid_email', 'c' => 'invalid_date', 'd' => 'invalid_identifier'], $v->errors());
    }

    public function testControlCharactersAndEnums(): void
    {
        $v = Validator::make(['name' => "Lumen\x00", 'type' => 'hack'], ['name' => ['string'], 'type' => ['enum:closed_fiscal_year,trailing_12_months']]);
        self::assertSame(['name' => 'invalid_characters', 'type' => 'invalid_choice'], $v->errors());
    }

    public function testRequiredNullableAndEmailNormalisation(): void
    {
        $v = Validator::make(['email' => '  Claire@Example.COM ', 'note' => null], [
            'email' => ['required', 'email'], 'note' => ['nullable', 'string'], 'name' => ['required', 'string'],
        ]);
        self::assertSame(['name' => 'required'], $v->errors());
        self::assertSame('claire@example.com', $v->validated()['email']);
        self::assertArrayHasKey('note', $v->validated());
        self::assertNull($v->validated()['note']);
    }
}
