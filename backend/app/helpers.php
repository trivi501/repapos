<?php

if (! function_exists('dinero')) {
    /** 1234.5 -> "$1,234.50" */
    function dinero($monto): string
    {
        $monto = (float) $monto;
        return ($monto < 0 ? '-$' : '$') . number_format(abs($monto), 2);
    }
}
