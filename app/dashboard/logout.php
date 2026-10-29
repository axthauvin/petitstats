<?php

use Petitstats\Auth\Auth;

require_once __DIR__ . '/../vendor/autoload.php';
Auth::logout();
header('Location: /dashboard/login.php');
exit;
