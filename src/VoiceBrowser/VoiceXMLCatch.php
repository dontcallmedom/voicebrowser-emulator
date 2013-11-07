<?php
namespace VoiceBrowser;
use VoiceBrowser\Exception\UnhandledVoiceXMLException;

class VoiceXMLCatch {
  public $type;
  public $prompts = array();
  public $reprompt = false;
  public $exit = false;

  public function loadFromXML($xml) {
    $xmlreader = new \XMLReader();
    $xmlreader->xml($xml);
    while($xmlreader->read()) {
      if ($xmlreader->nodeType == \XMLReader::ELEMENT) {
	switch($xmlreader->name) {
	case "noinput":
	case "cancel":
	case "exit":
	case "nomatch":
	case "maxsppechtimeout":
	case "connection.disconnect.hangup":
	  $this->type = $xmlreader->name;
	  break;
	case "catch":
	  $this->type = $xmlreader->getAttribute("event");
	  break;
	case "reprompt":
	  $this->reprompt = true;
	  break;
	case "exit":
	case "disconnect":
	  $this->exit = true;
	break;
	case "audio":
	case "prompt":
	  $p = new VoiceXMLPrompt();
	$p->loadFromXML($xmlreader->readOuterXML());
	$this->prompts[] = $p;
	$xmlreader->next();
	break;
	default:
	  throw new UnhandledVoiceXML('Did not expect '.$xmlreader->name.' in event catcher');
	}
      }
    }
  }
}
