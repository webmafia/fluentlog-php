<?php

namespace Webmafia\Fluentlog;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Stringable;

/**
 * 63-bit HexID compatible with Go's hexid package.
 *
 * Layout:
 *   [ 32 bits unix seconds | 10 bits ms | 6 bits node | 15 bits seq ]
 *   top bit always 0 -> safe for Postgres BIGINT
 */
final class Id implements JsonSerializable, Stringable
{
	private const MS_BITS   = 10;
	private const NODE_BITS = 6;
	private const SEQ_BITS  = 15;

	private const NODE_SHIFT = self::SEQ_BITS;                         // 15
	private const MS_SHIFT   = self::NODE_SHIFT + self::NODE_BITS;     // 21
	private const SEC_SHIFT  = self::MS_SHIFT + self::MS_BITS;         // 31

	private const MASK63 = 0x7FFFFFFFFFFFFFFF;
	private const MS_MASK   = (1 << self::MS_BITS) - 1;
	private const NODE_MASK = (1 << self::NODE_BITS) - 1;
	private const SEQ_MASK  = (1 << self::SEQ_BITS) - 1;

	private int $v;

	private function __construct(int $v)
	{
		$this->v = $v & self::MASK63;
	}

	/** Create new ID from timestamp, node, and seq. */
	public static function new(DateTimeImmutable $ts, int $nodeId, int $seq): self
	{
		$secs  = $ts->getTimestamp();
		$msecs = (int)floor(((int)$ts->format('u')) / 1000);

		$v = (($secs & 0xFFFFFFFF) << self::SEC_SHIFT)
			| (($msecs & self::MS_MASK) << self::MS_SHIFT)
			| (($nodeId & self::NODE_MASK) << self::NODE_SHIFT)
			| ($seq & self::SEQ_MASK);

		return new self($v);
	}

	public static function fromInt(int $v): self
	{
		return new self($v);
	}

	public static function fromString(string $hex): self
	{
		$id = IdCodec::fromHex($hex);
		return new self($id);
	}

	public function unix(): int
	{
		return ($this->v >> self::SEC_SHIFT) & 0xFFFFFFFF;
	}

	public function millis(): int
	{
		return ($this->v >> self::MS_SHIFT) & self::MS_MASK;
	}

	public function node(): int
	{
		return ($this->v >> self::NODE_SHIFT) & self::NODE_MASK;
	}

	public function seq(): int
	{
		return $this->v & self::SEQ_MASK;
	}

	public function entropy(): int
	{
		return $this->v & 0x7FFFFFFF;
	}

	public function time(): ?DateTimeImmutable
	{
		if ($this->hashed()) {
			return null;
		}
		$sec = $this->unix();
		$ms  = $this->millis();
		$dt  = (new DateTimeImmutable("@$sec"))->setTimezone(new DateTimeZone(date_default_timezone_get()));
		return $dt->modify("+{$ms} milliseconds");
	}

	public function toInt(): int
	{
		return $this->v;
	}

	public function toHex(): string
	{
		if(!function_exists('gmp_init')) return '';
		
		return IdCodec::toHex($this->v);
	}

	public function hashed(): bool
	{
		return $this->node() === 0;
	}

	public function isZero(): bool
	{
		return $this->v === 0;
	}

	public function jsonSerialize(): mixed
	{
		return $this->isZero() ? null : $this->toHex();
	}

	public function __toString(): string
	{
		return $this->toHex();
	}
}
