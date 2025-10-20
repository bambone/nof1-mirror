<?php
namespace Mirror\App;

final class Mapper
{
    public static function sideFromQty(float $qty): string
    {
        return $qty >= 0 ? 'Buy' : 'Sell';
    }

    public static function mirrorScaled(float $nof1Qty, float $scale): float
    {
        return abs($nof1Qty * $scale);
    }

    public static function riskBasedQty(float $riskUsd, float $entry, ?float $stop): ?float
    {
        if (!$stop || $stop == 0.0) return null;
        $riskPerUnit = abs($entry - $stop);
        if ($riskPerUnit <= 0) return null;
        return round($riskUsd / $riskPerUnit, 6);
    }
}
