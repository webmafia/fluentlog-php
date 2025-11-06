# fluentlog-php
This is a PHP version of [Fluentlog](https://github.com/webmafia/fluentlog), with three important differences:
1. Writing logs is not asynchronous.
2. Written logs are not buffered.
3. Written logs are not retried.

For this reason it's highly recommended to write to a local log collector (preferably [FluentBit](https://fluentbit.io/)).

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