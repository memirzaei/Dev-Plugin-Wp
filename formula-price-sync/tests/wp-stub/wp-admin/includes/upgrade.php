<?php
if (!function_exists('dbDelta')) { function dbDelta($sql) { $GLOBALS['fps_dbdelta_calls'][] = $sql; return array(); } }
