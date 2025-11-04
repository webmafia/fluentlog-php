<?php

namespace Webmafia\Fluentlog;

use Throwable;

class Logger
{
	private string $tag;
	private int $stackTraceTreshold;
	private Client $cli;
	private IdGenerator $gen;

	public function __construct(Client $cli, string $tag, int $stackTraceTreshold = Severity::NOTICE)
	{
		$this->cli = $cli;
		$this->tag = $tag;
		$this->stackTraceTreshold = $stackTraceTreshold;
		$this->gen = new IdGenerator();
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

	public function error(mixed $message, mixed ...$args): Id
	{
		return $this->log(Severity::ERROR, $message, $args);
	}

	public function warn(mixed $message, mixed ...$args): Id
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
		$fmt = [];
		$attrs = [];

		foreach ($args as $arg) {
			if (is_array($arg) && Utils::isAssoc($arg)) {
				$attrs = array_merge($attrs, $arg);
			} else {
				$fmt[] = $arg;
			}
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

		$id = $this->gen->id();

		$this->cli->writeMessage($this->tag, $id->time(), [
			'@id' => $id->toInt(),
			'pri' => $severity,
			'message' => $message,
			...$attrs
		]);

		return $id;
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
