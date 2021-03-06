<?php
namespace VoiceBrowser;
use VoiceBrowser\Exception\UnhandledVoiceXMLException;

class VoiceXMLOption {
  public $label;
  public $value;
  public $dtmf;

  public function loadFromXML($xml) {
    $xmlreader = new \XMLReader();
    $xmlreader->xml($xml);
    $xmlreader->read();
    $this->label = $xmlreader->readString();
    $this->value = $xmlreader->getAttribute("value");
    $dtmf = $xmlreader->getAttribute("dtmf");
    if ($dtmf === null) {
      throw new UnhandledVoiceXMLException('Cannot handle option without dtmf attribute');
    }
    $this->dtmf =  str_split($dtmf, 1);
  }
}
