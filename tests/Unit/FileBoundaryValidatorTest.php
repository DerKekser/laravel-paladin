<?php

namespace Kekser\LaravelPaladin\Tests\Unit;

use Kekser\LaravelPaladin\Services\FileBoundaryValidator;
use Kekser\LaravelPaladin\Tests\TestCase;

class FileBoundaryValidatorTest extends TestCase
{
    protected FileBoundaryValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FileBoundaryValidator;
    }

    /** @test */
    public function it_identifies_vendor_files_as_external()
    {
        $this->assertFalse($this->validator->isProjectFile('vendor/laravel/framework/src/Illuminate/Foundation/Application.php'));
        $this->assertFalse($this->validator->isProjectFile('/vendor/phpunit/phpunit/src/Framework/TestCase.php'));
    }

    /** @test */
    public function it_identifies_node_modules_files_as_external()
    {
        $this->assertFalse($this->validator->isProjectFile('node_modules/vue/dist/vue.js'));
        $this->assertFalse($this->validator->isProjectFile('/node_modules/axios/index.js'));
    }

    /** @test */
    public function it_identifies_app_files_as_internal()
    {
        $this->assertTrue($this->validator->isProjectFile('app/Http/Controllers/UserController.php'));
        $this->assertTrue($this->validator->isProjectFile('/app/Models/User.php'));
        $this->assertTrue($this->validator->isProjectFile('database/migrations/2024_01_01_create_users_table.php'));
    }

    /** @test */
    public function it_handles_paths_with_leading_slashes()
    {
        $this->assertTrue($this->validator->isProjectFile('/app/Http/Controllers/HomeController.php'));
        $this->assertFalse($this->validator->isProjectFile('/vendor/symfony/console/Application.php'));
    }

    /** @test */
    public function it_analyzes_issues_with_mixed_files_as_fixable()
    {
        $affectedFiles = [
            'vendor/laravel/framework/src/Illuminate/Database/Connection.php',
            'app/Http/Controllers/UserController.php',
        ];

        $result = $this->validator->analyzeIssue($affectedFiles);

        $this->assertTrue($result['is_fixable']);
        $this->assertCount(1, $result['internal_files']);
        $this->assertCount(1, $result['external_files']);
        $this->assertNull($result['reason']);
    }

    /** @test */
    public function it_analyzes_issues_with_all_external_files_as_not_fixable()
    {
        $affectedFiles = [
            'vendor/laravel/framework/src/Illuminate/Database/Connection.php',
            'vendor/symfony/console/Application.php',
            'node_modules/vue/dist/vue.js',
        ];

        $result = $this->validator->analyzeIssue($affectedFiles);

        $this->assertFalse($result['is_fixable']);
        $this->assertCount(0, $result['internal_files']);
        $this->assertCount(3, $result['external_files']);
        $this->assertStringContainsString('outside project boundaries', $result['reason']);
    }

    /** @test */
    public function it_analyzes_issues_with_no_files_as_not_fixable()
    {
        $result = $this->validator->analyzeIssue([]);

        $this->assertFalse($result['is_fixable']);
        $this->assertStringContainsString('No affected files', $result['reason']);
    }

    /** @test */
    public function it_extracts_files_from_stack_trace()
    {
        $stackTrace = <<<'TRACE'
#0 /var/www/app/Http/Controllers/UserController.php(45): App\Services\UserService->createUser()
#1 /var/www/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\Http\Controllers\UserController->store()
#2 /var/www/app/Models/User.php(123): Illuminate\Database\Eloquent\Model->save()
TRACE;

        $files = $this->validator->extractFilesFromStackTrace($stackTrace);

        $this->assertCount(3, $files);

        // Check that files are extracted (normalize expectations to match actual normalization)
        $hasControllerFile = collect($files)->contains(fn ($f) => str_contains($f, 'app/Http/Controllers/UserController.php'));
        $hasVendorFile = collect($files)->contains(fn ($f) => str_contains($f, 'vendor/laravel/framework'));
        $hasModelFile = collect($files)->contains(fn ($f) => str_contains($f, 'app/Models/User.php'));

        $this->assertTrue($hasControllerFile, 'Should extract UserController.php');
        $this->assertTrue($hasVendorFile, 'Should extract vendor file');
        $this->assertTrue($hasModelFile, 'Should extract User.php');
    }

    /** @test */
    public function it_extracts_files_from_laravel_style_stack_trace()
    {
        $stackTrace = 'ErrorException: Undefined variable $user in /var/www/app/Http/Controllers/HomeController.php on line 42';

        $files = $this->validator->extractFilesFromStackTrace($stackTrace);

        $this->assertCount(1, $files);
        $this->assertTrue(
            collect($files)->contains(fn ($f) => str_contains($f, 'app/Http/Controllers/HomeController.php')),
            'Should extract HomeController.php from Laravel-style error message'
        );
    }

    /** @test */
    public function it_handles_empty_stack_traces()
    {
        $files = $this->validator->extractFilesFromStackTrace('');

        $this->assertEmpty($files);
    }

    /** @test */
    public function it_normalizes_file_paths_correctly()
    {
        // Both should be treated as the same file
        $this->assertTrue($this->validator->isProjectFile('app/Http/Controllers/TestController.php'));
        $this->assertTrue($this->validator->isProjectFile('/app/Http/Controllers/TestController.php'));
        $this->assertTrue($this->validator->isProjectFile('./app/Http/Controllers/TestController.php'));
    }

    /** @test */
    public function it_prioritizes_allowed_paths_over_excluded_paths()
    {
        // Create a validator with custom config that has both allowed and excluded paths
        config([
            'paladin.file_boundaries.allowed_paths' => ['app/vendor/'],
            'paladin.file_boundaries.excluded_paths' => ['vendor/'],
        ]);

        $validator = new FileBoundaryValidator;

        // app/vendor/SomeClass.php should be allowed because app/vendor/ is in allowed_paths
        // even though it contains 'vendor' which is in excluded_paths
        $this->assertTrue($validator->isProjectFile('app/vendor/SomeClass.php'));

        // Regular vendor file should still be excluded
        $this->assertFalse($validator->isProjectFile('vendor/laravel/framework/src/File.php'));

        // Other app/ files should still work normally (not in allowed paths, but not excluded)
        $this->assertTrue($validator->isProjectFile('app/Http/Controllers/TestController.php'));
    }
}
