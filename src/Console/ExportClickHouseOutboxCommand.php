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
        $maxIterations = $input->getOption('once') === true ? 1 : max(0, (int) $input->getOption('max-iterations'));

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
