<?php

class VoiceXMLReader {
  protected $xmlreader;
  protected $variables=array();

  public function __construct() {
    $this->xmlreader = new XMLReader();
  }

  public function load($content) {
    $this->xmlreader->xml($content);
    $this->xmlreader->setRelaxNGSchema('vxml.rng');
    if (!$this->xmlreader->isValid()) {
      throw new Exception('VoiceXML document not valid');
    }
    $this->_read();
    return true;
  }

  protected function _read() {
    while ($this->xmlreader->read()) {
      if ($this->xmlreader->nodeType == XMLReader::ELEMENT) {
	switch($this->xmlreader->name) {
	case "vxml":
	  break;
	case "var":
	  break;
	case "property":
	  break;
	case "form":
	  break;
	case "block":
	  break;
	case "prompt":
	  break;
	case "audio":
	  break;
	case "submit":
	  break;
	default:
	  throw new Exception('Cannot handle element '.$this->xmlreader->name);
	}
	print $this->xmlreader->name."\n";
      } 
    }
    
  }
}
