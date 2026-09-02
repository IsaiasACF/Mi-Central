<?php
declare(strict_types=1);

namespace Modules\Discounts;

final class DiscountTextNormalizer
{
    public function cleanVisible(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function comparable(?string $value): string
    {
        $value = $this->cleanVisible($value) ?? '';
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = $this->removeAccents($value);
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    public function removeAccents(string $value): string
    {
        return strtr($value, [
            'á' => 'a',
            'à' => 'a',
            'ä' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'Á' => 'a',
            'À' => 'a',
            'Ä' => 'a',
            'Â' => 'a',
            'Ã' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ë' => 'e',
            'ê' => 'e',
            'É' => 'e',
            'È' => 'e',
            'Ë' => 'e',
            'Ê' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'ï' => 'i',
            'î' => 'i',
            'Í' => 'i',
            'Ì' => 'i',
            'Ï' => 'i',
            'Î' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ö' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'Ó' => 'o',
            'Ò' => 'o',
            'Ö' => 'o',
            'Ô' => 'o',
            'Õ' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'ü' => 'u',
            'û' => 'u',
            'Ú' => 'u',
            'Ù' => 'u',
            'Ü' => 'u',
            'Û' => 'u',
            'ñ' => 'n',
            'Ñ' => 'n',
            'ç' => 'c',
            'Ç' => 'c',
        ]);
    }
}
