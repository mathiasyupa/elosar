<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Patient lookup by name or cédula, forgiving about how people type.
 *
 * Every word has to appear somewhere in the name, in any order and without
 * caring about accents, ñ or capitals: "vera pedro" finds "Pedro Vera",
 * "andrade maria" finds "María José Andrade Ruiz", "nunez" finds "Núñez".
 * Front desks type surnames first as often as not. A cédula matches by its
 * digits anywhere in the number.
 */
class PatientSearch
{
    public static function apply(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $words = array_values(array_filter(preg_split('/\s+/', self::normalize($term)) ?: []));

        return $query->where(function (Builder $q) use ($term, $words): void {
            $q->where(function (Builder $name) use ($words): void {
                foreach ($words as $word) {
                    if (DB::connection()->getDriverName() === 'sqlite') {
                        $name->where('name', 'like', "%{$word}%");
                    } else {
                        $name->whereRaw("translate(lower(name), 'áéíóúüñÁÉÍÓÚÜÑ', 'aeiouunAEIOUUN') LIKE ?", ["%{$word}%"]);
                    }
                }
            })->orWhere('id_number', 'like', "%{$term}%");
        });
    }

    public static function normalize(string $text): string
    {
        return mb_strtolower(strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u',
            'ñ' => 'n', 'Ñ' => 'n',
        ]));
    }
}
