<?php

// <ignore>
if (!class_exists('Aerys\Process')) { if (PHP_MAJOR_VERSION < 7) { echo "To run aerys, you need to run it with PHP 7.\n"; } echo "This file is not supposed to be invoked directly. To run it, use `php bin/aerys -c demo.php`.\n"; die(1); } eval(file_get_contents(__FILE__, null, null, __COMPILER_HALT_OFFSET__)); __halt_compiler();
// </ignore>

/* --- Global server options -------------------------------------------------------------------- */

const AERYS_OPTIONS = [
    "keepAliveTimeout" => 60,
    //"deflateMinimumLength" => 0,
];

/* --- http://localhost:5000/ ------------------------------------------------------------------- */

(new Aerys\Host)->expose("*", 5000)->use(new Aerys\Reverse\ReverseProxy("http://amphp.org/", ["Host" => ["amphp.org"]]));
