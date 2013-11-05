<?php
require 'vendor/autoload.php';

class InvalidVoiceXMLException extends Exception {};
class UnhandedlVoiceXMLException extends Exception {};
class VoiceXMLDisconnectException extends Exception {};
class VoiceXMLErrorEvent extends Exception { 
  public $type;
  public function __construct($type, $message) {
    $this->type = $type;
    $this->message = $message;
  }
}

class Undefined {
  public static function Instance() {
    static $inst = null;
    if ($inst === null) {
      $inst = new Undefined();
    }
    return $inst;
  }

  public function __toString()
  {
    return "undefined";
  }
 
  private function __construct() {}
}

class VoiceXMLBrowser {
  public static $maxReprompts = 10;
  public static $defaultMaxTime = 10000;

  public static $url;
  public static function readExpr($varValue) {
    if ($varValue == null || $varValue == "") {
      $varValue = Undefined::Instance();
    } elseif ($varValue === "undefined") {
      $varValue = Undefined::Instance();
    } elseif (preg_match("/^'[^']*'$/", $varValue) 
	      || preg_match("/^\"[^\"]*\"$/", $varValue)) {
      $varValue = substr($varValue,1,strlen($varValue) - 2);
    } elseif (is_numeric($varValue)) {
      $varValue = (int)$varValue;
    } else {
      throw new UnhandedlVoiceXMLException('cannot handle attribute expr with values that are not string or number: '.$varValue );
    }
    return $varValue;
  }

  public static function readTime($value) {
    $matches = array();
    if ($value === null) {
      return $value;
    } else if (preg_match("/^ *([0-9]+) *(m?s) *$/", $value, $matches)) {
      if ($matches[2] == "ms") {
	return intval($matches[1]);
      } else {
	return intval($matches[1])*1000;
      }
    }
    return null;
  }

  public static function readCond($cond, $variables) {
    if ($cond == null) {
      return TRUE;
    }
    $matches = array();
    if (preg_match("/true/i", $cond)) {
      return TRUE;
    } elseif (preg_match("/false/i", $cond)) {
      return FALSE;
    } elseif (preg_match("/^ *([a-zA-Z_][^ =<>!]*) *(==|>|<|!=|<=|>=) *([^ ]*) *$/", $cond, $matches)) {
      $value = VoiceXMLBrowser::readExpr($matches[3]);
      $variableIsUndefined = !array_key_exists($matches[1],$variables);
      switch ($matches[2]) {
      case "==":
	return ($value === Undefined::Instance() && $variableIsUndefined) || $variables[$matches[1]] == $value;
      case ">":
	return $variables[$matches[1]] > $value;
      case "<":
	return $variables[$matches[1]] < $value;
      case "<=":
	return $variables[$matches[1]] <= $value;
      case ">=":
	return $variables[$matches[1]] >= $value;
      case "!=":
	return !$variableIsUndefined && ($value === Undefined::Instance() || $variables[$matches[1]] != $value);
      }
    } else {
      throw new UnhandedlVoiceXMLException('Cannot handle conditions that are not simple comparisons: '.$cond );
    }
  }

  public static function setUrl($url) {
    VoiceXMLBrowser::$url = $url;
  }

    // from http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
    public function absoluteUrl($rel)
    {
      /* return if already absolute URL */
        if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;

	if (VoiceXMLBrowser::$url === null) {
	  throw new Exception("No base URL set, can't resolve relative URL ".$rel);
	}

        /* queries and anchors */
        if ($rel[0]=='#' || $rel[0]=='?') return VoiceXMLBrowser::$url.$rel;

        /* parse base URL and convert to local variables:
         $scheme, $host, $path */
        extract(parse_url(VoiceXMLBrowser::$url));

        /* remove non-directory element from path */
        $path = preg_replace('#/[^/]*$#', '', $path);

        /* destroy path if relative url points to root */
        if ($rel[0] == '/') $path = '';

        /* dirty absolute URL */
        $abs = "$host$path/$rel";

        /* replace '//' or '/./' or '/foo/../' with '/' */
        $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
        for($n=1; $n>0; $abs=preg_replace($re, '/', $abs, -1, $n)) {}

        /* absolute URL is ready! */
        return $scheme.'://'.$abs;
    }


    public static function defaultEventCatcher() {
      $builder = function ($type, $prompt=null, $outcome = "reprompt") {
	$catcher = new VoiceXMLCatch();
	$catcher->type = $type;
	if ($prompt !== null) {
	  $p = new VoiceXMLPrompt();
	  $p->texts[] = $prompt;
	  $catcher->prompts[] = $p;
	}
	if ($outcome == "reprompt") {
	  $catcher->reprompt = true;
	} else if ($outcome == "exit") {
	  $catcher->exit = true;
	}
	return $catcher;
      };

      // inspired from default catch elements
      // http://www.w3.org/TR/voicexml20/#dml5.2.5
      $eventCatcher = array();
      $eventCatcher["cancel"] = $builder("cancel", null, 'none');
      $eventCatcher["error"] = $builder("error", "DEFAULT Error", 'exit');
      $eventCatcher["exit"] = $builder("exit", null, 'exit');
      $eventCatcher["help"] = $builder("help", "DEFAULT Help", 'reprompt');
      $eventCatcher["noinput"] = $builder("noinput", null, 'reprompt');
      $eventCatcher["nomatch"] = $builder("nomatch", "DEFAULT Try again", 'reprompt');
      $eventCatcher["maxspeechtimeout"] = $builder("maxspeechtimeout", "DEFAULT Too long", 'reprompt');
      $eventCatcher["connection.disconnect"] = $builder("connection.disconnect", null, 'exit');
      $eventCatcher["*"] = $builder("*", "DEFAULT Catch all", 'exit');
      return $eventCatcher;
    }

    public static function fetch($url, $method, $params, $client = null) {
      if ($client == null) {
	$client = new Guzzle\Http\Client();
      }
      if ($method == "GET") {
	$req = $client->get($url, array(), null, array("query" => $params));
      } else {
	// TODO: limit files that can be sent to a predefined path
	$req = $client->post($url, array(), 
			     // prefix filepath with @ per guzzle convention
			     array_map(function ($p) { if (is_object($p) && $p->file) { return "@".$p->file;} else { return $p;}}, $params));
      }
      try {
	$response = $req->send();
      } catch (Guzzle\Http\Exception\BadResponseException $e) {
	throw new VoiceXMLErrorEvent("bad.fetch", "Fetching ".$url." via HTTP ".$method." generated an error ".$e->getResponse()->getStatusCode(). "(".$e->getMessage().")");
      }
      $response = $req->send();
      $vxml = new VoiceXMLReader();
      $vmlx->load($response, $url);
    }
}

class VoiceXMLReader {
  protected $xmlreader;
  private $localxmlreader;
  public $variables = array();
  public $properties = array();
  public $url;
  public $callback;

  private $currentForm;

  public function __construct() {
    $this->xmlreader = new XMLReader();
    $this->callback = new VoiceXMLEventHandler();
  }

  public function load($content, $url = null) {
    $this->xmlreader->xml($content);
    $this->xmlreader->setRelaxNGSchema('vxml.rng');
    $this->url = $url;
    VoiceXMLBrowser::setUrl($url);

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
	case "var":
	case "property":
	case "form":
	case "disconnect":
	  call_user_func_array(array($this,'_read' . ucfirst($this->xmlreader->name)), array());
	  break;
	default:
	  throw new UnhandedlVoiceXMLException('Cannot handle element '.$this->xmlreader->name);
	}
      }
    }
  }

  private function _readDisconnect() {
    $this->xmlreader->next("vxml");
  }

  private function _readVar() {
    $varName = $this->xmlreader->getAttribute("name");
    $this->variables[$varName] = VoiceXMLBrowser::readExpr($this->xmlreader->getAttribute("expr"));
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
    $this->xmlreader = new XMLReader();
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
	VoiceXMLBrowser::fetch($next->url, $next->method, $next->params);
	break;
      }
    }
  }

  private function _formInit() {
    $this->xmlreader = new XMLReader();
    $this->xmlreader->xml($this->xml);
    while($this->xmlreader->read()) {
      if ($this->xmlreader->nodeType == XMLReader::ELEMENT) {
	switch($this->xmlreader->name) {
	case "form":
	  break;
	case "var":
	  $this->variables[$this->xmlreader->getAttribute("name")] = VoiceXMLBrowser::readExpr($this->xmlreader->getAttribute("expr"));
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


class VoiceXMLFormItem {
  protected $xml;
  protected $xmlreader;
  protected $variables;
  protected $expectsInput = false;
  public $guardCondition = true;
  public $name;
  public $value;
  public $promptCounter = 0;
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
    $value = VoiceXMLBrowser::readExpr($this->xmlreader->getAttribute("expr"));
    $this->value = ($value !== null ? $value : Undefined::Instance());
    $this->guardCondition = 
      // whether cond attribute resolve to true
      VoiceXMLBrowser::readCond($this->xmlreader->getAttribute("cond"), $this->variables) 
      // whether current value is undefined
      && $this->value === Undefined::Instance();
    $this->maxrecordtime = VoiceXMLBrowser::readTime($this->xmlreader->getAttribute("maxtime"));
    $this->maxrecordtime = $this->maxrecordtime !== null ? $this->maxrecordtime : VoiceXMLBrowser::$defaultMaxTime;

  }

  public function __construct($xml, $variables) {
    $this->eventCatcher = VoiceXMLBrowser::defaultEventCatcher();
    $this->xml =$xml;
    $this->variables = $variables;
    $this->xmlreader = new XMLReader();
    $this->xmlreader->xml($this->xml);
    while($this->xmlreader->read()) {
      if ($this->xmlreader->nodeType == XMLReader::ELEMENT) {
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
      if ($this->xmlreader->nodeType == XMLReader::ELEMENT) {
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
      throw new VoiceXMLDisconnectException();
      }
    call_user_func_array(array($eventhandler, "on" . $type), array());
    $eventhandler->onprompt($prompts);
    $this->repromptCounter++;
    if ($this->repromptCounter < VoiceXMLBrowser::$maxReprompts) {
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
    $xmlreader = new XMLReader();
    $xmlreader->xml($xml);
    $nestedConditionState = array();
    $ignore = false;
    while($xmlreader->read()) {
      if ($this->done) {
	break;
      }
      if ($xmlreader->nodeType == XMLReader::ELEMENT && !$this->done && !$ignore) {
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
	  $this->variables[$xmlreader->getAttribute("name")] = VoiceXMLBrowser::readExpr($this->xmlreader->getAttribute("expr"));
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
	  if (VoiceXMLBrowser::readCond($xmlreader->getAttribute("cond"), $this->variables)) {
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
	      $this->url = VoiceXMLBrowser::absoluteUrl($next);
	      $this->params = $this->variables;
	      $this->done = true;
	    }
	  } else {
	    throw new InvalidVoiceXMLException('<goto> element without next or nextitem attribute');
	  }
	  // TODO
	  break;
	case "submit":
	  if ($xmlreader->getAttribute("expr") !== null) {
	    throw new UnhandedlVoiceXMLException('Can’t handle expr attribute in submit');
	  }
	  $this->url = VoiceXMLBrowser::absoluteUrl($xmlreader->getAttribute("next"));
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
	  // TODO
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
      } elseif ($xmlreader->nodeType == XMLReader::END_ELEMENT && $xmlreader->name=="if") {
	array_shift($nestedConditionState);
	$ignore = false;
      }
    }
  }
}



class VoiceXMLPrompt {
  public $texts = array();
  public $audios = array();

  public function loadFromXML($xml) {
    $xmlreader = new XMLReader();
    $xmlreader->xml($xml);
    while($xmlreader->read()) {
      if ($xmlreader->nodeType == XMLReader::ELEMENT) {
	switch($xmlreader->name) {
	case "prompt":
	  break;
	case "audio":
	  $src = $xmlreader->getAttribute("src");
	  if ($src === null) {
	    throw new UnhandedlVoiceXMLException('Cannot handle audio element without src attribute');
	  }
	  $this->audios[]=VoiceXMLBrowser::absoluteUrl($src);
	  break;
	case "break":
	  break;
	case "value":
	default:
	  throw new UnhandedlVoiceXMLException('Cannot handle prompts with non-static content ('.$xmlreader->name.')');
	}
      } else if ($xmlreader->nodeType == XMLReader::TEXT) {
	$this->texts[]=$xmlreader->readString();
      }
    }
  }
}

class VoiceXMLOption {
  public $label;
  public $value;
  public $dtmf;

  public function loadFromXML($xml) {
    $xmlreader = new XMLReader();
    $xmlreader->xml($xml);
    $xmlreader->read();
    $this->label = $xmlreader->readString();
    $this->value = $xmlreader->getAttribute("value");
    $dtmf = $xmlreader->getAttribute("dtmf");
    if ($dtmf === null) {
      throw new UnhandedlVoiceXMLException('Cannot handle option without dtmf attribute');
    }
    $this->dtmf =  str_split($dtmf, 1);
  }
}

class VoiceXMLCatch {
  public $type;
  public $prompts = array();
  public $reprompt = false;
  public $exit = false;

  public function loadFromXML($xml) {
    $xmlreader = new XMLReader();
    $xmlreader->xml($xml);
    while($xmlreader->read()) {
      if ($xmlreader->nodeType == XMLReader::ELEMENT) {
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
	  throw new UnhandledVoiceXMLException('Did not expect '.$xmlreader->name.' in event catcher');
	}
      }
    }
  }
}


class VoiceXMLAudioRecord {
  public $file;
  public $length;
  public function __construct($file, $length) {
    $this->file = $file;
    $this->length = $length;
  }
}

class VoiceXMLEventHandler {
  public $onnoinput;
  public $onnomatch;
  public $onexit;
  public $ondisconnect;
  public $onprompt;
  public $onoption;
  public $onrecord;
  public $onmaxspeechtimeout;
  public $onerror;

  public function __construct() {
    $this->onerror = $this->onnoinput = $this->onnomatch = $this->onexit = $this->ondisconnect = $this->onrecord = $this->onmaxspeechtimeout = function () {
      return null;
    };
    $this->onprompt =$this->onoption =  function ($param) {
      return null;
    };
  }
  
  public function __call($method, $args)
  {
    $closure = $this->$method;
    return call_user_func_array($closure, $args);
  }

}

