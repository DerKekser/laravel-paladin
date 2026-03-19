<?php

use Illuminate\Support\Facades\File;
use Kekser\LaravelPaladin\Services\FileBoundaryValidator;

beforeEach(function () {
    $this->validator = new FileBoundaryValidator;
});

test('it identifies vendor files as external', function () {
    expect($this->validator->isProjectFile('vendor/laravel/framework/src/Illuminate/Foundation/Application.php'))->toBeFalse();
    expect($this->validator->isProjectFile('/vendor/phpunit/phpunit/src/Framework/TestCase.php'))->toBeFalse();
});

test('it identifies node modules files as external', function () {
    expect($this->validator->isProjectFile('node_modules/vue/dist/vue.js'))->toBeFalse();
    expect($this->validator->isProjectFile('/node_modules/axios/index.js'))->toBeFalse();
});

test('it identifies app files as internal', function () {
    expect($this->validator->isProjectFile('app/Http/Controllers/UserController.php'))->toBeTrue();
    expect($this->validator->isProjectFile('/app/Models/User.php'))->toBeTrue();
    expect($this->validator->isProjectFile('database/migrations/2024_01_01_create_users_table.php'))->toBeTrue();
});

test('it handles paths with leading slashes', function () {
    expect($this->validator->isProjectFile('/app/Http/Controllers/HomeController.php'))->toBeTrue();
    expect($this->validator->isProjectFile('/vendor/symfony/console/Application.php'))->toBeFalse();
});

test('it analyzes issues with mixed files as fixable', function () {
    $affectedFiles = [
        'vendor/laravel/framework/src/Illuminate/Database/Connection.php',
        'app/Http/Controllers/UserController.php',
    ];

    $result = $this->validator->analyzeIssue($affectedFiles);

    expect($result['is_fixable'])->toBeTrue();
    expect($result['internal_files'])->toHaveCount(1);
    expect($result['external_files'])->toHaveCount(1);
    expect($result['reason'])->toBeNull();
});

test('it analyzes issues with all external files as not fixable', function () {
    $affectedFiles = [
        'vendor/laravel/framework/src/Illuminate/Database/Connection.php',
        'vendor/symfony/console/Application.php',
        'node_modules/vue/dist/vue.js',
    ];

    $result = $this->validator->analyzeIssue($affectedFiles);

    expect($result['is_fixable'])->toBeFalse();
    expect($result['internal_files'])->toHaveCount(0);
    expect($result['external_files'])->toHaveCount(3);
    expect($result['reason'])->toContain('outside project boundaries');
});

test('it analyzes issues with no files as not fixable', function () {
    $result = $this->validator->analyzeIssue([]);

    expect($result['is_fixable'])->toBeFalse();
    expect($result['reason'])->toContain('No affected files');
});

test('it extracts files from stack trace', function () {
    $stackTrace = <<<'TRACE'
#0 /var/www/app/Http/Controllers/UserController.php(45): App\Services\UserService->createUser()
#1 /var/www/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\Http\Controllers\UserController->store()
#2 /var/www/app/Models/User.php(123): Illuminate\Database\Eloquent\Model->save()
TRACE;

    $files = $this->validator->extractFilesFromStackTrace($stackTrace);

    expect($files)->toHaveCount(3);

    // Check that files are extracted
    $hasControllerFile = collect($files)->contains(fn ($f) => str_contains($f, 'app/Http/Controllers/UserController.php'));
    $hasVendorFile = collect($files)->contains(fn ($f) => str_contains($f, 'vendor/laravel/framework'));
    $hasModelFile = collect($files)->contains(fn ($f) => str_contains($f, 'app/Models/User.php'));

    expect($hasControllerFile)->toBeTrue('Should extract UserController.php');
    expect($hasVendorFile)->toBeTrue('Should extract vendor file');
    expect($hasModelFile)->toBeTrue('Should extract User.php');
});

test('it extracts files from laravel style stack trace', function () {
    $stackTrace = 'ErrorException: Undefined variable $user in /var/www/app/Http/Controllers/HomeController.php on line 42';

    $files = $this->validator->extractFilesFromStackTrace($stackTrace);

    expect($files)->toHaveCount(1);
    expect(collect($files)->contains(fn ($f) => str_contains($f, 'app/Http/Controllers/HomeController.php')))
        ->toBeTrue('Should extract HomeController.php from Laravel-style error message');
});

test('it handles empty stack traces', function () {
    $files = $this->validator->extractFilesFromStackTrace('');

    expect($files)->toBeEmpty();
});

test('it normalizes file paths correctly', function () {
    // Both should be treated as the same file
    expect($this->validator->isProjectFile('app/Http/Controllers/TestController.php'))->toBeTrue();
    expect($this->validator->isProjectFile('/app/Http/Controllers/TestController.php'))->toBeTrue();
    expect($this->validator->isProjectFile('./app/Http/Controllers/TestController.php'))->toBeTrue();
});

test('it prioritizes allowed paths over excluded paths', function () {
    // Create a validator with custom config that has both allowed and excluded paths
    config([
        'paladin.file_boundaries.allowed_paths' => ['app/vendor/'],
        'paladin.file_boundaries.excluded_paths' => ['vendor/'],
    ]);

    $validator = new FileBoundaryValidator;

    // app/vendor/SomeClass.php should be allowed because app/vendor/ is in allowed_paths
    // even though it contains 'vendor' which is in excluded_paths
    expect($validator->isProjectFile('app/vendor/SomeClass.php'))->toBeTrue();

    // Regular vendor file should still be excluded
    expect($validator->isProjectFile('vendor/laravel/framework/src/File.php'))->toBeFalse();

    // Other app/ files should still work normally (not in allowed paths, but not excluded)
    expect($validator->isProjectFile('app/Http/Controllers/TestController.php'))->toBeTrue();
});

test('it parses gitignore patterns correctly', function () {
    $gitignoreContent = <<<'GITIGNORE'
# Comment
vendor/
node_modules/
*.log
!not_ignored.php
GITIGNORE;

    File::shouldReceive('exists')->with(base_path('.gitignore'))->andReturn(true);
    File::shouldReceive('get')->with(base_path('.gitignore'))->andReturn($gitignoreContent);

    $validator = new FileBoundaryValidator;

    expect($validator->isProjectFile('test.log'))->toBeFalse();
    expect($validator->isProjectFile('storage/logs/laravel.log'))->toBeFalse();
    expect($validator->isProjectFile('app/Models/User.php'))->toBeTrue();
});

test('it handles non existent gitignore', function () {
    File::shouldReceive('exists')->with(base_path('.gitignore'))->andReturn(false);

    $validator = new FileBoundaryValidator;
    expect($validator->isProjectFile('app/Models/User.php'))->toBeTrue();
});

test('it matches gitignore patterns from root', function () {
    $gitignoreContent = "/root_only.php\n/build/";
    File::shouldReceive('exists')->with(base_path('.gitignore'))->andReturn(true);
    File::shouldReceive('get')->with(base_path('.gitignore'))->andReturn($gitignoreContent);

    $validator = new FileBoundaryValidator;

    expect($validator->isProjectFile('root_only.php'))->toBeFalse();
    expect($validator->isProjectFile('subdir/root_only.php'))->toBeTrue();
    expect($validator->isProjectFile('build/app.js'))->toBeFalse();
});
