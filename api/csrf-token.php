<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Csrf.php';

lagohm_config();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['token' => Csrf::token()]);
