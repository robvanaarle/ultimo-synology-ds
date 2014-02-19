<?php

namespace ultimo\sdk\synology\ds;

class Core {
  const GROUPID_USERS = 100;
  const GROUPID_ADMINISTRATORS = 101;
  
  /**
   * Returns the SynoToken for the current connection.
   * @return string SynoToken for the current connection.
   */
  public function getSynoToken() {
    $data = $this->exec('/usr/syno/synoman/webman/login.cgi 2>/dev/null');
    
    // DEBUG
    //$data = "Content-type: text/html; charset=\"UTF-8\"\r\nP3P: CP=\"IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT\"\r\n\r\n{\n\"SynoToken\" : \"abcdef1234\",\n\"result\" : \"success\",\n\"success\" : true\n}";
    
    /*
     Example data:
     Content-type: text/html; charset="UTF-8" 
     P3P: CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"

      {
         "SynoToken" : "LRAc4dzJlzixo",
         "result" : "success",
         "success" : true
      }
     */
    
    // As this script returns a HTTP response, the data until the empty line are
    // headers. The remaining is the body.
    $lines = explode("\n", $data);
    $body = array();
    $headersRead = false;
    foreach ($lines as $line) {
      if (!$headersRead) {
        // remove \r
        $line = trim($line);
        // check if line is empty
        if (strlen($line) == 0) {
          $headersRead = true;
        }
      } else {
        $body[] = $line;
      }
    }
    
    if (empty($body)) {
      throw new Exception("Empty response", Exception::UNEXPECTED_RESPONSE);
    }
    
    $response = @json_decode(implode("\n", $body), true);
    
    if (json_last_error() != JSON_ERROR_NONE || !isset($response['success'])) {
      throw new Exception("Invalid response:\n$data", Exception::UNEXPECTED_RESPONSE);
    }
    
    if (!$response['success']) {
      throw new Exception("Failed to get SynoToken, result={$response['result']}:\n$data");
    }
    
    return $response["SynoToken"];
  }
  
  /**
   * Authenticates the user in the current connection.
   * @return The username of the authenticated user of the current connection,
   * or null if no user is logged in (or failure).
   */
  public function authenticate() {
    // For some reason calling authenticate.cgi works fine from perl, but not
    // from PHP. Exporting the SynoToken solves this.
    putenv('QUERY_STRING=SynoToken=' . $this->getSynoToken());
    $response = $this->exec('/usr/syno/synoman/webman/modules/authenticate.cgi');
    if (strcmp($response, '') == 0) {
      return null;
    }
    return $response;
  }
  
  /**
   * Returns the id of the user associated with the specified username.
   * @param string $username Username.
   * @return int The id of the user associated with the specfied username, or
   * null if the username is unknown.
   */
  public function getUserId($username) {
    // call id -u {username} and redirect error stream to /dev/null
    $userId = $this->exec('id -u ' . escapeshellarg($username) . ' 2>/dev/null');
    if (!is_numeric($userId)) {
      return null;
    }
    return (int) $userId;
  }
  
  /**
   * Returns an array with groupnames the user is part of.
   * @param string $username Username.
   * @return array Array with groupnames.
   */
  public function getUserGroupNames($username) {
    $groups = $this->exec('id -Gn ' . escapeshellarg($username) . ' 2>/dev/null');
    return explode(' ', $groups);
  }
  
  /**
   * Returns an array with group ids the user is part of.
   * @param string $username Username.
   * @return array Array with group ids.
   */
  public function getUserGroupIds($username) {
    $groups = $this->exec('id -G ' . escapeshellarg($username) . ' 2>/dev/null');
    
    if (strcmp($groups, '') == 0) {
      return array();
    }
    
    return explode(' ', $groups);
  }
  
  /**
   * Executes shell code on the DiskStation.
   * @param string $code Code to execute.
   * @return string Trimmed response of the executed code.
   */
  protected function exec($code) {
    return trim(shell_exec($code));
  }
}