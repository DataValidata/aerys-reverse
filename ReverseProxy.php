<?php

namespace Aerys\Reverse;

use Aerys\InternalRequest;
use Aerys\Middleware;
use Aerys\Request;
use Aerys\Response;
use Amp\Artax\Client;
use Amp\Artax\Notify;
use Amp\Loop;

class ReverseProxy implements Middleware {
	const MAX_INTERMEDIARY_BUFFER = 64 * 1024;

	private $target;
	private $headers;
	private $client;

	public function __construct(string $uri, $headers = [], Client $client = null) {
		$this->target = rtrim($uri, "/");

		if (is_callable($headers)) {
			$this->headers = $headers;
		} elseif (is_array($headers)) {
			foreach ($headers as $header => $values) {
				if (!is_array($values)) {
					throw new \UnexpectedValueException("Headers must be either callable or an array of arrays");
				}
				foreach ($values as $value) {
					if (!is_scalar($value)) {
						throw new \UnexpectedValueException("Header values must be scalars");
					}
				}
			}
			$this->headers = array_change_key_case($headers, CASE_LOWER);
		} else {
			throw new \UnexpectedValueException("Headers must be either callable or an array of arrays");
		}

		$this->client = $client ?? new Client(new \Amp\Artax\Cookie\NullCookieJar);
		$this->client->setAllOptions([
			Client::OP_DISCARD_BODY => true,
			Client::OP_HOST_CONNECTION_LIMIT => INF,
		]);
	}

	public function __invoke(Request $req, Response $res) {
		$headers = $req->getAllHeaders();
        if(array_key_exists('accept-encoding', $headers)) {
            unset($headers["accept-encoding"]);
        }
        if(array_key_exists('connection', $headers)) {
            $connection = $headers["connection"];
            unset($headers["connection"]);
            foreach ($connection as $value) {
                foreach (explode(",", strtolower($value)) as $type) {
                    $type = trim($type);
                    if ($type == "upgrade") {
                        $headers["connection"][0] = "upgrade";
                    } else {
                        unset($headers[$type]);
                    }
                }
            }
        }

		if ($this->headers) {
			if (is_callable($this->headers)) {
				$headers = ($this->headers)($headers);
			} else {
				$headers = $this->headers + $headers;
			}
		}

        $reqBody = yield $req->getBody();
        $artaxRequest = (new \Amp\Artax\Request)
            ->setMethod($req->getMethod())
            ->setUri($this->target . $req->getUri())
            ->setAllHeaders($headers)
        ;
        if($reqBody) {
            $artaxRequest->setBody($reqBody);
        }
        $stream = $this->client->request($artaxRequest);
		$stream->onEmit(function($update) use ($req, $res, &$hasBody, &$status, &$zlib) {
			list($type, $data) = $update;

			if ($type == Notify::RESPONSE_HEADERS) {
				$headers = array_change_key_case($data["headers"], CASE_LOWER);
				foreach ($data["headers"] as $header => $values) {
					foreach ($values as $value) {
						$res->addHeader($header, $value);
					}
				}
				$res->setStatus($status = $data["status"]);
				$res->setReason($data["reason"]);

				if (isset($headers["content-encoding"]) && strcasecmp(trim(current($headers["content-encoding"])), 'gzip') === 0) {
					$zlib = inflate_init(ZLIB_ENCODING_GZIP);
				}
				$hasBody = true;
			}

			if ($type == Notify::RESPONSE_BODY_DATA) {
				if ($zlib) {
					$data = inflate_add($zlib, $data);
				}
				$res->write($data);
			}

			if ($type == Notify::RESPONSE) {
				if (!$hasBody) {
					foreach ($data->getAllHeaders() as $header => $values) {
						foreach ($values as $value) {
							$res->addHeader($header, $value);
						}
					}
					$res->setStatus($status = $data->getStatus());
					$res->setReason($data->getReason());
				}
				if ($status == 101) {
					$req->setLocalVar("aerys.reverse.socket", $update["export_socket"]());
				}
				$res->end($zlib ? inflate_add($zlib, "", ZLIB_FINISH) : "");
			}
		});

		yield $stream;
	}

	// handle switching protocols by detaching socket from server and doing bidirectional forwarding on socket
	public function do(InternalRequest $ireq) {
		$headers = yield;
		if ($headers[":status"] == 101) {
			$yield = yield $headers;
		} else {
			return $headers; // detach Middleware otherwise
		}

		while ($yield !== null) {
			$yield = yield $yield;
		}

		Loop::defer([$this, "reapClient"], ["cb_data" => $ireq]);
	}

	public function reapClient($watcherId, InternalRequest $ireq) {
		$client = $ireq->client->socket;
		list($reverse, $externBuf) = $ireq->locals["aerys.reverse.socket"];
		$serverRefClearer = ($ireq->client->exporter)($ireq->client)();

		$internBuf = "";
		$clientWrite = Loop::onWritable($client, [self::class, "writer"], ["cb_data" => [&$externBuf, &$reverseRead, &$extern], "enable" => false, "keep_alive" => false]);
		$reverseWrite = Loop::onWritable($reverse, [self::class, "writer"], ["cb_data" => [&$internBuf, &$clientRead, &$intern], "enable" => false, "keep_alive" => false]);
		$clientRead = Loop::onReadable($client, [self::class, "reader"], ["cb_data" => [&$internBuf, $reverseWrite, &$intern], "keep_alive" => false]);
		$reverseRead = Loop::onReadable($reverse, [self::class, "reader"], ["cb_data" => [&$externBuf, $clientWrite, &$intern], "keep_alive" => false]);

	}

	public static function writer($watcher, $socket, $info) {
		$buffer = &$info[0];
		$bytes = @fwrite($socket, $buffer);

		if ($bytes == 0 && (!is_resource($socket) || @feof($socket))) {
            Loop::cancel($watcher);
            Loop::cancel($info[1]);
			return;
		}

		$buffer = substr($buffer, $bytes);
		if ($buffer === "") {
			if ($info[2]) {
                Loop::cancel($watcher);
			} else {
                Loop::disable($watcher);
			}
		}
		if (\strlen($buffer) < self::MAX_INTERMEDIARY_BUFFER) {
            Loop::enable($info[1]);
		}

	}

	public static function reader($watcher, $socket, $info) {
		$buffer = &$info[0];
		$data = @fread($socket, 8192);
		if ($data != "") {
			if ($buffer == "") {
                Loop::enable($info[1]);
			}
			$buffer .= $data;
			if (\strlen($buffer) > self::MAX_INTERMEDIARY_BUFFER) {
                Loop::disable($watcher);
			}
		} elseif (!is_resource($socket) || @feof($socket)) {
            Loop::cancel($watcher);
			if ($buffer == "") {
                Loop::cancel($info[1]);
			} else {
				$info[2] = true;
			}
		}
	}
}