<?php
/**
 * Static analysis stub for the Profiler class.
 * This file is never executed at runtime (wrapped in `if (false)`),
 * but IDEs and static analyzers will see the class declaration.
 */
if (false) {
    class Profiler
    {
        public function __construct($output_enabled = false, $trace_enabled = false) {}
        public function Profiler($output_enabled = false, $trace_enabled = false) {}
        public function startTimer($name, $desc = "") {}
        public function stopTimer($name) {}
        public function elapsedTime($name) { return 0.0; }
        public function elapsedOverall() { return 0.0; }
        public function printTimers($enabled = false) {}
        public function getMicroTime() { return microtime(true); }
    }
}
