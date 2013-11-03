<?php
class InvalidVoiceXMLException extends Exception {};
class UnhandedlVoiceXMLException extends Exception {};

class Undefined {
  public static function Instance() {
    static $inst = null;
    if ($inst === null) {
      $inst = new Undefined();
    }
    return $inst;
  }

  private function __construct() {}
}

class VoiceXMLReader {
  protected $xmlreader;
  public $variables=array();

  public function __construct() {
    $this->xmlreader = new XMLReader();
  }

  public function load($content) {
    $this->xmlreader->xml($content);
    $this->xmlreader->setRelaxNGSchema('vxml.rng');

    libxml_use_internal_errors(TRUE);
    if (!$this->xmlreader->isValid()) {
      throw new InvalidVoiceXMLException('VoiceXML document not valid: '.libxml_get_last_error()->message);
    }
    $this->_read();
    return true;
  }

  protected function _read() {
    while ($this->xmlreader->read()) {
      if (!$this->xmlreader->isValid()) {
	throw new InvalidVoiceXMLException('VoiceXML document not valid: '.libxml_get_last_error()->message);
      }
      if ($this->xmlreader->nodeType == XMLReader::ELEMENT) {
	switch($this->xmlreader->name) {
	case "vxml":
	  break;
	case "var":
	  $this->_readVar();
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
	  throw new UnhandedlVoiceXMLException('Cannot handle element '.$this->xmlreader->name);
	}
	print $this->xmlreader->name."\n";
      } 
    }
    print_r($this->variables);
  }

  private function _readVar() {
    $varName = $this->xmlreader->getAttribute("name");
    $varValue = $this->xmlreader->getAttribute("expr");
    if ($varValue == null || $varValue == "") {
      $varValue = Undefined::Instance();
    } elseif (preg_match("/^'[^']*'$/", $varValue) 
	      || preg_match("/^\"[^\"]*\"$/", $varValue)) {
      $varValue = substr($varValue,1,strlen($varValue) - 2);
    } elseif (is_numeric($varValue)) {
      $varValue = (int)$varValue;
    } else {
      throw new UnhandedlVoiceXMLException('In var '.$varName.', cannot handle attribute expr with values that are not string or number: '.$varValue );
    }
    $this->variables[$varName] = $varValue;
  }
}
