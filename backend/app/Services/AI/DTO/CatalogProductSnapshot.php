<?php

namespace App\Services\AI\DTO;

/**
 * Snapshot of one catalog product passed to the AI provider for resolution hints.
 */
final readonly class CatalogProductSnapshot
{
    /**
     * @param  array<int, array{id:string,name:string,price:?string}>  $variants
     * @param  array<int, array{id:string,name:string,modifiers:array<int,array{id:string,name:string,price_delta:string}>}>  $modifierGroups
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $nameAr,
        public ?string $nameFr,
        public string $price,
        public bool $isOrderable,
        public array $variants = [],
        public array $modifierGroups = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_ar' => $this->nameAr,
            'name_fr' => $this->nameFr,
            'price' => $this->price,
            'is_orderable' => $this->isOrderable,
            'variants' => $this->variants,
            'modifier_groups' => $this->modifierGroups,
        ];
    }
}
