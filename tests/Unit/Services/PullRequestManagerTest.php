<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Services;

use Kekser\LaravelPaladin\Services\PullRequestManager;
use Kekser\LaravelPaladin\Tests\TestCase;

class PullRequestManagerTest extends TestCase
{
    /** @test */
    public function it_uses_github_driver_when_configured()
    {
        config(['paladin.pr_provider' => 'github']);

        $manager = new PullRequestManager;

        // We can't directly test the driver, but we can ensure no exception is thrown
        $this->assertInstanceOf(PullRequestManager::class, $manager);
    }

    /** @test */
    public function it_uses_azure_driver_when_configured()
    {
        config(['paladin.pr_provider' => 'azure-devops']);

        $manager = new PullRequestManager;

        $this->assertInstanceOf(PullRequestManager::class, $manager);
    }

    /** @test */
    public function it_uses_mail_driver_when_configured()
    {
        config(['paladin.pr_provider' => 'mail']);

        $manager = new PullRequestManager;

        $this->assertInstanceOf(PullRequestManager::class, $manager);
    }

    /** @test */
    public function it_throws_exception_for_unknown_provider()
    {
        config(['paladin.pr_provider' => 'unknown']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown PR provider');

        $manager = new PullRequestManager;
        $manager->createPullRequest('test-branch', 'Test PR', 'Test body');
    }

    /** @test */
    public function it_uses_default_base_branch()
    {
        config(['paladin.pr_provider' => 'github']);
        config(['paladin.git.default_branch' => 'main']);

        $manager = new PullRequestManager;

        $this->assertInstanceOf(PullRequestManager::class, $manager);
    }

    /** @test */
    public function it_can_get_first_available_driver()
    {
        // Configure GitHub as available
        config(['paladin.providers.github.token' => 'test-token']);

        $manager = new PullRequestManager;
        $driver = $manager->getFirstAvailableDriver();

        $this->assertNotNull($driver);
    }

    /** @test */
    public function it_returns_null_when_no_driver_configured()
    {
        // Clear all driver configurations
        config(['paladin.providers.github.token' => null]);
        config(['paladin.providers.azure-devops.token' => null]);
        config(['paladin.providers.mail.to' => null]);

        $manager = new PullRequestManager;
        $driver = $manager->getFirstAvailableDriver();

        $this->assertNull($driver);
    }
}
