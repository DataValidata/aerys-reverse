<?php

namespace Aerys\Reverse;

use Amp\ByteStream\Message;
use Psr\Log\LoggerInterface as PsrLogger;
use Aerys\{ Bootable, Request, Response, Server };
use Amp\Artax\{ Client as OriginClient, Request as OriginRequest, Response as OriginResponse, Cookie\NullCookieJar };

class Proxy implements Bootable {
	const MAX_INTERMEDIARY_BUFFER = 64 * 1024;

	private $target;
	private $headers;

	/** @var  OriginClient */
	private $originClient;

	/** @var  PsrLogger */
	protected $logger;

	public function __construct(string $uri, $headers = [], OriginClient $client = null) {
        $this->initTarget($uri);
        $this->initArtaxClient($client);
        $this->initGlobalHeaders($headers);
	}

    function boot(Server $server, PsrLogger $logger)
    {
        $this->logger = $logger;
    }

    protected function initTarget(string $uri)
    {
        $this->target = rtrim($uri, "/");
    }

    protected function initGlobalHeaders($headers)
    {
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
    }

    protected function processClientHeaders(Request $clientRequest): array
    {
        $headers = $clientRequest->getAllHeaders();
        if (array_key_exists('accept-encoding', $headers)) {
            unset($headers["accept-encoding"]);
        }
        if (array_key_exists('connection', $headers)) {
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
        return $headers;
    }

    protected function initArtaxClient(OriginClient $originClient = null)
    {
        $this->originClient = $originClient ?? new OriginClient(new NullCookieJar);
        $this->originClient->setAllOptions([
            OriginClient::OP_HOST_CONNECTION_LIMIT => INF,
        ]);
    }

    public function __invoke(Request $clientRequest, Response $clientResponse)
    {
        $proxyStart = $this->getTimeMillis();
	    $originRequest = $this->createOriginRequest(
            $clientRequest,
            yield from $this->processClientBody($clientRequest)
        );

	    $originStart = $this->getTimeMillis();
        $originResponse = yield from $this->doOriginRequest($originRequest);

        $this->sendHeadersStatusAndReason($clientResponse, $originResponse);
        yield from $this->sendBody($clientResponse, $originResponse->getBody());
        $originStop = $proxyStop = $this->getTimeMillis();

        $totalLatency = $proxyStop - $proxyStart;
        $originLatency = $originStop - $originStart;
        $proxyLatency = $totalLatency - $originLatency;

        $this->logger->debug("PROXY: [{status}] {method} {uri} {latencyTotal}ms (overhead: {latencyProxy}ms)", [
            'status' => $originResponse->getStatus(),
            'method' => $originRequest->getMethod(),
            'uri' => $originRequest->getUri(),
            'latencyTotal' => $totalLatency,
            'latencyProxy' => $proxyLatency,
            'latencyOrigin' => $originLatency,
        ]);
	}

    protected function doOriginRequest(OriginRequest $originRequest)
    {
        return yield $this->originClient->request($originRequest);
    }

    protected function processClientBody(Request $req)
    {
        return yield $req->getBody();
    }

    protected function createOriginRequest(Request $clientRequest, $reqBody): OriginRequest
    {
        $headers = $this->processClientHeaders($clientRequest);
        $originRequest = new OriginRequest($this->target . $clientRequest->getUri(), $clientRequest->getMethod());
        foreach ($headers as $field => $value) {
            if (is_array($value)) {
                foreach ($value as $headerValue) {
                    $originRequest = $originRequest->withAddedHeader($field, $headerValue);
                }
            } else {
                $originRequest = $originRequest->withHeader($field, $value);
            }
        }
        if ($reqBody) {
            $originRequest = $originRequest->withBody($reqBody);
        }

        return $originRequest;
    }

    protected function sendHeadersStatusAndReason(Response $res, OriginResponse $originResponse)
    {
        $allHeaders = $originResponse->getAllHeaders();
        foreach ($allHeaders as $header => $values) {
            foreach ($values as $value) {
                $res->addHeader(strtolower($header), $value);
            }
        }

        $res->setStatus($status = $originResponse->getStatus());
        $res->setReason($reason = $originResponse->getReason());
    }

    protected function sendBody(Response $clientResponse, Message $originResponseBody)
    {
        $totalBytes = 0;
        while (($data = yield $originResponseBody->read()) !== null) {
            $clientResponse->write($data);
            $totalBytes += strlen($data);
        }

        $clientResponse->end( "");

        return $totalBytes;
    }

    private function getTimeMillis()
    {
        return (int) round(microtime(true)*1000);
    }
}