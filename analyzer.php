#!/usr/bin/env php

<?php

include(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chistach.php');

function usage()
{
    global $argv;
    $program = (isset($argv[0])) ? basename($argv[0]) : 'analyzer.php';
    print("USAGE: $program [OPTIONS] <root_directory> <start_file>\n");
    print("Options:\n");
    print(" --verbose     verbose output\n");
    print(" --cache=dir   custom cache folder\n");
    print(" --multi       multi file analyze\n");
    print(" --graph=path  build call graph\n");
    print(" --help        display help\n");
    die(1);
}

if (!isset($argv[2]))
{
    usage();
}

try
{
    ini_set('memory_limit', '2048M');

    $options = getopt('', ['verbose::', 'cache::', 'multi::', 'graph::', 'help::', 'root', 'start']);

    if (isset($options['help']))
        usage();

    $config = new ParseConfiguration();
    $config->verbose = isset($options['verbose']);
    $config->multiFile = isset($options['multi']);

    if (isset($options['cache']) && is_string($options['cache']) && strlen($options['cache']) > 0)
        $config->cacheDirectory = $options['cache'];

    $analyzer = new Analyzer($argv[count($argv) - 2], $argv[count($argv) - 1], $config);
    $analyzer->analyze();

    if (isset($options['graph']) && is_string($options['graph']) && strlen($options['graph']) > 0)
        $analyzer->generateGraphvizFile($options['graph']);

    $unusedFunctions = $analyzer->getUnusedFunctions();

    foreach ($unusedFunctions as $functionInfo)
        print("Unused function {$functionInfo->getFunctionName()} on line {$functionInfo->getLineNo()}\n");
}
catch (Exception $analyzerException)
{
    print($analyzerException->getMessage() . "\n");
    die(2);
}
