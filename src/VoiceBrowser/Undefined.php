<?php
namespace VoiceBrowser;

class Undefined {
  public static function Instance() {
    static $inst = null;
    if ($inst === null) {
      $inst = new Undefined();
    }
    return $inst;
  }

  public function __toString()
  {
    return "undefined";
  }
 
  private function __construct() {}
}
