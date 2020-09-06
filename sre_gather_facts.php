#!/bin/php
<?php

/**
 *
 */


/**
 * Register Gatherers
 * Each of these "Gatherers" is an anonymous function that, when called
 * will return a key/value array of pertinent facts
 */

$gatherers = [
  'drush_pml' => function() {
    $ret = null;
    $output = null;
    $lastline = exec('drush pml --format=json', $output, $ret);
    if($ret !== 0) {
      throw new Exception("Could not run `drush pml`");
    }

    $jsonOutputString = implode('', $output);
    $moduleData = json_decode($jsonOutputString, true);
    if(json_last_error()) {
      throw new Exception("Could not parse `drush pml` output");
    }

    return array_map(function($e) { return $e['version'];}, $moduleData);
  },
  'php-details' => function() {
    return ['php-version' => phpversion()];
  },
];


$output = array_reduce($gatherers, function($carry, $element) {
    try {
      return array_merge($carry, $element());
    } catch (Exception $exception) {
      fwrite(STDERR, $exception->getMessage());
      return $carry;
    }
  }, []);

echo json_encode($output);