<?php
namespace VoiceBrowser;
use VoiceBrowser\Exception\VoiceXMLDisconnectException, VoiceBrowser\Exception\UnhandedlVoiceXMLException, VoiceBrowser\Exception\InvalidVoiceXML, VoiceBrowser\Exception\VoiceXMLErrorEvent;

class VoiceXMLNext {
  public $method;
  public $nextformitemname;
  public $url;
  public $done = false;
  public $params = array();
  private $variables = array();

  public function __construct($variables) {
    $this->variables = $variables;
  }

  private function _merge($next) {

  }

  public function loadFromXML($xml) {
    $xmlreader = new \XMLReader();
    $xmlreader->xml($xml);
    $nestedConditionState = array();
    $ignore = false;
    while($xmlreader->read()) {
      if ($this->done) {
	break;
      }
      if ($xmlreader->nodeType == \XMLReader::ELEMENT && !$this->done && !$ignore) {
	if (count($nestedConditionState) && $nestedConditionState[0]["goToElse"] && $xmlreader->name!="else" && $xmlreader->name!="elseif") {
	  $xmlreader->next();
	}
	switch($xmlreader->name) {
	case "assign":
	  $name = $xmlreader->getAttribute("name");
	  if (!array_key_exists($name, $this->variables)) {
	    throw new VoiceXMLErrorEvent("error.semantic", "<assign> to a non-declared variable ".$name);
	  }
	  // intentional no break
	case "var":
	  $this->variables[$xmlreader->getAttribute("name")] = VoiceBrowser::readExpr($this->xmlreader->getAttribute("expr"));
	  $xmlreader->next();
	  break;
	case "clear":
	  $clearedVariables = preg_split("/\s+/",$xmlreader->getAttribute("namelist"));
	  foreach($clearedVariables as $name) {
	    if (!array_key_exists($name, $this->variables)) {
	      throw new VoiceXMLErrorEvent("error.semantic", "<clear> includes a non-declared variable ".$name);
	    }
	    $this->variables[$name] = Undefined::Instance();
	    // TODO: if the variable name corresponds to a form item,
	    // then the form item's prompt counter and event counters are reset
	  }
	  $xmlreader->next();
	  break;
	case "elseif":
	  if ($nestedConditionState[0]["stopAtElse"]) {
	    $ignore = true;
	    $xmlreader->next();
	  }
	  // intentional no break  
	case "if":
	  if (VoiceBrowser::readCond($xmlreader->getAttribute("cond"), $this->variables)) {
	    array_unshift($nestedConditionState, array("stopAtElse" => true, "goToElse" => false));
	  } else {
	    array_unshift($nestedConditionState, array("stopAtElse" => false, "goToElse" => true));
	  }
	  break;
	case "else":
	  if ($nestedConditionState[0]["goToElse"]) {
	    $nestedConditionState[0]["goToElse"] = false;
	  } else if ($nestedConditionState[0]["stopAtElse"]) {
	    $ignore = true;
	  }
	  $xmlreader->next();
	  break;
	case "disconnect":
	case "exit":
	  throw new VoiceXMLDisconnectException();
	case "goto":
	  if ($xmlreader->getAttribute("expritem") !== null) {
	    throw new UnhandedlVoiceXMLException('Can’t handle expritem attribute in goto in form item');
	  }
	  $nextitem = $xmlreader->getAttribute("nextitem");
	  $next = $xmlreader->getAttribute("next");
	  if ($nextitem !== null) {
	    $this->nextformitemname = $nextitem;
	    $this->done = true;
	  } else if ($next !== null) {
	    $matches = array();
	    if (preg_match('/^#(.*)$/', $next, $matches)) {
	      $this->nextformitem = $matches[1];
	      $this->done = true;
	    } else {
	      $this->method = "GET";
	      $this->url = VoiceBrowser::absoluteUrl($next);
	      $this->params = $this->variables;
	      $this->done = true;
	    }
	  } else {
	    throw new InvalidVoiceXML('<goto> element without next or nextitem attribute');
	  }
	  break;
	case "submit":
	  if ($xmlreader->getAttribute("expr") !== null) {
	    throw new UnhandedlVoiceXMLException('Can’t handle expr attribute in submit');
	  }
	  $this->url = VoiceBrowser::absoluteUrl($xmlreader->getAttribute("next"));
	  $method = $xmlreader->getAttribute("method");
	  $this->method = "GET";
	  if ($method !== null) {
	    $this->method = strtoupper($method);
	  }
	  $namelist = $xmlreader->getAttribute("namelist");
	  $this->params = array_filter($this->variables, function($i) { return $i !== Undefined::Instance();});
	  if ($namelist !== null) {
	    $selectedNames = preg_split("/\s+/", $namelist);
	    $this->params = array_intersect_key($this->params, array_flip($selectedNames));
	  }
	  $this->done = true;
	  break;
	case "filled":
	  // A <filled> element in an input item cannot specify a namelist
	  // if a namelist is specified, then an error.badfetch is thrown
	  if ($xmlreader->getAttribute("namelist") || $xmlreader->getAttribute("mode")) {
	    throw new VoiceXMLErrorEvent("error.badfetch", "namelist attribute on filled element in form item is forbidden");
	  }
	  break;
	case "throw":
	  throw new VoiceXMLErrorEvent($xmlreader->getAttribute("event"), $xmlreader->getAttribute("message"));
	case "log":
	case "return":
	  throw new UnhandedlVoiceXMLException('Can’t handle '.$xmlreader->name.' element');
	default:
	  break;
	}
      } elseif ($xmlreader->nodeType == \XMLReader::END_ELEMENT && $xmlreader->name=="if") {
	array_shift($nestedConditionState);
	$ignore = false;
      }
    }
  }
}
