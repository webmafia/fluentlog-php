<?php

namespace Webmafia\Fluentlog;

use DateTimeImmutable;
use Exception;
use MessagePack\Packer;
use MessagePack\BufferUnpacker;

final class TcpClient implements Client
{
	private Packer $pack;
	private BufferUnpacker $unpack;

	private string $host;
	private int $port;
	private bool $useTls;
	private string $sharedKey;
	private string $username;
	private string $password;
	private string $hostname;

	private $socket;

	public function __construct(
		string $host,
		int $port = 24224,
		bool $useTls = false,
		string $sharedKey = '',
		string $username = '',
		string $password = ''
	) {
		$this->pack   = new Packer();
		$this->unpack = new BufferUnpacker();
		$this->host = $host;
		$this->port = $port;
		$this->useTls = $useTls;
		$this->sharedKey = $sharedKey;
		$this->username  = $username;
		$this->password  = $password;
		$this->hostname  = gethostname() ?: 'localhost';
	}

	public function writeMessage(string $tag, DateTimeImmutable $time, array $record): void
	{
		$this->maybeConnect();

		foreach ($record as $key => $val) {
			$record[$key] = Utils::scalar($val);
		}

		$msg = $this->packMessage($tag, $time, $record);

		try {
			$this->write($msg);
		} catch(Exception $e) {
			$this->close();
			$this->maybeConnect();
			$this->write($msg);
		}
	}

	private function maybeConnect(): void {
		if (!$this->socket) {
			$this->connect();

			// If this was a new connection, do a handshake
			if (ftell($this->socket) === 0) {
				$this->handshake();
			}
		}
	}

	private function packMessage(string $tag, DateTimeImmutable $time, array $record): string
	{
		$msg = $this->pack->packArrayHeader(3);
		$msg .= $this->pack->packStr($tag);
		$msg .= $this->packDateTime($time);
		$msg .= $this->pack->packMap($record);

		return $msg;
	}

	private function packDateTime(DateTimeImmutable $dt): string
	{
		// Get seconds and nanoseconds
		$sec  = $dt->getTimestamp();
		$nsec = (int)$dt->format('u') * 1000; // microseconds → nanoseconds

		// Build 8-byte payload (seconds + nanoseconds, both BE uint32)
		$payload = pack('N2', $sec, $nsec);

		// FixExt8 (code 0xD7), type = 0
		return $this->pack->packExt(0, $payload);
	}

	private function connect(): void
	{
		$options = [
			'socket' => [
				'tcp_nodelay' => true
			]
		];

		if ($this->useTls) {
			$options['ssl'] = [
				'verify_peer'         => true,
				'verify_peer_name'    => true,
				'allow_self_signed'   => false,
				'capture_peer_cert'   => false,
				'disable_compression' => true
			];
		}

		$context = stream_context_create($options);

		$scheme = $this->useTls ? 'tls' : 'tcp';
		$uri = "{$scheme}://{$this->host}:{$this->port}";

		$this->socket = @stream_socket_client(
			$uri,
			$errno,
			$errstr,
			3,
			STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT,
			$context
		);

		if (!$this->socket) {
			throw new \RuntimeException("Failed to connect: $errstr ($errno)");
		}

		stream_set_blocking($this->socket, true);
		stream_set_timeout($this->socket, 3);
	}

	public function close(): void
	{
		if (is_resource($this->socket)) {
			fclose($this->socket);
		}

		$this->socket = null;
	}

	/** Perform HELO → PING → PONG handshake. */
	private function handshake(): void
	{
		$helo = $this->readMessage();
		if (!is_array($helo) || $helo[0] !== 'HELO') {
			throw new \RuntimeException('Invalid HELO from server');
		}

		$opts = $helo[1];
		$nonce = $opts['nonce'] ?? '';
		$authSalt = $opts['auth'] ?? '';

		$sharedKeySalt = random_bytes(16);
		$sharedKeyHex  = hash('sha512', $sharedKeySalt . $this->hostname . $nonce . $this->sharedKey);

		$passwordHex = '';
		if ($authSalt !== '' && $this->username !== '' && $this->password !== '') {
			$passwordHex = hash('sha512', $authSalt . $this->username . $this->password);
		}

		$ping = [
			'PING',
			$this->hostname,
			$sharedKeySalt,
			$sharedKeyHex,
			$this->username,
			$passwordHex,
		];

		$this->sendMessage($ping);

		$pong = $this->readMessage();
		if (!is_array($pong) || $pong[0] !== 'PONG') {
			throw new \RuntimeException('Invalid PONG from server');
		}

		[$type, $authResult, $reason, $serverHost, $serverDigest] = $pong;
		if ($authResult !== true) {
			throw new \RuntimeException("Server rejected handshake: $reason");
		}

		$expected = hash('sha512', $sharedKeySalt . $serverHost . $nonce . $this->sharedKey);
		if (!hash_equals($expected, $serverDigest)) {
			throw new \RuntimeException('Shared key verification failed');
		}
	}

	/** Send one MsgPack-encoded message. */
	private function sendMessage(array $msg): void
	{
		$bin = $this->pack->pack($msg);
		$this->write($bin);
	}

	private function write(string $bin): void
	{
		$total = strlen($bin);
		$written = 0;

		while ($written < $total) {
			$len = fwrite($this->socket, substr($bin, $written));
			if ($len === false) {
				throw new \RuntimeException('Failed to send message');
			}
			$written += $len;
		}
	}

	/** Read and unpack a single MsgPack object from TCP stream. */
	private function readMessage(): mixed
	{
		$this->unpack->reset();

		while (true) {
			$chunk = fread($this->socket, 4096);
			if ($chunk === '' || $chunk === false) {
				throw new \RuntimeException('Connection closed or read error');
			}

			$this->unpack->append($chunk);

			if ($this->unpack->hasRemaining()) {
				return $this->unpack->unpack();
			}
		}
	}
}
