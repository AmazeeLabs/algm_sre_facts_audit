#!/usr/bin/env php
<?php


class LagoonLogstashPusher {

  public static function pushUdp($host, $port, $payload) {

    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$socket) {
      throw new Exception('Could not open UDP socket for logstash: ' . $host . ':' . $port);
    }

    try {
      $msg = json_encode($payload) . "\n";
      if(!@socket_sendto($socket, $msg, strlen($msg), $flags = 0, $host, $port)) {
        throw new Exception('Could not send message to Logstash server: ' . $host . ':' . $port);
      }
    } catch (Exception $ex) {
      //we'll rethrow this, but we need to run some cleanup
      throw $ex;
    } finally {
      socket_close($socket);
    }
  }
}

class LagoonLogger {

  const LAGOON_LOGS_DEFAULT_HOST = 'application-logs.lagoon.svc';

  const LAGOON_LOGS_DEFAULT_PORT = '5140';

  const LAGOON_LOGS_DEFAULT_SAFE_BRANCH = 'safe_branch_unset';

  const LAGOON_LOGS_DEFAULT_LAGOON_PROJECT = 'project_unset';

  //The following is used to log Lagoon Logs issues if logging target
  //cannot be reached.
  const LAGOON_LOGGER_WATCHDOG_FALLBACK_IDENTIFIER = 'lagoon_logs_fallback_error';

  protected static $loggerInstance = NULL;

  protected $hostName;

  protected $hostPort;

  /**
   * LagoonLogger constructor.
   *
   * @param $hostName
   * @param $hostPort
   */
  public function __construct($hostName, $hostPort) {
    $this->hostName = $hostName;
    $this->hostPort = $hostPort;
  }

  /**
   * @return string
   *
   * This will return some kind of representation of the process
   */
  protected function getHostProcessIndex() {
    $nameArray = [];
    $nameArray['lagoonProjectName'] = getenv('LAGOON_PROJECT') ?: self::LAGOON_LOGS_DEFAULT_LAGOON_PROJECT;
    $nameArray['lagoonGitBranchName'] = getenv('LAGOON_GIT_SAFE_BRANCH') ?: self::LAGOON_LOGS_DEFAULT_SAFE_BRANCH;

    return implode('-', $nameArray);
  }

  /**
   * @param $logEntry
   */
  public function log($toLog) {

    $message = array(
      '@timestamp' => gmdate('c'),
      '@version' => 1,
      'type' => $this->getHostProcessIndex(),
      'application'=> 'logger',
    );


    $message['message'] = $toLog;

    try {
      LagoonLogstashPusher::pushUdp($this->hostName, $this->hostPort, $message);
    } catch (Exception $exception) {
      $logMessage = sprintf("Unable to reach %s to log: %s", $this->hostName . ":" . $this->hostPort,
        json_encode([
          $exception->getMessage(),
          $message,
        ]));
    }
  }
}

if($argc <= 1) {
  print("Too few arguments. Usage `lagoon_logger.php <DATA TO LOG>`\n");
  exit(1);
}


$logger = new LagoonLogger(LagoonLogger::LAGOON_LOGS_DEFAULT_HOST, LagoonLogger::LAGOON_LOGS_DEFAULT_PORT);
$logger->log($argv[1]);
