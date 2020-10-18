#!/bin/php
<?php

define('LAGOON_API_ENDPOINT', 'https://api.lagoon.amazeeio.cloud/graphql');

$PROJECT_NAME = !empty(getenv('LAGOON_PROJECT')) ? getenv('LAGOON_PROJECT') : getenv('LAGOON_SAFE_PROJECT');
$ENVIRONMENT_NAME = getenv('LAGOON_GIT_BRANCH');

if (empty($PROJECT_NAME) || empty($ENVIRONMENT_NAME)) {
    throw new \Exception("COULD NOT GET PROJECT OR ENVIRONMENT VALUES");
}

/**
 * Register Gatherers
 * Each of these "Gatherers" is an anonymous function that, when called
 * will return a key/value array of pertinent facts
 */

$gatherers = [
  'drush_status' => function () {
      $ret = 0;
      $output = null;
      $lastline = exec('drush status --format=json 2> /dev/null', $output, $ret);
      if ($ret !== 0) {
          throw new Exception("Could not run `drush status`");
      }

      $jsonOutputString = implode('', $output);

      $statusData = json_decode($jsonOutputString, true);
      if (json_last_error()) {
          throw new Exception("Could not parse `drush status` output");
      }

      $retArr = [];
      if(!empty($statusData['drupal-version'])) {
          $retArr['drupal-version'] = $statusData['drupal-version'];
      }
      if(!empty($statusData['drush-version'])) {
          $retArr['drush-version'] = $statusData['drush-version'];
      }

    return $retArr;
  },
//  'drush_pml' => function () {
//      $ret = null;
//      $output = null;
//      $lastline = exec('drush pml --format=json 2> /dev/null', $output, $ret);
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
//          return $e['version'];
//      }, $moduleData);
//  },
  'php-details' => function () {
      return ['php-version' => phpversion()];
  },
];


/**
 * The following code will delete/read/write to the facts DB
 * Note, all of this must be replaced by the Lagoon CLI at some point
 */

function getToken()
{
    $fullOutput = null;
    $returnCode = null;
    $token = exec('ssh -p 32222 -t lagoon@ssh.lagoon.amazeeio.cloud token 2> /dev/null',
      $fullOutput, $returnCode);

    if ($returnCode !== 0) {
        throw new \Exception("Unable to get token");
    }
    return $token;
}

function callGraphQL($token, $query, $queryType = "query")
{

    $curl = curl_init(LAGOON_API_ENDPOINT);

    $curl_post_data = [$queryType => $query];
    $data_string = json_encode($curl_post_data);

    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data_string),
        'Authorization: Bearer ' . $token,
      ]
    );

    $curl_response = curl_exec($curl);
    $info = "";
    if ($curl_response === false) {
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

function getProjectID($token, $projectName)
{
    $query = "query getProjectId {
  projectByName(name:\"$projectName\") {
    id
  }
}";

    $reponse = callGraphQL($token, $query);
    return $reponse->projectByName->id;
}

function getEnvironmentIdAndFacts($token, $projectId, $environmentName)
{
    $query = "query getEnvironmentDetails {
  environmentByName(project:$projectId, name:\"$environmentName\") {
    id
    name
    facts {
      id
      name
      value
    }
  }
}";

    $response = callGraphQL($token, $query);
    return $response->environmentByName;
}


function deleteFact($token, $environmentId, $factName)
{
    $mutation = "mutation deletingfacts {
  deleteFact(input:{environment:$environmentId, name:\"$factName\"})
  }";
    $response = callGraphQL($token, $mutation);
    return $response->deleteFact;
}

function writeFact($token, $environmentId, $factName, $factValue)
{
    $query = "mutation addingfact {
  addFact(input: {environment: $environmentId, name:\"$factName\", value:\"$factValue\"}) {
    id
    name
    value
  }
}";

    $response = callGraphQL($token, $query);
    return $response->addFact;
}

$gatheredFacts = array_reduce($gatherers, function ($carry, $element) {
    try {
        return array_merge($carry, $element());
    } catch (Exception $exception) {
        fwrite(STDERR, $exception->getMessage());
        return $carry;
    }
}, []);


try {
    $token = getToken();
    $projectId = getProjectID($token, $PROJECT_NAME);
    $DTAEnvironmentDetails = getEnvironmentIdAndFacts($token, $projectId,
      $ENVIRONMENT_NAME);

    $existentFacts = array_reduce($DTAEnvironmentDetails->facts, function ($carry, $e) {
        $carry[$e->name] = $e;
        return $carry;
    }, []);

    foreach ($gatheredFacts as $factName => $factValue) {
        if(in_array($factName, array_keys($existentFacts))) {
            deleteFact($token, $DTAEnvironmentDetails->id, $factName);
        }
        //TODO: do we need to check this write was successful over and above
        // not having an error thrown by the called functions?
        writeFact($token, $DTAEnvironmentDetails->id, $factName, $factValue);
    }
} catch (Exception $ex) {
    printf("Unable to process facts: " . $ex->getMessage());
    exit(1);
}

