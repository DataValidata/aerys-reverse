<?php

namespace Aerys\Reverse;

use Amp\Loop;
use Psr\Log\LoggerInterface as PsrLogger;
use Aerys\{ Bootable, InternalRequest, Middleware, Request, Response, Server };
use Amp\Artax\{ Client, Request as ArtaxRequest, Response as ArtaxResponse };

class ReverseProxy implements Middleware, Bootable {
	const MAX_INTERMEDIARY_BUFFER = 64 * 1024;

	private $target;
	private $headers;
	private $client;

	/** @var  PsrLogger */
	private $logger;

    function boot(Server $server, PsrLogger $logger)
    {
        $this->logger = $logger;
    }

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
        $artaxRequest = (new ArtaxRequest($this->target . $req->getUri(), $req->getMethod()));
        $this->logger->debug("Build ArtaxRequest : ".$req->getMethod()." ".$this->target.$req->getUri());
        foreach($headers as $field => $value){
            if(is_array($value)) {
                foreach($value as $headerValue) {
                    $artaxRequest = $artaxRequest->withAddedHeader($field, $headerValue);
                    $this->logger->debug("AddRequestHeader: [$field : $headerValue]");
                }
            } else {
                $artaxRequest = $artaxRequest->withHeader($field, $value);
                $this->logger->debug("AddRequestHeader: [$field : $value]");
            }
        }
        if($reqBody) {
            $artaxRequest = $artaxRequest->withBody($reqBody);
            $this->logger->debug("AddRequestBody");
        }



        /** @var ArtaxResponse $artaxResponse */
        $artaxResponse = yield $this->client->request($artaxRequest);
        $this->logger->debug("Got Artax Response");
        $allHeaders = $artaxResponse->getAllHeaders();
        foreach($allHeaders as $header => $values) {
            foreach ($values as $value) {
                $res->addHeader(strtolower($header), $value);
                $this->logger->debug("AddResponseHeader: [$header : $value]");
            }
        }

        $res->setStatus($status = $artaxResponse->getStatus());
        $this->logger->debug("SetResponseStatus: $status");
        $res->setReason($reason = $artaxResponse->getReason());
        $this->logger->debug("SetResponseReason: $reason");

        $body = $artaxResponse->getBody();
        while (($data = yield $body->read()) !== null) {
            $res->write($data);
            $this->logger->debug("Wrote response bytes : ".strlen($data));
        }

        $res->end( "");
	}

	// handle switching protocols by detaching socket from server and doing bidirectional forwarding on socket
	public function do(InternalRequest $ireq) {
		$headers = yield;
		if ($headers[":status"] == 101) {
		    $this->logger->alert("Yielding Headers");
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