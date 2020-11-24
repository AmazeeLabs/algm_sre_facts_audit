#!/bin/php
<?php

define('LAGOON_API_ENDPOINT', 'https://api.lagoon.amazeeio.cloud/graphql');

$PROJECT_NAME = !empty(getenv('LAGOON_PROJECT')) ? getenv('LAGOON_PROJECT') : getenv('LAGOON_SAFE_PROJECT');
$ENVIRONMENT_NAME = getenv('LAGOON_GIT_BRANCH');

if (empty($PROJECT_NAME) || empty($ENVIRONMENT_NAME)) {
  throw new \Exception("COULD NOT GET PROJECT OR ENVIRONMENT VALUES");
}

/**
 * This is the actual driver of the process - it will gather facts, delete
 * old facts using the source mechanism, and bulk write new facts.
 */
function run() {
  global $PROJECT_NAME, $ENVIRONMENT_NAME;
  $gatheredFacts = [];
  foreach (getGatherers() as $gatherer => $gatherFunc) {
    try {
      $facts = $gatherFunc($gatheredFacts);
      $gatheredFacts[$gatherer] = [];
      foreach ($facts as $name => $value) {
        $gatheredFacts[$gatherer][$name] = $value;
      }
    } catch (\Exception $ex) {
      echo "Could not execute : $gatherer\n";
    }
  }


  try {
    $token = getToken();
    $projectId = getProjectID($token, $PROJECT_NAME);
    $DTAEnvironmentDetails = getEnvironmentId($token, $projectId,
      $ENVIRONMENT_NAME);

    // We delete any facts unassigned to source
    // TODO: this should come out after our first run!
    deleteFactsBySource($token, $DTAEnvironmentDetails->id, "");

    foreach ($gatheredFacts as $gathererName => $values) {
      deleteFactsBySource($token, $DTAEnvironmentDetails->id, $gathererName);
      writeFactsInBulk($token, $DTAEnvironmentDetails->id, $values,
        $gathererName);
    }

  } catch (Exception $ex) {
    printf("Unable to process facts: " . $ex->getMessage());
    exit(1);
  }
  echo "Run completed\n";
}


/**
 * Register Gatherers
 * Each of these "Gatherers" is an anonymous function that, when called
 * will return a key/value array of pertinent facts
 */

function getGatherers() {
  $gatherers = [
    'drush_status' => function ($existingData = []) {
      $ret = 0;
      $output = NULL;
      $lastline = exec('drush status --format=json 2> /dev/null', $output,
        $ret);
      if ($ret !== 0) {
        throw new Exception("Could not run `drush status`");
      }

      $jsonOutputString = implode('', $output);

      $statusData = json_decode($jsonOutputString, TRUE);
      if (json_last_error()) {
        throw new Exception("Could not parse `drush status` output");
      }

      $retArr = [];
      if (!empty($statusData['drupal-version'])) {
        $retArr['drupal-version'] = $statusData['drupal-version'];
      }
      if (!empty($statusData['drush-version'])) {
        $retArr['drush-version'] = $statusData['drush-version'];
      }

      return $retArr;
    },
    'drush_pml' => function ($existingData = []) {
      $ret = NULL;
      $output = NULL;
      $lastline = exec('drush pml --format=json 2> /dev/null', $output, $ret);
      if ($ret !== 0) {
        throw new Exception("Could not run `drush pml`");
      }

      $jsonOutputString = implode('', $output);
      $moduleData = json_decode($jsonOutputString, TRUE);
      if (json_last_error()) {
        throw new Exception("Could not parse `drush pml` output");
      }

      return array_map(function ($e) {
        return $e['version'];
      }, $moduleData);
    },
    'php-details' => function ($existingData = []) {
      return ['php-version' => phpversion()];
    },
    'drupal_node_count' => function ($existingData = []) {

      //we won't run this module unless we've got a drupal version
      if(empty($existingData['drush_status']['drupal-version'])) {
          throw new Exception("Dependencies for 'drupal_node_count' unmet - skipping");
      }

      $drupalVersion = explode('.', $existingData['drush_status']['drupal-version'])[0];

      $ret = 0;
      $output = NULL;
      if($drupalVersion == "7") {
        $lastline = exec('echo "select count(*) as \'thecount\' from node where status = 1;" | drush sql-cli | tail -n1 2>/dev/null', $output,
          $ret);
      } else {
        $lastline = exec('drush php-eval "echo count(\Drupal::entityQuery(\'node\')->condition(\'status\', 1)->execute())"', $output,
          $ret);
      }

      if ($ret !== 0) {
        throw new Exception("Could not run `drupal_node_count`");
      }

      $retArr['node-count'] = $output[0];

      //drush php-eval "print count(language_list());"

      $ret = 0;
      $output = NULL;
      if($drupalVersion == "7") {
        $lastline = exec('drush php-eval "print count(language_list());" 2>/dev/null', $output,
          $ret);
      } else {
        $lastline = exec('drush php-eval "echo count(Drupal::languageManager()->getLanguages());"', $output,
          $ret);
      }

      if ($ret !== 0) {
        throw new Exception("Could not run `drupal_node_count`");
      }

      $retArr['lang-count'] = $output[0];

      return $retArr;
    },
  ];
  return $gatherers;
}


/**
 * returns token for the GraphQL api
 *
 * @return string
 * @throws \Exception
 */
function getToken() {
  $fullOutput = NULL;
  $returnCode = NULL;
  $token = exec('ssh -p 32222 -t lagoon@ssh.lagoon.amazeeio.cloud token 2> /dev/null',
    $fullOutput, $returnCode);

  if ($returnCode !== 0) {
    throw new \Exception("Unable to get token");
  }
  return $token;
}

/**
 * Runs a query against the GraphQL api
 *
 * @param $token
 * @param $query
 * @param string $queryType
 *
 * @return mixed
 * @throws \Exception
 */
function callGraphQL($token, $query, $queryType = "query") {

  $curl = curl_init(LAGOON_API_ENDPOINT);

  $curl_post_data = [$queryType => $query];
  $data_string = json_encode($curl_post_data);

  curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
  curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($curl, CURLOPT_POST, TRUE);
  curl_setopt($curl, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Content-Length: ' . strlen($data_string),
      'Authorization: Bearer ' . $token,
    ]
  );

  $curl_response = curl_exec($curl);
  $info = "";
  if ($curl_response === FALSE) {
    $info = curl_getinfo($curl);
    curl_close($curl);
    die('error occured during curl exec. Additional info: ' . var_export($info));
  }
  curl_close($curl);
  $responseDecode = json_decode($curl_response);
  if (json_last_error() > 0) {
    throw new Exception("API decode error: " . json_last_error_msg());
  }
  return $responseDecode->data;
}

/**
 * @param $token
 * @param $projectName
 *
 * @return mixed
 */
function getProjectID($token, $projectName) {
  $query = "query getProjectId {
  projectByName(name:\"$projectName\") {
    id
  }
}";

  $reponse = callGraphQL($token, $query);
  return $reponse->projectByName->id;
}

/**
 * @param $token
 * @param $projectId
 * @param $environmentName
 *
 * @return mixed
 */
function getEnvironmentId($token, $projectId, $environmentName) {
  $query = "query getEnvironmentDetails {
  environmentByName(project:$projectId, name:\"$environmentName\") {
    id
    name
  }
}";

  $response = callGraphQL($token, $query);
  return $response->environmentByName;
}

/**
 * @param $token
 * @param $environmentId
 * @param $source
 *
 * @return mixed
 */
function deleteFactsBySource($token, $environmentId, $source) {
  $mutation = "mutation deletingfacts {
  deleteFactsFromSource(input:{environment:$environmentId, source:\"$source\"})
  }";
  $response = callGraphQL($token, $mutation);
  return $response->deleteFactsFromSource;
}


/**
 * @param $token
 * @param $environmentId
 * @param $facts
 * @param string $source
 *
 * @return mixed
 */
function writeFactsInBulk(
  $token,
  $environmentId,
  $facts,
  $source = "io-facts"
) {
  $factString = "";
  foreach ($facts as $factName => $factValue) {
    $factString .= "{environment: $environmentId, name:\"$factName\", value:\"$factValue\", source:\"$source\", description: \"\"},";
  }

  $query = "mutation addingfact {
  addFacts(input: {facts: [
    $factString
    ]}) {
    id
    name
    value
    source
  }
}";


  $response = callGraphQL($token, $query);
  return $response->addFacts;
}


run();