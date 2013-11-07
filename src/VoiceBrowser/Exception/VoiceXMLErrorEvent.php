<?php
namespace VoiceBrowser\Exception;
class VoiceXMLErrorEvent extends \Exception { 
  public $type;
  public function __construct($type, $message) {
    $this->type = $type;
    $this->message = $message;
  }
}
