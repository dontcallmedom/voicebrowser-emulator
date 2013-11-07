<?php
namespace VoiceBrowser;
use VoiceBrowser\Exception\UnhandledVoiceXMLException;

class VoiceXMLPrompt {
  public $texts = array();
  public $audios = array();

  public function loadFromXML($xml) {
    $xmlreader = new \XMLReader();
    $xmlreader->xml($xml);
    while($xmlreader->read()) {
      if ($xmlreader->nodeType == \XMLReader::ELEMENT) {
	switch($xmlreader->name) {
	case "prompt":
	  break;
	case "audio":
	  $src = $xmlreader->getAttribute("src");
	  if ($src === null) {
	    throw new UnhandledVoiceXMLException('Cannot handle audio element without src attribute');
	  }
	  $this->audios[]=VoiceBrowser::absoluteUrl($src);
	  break;
	case "break":
	  break;
	case "value":
	default:
	  throw new UnhandledVoiceXMLException('Cannot handle prompts with non-static content ('.$xmlreader->name.')');
	}
      } else if ($xmlreader->nodeType == \XMLReader::TEXT) {
	$this->texts[]=$xmlreader->readString();
      }
    }
  }
}

