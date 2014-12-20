<?php
namespace ultimo\sdk\synology\ds;

class Core {
  const GROUPID_USERS = 100;
  const GROUPID_ADMINISTRATORS = 101;
  
  /**
   * Logs in the the user with the specified username and password.
   * @param string $username DSM username, case-insensitive.
   * @param string $password DSM password.
   * @param bool $sendHeaders Whether to passthrough headers, needed
   * to log on the client. Otherwise the return value only indicates
   * whether the username and password are correct.
   * @return bool whether login was successfull
   */
  public function login($username, $password, $sendHeaders=true) {
    $queryData = array(
      'username' => $username,
      'passwd' => $password
    );
    
    $rawResponse = $this->exec('/usr/syno/synoman/webman/login.cgi', array(), $queryData);
    $response = $this->parseHttpResponse($rawResponse);
    
    if ($response['data']['result'] == 'error') {
      return false;
    }
    
    if ($sendHeaders) {
      $this->passthroughHeaders($response['headers']);
    }
    
    // append the new id to the HTTP_COOKIE environment variable, so the user
    // is logged in now, instead of next request
    if (isset($response['headers']['Set-Cookie'])) {
      if (preg_match("/id=[^;]+/", $response['headers']['Set-Cookie'], $match)) {
        $httpCookie = $match[0];
        if (getenv('HTTP_COOKIE')) {
          $httpCookie = getenv('HTTP_COOKIE') . '; ' . $httpCookie;
        }
        putenv("HTTP_COOKIE={$httpCookie}"); 
      }
    }
    
    return true;
  }
  
  /**
   * Logs out the current user.
   * @param bool $sendHeaders Whether to passthrough headers.
   */
  public function logout($sendHeaders=true) {
    $rawResponse = $this->exec('/usr/syno/synoman/webman/logout.cgi');
    $response = $this->parseHttpResponse($rawResponse);
   
    if ($sendHeaders) {
      $this->passthroughHeaders($response['headers']);
    }
  }

  /**
   * Passes through an hashtable of headers.
   * @param array $headers Hashtable of headers.
   */
  protected function passthroughHeaders(array $headers) {
    $exceptions = array('location', 'content-type'); 
    foreach ($headers as $name => $value) {
      if (in_array(strtolower($name), $exceptions)) {
        continue;
      }
      header("{$name}: {$value}");
    }
  }
  
  /**
   * Authenticates the user in the current connection.
   * @param string $synoToken SynoToken, for example retrieved by login().
   * @return The username of the authenticated user of the current connection
   * or beloning to the provided SynoToken, or null if no user is logged in
   * (or failure).
   */
  public function authenticate($synoToken=null) {
    $queryData = null;
    
    // add SynoToken (if present) for backwardscompatibility for DSM 4.x and
    // early 5.0
    $synoToken = $this->getSynoToken();
    if ($synoToken !== null) {
      $queryData = array('SynoToken' => $synoToken);
    }
    
    $response = $this->exec('/usr/syno/synoman/webman/modules/authenticate.cgi', array(), $queryData);
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
  protected function exec($code, $env=array(), $queryData=null) {
    // make sure the shell code is executed with the same environment variables as this PHP script,
    // this may be needed on older DSM versions
    foreach ($_SERVER as $name => $value) {
      if (!array_key_exists($name, $env) && !getenv($name) && (strpos($name, 'HTTP_') === 0 || $name == 'REMOTE_ADDR')) {
        $env[$name] = $value;
      }
    }
    
    // add query data to environment
    if ($queryData !== null) {
      $env['QUERY_STRING'] = http_build_query($queryData);
    }
    
    // set the environment variable as save the current values
    $oldEnv = array();
    foreach ($env as $name => $value) {
      $oldEnv[$name] = getenv($name);
      putenv("{$name}={$value}");
    }
    
    // fetch the exec result
    $output = trim(shell_exec($code));
    
    // restore the environment
    foreach ($oldEnv as $name => $value) {
      if ($value === null || $value === false) {
        putenv($name);
      } else {
        putenv("{$name}={$value}");
      }
    }
    return $output;
  }
  
  /**
   * Parses a HTTP response by a DiskStation script. It expects the body
   * is json encoded.
   * @param string $httpResponse Raw HTTP response.
   * @return array Hashtable with headers and data.
   */
  protected function parseHttpResponse($httpResponse) {
    // construct resulting object
    $response = array('headers' => array(), 'data' => array());
    /*
     Example data:
     Content-type: text/html; charset="UTF-8" 
     P3P: CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"

      {
         "result" : "success",
         "success" : true
      }
     */
    
    // As this script returns a HTTP response, the data until the empty line are
    // headers. The remaining is the body.
    $lines = explode("\n", $httpResponse);
    $body = array(); // holds each line of the body
    $bodyStarted = false; // whether all headers are read and the body has started
    foreach ($lines as $line) {
      if (!$bodyStarted) {
        // remove \r
        $line = trim($line);
        
        // check if line is empty
        if (strlen($line) == 0) {
          $bodyStarted = true;
        } else {
          // add header to response
          list($name, $value) = explode(':', $line); 
          $response['headers'][trim($name)] = trim($value);
        }
      } else {
        $body[] = $line;
      }
    }
    
    // body may not be empty
    if (empty($body)) {
      $data = array();
    } else {
    
      // decode the body
      $data = @json_decode(implode("\n", $body), true);
    
      // check if there where any errors while decoding the json
      if (json_last_error() != JSON_ERROR_NONE || !isset($data['success'])) {
        throw new Exception("Invalid response:\n$httpResponse", Exception::UNEXPECTED_RESPONSE);
      }
    }
    
    // add data to response and return the response
    $response['data'] = $data;
    return $response;
  }
  
  /**
   * Returns the SynoToken for the current connection. This only works for
   * DMS 4.x and early 5.0.
   * @return string SynoToken for the current connection.
   */
  public function getSynoToken() {
    $rawResponse = $this->exec('/usr/syno/synoman/webman/login.cgi 2>/dev/null');
    $response = $this->parseHttpResponse($rawResponse);
    if (!isset($response['data']['SynoToken'])) {
      return null;
    }
    return $response['data']['SynoToken'];
  }
  
}