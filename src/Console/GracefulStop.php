<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Console;

/**
 * A stop request the worker loop checks between batches, so that a `SIGTERM`
 * from Kubernetes or systemd ends the loop after the current batch is
 * acknowledged instead of killing it mid-flight and leaving the batch to
 * stale-claim recovery.
 *
 * {@see listenToSignals()} wires `SIGTERM` and `SIGINT` to {@see request()}
 * when `ext-pcntl` is loaded and reports whether it could; without the
 * extension the loop still stops on an explicit {@see request()}.
 *
 * @api
 */
final class GracefulStop
{
    // The POSIX numbers rather than the `SIGTERM` / `SIGINT` constants: those
    // only exist once ext-pcntl is loaded, and this class must compile — and
    // be analysed — without it.
    private const int SIGINT = 2;
    private const int SIGTERM = 15;

    private bool $requested = false;

    private bool $listening = false;

    public function request(): void
    {
        $this->requested = true;
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }

    /**
     * Whether {@see listenToSignals()} has been called — not whether it could
     * install anything; see its return value for that.
     */
    public function isListening(): bool
    {
        return $this->listening;
    }

    /**
     * @return bool whether handlers were installed — false without `ext-pcntl`
     */
    public function listenToSignals(): bool
    {
        $this->listening = true;

        if (!\function_exists('pcntl_signal')) {
            return false;
        }

        \pcntl_async_signals(true);
        \pcntl_signal(self::SIGTERM, $this->request(...));
        \pcntl_signal(self::SIGINT, $this->request(...));

        return true;
    }
}
