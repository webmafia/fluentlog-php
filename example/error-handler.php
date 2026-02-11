<?php

use Webmafia\Fluentlog\Logger;
use Webmafia\Fluentlog\TcpClient;

require_once('../vendor/autoload.php');

$env = parse_ini_file('.env');

$client = new TcpClient(
	host: $env['HOST'] ?? 'localhost',
	useTls: !empty($env['TLS']),
    sharedKey: $env['SHARED_KEY'] ?? '',
    username: $env['USERNAME'] ?? '',
    password: $env['PASSWORD'] ?? ''
);

$logger = new Logger($client, 'php');
$logger->registerErrorHandler();

include('bad-file.php');