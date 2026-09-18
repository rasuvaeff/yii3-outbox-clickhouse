<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Double;

/**
 * What {@see FlakyStorage} throws: distinguishable from anything ClickHouse or
 * the router raises, so a test can assert the storage's own exception is the
 * one that reaches the caller.
 */
final class StorageDown extends \RuntimeException {}
