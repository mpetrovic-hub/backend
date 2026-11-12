<?php

/** The forced SSH command accepts one small JSON object, never a shell command. */
final class Kiwi_Retention_Archive_Health_Transport
{
    private const REQUEST_LIMIT = 16384;
    private const CONTROL_RESPONSE_LIMIT = 262144;
    private $service;
    private $control_seconds;
    private $copy_seconds;
    private $source_rate;

    public function __construct(Kiwi_Retention_Archive_Health_Service $service, array $limits = [])
    {
        $this->service = $service;
        $this->control_seconds = $limits['control_seconds'] ?? 30;
        $this->copy_seconds = $limits['copy_seconds'] ?? 2700;
        $this->source_rate = $limits['source_bytes_per_second'] ?? 4194304;
        if ((!is_int($this->control_seconds) && !is_float($this->control_seconds))
            || !is_finite((float) $this->control_seconds) || $this->control_seconds <= 0
            || (!is_int($this->copy_seconds) && !is_float($this->copy_seconds)) || $this->copy_seconds <= 0
            || !is_int($this->source_rate) || $this->source_rate < 1
            || !is_finite($this->source_rate * $this->copy_seconds)
            || $this->source_rate * $this->copy_seconds > PHP_INT_MAX
            || array_diff(array_keys($limits), ['control_seconds', 'copy_seconds', 'source_bytes_per_second'])) {
            throw new InvalidArgumentException('Invalid archive-health limits.');
        }
    }

    /** Returns the SSH process exit code. stdout belongs exclusively to this protocol. */
    public function serve(string $action, $input, $output): int
    {
        $deadline = $this->now() + $this->control_seconds;
        try {
            if (!in_array($action, ['status', 'export', 'result', 'fail'], true)) {
                throw new RuntimeException('Unsupported archive-health action.');
            }
            if (!@stream_set_blocking($input, false) || !@stream_set_blocking($output, false)) {
                throw new RuntimeException('Nonblocking protocol I/O is unavailable.');
            }
            @stream_set_write_buffer($output, 0);
            $request = $this->read_request($input, $deadline);
            $this->validate_request($action, $request);
            if ($action === 'export') { return $this->export($request, $output); }
            if ($action === 'status') { $response = $this->service->status($request['attempt_id']); }
            elseif ($action === 'fail') {
                $response = $this->service->fail_attempt($request['attempt_id'], $request['phase'], $request['message'], $request['reason_code']);
            } else {
                if (!method_exists($this->service, 'accept_result')) { throw new RuntimeException('Result acceptance is unavailable.'); }
                $response = $this->service->accept_result($request);
            }
            $exit = $response['_exit_code']; unset($response['_exit_code']);
            $this->write_json($output, $response, $deadline);
            return $exit;
        } catch (Throwable $error) {
            try {
                $this->write_json($output, ['schema_version' => 1, 'result' => 'error',
                    'reason_code' => 'health_run_failed', 'message' => 'Archive-health request could not be completed safely.'], $deadline);
            } catch (Throwable $ignored) { /* A stalled or broken stdout is never a successful call. */ }
            return 2;
        }
    }

    private function validate_request(string $action, array $request): void
    {
        $fields = ['status' => ['schema_version', 'attempt_id'],
            'export' => ['schema_version', 'attempt_id', 'start_kind', 'generation', 'expected_bytes'],
            'fail' => ['schema_version', 'attempt_id', 'phase', 'reason_code', 'message'],
            'result' => ['schema_version', 'attempt_id', 'generation', 'received_bytes', 'sha256',
                'transfer_complete', 'check', 'check_completed', 'outcome']];
        $keys = array_keys($request); sort($keys);
        $expected = $fields[$action] ?? []; sort($expected);
        if ($keys !== $expected || $request['schema_version'] !== 1
            || ($request['attempt_id'] !== null && (!is_string($request['attempt_id'])
                || !Kiwi_Retention_Archive_Health_Store::valid_attempt_id($request['attempt_id'])))) {
            throw new RuntimeException('Invalid archive-health request fields.');
        }
        if ($action === 'export' && ($request['attempt_id'] === null
            || !in_array($request['start_kind'], ['scheduled', 'manual'], true)
            || !is_string($request['generation']) || $request['generation'] === ''
            || Kiwi_Retention_Archive_Name::normalize($request['generation']) !== $request['generation']
            || !is_int($request['expected_bytes']) || $request['expected_bytes'] < 1
            || $request['expected_bytes'] > $this->source_rate * $this->copy_seconds)) {
            throw new RuntimeException('Invalid export target or capacity.');
        }
        if ($action === 'fail' && (!in_array($request['phase'], $request['attempt_id'] === null
                ? ['start', 'activation'] : ['copy', 'verification', 'check', 'result'], true)
            || !in_array($request['reason_code'], ['health_run_failed', 'health_child_timeout'], true)
            || ($request['reason_code'] === 'health_child_timeout' && $request['phase'] !== 'check')
            || !is_string($request['message']) || trim($request['message']) === '' || strlen($request['message']) > 300)) {
            throw new RuntimeException('Invalid technical failure.');
        }
        if ($action === 'result' && ($request['attempt_id'] === null
            || !is_string($request['generation']) || $request['generation'] === ''
            || Kiwi_Retention_Archive_Name::normalize($request['generation']) !== $request['generation']
            || !is_int($request['received_bytes']) || $request['received_bytes'] < 1
            || !is_string($request['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $request['sha256']) !== 1
            || $request['transfer_complete'] !== true || $request['check_completed'] !== true
            || $request['check'] !== 'quick_check' || !in_array($request['outcome'], ['ok', 'corruption'], true))) {
            throw new RuntimeException('Invalid completed check proof.');
        }
    }

    private function export(array $request, $output): int
    {
        $started = $this->now();
        $deadline = $started + $this->copy_seconds;
        $header_started = false;
        $result = $this->service->with_new_attempt($request['attempt_id'], $request['start_kind'],
            $request['generation'], $request['expected_bytes'], function (array $attempt, string $path) use ($output, $deadline, &$header_started): array {
                $source = null;
                try {
                    $identity = $this->source_identity($path);
                    if ($identity['file']['size'] !== $attempt['expected_bytes']) { throw new RuntimeException('Archive size changed.'); }
                    $source = @fopen($path, 'rb');
                    if (!is_resource($source) || $this->stat_identity(@fstat($source)) !== $identity['file']
                        || $this->source_identity($path) !== $identity) { throw new RuntimeException('Archive source changed.'); }
                    $header_started = true;
                    $this->write_json($output, ['schema_version' => 1, 'type' => 'archive',
                        'attempt_id' => $attempt['attempt_id'], 'generation' => $attempt['generation'],
                        'bytes' => $attempt['expected_bytes']], $deadline);
                    $hash = hash_init('sha256'); $bytes = 0;
                    while ($bytes < $attempt['expected_bytes']) {
                        $size = min(65536, $attempt['expected_bytes'] - $bytes);
                        // Reserve read time before reading; even the first block is paced.
                        $read_at = $this->now() + $size / $this->source_rate;
                        while ($this->now() < $read_at) {
                            $this->check_deadline($deadline);
                            usleep(max(1, (int) (min(0.05, $read_at - $this->now()) * 1000000)));
                        }
                        $this->check_deadline($deadline);
                        $chunk = @fread($source, $size);
                        if (!is_string($chunk) || strlen($chunk) !== $size) { throw new RuntimeException('Archive source ended early.'); }
                        hash_update($hash, $chunk); $bytes += strlen($chunk);
                        $this->write_bytes($output, $chunk, $deadline);
                    }
                    if ($this->source_identity($path) !== $identity || $this->stat_identity(@fstat($source)) !== $identity['file']) {
                        throw new RuntimeException('Archive source or sidecars changed during export.');
                    }
                    $sha256 = hash_final($hash);
                    $proof = $this->service->record_transfer($attempt['attempt_id'], $bytes, $sha256);
                    // Unknown storage never publishes a terminal proof or releases Pending.
                    if ($proof['_exit_code'] !== 0) { return $proof; }
                    $this->write_json($output, ['schema_version' => 1, 'type' => 'complete',
                        'attempt_id' => $attempt['attempt_id'], 'generation' => $attempt['generation'],
                        'bytes' => $bytes, 'sha256' => $sha256], $deadline);
                    return ['_exit_code' => 0];
                } catch (Throwable $error) {
                    $this->service->fail_attempt($attempt['attempt_id'], 'copy', $error->getMessage());
                    return ['_exit_code' => 2];
                } finally { if (is_resource($source)) { fclose($source); } }
            });
        if (!$header_started) {
            $response = ['schema_version' => 1, 'result' => 'error', 'reason_code' => 'health_run_failed',
                'message' => 'Archive export could not be started safely.'];
            $this->write_json($output, $response, min($deadline, $this->now() + $this->control_seconds));
        }
        return $result['_exit_code'];
    }

    private function source_identity(string $path): array
    {
        clearstatcache(true, $path);
        $identity = ['file' => $this->stat_identity(@lstat($path)), 'sidecars' => []];
        foreach (['-wal', '-journal'] as $suffix) {
            $sidecar = $path . $suffix; clearstatcache(true, $sidecar);
            if (!file_exists($sidecar) && !is_link($sidecar)) { $identity['sidecars'][$suffix] = null; continue; }
            $stat = $this->stat_identity(@lstat($sidecar));
            if ($stat['size'] !== 0) { throw new RuntimeException('Active archive sidecar.'); }
            $identity['sidecars'][$suffix] = $stat;
        }
        return $identity;
    }

    private function stat_identity($stat): array
    {
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('Archive source is not a regular file.');
        }
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']));
    }

    private function read_request($input, float $deadline): array
    {
        $json = '';
        while (!feof($input)) {
            $this->check_deadline($deadline);
            $part = @fread($input, self::REQUEST_LIMIT + 1 - strlen($json));
            if (!is_string($part)) { throw new RuntimeException('Request input failed.'); }
            $json .= $part;
            if (strlen($json) > self::REQUEST_LIMIT) { throw new RuntimeException('Request is too large.'); }
            if ($part === '' && !feof($input)) { $this->wait_ready($input, false, $deadline); }
        }
        $object = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        if (!$object instanceof stdClass) { throw new RuntimeException('A JSON object is required.'); }
        foreach (get_object_vars($object) as $value) {
            if (is_array($value) || is_object($value)) { throw new RuntimeException('Flat request fields are required.'); }
        }
        // json_decode alone silently accepts duplicates. Tokenize the already valid flat
        // object, decoding escaped key names as well, so aliases cannot bypass this check.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]|[^\s{}\[\],:]+/s', $json, $matches);
        $seen = [];
        for ($i = 1; $i < count($matches[0]) - 1; $i += 4) {
            $key = json_decode($matches[0][$i], true, 2, JSON_THROW_ON_ERROR);
            if (!is_string($key) || isset($seen[$key])) { throw new RuntimeException('Duplicate request field.'); }
            $seen[$key] = true;
        }
        return get_object_vars($object);
    }

    private function write_json($output, array $value, float $deadline): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $limit = isset($value['type']) ? self::REQUEST_LIMIT : self::CONTROL_RESPONSE_LIMIT;
        if (strlen($json) > $limit) { throw new RuntimeException('Protocol response is too large.'); }
        $this->write_bytes($output, $json, $deadline);
    }

    private function write_bytes($output, string $bytes, float $deadline): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $this->check_deadline($deadline);
            $written = @fwrite($output, substr($bytes, $offset, 8192));
            if ($written === false) { throw new RuntimeException('Protocol output failed.'); }
            if ($written === 0) { $this->wait_ready($output, true, $deadline); }
            $offset += $written;
        }
    }

    private function wait_ready($stream, bool $writing, float $deadline): void
    {
        $this->check_deadline($deadline);
        $remaining = min(0.1, $deadline - $this->now());
        $read = $writing ? [] : [$stream]; $write = $writing ? [$stream] : []; $except = [];
        if (@stream_select($read, $write, $except, 0, max(1, (int) ($remaining * 1000000))) === false) {
            throw new RuntimeException('Protocol stream cannot be polled.');
        }
    }

    private function now(): float { return hrtime(true) / 1000000000; }
    private function check_deadline(float $deadline): void
    {
        if ($this->now() >= $deadline) { throw new RuntimeException('Archive-health time budget exhausted.'); }
    }
}
