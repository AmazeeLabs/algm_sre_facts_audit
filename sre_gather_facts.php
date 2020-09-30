#!/bin/php
<?php

/**
 * This is the ALGM-SRE fact audit script - it simply runs commands, generates
 * key/value pairs, and feeds them to the appropriate endpoint
 *
 * Some notes on the design - it is designed to be a _single_ script, as well
 * as one that runs on anything with php > 5.4 - hence nothing too fancy
 *
 */

//TODO: Ensure that


/**
 * Register Gatherers
 * Each of these "Gatherers" is an anonymous function that, when called
 * will return a key/value array of pertinent facts
 */

$gatherers = [
//  'drush_pml' => function () {
//      $ret = null;
//      $output = null;
//      $lastline = exec('drush pml --format=json', $output, $ret);
//      if ($ret !== 0) {
//          throw new Exception("Could not run `drush pml`");
//      }
//
//      $jsonOutputString = implode('', $output);
//      $moduleData = json_decode($jsonOutputString, true);
//      if (json_last_error()) {
//          throw new Exception("Could not parse `drush pml` output");
//      }
//
//      return array_map(function ($e) {
//          return !empty($e['version']) ? $e['version'] : 'no version info';
//      }, $moduleData);
//  },
  'php-details' => function () {
      return ['php-version' => phpversion()];
  },
];


function isCliAvailable() {
    
}

function writeFactsToFactsDB($facts)
{
    //invariants
    //1 - we want to know the environment and

    $projectName = !empty(getenv('LAGOON_PROJECT')) ? getenv('LAGOON_PROJECT') : getenv('LAGOON_SAFE_PROJECT');
    $environmentName = !empty(getenv('LAGOON_ENVIRONMENT')) ? getenv('LAGOON_ENVIRONMENT') : getenv('LAGOON_GIT_BRANCH');

    if(empty($projectName) || empty($environmentName)) {
        return FALSE;
    }

    echo "$projectName:$environmentName\n";

    //2 - we want to ensure that the lagoon-cli is available


    foreach ($facts as $key => $value) {
//        echo(sprintf("%s:%s\n", $key, $value));
        //delete the fact if it already exists
        $responseOutput = [];
        $responseRetVal = 0;

        exec(sprintf('lagoon-cli fact delete -p %s -e %s -N "%s"', $projectName, $environmentName, $key), $responseOutput, $responseRetVal);
        print_r($responseOutput);
        print_r($responseRetVal);

        exec(sprintf('lagoon-cli fact add -p %s -e %s -N "%s" -V "%s"', $projectName, $environmentName, $key, $value), $responseOutput, $responseRetVal);
        print_r($responseOutput);

    }

}


$output = array_reduce($gatherers, function ($carry, $element) {
    try {
        return array_merge($carry, $element());
    } catch (Exception $exception) {
        fwrite(STDERR, $exception->getMessage());
        return $carry;
    }
}, []);

writeFactsToFactsDB($output);

//echo json_encode($output);