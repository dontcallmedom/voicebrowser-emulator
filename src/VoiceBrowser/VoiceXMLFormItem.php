<?php
namespace VoiceBrowser;
use VoiceBrowser\Exception\VoiceXMLDisconnectException, VoiceBrowser\Exception\VoiceXMLErrorEvent;

class VoiceXMLFormItem {
  protected $xml;
  protected $xmlreader;
  protected $variables;
  protected $expectsInput = false;
  public $guardCondition = true;
  public $name;
  public $value;
  public $promptCounter = 0;
  public $repromptCounter = 0;
  public $prompts = array();
  public $options = array();
  public $type;
  public $eventCatcher = array();
  
  private function _initFormItem() {
    $this->type = $this->xmlreader->name;
    $name = $this->xmlreader->getAttribute("name");
    if ($name == null && $this->xmlreader->name != "block") {
      throw new UnhandedlVoiceXMLException('Cannot handle form item without an explicit name');
    }
    $this->name = $name;
    $value = VoiceBrowser::readExpr($this->xmlreader->getAttribute("expr"));
    $this->value = ($value !== null ? $value : Undefined::Instance());
    $this->guardCondition = 
      // whether cond attribute resolve to true
      VoiceBrowser::readCond($this->xmlreader->getAttribute("cond"), $this->variables) 
      // whether current value is undefined
      && $this->value === Undefined::Instance();
    $this->maxrecordtime = VoiceBrowser::readTime($this->xmlreader->getAttribute("maxtime"));
    $this->maxrecordtime = $this->maxrecordtime !== null ? $this->maxrecordtime : VoiceBrowser::$defaultMaxTime;

  }

  public function __construct($xml, $variables) {
    $this->eventCatcher = VoiceBrowser::defaultEventCatcher();
    $this->xml =$xml;
    $this->variables = $variables;
    $this->xmlreader = new \XMLReader();
    $this->xmlreader->xml($this->xml);
    while($this->xmlreader->read()) {
      if ($this->xmlreader->nodeType == \XMLReader::ELEMENT) {
	switch($this->xmlreader->name) {
	case "field":
	case "record":
	  $this->expectsInput = true;
	// intentional no break!
	case "block":
	  $this->_initFormItem();
	  $this->xmlreader->next();
	break;
	}
      }
    }
  }

  public function collect(&$eventhandler) {
    $this->xmlreader->xml($this->xml);
    while($this->xmlreader->read()) {
      if ($this->xmlreader->nodeType == \XMLReader::ELEMENT) {
	switch($this->xmlreader->name) {
	case "field":
	case "record":
	case "block":
	  break;
	case "audio":
	case "prompt":
	  if ($this->xmlreader->getAttribute("cond" !== null) || $this->xmlreader->getAttribute("count" !== null)) {
	    throw new UnhandedlVoiceXMLException('Cannot handle conditional prompts in form item '.$this->name);
	  }
	$p = new VoiceXMLPrompt();
	$p->loadFromXML($this->xmlreader->readOuterXML());
	$this->prompts[] = $p;
	  $this->xmlreader->next();
	  break;
	case "option":
	  $o = new VoiceXMLOption();
	  $o->loadFromXML($this->xmlreader->readOuterXML());
	  $this->options[] = $o;
	  $this->xmlreader->next();
	  break;
	case "enumerate":
	case "value":
	case "script":
	case "link":
	case "grammar":
	  throw new UnhandedlVoiceXMLException('Cannot handle '.$this->xmlreader->name.' element in form item '.$this->name);
	case "catch":
	case "help":
	case "noinput":
	case "nomatch":
	case "error":
	  $catcher = new VoiceXMLCatch();
	$catcher->loadFromXML($this->xmlreader->readOuterXML());
	$this->eventCatcher[$catcher->type] = $catcher;
	$this->xmlreader->next();
	  break;
	case "if":
	case "var":
	case "assign":
	case "property":
	case "clear":
	case "disconnect":
	case "exit":
	case "goto":
	case "log":
	case "return":
	case "submit":
	case "throw":
	case "filled":
	  // for process only
	  $this->xmlreader->next();
	  break;
	default:
	  throw new UnhandedlVoiceXMLException('Unexpected '.$this->xmlreader->name.' element in form item '.$this->name);
	}
      }
    }
    $eventhandler->onprompt($this->prompts);
    return $this->_processInput($eventhandler);
  }

  private function _processEvent(&$eventhandler, $type) {
    
    $prompts = $this->eventCatcher[$type]->prompts;
    if ($this->eventCatcher[$type]->reprompt) {
      $prompts = array_merge($prompts, $this->prompts);
    } else if ($this->eventCatcher[$type]->exit) {
      throw new Exception\VoiceXMLDisconnectException();
      }
    call_user_func_array(array($eventhandler, "on" . $type), array());
    $eventhandler->onprompt($prompts);
    $this->repromptCounter++;
    if ($this->repromptCounter < VoiceBrowser::$maxReprompts) {
      return $this->_processInput($eventhandler);
    } else {
      throw new VoiceXMLDisconnectException();
    }
  }

  private function _processInput(&$eventhandler) {
    if (!$this->expectsInput) {
      return TRUE;
    }
    if (count($this->options)) {
      $input = $eventhandler->onoption($this->options);
      if ($input === null) {
	return $this->_processEvent($eventhandler, "noinput");
      } else if (!in_array($input, array_map(function ($op) { return $op->dtmf;}, $this->options))) {
	return $this->_processEvent($eventhandler, "nomatch");
      }
      //    $this->promptCounter++;
      $selectedOptionIndex = array_search($input, array_map(function ($op) { return $op->dtmf;}, $this->options));
      return $this->options[$selectedOptionIndex]->value;
    } else { 
      $record = $eventhandler->onrecord();
      if ($record === null) {
	return $this->_processEvent($eventhandler, "noinput");
      } else if ($record->length > $this->maxrecordtime) {
	return $this->_processEvent($eventhandler, "maxspeechtimeout");
      }
      return $record;
    }
  }

  public function execute($variables) {
    $next = new VoiceXMLNext($variables);
    $next->loadFromXML($this->xml);
    return $next;
  }
}
