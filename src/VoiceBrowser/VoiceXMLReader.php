<?php
namespace VoiceBrowser;
use VoiceBrowser\Exception\InvalidVoiceXML, VoiceBrowser\Exception\UnhandledVoiceXML, VoiceBrowser\Exception\VoiceXMLErrorEvent, VoiceBrowser\VoiceXMLFormReader;

class VoiceXMLReader {
  protected $xmlreader;
  private $localxmlreader;
  public $variables = array();
  public $properties = array();
  public $url;
  public $callback;

  private $currentForm;

  public function __construct() {
    $this->xmlreader = new \XMLReader();
    $this->callback = new VoiceXMLEventHandler();
  }

  public function load($content, $url = null) {
    $this->xmlreader->xml($content);
    $this->xmlreader->setRelaxNGSchema('vxml.rng');
    $this->url = $url;
    VoiceBrowser::setUrl($url);

    libxml_use_internal_errors(TRUE);
    if (!$this->xmlreader->isValid()) {
      throw new InvalidVoiceXML('VoiceXML document not valid: '.libxml_get_last_error()->message);
    }
    return true;
  }

  public function read() {
    while ($this->xmlreader->read()) {
      if (!$this->xmlreader->isValid()) {
	throw new InvalidVoiceXML('VoiceXML document not valid: '.libxml_get_last_error()->message);
      }
      if ($this->xmlreader->nodeType == \XMLReader::ELEMENT) {
	switch($this->xmlreader->name) {
	case "vxml":
	case "var":
	case "property":
	case "disconnect":
	  call_user_func_array(array($this,'_read' . ucfirst($this->xmlreader->name)), array());
	  break;
	case "form":
	  $readForm = $this->_readForm();
	  while (TRUE) {
	    if (!$readForm->valid()) break;
	    $io = $readForm->current();
	    $val = (yield $io);
	    $readForm->send($val);
	  }
	  $this->xmlreader->next();

	  break;
	default:
	  throw new UnhandledVoiceXML('Cannot handle element '.$this->xmlreader->name);
	}
      }
    }
  }

  private function _readDisconnect() {
    $this->xmlreader->next("vxml");
  }

  private function _readVar() {
    $varName = $this->xmlreader->getAttribute("name");
    $this->variables[$varName] = VoiceBrowser::readExpr($this->xmlreader->getAttribute("expr"));
  }
  
  private function _readVxml() {
    $appUrl = $this->xmlreader->getAttribute("application");
    if ($appUrl != null && $appUrl != $this->url) {
      throw new UnhandledVoiceXMLException('Cannot handle application attribute on vxml element');
    }
  }

  private function _readProperty() {
    $this->properties[$this->xmlreader->getAttribute("name")] = $this->xmlreader->getAttribute("value");
  }

  private function _readForm() {
    $xml = $this->xmlreader->readOuterXML();
    $form = new VoiceXMLFormReader($this->variables);
    $form->loadFromXML($xml);
    $process = $form->process();
    while (TRUE) {
      if (!$process->valid()) break;
      $io = $process->current();
      $val = (yield $io);
      $process->send($val);
    }
  }  
}

