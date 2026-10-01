<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

lagohm_config();
Auth::logout();
header('Location: login.php');
