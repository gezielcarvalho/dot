<?php
if (!defined('DP_BASE_DIR')) {
  die('You should not access this file directly.');
}

// Normalize a date to the Monday (start) of its week at 00:00:00
if (!function_exists('week_start_monday')) {
    function week_start_monday($date)
    {
        $d = new CDate($date);
        $dow = $d->getDayOfWeek(); // 0=Sun,1=Mon,...6=Sat
        $offset = ($dow + 6) % 7; // days since Monday
        for ($i = 0; $i < $offset; $i++) {
            $prev = $d->getPrevDay();
            $d = new CDate($prev->format('%Y-%m-%d 00:00:00'));
        }
        return $d;
    }
}

// Normalize a date to the Sunday (end) of its week at 23:59:59
if (!function_exists('week_end_sunday')) {
    function week_end_sunday($date)
    {
        $d = new CDate($date);
        $dow = $d->getDayOfWeek(); // 0=Sun,1=Mon,...6=Sat
        $offset = ($dow + 6) % 7; // days since Monday
        $days_to_sunday = 6 - $offset;
        for ($i = 0; $i < $days_to_sunday; $i++) {
            $next = $d->getNextDay();
            $d = new CDate($next->format('%Y-%m-%d 23:59:59'));
        }
        return $d;
    }
}

// Convert PEAR Date day-of-week (0=Sun..6=Sat) to Monday-based index (0=Mon..6=Sun)
if (!function_exists('dow_monday_index')) {
    function dow_monday_index($date)
    {
        $d = new CDate($date);
        return ($d->getDayOfWeek() + 6) % 7;
    }
}

?>
