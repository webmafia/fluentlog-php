<?php

namespace Webmafia\Fluentlog;

use DateTimeImmutable;
use Exception;
use RuntimeException;

final class TextClient implements Client
{
	private mixed $out;

	public function __construct(mixed $out)
	{
		if (!self::isWritableResource($out)) {
			throw new Exception('Output must be a writable resource');
		}

		$this->out = $out;
	}

	static private function isWritableResource($res): bool
	{
		if (!is_resource($res)) {
			return false;
		}

		$meta = stream_get_meta_data($res);

		if (!isset($meta['mode'])) {
			return false;
		}

		return strpbrk($meta['mode'], 'waxc+') !== false;
	}

	public function writeMessage(string $tag, DateTimeImmutable $time, array $record): void
	{
		$msg = $time->format('Y-m-d H:i:s') . '  ' . $tag;

		foreach ($record as $key => $val) {
			$msg .= '  ' . $key . '=' . str_replace("\n", "\n  ", Utils::stringify($val));
		}

		$msg .= "\n";
		$len = fwrite($this->out, $msg);

		if ($len === false || $len !== strlen($msg)) {
			throw new \RuntimeException('Failed to send message');
		}
	}
}
