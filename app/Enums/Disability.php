<?php

namespace App\Enums;

enum Disability: string
{
    case PreferNotToSay = 'prefer_not_to_say';
    case None = 'none';
    case Seeing = 'seeing';
    case Hearing = 'hearing';
    case Mobility = 'mobility';
    case Learning = 'learning';
    case Speech = 'speech';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PreferNotToSay => 'Prefer not to say',
            self::None => 'None',
            self::Seeing => 'Seeing',
            self::Hearing => 'Hearing',
            self::Mobility => 'Physical / mobility',
            self::Learning => 'Learning',
            self::Speech => 'Speech',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
