<?php

namespace Webmafia\Fluentlog;

final class IdCodec
{
	private const MULTIPLIER     = '0x6eed0e9da4d94a4f';
	private const INV_MULTIPLIER = '0x2f72b4215a3d8caf';
	private const MASK64         = '0xFFFFFFFFFFFFFFFF';

	public static function toHex(int $id): string
	{
		$scrambled = self::mul64($id, self::MULTIPLIER);
		$bin = self::gmpToBigEndian8($scrambled);
		return strtolower(bin2hex($bin));
	}

	public static function fromHex(string $hex): int
	{
		$hex = strtolower(trim($hex));
		if (strlen($hex) !== 16) {
			throw new \InvalidArgumentException('invalid ID hex length');
		}

		$scrambled = gmp_init('0x' . $hex, 0);
		$original  = self::mul64($scrambled, self::INV_MULTIPLIER);
		$original = gmp_and($original, gmp_init('0x7FFFFFFFFFFFFFFF', 0));
		return (int)gmp_strval($original, 10);
	}

	/** Unsigned 64-bit modular multiply (a*b mod 2⁶⁴) using GMP. */
	private static function mul64(int|string|\GMP $a, int|string|\GMP $b): \GMP
	{
		$x = gmp_init((string)$a, 0);
		$y = gmp_init((string)$b, 0);
		$res = gmp_mul($x, $y);
		return gmp_and($res, gmp_init(self::MASK64, 0));
	}

	/** Convert GMP value → 8-byte big-endian binary string. */
	private static function gmpToBigEndian8(\GMP $num): string
	{
		$hex = gmp_strval($num, 16);
		$hex = str_pad($hex, 16, '0', STR_PAD_LEFT);
		return hex2bin($hex);
	}
}
