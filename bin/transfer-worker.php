<?php
// Moves uploads from the spool (lib/spool.php) to the file storage, one file at a time.
//
//   php bin/transfer-worker.php                 run until stopped (the service)
//   php bin/transfer-worker.php --once          transfer everything that is due, clean up, exit
//   php bin/transfer-worker.php --cleanup       clean up only (cron)
//   php bin/transfer-worker.php --status        list the uploads in the spool
//   php bin/transfer-worker.php --requeue <id>  retry a failed upload ("all": every failed upload)
//
// Every transfer runs in a child process (--job <id>) that loads the configuration of the instance
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['HTTP_HOST'] ??= 'localhost';

const EXIT_RETRY = 75;
const CLEANUP_INTERVAL = 600;
const STORAGE_CLEANUP_INTERVAL = 3600;
const STALE_MULTIPART_AGE = 24 * 3600;
const FINISHED_RECORD_TTL = 24 * 3600;

$opts = getopt('', ['once', 'cleanup', 'status', 'requeue:', 'job:', 'cleanup-storage', 'verbose']);
$verbose = isset($opts['verbose']);

if (isset($opts['job'])) {
    require_once "{$_SERVER['DOCUMENT_ROOT']}/lib/storage.php";
    exit(run_job((string) $opts['job']));
}
if (isset($opts['cleanup-storage'])) {
    require_once "{$_SERVER['DOCUMENT_ROOT']}/lib/storage.php";
    exit(cleanup_storage());
}

require_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
require_once "{$_SERVER['DOCUMENT_ROOT']}/lib/spool.php";

if (!SPOOL->is_available()) {
    worker_log('spool directory ' . SPOOL_DIR . ' is missing or not writable');
    exit(1);
}

if (isset($opts['status'])) {
    print_status();
    exit(0);
}
if (isset($opts['requeue'])) {
    exit(requeue((string) $opts['requeue']));
}

// the worker itself
$lock = fopen(SPOOL_DIR . '/worker.lock', 'c');
$service = !isset($opts['once']) && !isset($opts['cleanup']);
if (!$lock || !flock($lock, $service ? LOCK_EX : LOCK_EX | LOCK_NB)) {
    worker_log('another transfer worker is running', true);
    exit(0);
}

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
        pcntl_signal($signal, function () use (&$stop) {
            $stop = true;
        });
    }
}

if (isset($opts['cleanup'])) {
    cleanup(true);
    exit(0);
}

worker_log(sprintf('started (pid %d, spool %s)', getmypid(), SPOOL_DIR));
$last_cleanup = 0;
$pause_until = 0;

while (!$stop) {
    if (time() - $last_cleanup >= CLEANUP_INTERVAL) {
        cleanup(!$service || $last_cleanup === 0);
        $last_cleanup = time();
    }

    $job = time() >= $pause_until ? next_job() : null;
    if ($job === null) {
        if (!$service) {
            break;
        }
        interruptible_sleep(5, $stop);
        continue;
    }

    $started = microtime(true);
    $code = run_child(['--job', $job['id']], $job['config'], $stop);
    if ($code === EXIT_RETRY) {
        $pause_until = (int) (SPOOL->load($job['id'])['transfer']['next_attempt_at'] ?? time() + 30);
        if (!$service) {
            break;
        }
    } elseif ($code !== 0 && !$stop) {
        record_failure($job['id'], "transfer process exited with code $code");
        $pause_until = time() + (int) CONFIG['spool']['backoff_min'];
    }
    if ($verbose) {
        worker_log(sprintf('job %s exited with %d after %.1fs', $job['id'], $code, microtime(true) - $started));
    }
}

worker_log('stopped');
exit(0);

function worker_log(string $message, bool $stderr_only = false): void
{
    $line = sprintf("[%s] [%d] %s\n", date('Y-m-d H:i:s'), getmypid(), preg_replace('/\s+/', ' ', trim($message)));
    $path = defined('CONFIG') ? (string) (CONFIG['spool']['log'] ?? '') : '';
    if ($stderr_only || $path === '' || @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, $line);
    } elseif ($GLOBALS['verbose'] ?? false) {
        fwrite(STDERR, $line);
    }
}

function interruptible_sleep(int $seconds, bool &$stop): void
{
    for ($i = 0; $i < $seconds * 10 && !$stop; $i++) {
        usleep(100000);
    }
}

function run_child(array $args, string|null $config, bool &$stop): int
{
    $env = getenv();
    if ($config !== null) {
        if (!is_file($config) || !str_ends_with($config, '.ini')) {
            worker_log("configuration $config does not exist");
            return 1;
        }
        $env['ANONUPLOAD_CONFIG_FILE_PATH'] = $config;
    }
    $cmd = [PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'display_errors=stderr', __FILE__, ...$args];
    if ($GLOBALS['verbose'] ?? false) {
        $cmd[] = '--verbose';
    }

    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, $env);
    if (!is_resource($proc)) {
        worker_log('failed to start a transfer process');
        return 1;
    }

    $terminated = false;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $code = $status['exitcode'];
            break;
        }
        if ($stop && !$terminated) {
            proc_terminate($proc, SIGTERM);
            $terminated = true;
        }
        usleep(200000);
    }
    proc_close($proc);
    return $code;
}

function next_job(): array|null
{
    $now = time();
    $best = null;
    foreach (SPOOL->records() as $r) {
        $t = $r['transfer'] ?? [];
        $due = (int) ($t['next_attempt_at'] ?? 0) <= $now && match ($r['state']) {
            Spool::QUEUED, Spool::TRANSFERRING => true,
            Spool::DELETED => !empty($t['upload_id']) && empty($t['completed']),
            Spool::FAILED => $now - (int) ($r['finished_at'] ?? $now) > FAILED_RETENTION,
            default => false,
        };
        if ($due && ($best === null || ($r['queued_at'] ?? $r['created_at']) < ($best['queued_at'] ?? $best['created_at']))) {
            $best = $r;
        }
    }
    return $best;
}

function backoff(int $attempts): int
{
    $min = (int) CONFIG['spool']['backoff_min'];
    $max = (int) CONFIG['spool']['backoff_max'];
    $delay = min($max, $min * 2 ** min(20, $attempts - 1));
    return max(1, (int) round($delay * (0.8 + mt_rand() / mt_getrandmax() * 0.4)));
}

function record_failure(string $id, string $error): array|null
{
    return SPOOL->update($id, function (array $r) use ($error) {
        if (!in_array($r['state'], [Spool::QUEUED, Spool::TRANSFERRING], true)) {
            return null;
        }
        $t = $r['transfer'] ?? [];
        $t['attempts'] = (int) ($t['attempts'] ?? 0) + 1;
        $t['last_error'] = mb_substr(preg_replace('/\s+/', ' ', $error), 0, 500);
        $t['last_error_at'] = time();
        if ($t['attempts'] >= (int) CONFIG['spool']['max_attempts']) {
            $r['state'] = Spool::FAILED;
            $r['finished_at'] = time();
        } else {
            $r['state'] = Spool::QUEUED;
            $t['next_attempt_at'] = time() + backoff($t['attempts']);
        }
        $r['transfer'] = $t;
        return $r;
    });
}

function run_job(string $id): int
{
    $record = SPOOL->load($id);
    if ($record === null || FILESTORAGE === null) {
        return 0;
    }
    $name = (string) ($record['file'] ?? '');
    $transfer = $record['transfer'] ?? [];

    if ($record['state'] === Spool::DELETED || $record['state'] === Spool::FAILED) {
        if ($record['state'] === Spool::FAILED && time() - (int) ($record['finished_at'] ?? time()) <= FAILED_RETENTION) {
            return 0;
        }
        try {
            FILESTORAGE->abort_transfer($name, $transfer);
        } catch (Throwable $e) {
            worker_log("$id: failed to abort the multipart upload of $name: {$e->getMessage()}");
            SPOOL->update($id, function (array $r) {
                $t = $r['transfer'] ?? [];
                $t['cleanup_attempts'] = (int) ($t['cleanup_attempts'] ?? 0) + 1;
                $t['next_attempt_at'] = time() + backoff($t['cleanup_attempts']);
                if ($t['cleanup_attempts'] >= (int) CONFIG['spool']['max_attempts']) {
                    unset($t['upload_id']);
                }
                $r['transfer'] = $t;
                return $r;
            });
            return EXIT_RETRY;
        }
        if ($record['state'] === Spool::FAILED) {
            SPOOL->remove($id);
            worker_log(sprintf('%s: removed %s, failed more than %d days ago', $id, $name, FAILED_RETENTION / 86400));
        } else {
            SPOOL->update($id, function (array $r) {
                unset($r['transfer']['upload_id']);
                return $r;
            });
        }
        return 0;
    }

    if (!in_array($record['state'], [Spool::QUEUED, Spool::TRANSFERRING], true)) {
        return 0;
    }

    $path = SPOOL->data_path($id);
    if (!Spool::is_file_name($name) || !is_file($path) || filesize($path) !== (int) $record['size']) {
        SPOOL->set_state($id, Spool::FAILED, ['error' => 'the spooled data is missing or has the wrong size']);
        worker_log("$id: $name: the spooled data is missing or has the wrong size, marked failed");
        return 0;
    }

    SPOOL->set_state($id, Spool::TRANSFERRING, ['transfer' => ['started_at' => time()] + $transfer]);
    $attempt = (int) ($transfer['attempts'] ?? 0) + 1;
    worker_log(sprintf('%s: transferring %s (%s, attempt %d)%s', $id, $name, format_size((int) $record['size']), $attempt, empty($transfer['upload_id']) ? '' : ", resuming {$transfer['upload_id']}"));

    $progress_keys = array_flip(['upload_id', 'part_size', 'parts', 'completed']);
    $state = array_intersect_key($transfer, $progress_keys);
    $checkpoint = function (array $s) use ($id, $progress_keys) {
        SPOOL->update($id, function (array $r) use ($s, $progress_keys) {
            $r['transfer'] = array_diff_key($r['transfer'] ?? [], $progress_keys) + $s;
            return $r;
        });
    };
    $should_continue = fn() => (SPOOL->load($id)['state'] ?? null) === Spool::TRANSFERRING;

    $options = new FileOptions();
    $options->content_type = $record['mime'] ?? null;
    $started = microtime(true);

    try {
        FILESTORAGE->transfer_file($name, $path, $options, $state, $checkpoint, $should_continue);
    } catch (TransferCancelledException $e) {
        worker_log("$id: $name was deleted during the transfer");
        try {
            FILESTORAGE->abort_transfer($name, $state);
            SPOOL->update($id, function (array $r) {
                unset($r['transfer']['upload_id']);
                return $r;
            });
        } catch (Throwable $e) {
            // the next run (or the stale multipart cleanup) retries
        }
        return 0;
    } catch (Throwable $e) {
        $r = record_failure($id, $e->getMessage());
        $t = $r['transfer'] ?? [];
        if (($r['state'] ?? null) === Spool::FAILED) {
            worker_log(sprintf('%s: %s FAILED after %d attempts, data kept for %d days: %s', $id, $name, $t['attempts'], FAILED_RETENTION / 86400, $e->getMessage()));
            try {
                FILESTORAGE->abort_transfer($name, $state);
                SPOOL->update($id, function (array $r) {
                    unset($r['transfer']['upload_id'], $r['transfer']['parts']);
                    return $r;
                });
            } catch (Throwable $e2) {
                // released when the failed upload expires, or by the stale multipart cleanup
            }
        } else {
            worker_log(sprintf('%s: %s attempt %d failed after %.1fs, retrying in %ds: %s', $id, $name, $t['attempts'] ?? $attempt, microtime(true) - $started, max(0, ($t['next_attempt_at'] ?? time()) - time()), $e->getMessage()));
        }
        return EXIT_RETRY;
    }

    $final = SPOOL->update($id, function (array $r) {
        if ($r['state'] !== Spool::TRANSFERRING) {
            return null;
        }
        $r['state'] = Spool::READY;
        $r['finished_at'] = time();
        return $r;
    });

    if (($final['state'] ?? null) !== Spool::READY) {
        worker_log("$id: $name was deleted during the transfer, removing it from the storage");
        SPOOL->unlink_file($name, $id);
        if (!FILESTORAGE->delete_file($name)) {
            worker_log("$id: failed to delete $name from the storage");
        }
        return 0;
    }

    SPOOL->unlink_file($name, $id);
    @unlink($path);

    $seconds = microtime(true) - $started;
    worker_log(sprintf('%s: %s is stored (%s in %.1fs, %.1f MiB/s, VmHWM %s)', $id, $name, format_size((int) $record['size']), $seconds, $record['size'] / 1048576 / max($seconds, 0.001), peak_rss()));

    generate_thumbnail($id, $name);
    return 0;
}

function generate_thumbnail(string $id, string $name): void
{
    if (THUMBNAILER === null) {
        return;
    }
    try {
        THUMBNAILER->generate_thumbnails([
            [
                'input_path' => THUMBNAILER instanceof S3ProxyThumbnailer ? $name : 'local://' . CONFIG['storage']['directory'] . "/$name",
                'width' => CONFIG['thumbnails']['width'],
                'height' => CONFIG['thumbnails']['height'],
            ]
        ]);
    } catch (Throwable $e) {
        worker_log("$id: failed to generate the thumbnail of $name: {$e->getMessage()}");
    }
}

function cleanup(bool $storage_too): void
{
    $now = time();
    $configs = [CONFIG_FILE_PATH => true];

    foreach (SPOOL->records() as $id => $r) {
        $configs[$r['config'] ?? CONFIG_FILE_PATH] = true;
        $idle = $now - (int) ($r['updated_at'] ?? 0);
        $finished = $now - (int) ($r['finished_at'] ?? $r['updated_at'] ?? 0);
        $remove = match ($r['state']) {
            Spool::UPLOADING => $idle > (($r['source'] ?? null) === 'form' ? min(3600, STALE_UPLOAD_TTL) : STALE_UPLOAD_TTL),
            Spool::READY, Spool::REJECTED, Spool::ABORTED => $finished > FINISHED_RECORD_TTL,
            Spool::DELETED => $finished > FINISHED_RECORD_TTL && empty($r['transfer']['upload_id']),
            default => false,
        };
        if ($remove) {
            SPOOL->remove($id);
            worker_log(sprintf('cleanup: removed %s upload %s (%s)', $r['state'], $id, $r['client_name'] ?? ''));
        }
    }

    $records = SPOOL->records();
    foreach (glob(SPOOL_DIR . '/data/*') ?: [] as $path) {
        if (!isset($records[basename($path)]) && $now - (int) @filemtime($path) > 3600) {
            @unlink($path);
            worker_log('cleanup: removed orphaned data ' . basename($path));
        }
    }
    foreach (array_merge(glob(SPOOL_DIR . '/tmp/*') ?: [], glob(SPOOL_DIR . '/state/*.tmp') ?: [], glob(SPOOL_DIR . '/files/*.tmp') ?: []) as $path) {
        if (is_file($path) && $now - (int) @filemtime($path) > STALE_UPLOAD_TTL) {
            @unlink($path);
        }
    }
    foreach (glob(SPOOL_DIR . '/state/*.{lock,busy}', GLOB_BRACE) ?: [] as $path) {
        if (!isset($records[basename(basename($path, '.lock'), '.busy')]) && $now - (int) @filemtime($path) > 3600) {
            @unlink($path);
        }
    }
    foreach (glob(SPOOL_DIR . '/files/*') ?: [] as $path) {
        $id = trim((string) @file_get_contents($path));
        if (!isset($records[$id]) || !in_array($records[$id]['state'], Spool::SPOOLED_STATES, true)) {
            if ($now - (int) @filemtime($path) > 3600) {
                @unlink($path);
            }
        }
    }

    $stamp = SPOOL_DIR . '/storage-cleanup.stamp';
    if ($storage_too || $now - (int) @filemtime($stamp) > STORAGE_CLEANUP_INTERVAL) {
        @touch($stamp);
        $stop = false;
        foreach (array_keys($configs) as $config) {
            run_child(['--cleanup-storage'], $config, $stop);
        }
    }
}

function cleanup_storage(): int
{
    if (!(FILESTORAGE instanceof S3FileStorage)) {
        return 0;
    }
    $in_use = [];
    foreach (SPOOL->records() as $r) {
        if (in_array($r['state'], [Spool::QUEUED, Spool::TRANSFERRING], true) && !empty($r['transfer']['upload_id'])) {
            $in_use[$r['transfer']['upload_id']] = true;
        }
    }
    try {
        foreach (FILESTORAGE->list_multipart_uploads(time() - STALE_MULTIPART_AGE) as $u) {
            if (isset($in_use[$u['upload_id']])) {
                continue;
            }
            FILESTORAGE->abort_multipart_upload($u['key'], $u['upload_id']);
            worker_log(sprintf('cleanup: aborted stale multipart upload %s of %s (started %s)', $u['upload_id'], $u['key'], date('Y-m-d H:i', $u['initiated'])));
        }
    } catch (Throwable $e) {
        worker_log('cleanup: could not list multipart uploads (storage unreachable?): ' . $e->getMessage());
        return EXIT_RETRY;
    }
    return 0;
}

function requeue(string $which): int
{
    $count = 0;
    foreach (SPOOL->records() as $id => $r) {
        if ($r['state'] !== Spool::FAILED || ($which !== 'all' && $which !== $id)) {
            continue;
        }
        if (!is_file(SPOOL->data_path($id))) {
            echo "$id: the data is gone, cannot retry\n";
            continue;
        }
        SPOOL->update($id, function (array $r) {
            $r['state'] = Spool::QUEUED;
            unset($r['finished_at'], $r['error']);
            $r['transfer'] = ['attempts' => 0, 'next_attempt_at' => 0] + ($r['transfer'] ?? []);
            return $r;
        });
        $count++;
    }
    echo "requeued $count upload(s)\n";
    return $count > 0 || $which === 'all' ? 0 : 1;
}

function print_status(): void
{
    $now = time();
    $usage = SPOOL->usage();
    printf("spool %s: %s on disk, %s reserved of %s, %s free on the filesystem\n", SPOOL_DIR, format_size($usage['actual']), format_size($usage['reserved']), format_size(SPOOL_MAX_BYTES), format_size((int) disk_free_space(SPOOL_DIR)));
    foreach (SPOOL->records() as $id => $r) {
        $t = $r['transfer'] ?? [];
        printf(
            "%s %-12s %10s %-24s age %6ds attempts %d%s%s\n",
            $id,
            $r['state'],
            format_size((int) $r['size']),
            $r['file'] ?? '-',
            $now - (int) $r['created_at'],
            $t['attempts'] ?? 0,
            isset($t['next_attempt_at']) && $t['next_attempt_at'] > $now ? sprintf(' next in %ds', $t['next_attempt_at'] - $now) : '',
            !empty($t['last_error']) ? " last error: {$t['last_error']}" : ''
        );
    }
}

function format_size(int $bytes): string
{
    return $bytes >= 1073741824 ? sprintf('%.2f GiB', $bytes / 1073741824) : sprintf('%.1f MiB', $bytes / 1048576);
}

function peak_rss(): string
{
    preg_match('/VmHWM:\s+(\d+)\s+kB/', (string) @file_get_contents('/proc/self/status'), $m);
    return isset($m[1]) ? sprintf('%.1f MiB', $m[1] / 1024) : '?';
}
