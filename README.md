# Ultimo Synology DS
Implementation of the Synology Diskstation SDK in PHP

Currently only support authentication.

## Features
* Authentication
* Get system user id
* Get system user group names and ids

## Requirements
* PHP 5.3

## Usage
	$core = new \ultimo\sdk\synology\ds\Core();
	$username = $core->authenticate();
	
	$userId = $core->getUserId($username);
	$groups = $core->getUserGroupIds($username);