<?php

declare(strict_types=1);

/*
 * A backend that takes a while to answer, for tests that must tell queries
 * sent together from queries sent one after the other.
 *
 *     php slow-backend.php <port> <delay-ms> <peak-file>
 *
 * One process, every connection in one stream_select loop: each request is
 * answered <delay-ms> after it arrived, and <peak-file> holds the most
 * requests that were waiting for an answer at the same time. The answer is
 * the same small Prometheus payload for every query (a matrix for
 * query_range, a vector otherwise).
 */

[, $port, $delayMs, $peakFile] = $argv + [null, '0', '300', ''];

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);

if ($server === false) {
    fwrite(STDERR, "slow-backend: {$error}\n");
    exit(1);
}

/** @var array<int, array{socket: resource, buffer: string, due: float|null, path: string}> $clients */
$clients = [];
$peak = 0;

while (true) {
    $read = [$server, ...array_column($clients, 'socket')];
    $write = null;
    $except = null;

    $due = array_filter(array_column($clients, 'due'));
    $wait = $due === [] ? 1.0 : max(0.0, min($due) - microtime(true));

    if (stream_select($read, $write, $except, (int) $wait, (int) (fmod($wait, 1.0) * 1_000_000)) === false) {
        break;
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server, 0);

            if ($client !== false) {
                $clients[(int) $client] = ['socket' => $client, 'buffer' => '', 'due' => null, 'path' => ''];
            }

            continue;
        }

        $id = (int) $socket;
        $chunk = fread($socket, 65536);

        if ($chunk === '' || $chunk === false) {
            fclose($socket);
            unset($clients[$id]);

            continue;
        }

        $clients[$id]['buffer'] .= $chunk;

        if ($clients[$id]['due'] === null && str_contains($clients[$id]['buffer'], "\r\n\r\n")) {
            $clients[$id]['due'] = microtime(true) + ((int) $delayMs) / 1000;
            $clients[$id]['path'] = explode(' ', $clients[$id]['buffer'])[1] ?? '/';

            $waiting = count(array_filter(array_column($clients, 'due')));

            if ($waiting > $peak) {
                $peak = $waiting;
                file_put_contents($peakFile, (string) $peak);
            }
        }
    }

    foreach ($clients as $id => $client) {
        if ($client['due'] === null || $client['due'] > microtime(true)) {
            continue;
        }

        $body = str_contains($client['path'], 'query_range')
            ? '{"status":"success","data":{"resultType":"matrix","result":[{"metric":{},"values":[[1735689600,"2"],[1735689660,"2"]]}]}}'
            : '{"status":"success","data":{"resultType":"vector","result":[{"metric":{},"value":[1735689600,"10"]}]}}';

        fwrite($client['socket'], "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
        fclose($client['socket']);
        unset($clients[$id]);
    }
}
