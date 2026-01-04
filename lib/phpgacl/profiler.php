<?php
// Wrapper to help static analyzers find the Profiler class (original code in profiler.inc)
if (!class_exists('Profiler')) {
    require_once __DIR__ . '/profiler.inc';
}
