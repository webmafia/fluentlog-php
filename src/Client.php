<?php

namespace Webmafia\Fluentlog;

use DateTimeImmutable;

interface Client
{
	public function writeMessage(string $tag, DateTimeImmutable $time, array $record): void;
}
