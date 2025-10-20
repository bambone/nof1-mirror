<?php
namespace Mirror\App;

final class Quantizer
{
    /**
     * Округляет qty ВНИЗ к допустимому шагу и проверяет минимум.
     */
    public static function snapQty(float $qty, float $min, float $step): float
    {
        if ($qty <= 0) return 0.0;
        if ($step <= 0) $step = 1e-8;

        $steps = floor($qty / $step);
        $q = $steps * $step;

        // форматируем без лишних нулей/ошибок округления
        $precision = self::precisionFromStep($step);
        $q = (float)number_format($q, $precision, '.', '');

        if ($q < $min) return 0.0;
        return $q;
    }

    private static function precisionFromStep(float $step): int
    {
        $p = 0;
        while ($step < 1 && $p < 10) {
            $step *= 10;
            $p++;
        }
        return $p;
    }
}
