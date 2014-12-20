<?php

namespace ultimo\sdk\synology\ds;

class Exception extends \Exception {
  const UNEXPECTED_RESPONSE = 1;
  const EMPTY_RESPONSE = 2;
}