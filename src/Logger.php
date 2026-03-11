<?php

namespace Webmafia\Fluentlog;

use Error;
use ErrorException;
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
			list($fmt, $attrs) = $this->processAssocArgs($args);
		} else {
			list($fmt, $attrs) = $this->processVariadricArgs($message, $args);
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

	private function processAssocArgs(array $args): array
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

	private function processVariadricArgs(string $message, array $args): array
	{
		$fmt = [];
		$attrs = [];
		$offset = 0;
		$args = array_values($args);

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
			if (isset($row['file'], $row['line'])) {
				$trace[] = $row['file'] . ':' . $row['line'];
			}
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

	/**
	 * Registers error handlers that will catch any error that occurs afterwards.
	 * 
	 * @param array{exclude_paths?: array<string, int>} $params Parameters for the error handler
	 */
	public function registerErrorHandler($params = []): void
	{
		set_error_handler(function($num, $str, $file, $line, $context = null) use ($params) {
			$this->handleException(new ErrorException($str, 0, $num, $file, $line), $params);
		});

		set_exception_handler(function(Throwable $e) use ($params) {
			$this->handleException($e, $params);
		});

		register_shutdown_function(function() use ($params) {
			$error = error_get_last();

			if ($error && $error['type'] == E_ERROR) {
				$this->handleException(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']), $params);
			}
		});
	}

	private function handleException(Throwable $e, $params = []): void
	{
		$severity = Severity::ERROR;

		if($e instanceof ErrorException) {
			$severity = self::getSeverity($e->getSeverity());
		} elseif($e instanceof Error) {
			$severity = Severity::CRITICAL;
		}

		if (!empty($params['exclude_paths'])) {
			$trace = self::stackTracecFromThrowable($e);

			foreach($params['exclude_paths'] as $path => $sev) {
				if (!is_string($path) || !is_int($sev) || $severity > $sev) {
					continue;
				}

				foreach($trace as $filename) {
					if (str_starts_with($filename, $path)) {
						return;
					}
				}
			}
		}

		$this->log($severity, $e, []);
	}

	static private function getSeverity($errno): int
	{
		static $severities = [
			E_ERROR => Severity::ERROR,
			E_WARNING => Severity::WARNING,
			E_PARSE => Severity::CRITICAL,
			E_NOTICE => Severity::NOTICE,
			E_CORE_ERROR => Severity::ERROR,
			E_CORE_WARNING => Severity::WARNING,
			E_COMPILE_ERROR => Severity::ERROR,
			E_COMPILE_WARNING => Severity::WARNING,
			E_USER_ERROR => Severity::ERROR,
			E_USER_WARNING => Severity::WARNING,
			E_USER_NOTICE => Severity::NOTICE,
			E_RECOVERABLE_ERROR => Severity::ERROR,
			E_DEPRECATED => Severity::WARNING,
			E_USER_DEPRECATED => Severity::WARNING
		];

		return $severities[$errno] ?? Severity::WARNING;
	}
}
