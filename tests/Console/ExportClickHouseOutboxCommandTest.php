<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Console;

use Psr\Clock\ClockInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExportRunner;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;
use Rasuvaeff\Yii3OutboxClickHouse\Console\ExportClickHouseOutboxCommand;
use Rasuvaeff\Yii3OutboxClickHouse\Console\GracefulStop;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\HookedWriterFactory;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingWriterFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ExportClickHouseOutboxCommand::class)]
final class ExportClickHouseOutboxCommandTest
{
    private InMemoryStorage $storage;

    private CommandTester $tester;

    private GracefulStop $stop;

    /** @var list<int> */
    private array $slept = [];

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->stop = new GracefulStop();
        $this->slept = [];
        $this->tester = $this->tester(new RecordingWriterFactory());
    }

    /**
     * @param ?\Closure(int): void $sleep
     */
    private function tester(
        ClickHouseWriterFactoryInterface $factory,
        int $idleSleepSeconds = 0,
        int $busySleepSeconds = 0,
        int $maxAttempts = 3,
        ?\Closure $sleep = null,
    ): CommandTester {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-06-11 12:10:00');
            }
        };

        $exporter = new ClickHouseOutboxExporter(
            storage: $this->storage,
            router: new MapClickHouseMessageRouter(routes: ['ab.exposure' => ['table' => 't', 'columns' => ['event_id', 'experiment']]]),
            retryPolicy: new RetryPolicy(maxAttempts: $maxAttempts, delaySeconds: 30),
            clock: $clock,
            writerFactory: $factory,
            fetchLimit: 1,
        );

        return new CommandTester(new ExportClickHouseOutboxCommand(
            new ClickHouseOutboxExportRunner($exporter, idleSleepSeconds: $idleSleepSeconds, busySleepSeconds: $busySleepSeconds),
            $this->stop,
            $sleep ?? function (int $seconds): void {
                $this->slept[] = $seconds;
            },
        ));
    }

    public function onceRunsExactlyOneIteration(): void
    {
        $this->seed(3);

        $exit = $this->tester->execute(['--once' => true, '--max-iterations' => '3']);

        Assert::same($exit, 0);
        Assert::true(str_contains($this->tester->getDisplay(), 'published=1'));
        Assert::count($this->storage->findPending(), 2);
    }

    public function maxIterationsRunsExactlyThatMany(): void
    {
        $this->seed(3);

        $exit = $this->tester->execute(['--max-iterations' => '2']);

        Assert::same($exit, 0);
        Assert::count($this->storage->findPending(), 1);
    }

    public function maxIterationsOfOneRunsExactlyOneIteration(): void
    {
        $this->seed(3);

        $exit = $this->tester->execute(['--max-iterations' => '1']);

        Assert::same($exit, 0);
        Assert::count($this->storage->findPending(), 2);
        Assert::true(str_contains($this->tester->getDisplay(), 'published=1'));
    }

    public function rejectsNegativeMaxIterations(): void
    {
        // max(0, -5) used to mean "run forever" — the loop bound silently
        // became unlimited for a value the user meant as a limit.
        $exit = $this->tester->execute(['--max-iterations' => '-5']);

        Assert::same($exit, Command::INVALID);
        Assert::string($this->tester->getDisplay())->contains('must be a non-negative integer');
    }

    public function rejectsNonNumericMaxIterations(): void
    {
        $exit = $this->tester->execute(['--max-iterations' => 'many']);

        Assert::same($exit, Command::INVALID);
    }

    public function rejectsNegativeMaxIterationsEvenWithOnce(): void
    {
        // `--once` used to return before the option was looked at, so a value
        // the command documents as invalid exited SUCCESS and drained a batch.
        $this->seed(3);

        $exit = $this->tester->execute(['--once' => true, '--max-iterations' => '-5']);

        Assert::same($exit, Command::INVALID);
        Assert::count($this->storage->findPending(), 3);
    }

    public function rejectsMaxIterationsAbovePhpIntMax(): void
    {
        // Digits alone are not enough: the cast clamps anything past
        // PHP_INT_MAX to PHP_INT_MAX, which is the coercion this option rejects.
        $exit = $this->tester->execute(['--max-iterations' => '9223372036854775808']);

        Assert::same($exit, Command::INVALID);
        Assert::string($this->tester->getDisplay())->contains('must be a non-negative integer');
    }

    public function acceptsMaxIterationsWithLeadingZeros(): void
    {
        $this->seed(3);

        $exit = $this->tester->execute(['--max-iterations' => '02']);

        Assert::same($exit, 0);
        Assert::count($this->storage->findPending(), 1);
    }

    public function reportsZeroOnEmptyStorage(): void
    {
        $exit = $this->tester->execute(['--once' => true]);

        Assert::same($exit, 0);
        Assert::true(str_contains($this->tester->getDisplay(), 'published=0'));
    }

    private function seed(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->storage->save(new OutboxMessage(
                id: 'm' . $i,
                type: 'ab.exposure',
                payload: '{"experiment":"x"}',
                status: OutboxStatus::Pending,
                createdAt: new \DateTimeImmutable('2026-06-11 12:0' . $i . ':00'),
            ));
        }
    }

    // --- exit code ----------------------------------------------------------

    public function terminalFailuresStillExitZeroByDefault(): void
    {
        $this->seed(1);
        $tester = $this->tester(new RecordingWriterFactory(['t' => new ClickHouseWriteException('down')]), maxAttempts: 1);

        Assert::same($tester->execute(['--max-iterations' => '1']), Command::SUCCESS);
        Assert::true(str_contains($tester->getDisplay(), 'terminalFailed=1'));
    }

    public function failOnErrorExitsNonZeroWhenABatchTerminatedAMessage(): void
    {
        $this->seed(1);
        $tester = $this->tester(new RecordingWriterFactory(['t' => new ClickHouseWriteException('down')]), maxAttempts: 1);

        Assert::same($tester->execute(['--max-iterations' => '1', '--fail-on-error' => true]), Command::FAILURE);
    }

    public function failOnErrorRemembersATerminalFailureFromAnEarlierBatch(): void
    {
        // Batch 1 terminates m1 (attempts spent on the outage), batch 2 is
        // empty: the last result is clean, the run was not.
        $this->seed(1);
        $tester = $this->tester(new RecordingWriterFactory(['t' => new ClickHouseWriteException('down')]), maxAttempts: 1);

        Assert::same($tester->execute(['--max-iterations' => '2', '--fail-on-error' => true]), Command::FAILURE);
        Assert::true(str_contains($tester->getDisplay(), 'terminalFailed=0'));
    }

    public function failOnErrorIgnoresRetriesAndExitsZeroOnACleanRun(): void
    {
        $this->seed(2);
        $tester = $this->tester(new RecordingWriterFactory(['t' => new ClickHouseWriteException('down')]), maxAttempts: 3);

        // Two retries scheduled, nothing terminated: a ClickHouse outage is
        // not an error of this run.
        Assert::same($tester->execute(['--max-iterations' => '2', '--fail-on-error' => true]), Command::SUCCESS);

        $this->storage = new InMemoryStorage();
        $this->seed(1);
        $clean = $this->tester(new RecordingWriterFactory());

        Assert::same($clean->execute(['--max-iterations' => '1', '--fail-on-error' => true]), Command::SUCCESS);
    }

    public function failOnErrorAppliesToOnceAsWell(): void
    {
        $this->seed(1);
        $tester = $this->tester(new RecordingWriterFactory(['t' => new ClickHouseWriteException('down')]), maxAttempts: 1);

        Assert::same($tester->execute(['--once' => true, '--fail-on-error' => true]), Command::FAILURE);

        $this->storage = new InMemoryStorage();
        $this->seed(1);
        $clean = $this->tester(new RecordingWriterFactory());

        Assert::same($clean->execute(['--once' => true, '--fail-on-error' => true]), Command::SUCCESS);
        Assert::same($clean->execute(['--once' => true]), Command::SUCCESS);
    }

    // --- graceful stop ------------------------------------------------------

    public function aStopRequestEndsTheLoopAfterTheCurrentBatch(): void
    {
        $this->seed(3);
        // The "signal" arrives while the second batch is being written.
        $tester = $this->tester(new HookedWriterFactory(function (int $call): void {
            if ($call === 2) {
                $this->stop->request();
            }
        }));

        $exit = $tester->execute([]);

        Assert::same($exit, Command::SUCCESS);
        Assert::same($this->storage->getById('m1')?->getStatus(), OutboxStatus::Published);
        // The batch in flight when the request came was finished and
        // acknowledged...
        Assert::same($this->storage->getById('m2')?->getStatus(), OutboxStatus::Published);
        // ...and no further batch was started.
        Assert::same($this->storage->getById('m3')?->getStatus(), OutboxStatus::Pending);
        Assert::true(str_contains($tester->getDisplay(), 'Stopped on signal after the current batch'));
    }

    public function aStopRequestedBeforeTheFirstBatchRunsNothing(): void
    {
        $this->seed(1);
        $this->stop->request();

        $exit = $this->tester->execute([]);

        Assert::same($exit, Command::SUCCESS);
        Assert::same($this->storage->getById('m1')?->getStatus(), OutboxStatus::Pending);
        Assert::true(str_contains($this->tester->getDisplay(), 'published=0'));
    }

    public function withoutAStopRequestNothingIsPrintedAboutSignals(): void
    {
        $this->seed(1);

        $this->tester->execute(['--max-iterations' => '1']);

        Assert::false(str_contains($this->tester->getDisplay(), 'Stopped on signal'));
    }

    public function theLoopListensToSignalsButOnceDoesNot(): void
    {
        $this->tester->execute(['--once' => true]);
        Assert::false($this->stop->isListening());

        $this->tester->execute(['--max-iterations' => '1']);
        Assert::true($this->stop->isListening());
    }

    public function sleepsOneSecondAtATimeBetweenBatches(): void
    {
        $this->seed(1);
        $tester = $this->tester(new RecordingWriterFactory(), idleSleepSeconds: 3, busySleepSeconds: 2);

        $tester->execute(['--max-iterations' => '3']);

        // busy (2 s) after the batch that exported m1, idle (3 s) after the
        // empty one, nothing after the last.
        Assert::same($this->slept, [1, 1, 1, 1, 1]);
    }

    public function aStopRequestCutsTheSleepShort(): void
    {
        $this->seed(1);
        $sleeps = 0;
        $tester = $this->tester(
            new RecordingWriterFactory(),
            idleSleepSeconds: 5,
            busySleepSeconds: 5,
            sleep: function (int $seconds) use (&$sleeps): void {
                $sleeps += $seconds;

                if ($sleeps === 2) {
                    $this->stop->request();
                }
            },
        );

        $tester->execute([]);

        // Two of the five seconds were slept; the request ended the pause and
        // the loop without a further batch.
        Assert::same($sleeps, 2);
        Assert::same($this->storage->getById('m1')?->getStatus(), OutboxStatus::Published);
    }

    public function aZeroSleepNeverCallsTheSleeper(): void
    {
        $this->seed(2);

        $this->tester->execute(['--max-iterations' => '3']);

        Assert::same($this->slept, []);
    }
}
