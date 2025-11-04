<?php

namespace Webmafia\Fluentlog;

class Utils
{
	static public function stringify(mixed $val): string
	{
		if ($val === null) return 'null';
		if ($val === true) return 'true';
		if ($val === false) return 'false';

		if (is_scalar($val)) {
			return (string)$val;
		}

		if (is_object($val) && method_exists($val, '__toString')) {
			return $val->__toString();
		}

		if (is_array($val) && !self::isAssoc($val)) {
			return implode("\n", array_map(fn($v) => self::stringify($v), $val));
		}

		return json_encode($val);
	}

	static public function scalar(mixed $val): mixed
	{
		if (is_scalar($val)) {
			return $val;
		}

		if (is_object($val) && method_exists($val, '__toString')) {
			return $val->__toString();
		}

		if (is_array($val) && !self::isAssoc($val)) {
			return array_map(fn($v) => self::stringify($v), $val);
		}

		return json_encode($val);
	}

	static public function isAssoc(array $a): bool
	{
		$i = 0;
		foreach ($a as $k => $_) {
			if ($k !== $i++) return true; // associative
		}
		return false; // numeric
	}
}
