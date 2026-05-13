<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$_SESSION = [];
session_destroy();
header('Location: /public/index.php');
exit;

