<?php

namespace App\Support;

use App\Enums\Disability;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StudentProfile
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')],
            'disabilities' => ['nullable', 'array', 'max:8'],
            'disabilities.*' => ['required', 'string', Rule::enum(Disability::class), 'distinct'],
            'disability_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public static function after(Validator $validator): void
    {
        /** @var list<string> $selected */
        $selected = array_values($validator->getValue('disabilities') ?? []);
        $exclusive = array_intersect($selected, [Disability::PreferNotToSay->value, Disability::None->value]);
        if ($exclusive !== [] && count($selected) > 1) {
            $validator->errors()->add('disabilities', 'Choose either one disclosure option or the disabilities that apply.');
        }
        if (in_array(Disability::Other->value, $selected, true) && trim((string) $validator->getValue('disability_note')) === '') {
            $validator->errors()->add('disability_note', 'Add a short note when you choose Other.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, school_class_id: int, disabilities: list<string>, disability_note: string|null}
     */
    public static function normalize(array $data): array
    {
        /** @var list<string> $selected */
        $selected = array_values($data['disabilities'] ?? []);
        $note = in_array(Disability::Other->value, $selected, true) ? trim((string) ($data['disability_note'] ?? '')) : null;

        return [
            'name' => $data['name'],
            'school_class_id' => (int) $data['school_class_id'],
            'disabilities' => $selected,
            'disability_note' => $note === '' ? null : $note,
        ];
    }
}
