<?php

namespace Webmafia\Fluentlog;

use Throwable;

class Logger
{
	private string $tag;
	private int $stackTraceTreshold;
	private Client $cli;
	private IdGenerator $gen;
	private bool $assoc;

	public function __construct(Client $cli, string $tag, int $stackTraceTreshold = Severity::NOTICE, bool $assoc = false)
	{
		$this->cli = $cli;
		$this->tag = $tag;
		$this->stackTraceTreshold = $stackTraceTreshold;
		$this->gen = new IdGenerator();
		$this->assoc = $assoc;
	}

	public function emerg(mixed $message, mixed ...$args): Id
	{
		return $this->log(Severity::EMERGENCY, $message, $args);
	}

	public function alert(mixed $message, mixed ...$args): Id
	{
		return $this->log(Severity::ALERT, $message, $args);
	}

	public function crit(mixed $message, mixed ...$args): Id
	{
		return $this->log(Severity::CRITICAL, $message, $args);
	}

	public function err(mixed $message, mixed ...$args): Id
	{
		return $this->log(Severity::ERROR, $message, $args);
	}

	public function warning(mixed $message, mixed ...$args): Id
	{
		return $this->log(Severity::WARNING, $message, $args);
	}

	public function notice(mixed $message, mixed ...$args): Id
	{
		return $this->log(Severity::NOTICE, $message, $args);
	}

	public function info(mixed $message, mixed ...$args): Id
	{
		return $this->log(Severity::INFORMATIONAL, $message, $args);
	}

	public function debug(mixed $message, mixed ...$args): Id
	{
		return $this->log(Severity::DEBUG, $message, $args);
	}

	private function log(int $severity, mixed $message, array $args): Id
	{
		if($this->assoc) {
			list($fmt, $attrs) = $this->process_assoc_args($args);
		} else {
			list($fmt, $attrs) = $this->process_variadric_args($message, $args);
		}

		if ($message instanceof Throwable) {
			$attrs['stackTrace'] = self::stackTracecFromThrowable($message);
			$message = $message->getMessage();
		}

		if ($severity <= $this->stackTraceTreshold && empty($attrs['stackTrace'])) {
			$attrs['stackTrace'] = self::stackTrace(2);
		}

		if (!empty($fmt)) {
			$message = vsprintf($message, $fmt);
		}

		if (array_key_exists('@id', $attrs)) {
			trigger_error('"@id" is a reserved argument', E_USER_WARNING);
			unset($attrs['@id']);
		}

		if (in_array('pri', $attrs)) {
			trigger_error('"pri" is a reserved argument', E_USER_WARNING);
			unset($attrs['pri']);
		}

		if (in_array('message', $attrs)) {
			trigger_error('"message" is a reserved argument', E_USER_WARNING);
			unset($attrs['message']);
		}

		$id = $this->gen->id();

		$this->cli->writeMessage($this->tag, $id->time(), [
			'@id' => $id->toInt(),
			'pri' => $severity,
			'message' => $message,
			...$attrs
		]);

		return $id;
	}

	private function process_assoc_args(array $args): array
	{
		$fmt = [];
		$attrs = [];

		foreach($args as $arg) {
			if(is_array($arg) && Utils::isAssoc($arg)) {
				$attrs = array_merge($attrs, $arg);
			} else {
				$fmt[] = $arg;
			}
		}

		return [
			$fmt,
			$attrs
		];
	}

	private function process_variadric_args(string $message, array $args): array
	{
		$fmt = [];
		$attrs = [];
		$offset = 0;

		if(str_contains($message, '%')) {
			preg_match_all("/%([0-9]+\$)?(-|\+|0|\s|('\p{L}))?([0-9]|\*)?(\.([0-9]+|\*))?(b|c|d|e|E|f|F|g|G|h|H|o|s|u|x|X)/", $message, $matches);
			$offset = count($matches[0]);
			$nums = [];

			foreach($matches[4] as $m) {
				if(is_int($m)) {
					array_push($nums, $m);
				}
			}

			$offset += substr_count(implode('', $matches[0]), '*');
			$offset -= count($nums) - count(array_unique($nums));
			$fmt = array_slice($args, 0, $offset);
		}

		$keys = [];
		$vals = [];

		foreach(array_slice($args, $offset) as $i => $arg) {
			if($i % 2 === 0) {
				$keys[] = $arg;
			} else {
				$vals[] = $arg;
			}
		}

		while (count($keys) > count($vals)) {
			array_pop($keys);
		}

		$attrs = array_combine($keys, $vals);

		return [
			$fmt,
			$attrs
		];
	}

	static private function stackTracecFromThrowable(Throwable $e): array
	{
		$trace = [];
		$trace[] = $e->getFile() . ':' . $e->getLine();

		foreach ($e->getTrace() as $row) {
			$trace[] = $row['file'] . ':' . $row['line'];
		}

		return $trace;
	}

	static private function stackTrace(int $skip = 0): array
	{
		$trace = [];

		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16 + $skip);
		$len = sizeof($backtrace);

		for ($i = $skip; $i < $len; $i++) {
			if (!empty($backtrace[$i]['file']) && !empty($backtrace[$i]['line'])) {
				$trace[] = $backtrace[$i]['file'] . ':' . $backtrace[$i]['line'];
			}
		}

		return $trace;
	}
}
