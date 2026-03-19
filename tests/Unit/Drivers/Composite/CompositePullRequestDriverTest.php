<?php

namespace Tests\Unit\Drivers\Composite;

use Kekser\LaravelPaladin\Contracts\PullRequestDriver;
use Kekser\LaravelPaladin\Pr\Drivers\Composite\CompositePullRequestDriver;
use Mockery;
use PHPUnit\Framework\TestCase;

class CompositePullRequestDriverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_it_delegates_to_all_drivers()
    {
        $driver1 = Mockery::mock(PullRequestDriver::class);
        $driver2 = Mockery::mock(PullRequestDriver::class);

        $driver1->shouldReceive('createPullRequest')
            ->with('branch', 'title', 'body', 'main')
            ->once()
            ->andReturn('url1');

        $driver2->shouldReceive('createPullRequest')
            ->with('branch', 'title', 'body', 'main')
            ->once()
            ->andReturn('url2');

        $composite = new CompositePullRequestDriver([$driver1, $driver2]);
        $result = $composite->createPullRequest('branch', 'title', 'body', 'main');

        $this->assertEquals('url1', $result);
    }

    public function test_it_returns_first_successful_url()
    {
        $driver1 = Mockery::mock(PullRequestDriver::class);
        $driver2 = Mockery::mock(PullRequestDriver::class);

        $driver1->shouldReceive('createPullRequest')->andReturn(null);
        $driver2->shouldReceive('createPullRequest')->andReturn('url2');

        $composite = new CompositePullRequestDriver([$driver1, $driver2]);
        $result = $composite->createPullRequest('branch', 'title', 'body', 'main');

        $this->assertEquals('url2', $result);
    }

    public function test_is_configured_returns_true_only_if_all_drivers_are_configured()
    {
        $driver1 = Mockery::mock(PullRequestDriver::class);
        $driver2 = Mockery::mock(PullRequestDriver::class);

        $driver1->shouldReceive('isConfigured')->andReturn(true);
        $driver2->shouldReceive('isConfigured')->andReturn(true);

        $composite = new CompositePullRequestDriver([$driver1, $driver2]);
        $this->assertTrue($composite->isConfigured());

        $driver3 = Mockery::mock(PullRequestDriver::class);
        $driver3->shouldReceive('isConfigured')->andReturn(false);

        $composite2 = new CompositePullRequestDriver([$driver1, $driver3]);
        $this->assertFalse($composite2->isConfigured());
    }

    public function test_is_configured_returns_false_if_no_drivers()
    {
        $composite = new CompositePullRequestDriver([]);
        $this->assertFalse($composite->isConfigured());
    }

    public function test_it_collects_errors_from_all_drivers()
    {
        $driver1 = Mockery::mock(PullRequestDriver::class);
        $driver2 = Mockery::mock(PullRequestDriver::class);

        $driver1->shouldReceive('getConfigurationErrors')->andReturn(['error1']);
        $driver2->shouldReceive('getConfigurationErrors')->andReturn(['error2', 'error3']);

        $composite = new CompositePullRequestDriver([$driver1, $driver2]);
        $errors = $composite->getConfigurationErrors();

        $this->assertEquals(['error1', 'error2', 'error3'], $errors);
    }
}
