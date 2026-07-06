<?php declare(strict_types=1);

namespace Phalcon\Queue\Tests\Unit;

use Phalcon\Queue\Jobs\Status;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    public function testEnumBackingValues(): void
    {
        $this->assertSame('pending', Status::PENDING->value);
        $this->assertSame('processing', Status::PROCESSING->value);
        $this->assertSame('failed', Status::FAILED->value);
        $this->assertSame('completed', Status::COMPLETED->value);
        $this->assertSame('unknown', Status::UNKNOWN->value);
    }

    public function testFromBackingValue(): void
    {
        $this->assertSame(Status::COMPLETED, Status::from('completed'));
        $this->assertNull(Status::tryFrom('nope'));
    }

    public function testHasExactlyFiveCases(): void
    {
        $this->assertCount(5, Status::cases());
    }
}
