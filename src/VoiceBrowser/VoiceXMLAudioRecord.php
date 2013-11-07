<?php
namespace VoiceBrowser;
class VoiceXMLAudioRecord {
  public $file;
  public $length;
  public function __construct($file, $length) {
    $this->file = $file;
    $this->length = $length;
  }
}
