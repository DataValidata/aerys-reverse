<?php


namespace Aerys\Reverse;


use Aerys\InternalRequest;
use Aerys\Middleware;
use Amp\Loop;

class ProtocolSwitchingProxy extends Proxy implements Middleware
{
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