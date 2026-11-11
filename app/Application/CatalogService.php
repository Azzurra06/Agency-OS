<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Database\OrgId;
use App\Infrastructure\Repositories\ServiceRepository;
use App\Support\Decimal;
use App\Support\Validator;

/**
 * Catalogue des prestations : validation (liste blanche) et quotas.
 */
final class CatalogService
{
    public const MAX_SERVICES = 100;

    /** @var array<string, list<string>> */
    private const RULES = [
        'name' => ['required', 'string', 'min:2', 'max:150'],
        'category' => ['required', 'enum:showcase,ecommerce,redesign,seo,custom,other'],
        'current_price' => ['nullable', 'decimal', 'min:0', 'max:99999999.99'],
        'annual_volume' => ['nullable', 'int', 'min:0', 'max:10000'],
        'is_active' => ['bool'],
    ];

    public function __construct(private readonly ServiceRepository $services)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(OrgId $org, array $input, bool $isDemo): string
    {
        $v = $this->validate($input, true);
        $limit = $isDemo ? DemoService::MAX_SERVICES : self::MAX_SERVICES;
        if (count($this->services->all($org)) >= $limit) {
            throw new ValidationException(['name' => 'quota_reached']);
        }
        $price = $v['current_price'] ?? null;
        $volume = $v['annual_volume'] ?? null;

        return $this->services->create(
            $org,
            is_string($v['name'] ?? null) ? $v['name'] : '',
            is_string($v['category'] ?? null) ? $v['category'] : 'other',
            $price instanceof Decimal ? $price : null,
            is_int($volume) ? $volume : null,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(OrgId $org, string $publicId, array $input): bool
    {
        return $this->services->update($org, $publicId, $this->validate($input, false));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $creating): array
    {
        $rules = self::RULES;
        if (!$creating) {
            $rules['name'] = ['string', 'min:2', 'max:150'];
            $rules['category'] = ['enum:showcase,ecommerce,redesign,seo,custom,other'];
        }
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }
}
