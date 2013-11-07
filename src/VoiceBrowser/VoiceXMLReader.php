<?php
namespace VoiceBrowser;
use VoiceBrowser\Exception\InvalidVoiceXML, VoiceBrowser\Exception\UnhandedlVoiceXML, VoiceBrowser\Exception\VoiceXMLErrorEvent;

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
    $this->_read();
    return true;
  }

  protected function _read() {
    while ($this->xmlreader->read()) {
      if (!$this->xmlreader->isValid()) {
	throw new InvalidVoiceXML('VoiceXML document not valid: '.libxml_get_last_error()->message);
      }
      if ($this->xmlreader->nodeType == \XMLReader::ELEMENT) {
	switch($this->xmlreader->name) {
	case "vxml":
	case "var":
	case "property":
	case "form":
	case "disconnect":
	  call_user_func_array(array($this,'_read' . ucfirst($this->xmlreader->name)), array());
	  break;
	default:
	  throw new UnhandedlVoiceXML('Cannot handle element '.$this->xmlreader->name);
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
      throw new UnhandedlVoiceXMLException('Cannot handle application attribute on vxml element');
    }
  }

  private function _readProperty() {
    $this->properties[$this->xmlreader->getAttribute("name")] = $this->xmlreader->getAttribute("value");
  }

  private function _readForm() {
    $xml = $this->xmlreader->readOuterXML();
    $form = new VoiceXMLFormReader($this->variables);
    $form->loadFromXML($xml);
    $next = $form->process($this->callback);
    if ($next !== null && $next->url) {

    }
    $this->xmlreader->next();
  }  
}

class VoiceXMLFormReader {
  private $xml;
  private $xmlreader;
  private $collectxmlreader;
  private $variables = array();
  private $nextFormItem = null;
  private $prompts = array();
  private $items = array();
  private $nameditems = array();
  private $currentItemIndex = 0;
  private $repromptCounter = 0;
  private $maxrecordtime;

  public function __construct($variables) {
    $this->variables = $variables;
  }

  public function loadFromXML($xml) {
    $this->xml = $xml;
  }

  public function process($callback) {
    $this->_formInit();
    $this->xmlreader = new \XMLReader();
    $this->xmlreader->xml($this->xml);
    while (TRUE) {
      $formitem = $this->_formSelect();
      if ($formitem === null) {
	break;
      }
      try {
	$value = $formitem->collect($callback);
	if ($formitem->name)  {
	  $this->variables[$formitem->name] = $value;
	}
      } catch (VoiceXMLDisconnectException $e) {
	// TODO: handle connection.disconnect.hangup event
	break;
      }
      $next = $formitem->execute($this->variables);
      if ($next->nextformitemname) {
	if (array_key_exists($next->nextformitemname, $this->nameditems)) {
	  $this->nextFormItem = $this->nameditems[$next->nextformitemname];
	} else {
	  throw new VoiceXMLErrorEvent("error.badfetch", "Asked to reach unknown form item  with name ".$next->nextformitemname);
	}
      } else if ($next->url) {
	$this->nextFormItem = null;
	VoiceBrowser::fetch($next->url, $next->method, $next->params);
	break;
      }
    }
  }

  private function _formInit() {
    $this->xmlreader = new \XMLReader();
    $this->xmlreader->xml($this->xml);
    while($this->xmlreader->read()) {
      if ($this->xmlreader->nodeType == \XMLReader::ELEMENT) {
	switch($this->xmlreader->name) {
	case "form":
	  break;
	case "var":
	  $this->variables[$this->xmlreader->getAttribute("name")] = VoiceBrowser::readExpr($this->xmlreader->getAttribute("expr"));
	  break;
	case "property":
	  break;
	case "record":
	case "field":
	case "block":
	  $item = new VoiceXMLFormItem($this->xmlreader->readOuterXML(), $this->variables);
	$this->items[] = $item;
	if ($item->name) {
	  $this->nameditems[$item->name] = $item;
	}
	$this->xmlreader->next();
	break;
	default:
	  throw new UnhandedlVoiceXMLException('Cannot handle form item '.$this->xmlreader->name);
	}
      }
    }
    reset($this->items);
  }

  private function _formSelect() {
    if ($this->nextFormItem) {
      $item = $this->nextFormItem;
      $this->nextFormItem = null;
      return $item;
    }
    while($item = current($this->items)) {
      next($this->items);
      if ($item->guardCondition) {	
	return $item;
      }
    }
    return null;
  }

  private function _formProcess() {
  }

}
