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


const LAGOON_CLI = "lagoon";


/**
 * Register Gatherers
 * Each of these "Gatherers" is an anonymous function that, when called
 * will return a key/value array of pertinent facts
 */

$gatherers = [
      'drush_pml' => function () {
          $ret = null;
          $output = null;
          $lastline = exec('drush pml --format=json', $output, $ret);
          if ($ret !== 0) {
              throw new Exception("Could not run `drush pml`");
          }

          $jsonOutputString = implode('', $output);
          $moduleData = json_decode($jsonOutputString, true);
          if (json_last_error()) {
              throw new Exception("Could not parse `drush pml` output");
          }

          return array_map(function ($e) {
              return !empty($e['version']) ? $e['version'] : 'no version info';
          }, $moduleData);
      },
  'php-details' => function () {
      return ['php-version' => phpversion()];
  },
];


function isCliAvailable()
{
    $retVal = 0;
    $retOutput = [];

    exec(sprintf("which %s", LAGOON_CLI), $retOutput, $retVal);

    if ($retVal == 0) {
        return $retOutput[0];
    }
    return false;
}

function writeFactsToFactsDB($facts)
{

    $projectName = !empty(getenv('LAGOON_PROJECT')) ? getenv('LAGOON_PROJECT') : getenv('LAGOON_SAFE_PROJECT');
    $environmentName = !empty(getenv('LAGOON_ENVIRONMENT')) ? getenv('LAGOON_ENVIRONMENT') : getenv('LAGOON_GIT_BRANCH');

    if (empty($projectName) || empty($environmentName)) {
        return false;
    }

    if ($cli = isCliAvailable() === false) {
        return false;
    }


    foreach ($facts as $key => $value) {
        $responseOutput = [];
        $responseRetVal = 0;
        exec(sprintf('%s fact delete -p %s -e %s -N "%s" 2> /dev/null', LAGOON_CLI,
          $projectName, $environmentName, $key), $responseOutput,
          $responseRetVal);
        if($responseRetVal > 0) {
            var_dump($responseOutput);
        }
        exec(sprintf('%s fact add -p %s -e %s -N "%s" -V "%s" 2> /dev/null', LAGOON_CLI,
          $projectName, $environmentName, $key, $value), $responseOutput,
          $responseRetVal);
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

echo json_encode($output);