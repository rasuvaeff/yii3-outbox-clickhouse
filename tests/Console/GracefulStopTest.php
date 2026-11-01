<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Console;

use Rasuvaeff\Yii3OutboxClickHouse\Console\GracefulStop;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(GracefulStop::class)]
final class GracefulStopTest
{
    public function isNotRequestedUntilAsked(): void
    {
        $stop = new GracefulStop();

        Assert::false($stop->isRequested());

        $stop->request();

        Assert::true($stop->isRequested());
    }

    public function requestingTwiceIsIdempotent(): void
    {
        $stop = new GracefulStop();
        $stop->request();
        $stop->request();

        Assert::true($stop->isRequested());
    }

    public function listeningReportsWhetherSignalHandlersCouldBeInstalled(): void
    {
        $stop = new GracefulStop();
        Assert::false($stop->isListening());

        $installed = $stop->listenToSignals();

        Assert::true($stop->isListening());
        Assert::same($installed, \function_exists('pcntl_signal'));
        Assert::false($stop->isRequested());

        if ($installed && \function_exists('posix_kill')) {
            // With the handlers in place, the real signal is the request.
            \posix_kill(\posix_getpid(), \SIGTERM);
            \pcntl_signal_dispatch();

            Assert::true($stop->isRequested());

            \pcntl_signal(\SIGTERM, \SIG_DFL);
            \pcntl_signal(\SIGINT, \SIG_DFL);
        }
    }

    public function sigtermIsDeliveredWithoutAnExplicitDispatch(): void
    {
        $stop = new GracefulStop();

        if (!$stop->listenToSignals() || !\function_exists('posix_kill')) {
            Assert::false($stop->isRequested());

            return;
        }

        \pcntl_async_signals(false);
        $stop->listenToSignals();

        Assert::true(\pcntl_async_signals());

        \posix_kill(\posix_getpid(), \SIGTERM);

        Assert::true($stop->isRequested());

        \pcntl_signal(\SIGTERM, \SIG_DFL);
        \pcntl_signal(\SIGINT, \SIG_DFL);
    }

    public function sigintIsARequestToo(): void
    {
        $stop = new GracefulStop();

        if (!$stop->listenToSignals() || !\function_exists('posix_kill')) {
            Assert::false($stop->isRequested());

            return;
        }

        \posix_kill(\posix_getpid(), \SIGINT);

        Assert::true($stop->isRequested());

        \pcntl_signal(\SIGTERM, \SIG_DFL);
        \pcntl_signal(\SIGINT, \SIG_DFL);
    }
}
