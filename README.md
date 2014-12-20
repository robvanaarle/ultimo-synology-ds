# Ultimo Synology DS
Implementation of the Synology Diskstation SDK in PHP

Currently only supports authentication.

## Features
* Login
* Logout
* Authentication
* Get system user id
* Get system user group names and ids

## Requirements
* PHP 5.3

## Usage
	$core = new \ultimo\sdk\synology\ds\Core();
	
	// get details of the user currently logged in to DSM
	$username = $core->authenticate();
	$userId = $core->getUserId($username);
	$groups = $core->getUserGroupIds($username);
	
	// log in as admin
	if ($core->login("AdMiN", "secret")) {
	  echo "Now logged in as " . $core->authenticate()";
	}
	
## Background information
More information can be found in the following [blog post](http://www.robvanaarle.com/blog/2014/08/synology-dsm-web-authentication-with-php/).