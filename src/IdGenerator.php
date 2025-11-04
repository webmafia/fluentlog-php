<?php

namespace Webmafia\Fluentlog;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Non-thread-safe generator, identical semantics to Go’s Generator.
 */
final class IdGenerator
{
	private int $seq;
	private int $node;

	public function __construct(int $node = 1)
	{
		if ($node < 1 || $node > 63) {
			throw new \InvalidArgumentException('node must be between 1 and 63');
		}
		$this->node = $node;
		$this->seq  = random_int(0, 0x7FFFFFFF);
	}

	public function id(): Id
	{
		$now = self::now();
		$id  = Id::new($now, $this->node, $this->seq & 0x7FFF);
		$this->seq = ($this->seq + 1) & 0x7FFFFFFF;
		return $id;
	}

	public function idFromTime(DateTimeImmutable $ts): Id
	{
		$id  = Id::new($ts, $this->node, $this->seq & 0x7FFF);
		$this->seq = ($this->seq + 1) & 0x7FFFFFFF;
		return $id;
	}

	private static function now(): \DateTimeImmutable
	{
		$t   = microtime(true);           // wall-clock seconds (float)
		$sec = (int)$t;
		$ms  = (int) floor(($t - $sec) * 1000);

		// Build from epoch seconds in UTC, then add ms and set to local tz
		$dt = (new \DateTimeImmutable('@' . $sec))
			->setTimezone(new \DateTimeZone(date_default_timezone_get()));

		return $dt->modify("+{$ms} milliseconds");
	}
}
