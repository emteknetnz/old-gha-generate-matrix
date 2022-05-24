<?php

const DB_MYSQL_57 = 'mysql57';
const DB_MYSQL_57_PDO = 'mysql57pdo';
const DB_MYSQL_80 = 'mysql80';
const DB_PGSQL = 'pgsql';

# Manually update this after each minor CMS release
$installerToPhpVersions = [
    '4.9' => [
        '7.1',
        '7.2',
        '7.3',
        '7.4'
    ],
    '4.10' => [
        '7.3',
        '7.4',
        '8.0',
    ],
    '4.11' => [
        '7.4',
        '8.0',
        '8.1',
    ],
    '4' => [
        '7.4',
        '8.0',
        '8.1',
    ],
];

function isLockedStepped($repo)
{
    return in_array($repo, [
        'silverstripe-admin',
        'silverstripe-asset-admin',
        'silverstripe-assets',
        'silverstripe-campaign-admin',
        'silverstripe-cms',
        'silverstripe-errorpage',
        'silverstripe-framework',
        'silverstripe-reports',
        'silverstripe-siteconfig',
        'silverstripe-versioned',
        'silverstripe-versioned-admin',
        // recipe-solr-search is not a true recipe, doesn't include recipe-cms/core
        'recipe-solr-search',
    ]);
}

function shouldNotRequireInstaller($repo)
{
    // these include recipe-cms/core, so we don't want to composer require installer
    // in .travis.yml they use the 'self' provision rather than 'standard'
    return in_array($repo, [
        'recipe-authoring-tools',
        'recipe-blog',
        'recipe-ccl',
        'recipe-cms',
        'recipe-collaboration',
        'recipe-content-blocks',
        'recipe-core',
        'recipe-form-building',
        'recipe-kitchen-sink',
        'recipe-reporting-tools',
        'recipe-services',
        'silverstripe-installer',
        // vendor-plugin is not a recipe, though we also do not want installer
        'vendor-plugin'
    ]);
}

function getInstallerVersion($repo, $branch)
{
    global $installerToPhpVersions;
    if (shouldNotRequireInstaller($repo)) {
        return '';
    }
    $v = explode('.', $branch);
    if (count($v) == 1) {
        return '4.x-dev';
    }
    if (isLockedStepped($repo)) {
        return '4' . $v[1] . 'x-dev';
    } else {
        // use the latest minor version of installer
        $a = array_keys($installerToPhpVersions);
        $a = array_map(fn($k) => (int) $k, $a);
        sort($a);
        return $a[count($a) - 1] . 'x-dev';
    }
}

function createJob($phpNum, $opts)
{
    global $installerToPhpVersions, $installerVersion;
    $phpVersions = $installerToPhpVersions[str_replace('.x-dev', '', $installerVersion)];
    $default = [
        'installer_version' => $installerVersion,
        'php' => $phpVersions[$phpNum] ?? $phpVersions[count($phpVersions) - 1],
        'db' => DB_MYSQL_57,
        'composer_args' => ''
    ];
    return array_merge($default, $opts);
}

// Reads inputs.yml and creates a new json matrix
$inputs = yaml_parse(file_get_contents('__inputs.yml'));

// $myRef will either be a branch for push (i.e cron) and pull-request (target branch), or a semver tag
$myRef = $inputs['github_my_ref'];
$isTag = preg_match('#^[0-9]+\.[0-9]+\.[0-9]+$#', $m);
$branch = $isTag ? sprintf('%d.%d', $m[1], $m[2]) : $myRef;

$installerVersion = getInstallerVersion($inputs['github_repository'], $branch);

$run = [];
$extraJobs = [];
$simpleMatrix = false;
$githubRepository = '';
foreach ($inputs as $input => $value) {
    if (preg_match('#^run_#', $input)) {
        if ($value === 'true' || $value === true) {
            $value = true;
        }
        if ($value === 'false' || $value === false) {
            $value = false;
        }
        // e.g. run_phplinting => phplinting
        $type = str_replace('run_', '', $input);
        $run[$type] = $value;
    } else if ($input === 'extra_jobs') {
        if ($value === 'none') {
            $value = [];
        }
        $extraJobs = $value;
    } else if ($input === 'simple_matrix') {
        $simpleMatrix = $value === 'true' || $value === true;
    } else if ($input === 'github_repository') {
        $githubRepository = $value;
    }
}
$matrix = ['include' => []];
if ((file_exists('phpunit.xml') || file_exists('phpunit.xml.dist')) && $run['phpunit']) {
    $d = new DOMDocument();
    $d->preserveWhiteSpace = false;
    $fn = file_exists('phpunit.xml') ? 'phpunit.xml' : 'phpunit.xml.dist';
    $d->load($fn);
    $x = new DOMXPath($d);
    $tss = $x->query('//testsuite');
    foreach ($tss as $ts) {
        if (!$ts->hasAttribute('name') || $ts->getAttribute('name') == 'Default') {
            continue;
        }
        if ($simpleMatrix) {
            $matrix['include'][] = createJob(0, [
                'phpunit' => true,
                'phpunit_suite' => $ts->getAttribute('name'),
            ]);
        } else {
            $matrix['include'][] = createJob(0, [
                'composer_args' => '--prefer-lowest',
                'phpunit' => true,
                'phpunit_suite' => $ts->getAttribute('name'),
            ]);
            $matrix['include'][] = createJob(1, [
                'db' => 'pgsql',
                'phpunit' => true,
                'phpunit_suite' => $ts->getAttribute('name')
            ]);
            $matrix['include'][] = createJob(3, [
                'db' => DB_MYSQL_80,
                'phpunit' => true,
                'phpunit_suite' => $ts->getAttribute('name')
            ]);
        }
    }
    if (count($matrix['include']) == 0) {
        if ($simpleMatrix) {
            $matrix['include'][] = createJob(0, [
                'phpunit' => true,
                'phpunit_suite' => 'all'
            ]);
        } else {
            $matrix['include'][] = createJob(0, [
                'composer_args' => '--prefer-lowest',
                'phpunit' => true,
                'phpunit_suite' => 'all'
            ]);
            $matrix['include'][] = createJob(1, [
                'db' => DB_PGSQL,
                'phpunit' => true,
                'phpunit_suite' => 'all'
            ]);
            $matrix['include'][] = createJob(3, [
                'db' => DB_MYSQL_80,
                'phpunit' => true,
                'phpunit_suite' => 'all'
            ]);
        }
    }
}
// skip phpcs on silverstripe-installer which include sample file for use in projects
if ((file_exists('phpcs.xml') || file_exists('phpcs.xml.dist')) && !preg_match('#/silverstripe-installer$#', $githubRepository)) {
    $matrix['include'][] = createJob(0, [
        'phplinting' => true
    ]);
}
// phpcoverage also runs unit tests
// always run on silverstripe account
// TODO: remove todo forcing it to run
if (true || $run['phpcoverage'] || preg_match('#^silverstripe/#', $githubRepository)) {
    $phpNum = $simpleMatrix ? 0 : 2;
    $matrix['include'][] = createJob($phpNum, [
        'db' => DB_PGSQL,
        'phpcoverage' => true
    ]);
}
// skip behat on silverstripe-installer which include sample file for use in projects
if (file_exists('behat.yml') && $run['endtoend'] && !preg_match('#/silverstripe-installer#', $githubRepository)) {
    $matrix['include'][] = createJob(0, [
        'endtoend' => true,
        'endtoend_suite' => 'root'
    ]);
    if (!$simpleMatrix) {
        $phpNum = 1;
        $matrix['include'][] = createJob($phpNum, [
            'db' => DB_MYSQL_57_PDO,
            'endtoend' => true,
            'endtoend_suite' => 'root'
        ]);
    }
}
// javascript tests
if (file_exists('package.json') && $run['js']) {
    $matrix['include'][] = createJob(0, [
        'js' => true
    ]);
}
foreach ($extraJobs as $arr) {
    $matrix['include'][] = createJob(0, $arr);
}
$json = json_encode($matrix);
$json = preg_replace("#\n +#", "\n", $json);
$json = str_replace("\n", '', $json);
echo trim($json);
