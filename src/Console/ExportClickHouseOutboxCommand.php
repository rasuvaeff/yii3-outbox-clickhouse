<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Console;

use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseExportResult;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExportRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drains the outbox into ClickHouse. Runs forever by default; use `--once` for a
 * single batch (e.g. from cron) or `--max-iterations` to bound the loop. Works in
 * any Symfony Console / `yiisoft/yii-console` application.
 *
 * `--max-iterations` accepts non-negative integers no greater than `PHP_INT_MAX`,
 * `0` meaning unlimited. Anything else exits with {@see Command::INVALID} rather
 * than being coerced — a coerced `-5` used to mean "run forever", and a cast of
 * an oversized digit string still means it. The option is validated before
 * `--once` is honoured, so a malformed value is never silently ignored.
 *
 * @api
 */
#[AsCommand(name: 'outbox:clickhouse:export', description: 'Export pending outbox messages to ClickHouse in batches')]
final class ExportClickHouseOutboxCommand extends Command
{
    public function __construct(private readonly ClickHouseOutboxExportRunner $runner)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run a single export batch and exit')
            ->addOption('max-iterations', null, InputOption::VALUE_REQUIRED, 'Stop after N iterations (0 = unlimited)', '0');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxIterationsOption = $input->getOption('max-iterations');

        if (!is_string($maxIterationsOption) || preg_match('/^\d+\z/', $maxIterationsOption) !== 1) {
            return $this->rejectMaxIterations($output);
        }

        // Leading zeros belong to the user, an overflow does not: normalize
        // them away, then round-trip through int. A digit string past
        // PHP_INT_MAX comes back clamped and no longer matches — the same
        // silent coercion `-5` used to get.
        $normalized = ltrim($maxIterationsOption, '0');
        $normalized = $normalized === '' ? '0' : $normalized;

        if ((string) (int) $normalized !== $normalized) {
            return $this->rejectMaxIterations($output);
        }

        $maxIterations = (int) $normalized;

        if ($input->getOption('once') === true) {
            $result = $this->runner->runOnce();
            $this->report($result, $output);

            return Command::SUCCESS;
        }

        $result = $this->runner->run(
            static fn(int $iteration): bool => $maxIterations === 0 || $iteration <= $maxIterations,
            static function (int $seconds): void {
                if ($seconds > 0) {
                    sleep($seconds);
                }
            },
        );

        $this->report($result, $output);

        return Command::SUCCESS;
    }

    private function rejectMaxIterations(OutputInterface $output): int
    {
        $output->writeln(sprintf(
            '<error>--max-iterations must be a non-negative integer no greater than %d (0 = unlimited)</error>',
            PHP_INT_MAX,
        ));

        return Command::INVALID;
    }

    private function report(ClickHouseExportResult $result, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            'Exported: published=%d retryScheduled=%d terminalFailed=%d skipped=%d groups=%d',
            $result->published,
            $result->retryScheduled,
            $result->terminalFailed,
            $result->skipped,
            $result->groupCount(),
        ));
    }
}
