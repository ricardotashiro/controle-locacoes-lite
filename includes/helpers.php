<?php

if (!function_exists('parseMoneyValuePhp')) {
    function parseMoneyValuePhp($value): float {
        $value = trim((string)$value);
        if ($value === '') return 0.0;
        $value = preg_replace('/[^\d,\.\-]/', '', $value);
        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');
        if ($hasComma && $hasDot) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($hasComma) {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? (float)$value : 0.0;
    }
}

if (!function_exists('normalizeDailyRateValuePhp')) {
    function normalizeDailyRateValuePhp($value): float {
        $n = (float)$value;
        while ($n > 9999) {
            $n = $n / 100;
        }
        return round($n, 2);
    }
}

if (!function_exists('moneyBr')) {
    function moneyBr(float $value): string {
        return number_format($value, 2, ',', '.');
    }
}

if (!function_exists('normalizePersonNamePhp')) {
    function normalizePersonNamePhp(string $value): string {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/\s+/', ' ', $value);
        return $value;
    }
}

if (!function_exists('normalizePhoneDigitsPhp')) {
    function normalizePhoneDigitsPhp(string $value): string {
        return preg_replace('/\D+/', '', (string)$value);
    }
}

if (!function_exists('normalizeDocumentPhp')) {
    function normalizeDocumentPhp(?string $value): ?string {
        $value = strtoupper(trim((string)$value));
        $value = preg_replace('/[^A-Z0-9]+/', '', $value);
        return $value !== '' ? $value : null;
    }
}
