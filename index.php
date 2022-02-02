<?php
ini_set('display_errors',1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('log_errors', 1);
ini_set('error_log', 'tmp/errorslog.txt');
if (!empty($_GET)) {
    require_once 'getmovie.php';
}
