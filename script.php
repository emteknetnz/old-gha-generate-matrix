<?php
// Reads inputs.yml and creates a new json matrix
$inputs = yaml_parse(file_get_contents('__inputs.yml'));
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
        $matrix['include'][] = [
            'php' => '7.4',
            'db' => 'mysql57',
            'phpunit' => true,
            'phpunit_suite' => $ts->getAttribute('name')
        ];
        if (!$simpleMatrix) {
            $matrix['include'][] = [
                'php' => '8.0',
                'db' => 'mysql80',
                'phpunit' => true,
                'phpunit_suite' => $ts->getAttribute('name')
            ];
        }
    }
    if (count($matrix['include']) == 0) {
        $matrix['include'][] = [
            'php' => '7.4',
            'db' => 'mysql57',
            'phpunit' => true,
            'phpunit_suite' => 'all'
        ];
        if (!$simpleMatrix) {
            $matrix['include'][] = [
                'php' => '8.0',
                'db' => 'mysql80',
                'phpunit' => true,
                'phpunit_suite' => 'all'
            ];
        }
    }
}
// skip phpcs and behat on silverstripe-installer which include sample files for use in projects
if ((file_exists('phpcs.xml') || file_exists('phpcs.xml.dist')) && !preg_match('#/silverstripe-installer$#', $githubRepository)) {
    $matrix['include'][] = [
        'php' => '7.4',
        'db' => 'mysql57',
        'phplinting' => true
    ];
}
if ($run['phpcoverage'] || preg_match('#^silverstripe/#', $githubRepository)) {
    $matrix['include'][] = [
        'php' => '7.4',
        'db' => 'mysql57',
        'phpcoverage' => true
    ];
}
if (file_exists('behat.yml') && $run['endtoend'] && !preg_match('#/silverstripe-installer#', $githubRepository)) {
    // graphql 3
    $matrix['include'][] = [
        'php' => '7.4',
        'db' => 'mysql57',
        'endtoend' => true,
        'endtoend_suite' => 'root'
    ];
    if (!$simpleMatrix) {
        // graphql 4
        $matrix['include'][] = [
            'php' => '8.0',
            'db' => 'pgsql',
            'endtoend' => true,
            'endtoend_suite' => 'root'
        ];
    }
}
if (file_exists('package.json') && $run['js']) {
    $matrix['include'][] = [
        'php' => '7.4',
        'db' => 'mysql57',
        'js' => true
    ];
}
foreach ($extraJobs as $arr) {
    $matrix['include'][] = $arr;
}
$json = json_encode($matrix);
$json = preg_replace("#\n +#", "\n", $json);
$json = str_replace("\n", '', $json);
echo trim($json);
