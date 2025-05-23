<?php

declare(strict_types=1);

namespace Deployer;

require 'recipe/common.php';

use Symfony\Component\Console\Input\InputOption;

option('staging', null, InputOption::VALUE_NONE, 'Run staging commands');

set('bin/console', '{{bin/php}} {{release_or_current_path}}/bin/console');

set('keep_releases', getenv("KEEP_RELEASES") ?: 3);

set('allow_anonymous_stats', false);
set('default_timeout', 900);
set('writable_mode', 'chmod');
add('executable_files', ['bin/console']);

add('shared_dirs', [
    'config/jwt',
    'files',
    'var/log',
    'public/media',
    'public/sitemap',
    'public/thumbnail',
]);
add('create_shared_dirs', [
    'config/jwt',
    'files',
    'var/cache',
    'var/log',
    'public/media',
    'public/sitemap',
    'public/thumbnail',
]);
add('writable_dirs', [
    'var/cache',
    'var/log',
    'files',
    'public',
]);

add('shared_files', [
    '.env.local',
    'public/.htaccess',
    'install.lock',
]);

task('deploy:update_code')->setCallback(static function() {
    upload('.', '{{release_path}}', [
        'options' => [
            '--exclude=.git',
            '--exclude=.gitlab-ci',
            '--exclude=.gitlab-ci.yml',
            '--exclude=deploy.php',
            '--exclude=.deploy-base.php',
            '--exclude=.composer-cache',
        ],
    ]);
});

task('deploy', [
    'deploy:prepare',
    'sw:update',
    'sw:plugin_update',
    'sw:theme_compile',
    'deploy:publish',
]);

after('deploy:symlink', 'reload:workers_and_php');

task('reload:workers_and_php', [
    'php:reload',
    'sw:stop_message_workers'
 ]);
 

task('sw:update', static function() {
    run('cd {{release_path}} && {{bin/php}} bin/console system:update:finish --skip-asset-build');
});

task('sw:activate_staging', static function() {
    run('cd {{release_path}} && {{bin/php}} bin/console system:setup:staging');
});

task('sw:cache_clear', static function() {
    run('cd {{release_path}} && {{bin/php}} bin/console cache:clear --no-warmup');
});

task('sw:plugin_update', static function() {
    run('cd {{release_path}} && {{bin/php}} bin/console plugin:refresh');
    run('cd {{release_path}} && {{bin/php}} bin/console plugin:update:all');

    if(input()->hasOption('tag') && input()->getOption('tag')) {
        invoke('sw:activate_staging');
    }
});

task('php:reload', static function() {
    $maxclusterCluster = getenv("MAXCLUSTER_CLUSTER");
    $maxclusterNode = getenv("MAXCLUSTER_NODE");
    $token = getenv("MAXCLUSTER_PAT");

    if($token && $maxclusterCluster && $maxclusterNode){
        run("cluster-control php:reload --pa_token='$token' $maxclusterCluster $maxclusterNode");
    }
});

task('sw:stop_message_workers', static function() {
    run('cd {{release_path}} && {{bin/php}} bin/console messenger:stop-workers');
});

task('sw:theme_compile', function() {
    run('cd {{release_path}} && {{bin/php}} bin/console theme:refresh');
    run('cd {{release_path}} && {{bin/php}} bin/console theme:compile');
});

task('sw:cleanup_old_caches', static function() {
    $releases = get('releases_list');
    foreach (array_slice($releases, 1) as $release) {
        try {
            run("rm -rf {{deploy_path}}/releases/$release/var/cache/*");
        }catch (\Throwable){}
    }
});

after('deploy:cleanup', 'sw:cleanup_old_caches');
after('deploy:failed', 'deploy:unlock');


