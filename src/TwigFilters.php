<?php

namespace HoldGrip;

final class TwigFilters
{
    public static function time(int $time): string
    {
        $tstring = '0';
        if ($time >= 1000) {
            $tstring = date('H:i:s', floor($time / 1000));
            $tstring = ltrim($tstring, '0: ');
        }
        $tstring .= '.'.substr($time, -3, 2);

        $day = date('j', floor($time / 1000)) - 1;
        if ($day) {
            $tstring = $day.'d '.$tstring;
        }

        return $tstring;
    }

    public static function place(int $place)
    {
        $suffix = match ($place % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };

        return number_format($place).$suffix;
    }
}
