<?php

namespace DHB;

final class TimeFormatter
{
    public static function format(int $time): string
    {
        $tstring = date('H:i:s', $time / 1000);
        $tstring = ltrim($tstring, '0: ');
        $tstring .= '.'.substr($time, -3, 2);
        $day = date('j', $time / 1000) - 1;
        if ($day) {
            $tstring = $day.'d '.$tstring;
        }
        return $tstring;
    }
}
