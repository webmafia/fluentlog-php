<?php

namespace Webmafia\Fluentlog;

use DateTimeImmutable;
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
		if (!$this->socket) {
			$this->connect();
			$this->handshake();
		}

		foreach ($record as $key => $val) {
			$record[$key] = Utils::scalar($val);
		}

		$msg = $this->pack->packArrayHeader(3);
		$msg .= $this->pack->packStr($tag);
		$msg .= $this->packDateTime($time);
		$msg .= $this->pack->packMap($record);

		$len = fwrite($this->socket, $msg);

		if ($len === false || $len !== strlen($msg)) {
			throw new \RuntimeException('Failed to send message');
		}
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
		$context = stream_context_create([
			$this->useTls ? 'ssl' : 'tcp' => $this->useTls ? [
				'verify_peer'       => true,
				'verify_peer_name'  => true,
				'allow_self_signed' => false,
				'capture_peer_cert' => false,
				'SNI_enabled'       => true,
				'SNI_server_name'   => $this->host,
			] : [],
		]);

		$scheme = $this->useTls ? 'tls' : 'tcp';
		$uri = "{$scheme}://{$this->host}:{$this->port}";

		$this->socket = @stream_socket_client(
			$uri,
			$errno,
			$errstr,
			5,
			STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_PERSISTENT,
			$context
		);

		if (!$this->socket) {
			throw new \RuntimeException("Failed to connect: $errstr ($errno)");
		}

		stream_set_timeout($this->socket, 5);
	}

	public function close(): void
	{
		if (is_resource($this->socket)) {
			fclose($this->socket);
		}
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

		echo "[Handshake OK] Server: {$serverHost}\n";
	}

	/** Send one MsgPack-encoded message. */
	private function sendMessage(array $msg): void
	{
		$bin = $this->pack->pack($msg);
		$len = fwrite($this->socket, $bin);
		if ($len === false || $len !== strlen($bin)) {
			throw new \RuntimeException('Failed to send message');
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
