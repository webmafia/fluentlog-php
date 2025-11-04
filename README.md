# fluentlog-php
This is a PHP port of [Fluentlog](https://github.com/webmafia/fluentlog).

## Installation
```sh
composer require webmafia/fluentlog
```

## Usage example
```php
<?php

use Webmafia\Fluentlog\Logger;
use Webmafia\Fluentlog\TcpClient;
use Webmafia\Fluentlog\TextClient;

require_once('../vendor/autoload.php');

$env = parse_ini_file('.env');

$client = new TcpClient(
	host: $env['HOST'],
	useTls: !empty($env['TLS']),
    sharedKey: $env['SHARED_KEY'],
    username: $env['USERNAME'],
    password: $env['PASSWORD']
);

// $client = new TextClient(fopen('php://output', 'w'));

$logger = new Logger($client, 'php');
$start = microtime(true);

for($i = 0; $i < 10; $i++) {
	echo $logger->info('hello from php %d', $i) . "\n";
}

$end = microtime(true);
$dur = $end - $start;
echo 'Done in ' . $dur . ' seconds' . "\n";
```