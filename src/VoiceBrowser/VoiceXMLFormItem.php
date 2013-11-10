<?php
namespace VoiceBrowser;
use VoiceBrowser\Exception\VoiceXMLDisconnectException, VoiceBrowser\Exception\VoiceXMLErrorEvent, VoiceBrowser\Value;

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
      throw new UnhandledVoiceXMLException('Cannot handle form item without an explicit name');
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

  public function collect() {
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
	    throw new UnhandledVoiceXMLException('Cannot handle conditional prompts in form item '.$this->name);
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
	  throw new UnhandledVoiceXMLException('Cannot handle '.$this->xmlreader->name.' element in form item '.$this->name);
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
	  throw new UnhandledVoiceXMLException('Unexpected '.$this->xmlreader->name.' element in form item '.$this->name);
	}
      }
    }
    foreach($this->prompts as $p) {
      $ret = (yield $p);
    }
    $inputGenerator = $this->_processInput();
    while (TRUE) {
      if (!$inputGenerator->valid()) break;
      $input = $inputGenerator->current();
      $val = (yield $input);
      $inputGenerator->send($val);
    }
  }

  private function _processEvent( $type) {
    $prompts = $this->eventCatcher[$type]->prompts;
    if ($this->eventCatcher[$type]->reprompt) {
      $prompts = array_merge($prompts, $this->prompts);
    } else if ($this->eventCatcher[$type]->exit) {
      throw new Exception\VoiceXMLDisconnectException();
    }
    foreach($prompts as $p) {
      yield $p;
    }
    $this->repromptCounter++;
    if ($this->repromptCounter < VoiceBrowser::$maxReprompts) {
      $inputGenerator = $this->_processInput();
      while (TRUE) {
	if (!$inputGenerator->valid()) break;
	$input = $inputGenerator->current();
	$val = (yield $input);
	$inputGenerator->send($val);
      }
    } else {
      throw new VoiceXMLDisconnectException();
    }
  }

  private function _processInput() {
    if (count($this->options)) {
      $input = (yield $this->options);
      if ($input === null) {
	yield $this->_processEvent("noinput")->current();
      } else if (!in_array($input, array_map(function ($op) { return $op->dtmf;}, $this->options))) {
	yield $this->_processEvent("nomatch")->current();
      }
      //    $this->promptCounter++;
      $selectedOptionIndex = array_search($input, array_map(function ($op) { return $op->dtmf;}, $this->options));
      yield new Value($this->options[$selectedOptionIndex]->value);
    } else if ($this->expectsInput) { 
      $record = (yield "record");
      if ($record === null) {
	$eventGenerator = $this->_processEvent("noinput");
	while (TRUE) {
	  if (!$eventGenerator->valid()) break;
	  $input = $eventGenerator->current();
	  $val = (yield $input);
	  $eventGenerator->send($val);
	}	
      } else if ($record->length > $this->maxrecordtime) {
	$eventGenerator = $this->_processEvent("maxspeechtimeout");
	while (TRUE) {
	  if (!$eventGenerator->valid()) break;
	  $input = $eventGenerator->current();
	  $val = (yield $input);
	  $eventGenerator->send($val);
	}	
      }
      yield new Value($record);
    }
  }


  public function execute($variables) {
    $next = new VoiceXMLNext($variables);
    $next->loadFromXML($this->xml);
    return $next;
  }
}
