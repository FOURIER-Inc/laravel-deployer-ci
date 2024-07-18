<?php

namespace Deployer;

require 'contrib/rsync.php';
require 'recipe/laravel.php';

set('rsync', [
    ...get('rsync'),
    'exclude' => [
        '.git',
        '.github',
        'node_modules',
        'tests',
        'vendor',
        '.editorconfig',
        '.env.example',
        '.gitattributes',
        '.gitignore',
        'deploy.php',
        'package.json',
        'package-lock.json',
        'phpunit.xml',
        'README.md',
        'vite.config.js',
    ],
]);

set('rsync_src', $_SERVER['GITHUB_WORKSPACE']);
set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-scripts --optimize-autoloader --apcu-autoloader');

// Hosts
$hostname = $_SERVER['SERVER_NAME'];
$port = $_SERVER['SERVER_PORT'];
$user = $_SERVER['SERVER_USER'];
$path = $_SERVER['SERVER_PATH'];

host($hostname)
    ->setHostname($hostname)
    ->setPort($port)
    ->setRemoteUser($user)
    ->setDeployPath($path);

function overwriteEnvFile(string $filename, string $key_name, mixed $value): void
{
    $value = str_replace('/', '\/', $value);
    run("sed -i '' \"s/^$key_name=.*$/$key_name=$value/\" $filename");
}

function overwriteEnvFileWithEnv(string $filename, string $key_name, ?string $env_name = null): void
{
    $value = $_SERVER[$env_name ?? $key_name];
    overwriteEnvFile($filename, $key_name, $value);
}

desc('Make production .env file on local machine');
task('env:overwrite', function () {
    $filename = run('mktemp');

    $base = '{{release_path}}/'.$_SERVER['ENV_FILE'];
    run("cat $base > $filename");

    $app_key = run("grep '^APP_KEY=' {{deploy_path}}/shared/.env | cut -d '=' -f2-");
    if (! empty($app_key)) {
        overwriteEnvFile($filename, 'APP_KEY', $app_key);
    }

    overwriteEnvFileWithEnv($filename, 'DB_CONNECTION');
    overwriteEnvFileWithEnv($filename, 'DB_HOST');
    overwriteEnvFileWithEnv($filename, 'DB_PORT');
    overwriteEnvFileWithEnv($filename, 'DB_DATABASE');
    overwriteEnvFileWithEnv($filename, 'DB_USERNAME');
    overwriteEnvFileWithEnv($filename, 'DB_PASSWORD');
    overwriteEnvFileWithEnv($filename, 'MAIL_MAILER');
    overwriteEnvFileWithEnv($filename, 'MAIL_HOST');
    overwriteEnvFileWithEnv($filename, 'MAIL_PORT');
    overwriteEnvFileWithEnv($filename, 'MAIL_USERNAME');
    overwriteEnvFileWithEnv($filename, 'MAIL_PASSWORD');

    run("mv $filename {{deploy_path}}/shared/.env");
    run("rm -f $filename");
});

desc('key generate if not exists');
task('key:init', function () {
    $key = run("grep '^APP_KEY=' {{deploy_path}}/shared/.env | cut -d '=' -f2-");
    if (empty($key)) {
        artisan('key:generate')();
    }
});

// Tasks
desc('Prepares a new release');
task('deploy:prepare', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'rsync',
    'deploy:shared',
    'env:overwrite',
    'deploy:writable',
]);

desc('Deploys your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'artisan:storage:link',
    'key:init',
    'artisan:migrate',
    'artisan:optimize:clear',
    'artisan:optimize',
    'deploy:publish',
]);

after('deploy:failed', 'deploy:unlock');
