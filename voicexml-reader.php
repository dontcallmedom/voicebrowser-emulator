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

class VoiceXMLParser {
  public static function readExpr($varValue) {
    if ($varValue == null || $varValue == "") {
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
      switch ($matches[2]) {
      case "==":
	return $variables[$matches[1]] == VoiceXMLParser::readExpr($matches[3]);
      case ">":
	return $variables[$matches[1]] > VoiceXMLParser::readExpr($matches[3]);
      case "<":
	return $variables[$matches[1]] < VoiceXMLParser::readExpr($matches[3]);
      case "<=":
	return $variables[$matches[1]] <= VoiceXMLParser::readExpr($matches[3]);
      case ">=":
	return $variables[$matches[1]] >= VoiceXMLParser::readExpr($matches[3]);
      case "!=":
	return $variables[$matches[1]] != VoiceXMLParser::readExpr($matches[3]);
      }
    } else {
      throw new UnhandedlVoiceXMLException('Cannot handle conditions that are not simple comparisons: '.$cond );
    }
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
    $this->callback = function ($type, $params) {
    };
  }

  public function load($content, $url = null) {
    $this->xmlreader->xml($content);
    $this->xmlreader->setRelaxNGSchema('vxml.rng');
    $this->url = $url;

    libxml_use_internal_errors(TRUE);
    if (!$this->xmlreader->isValid()) {
      throw new InvalidVoiceXMLException('VoiceXML document not valid: '.libxml_get_last_error()->message);
    }
    $this->_read();
    return true;
  }

  protected function _read() {
    $depth = -1;
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
	  call_user_func_array(array($this,'_read' . ucfirst($this->xmlreader->name)), array($depth));
	  break;
	default:
	  throw new UnhandedlVoiceXMLException('Cannot handle element '.$this->xmlreader->name);
	}
	$depth++;
	$this->variables[$depth]=array();
	$this->properties[$depth]=array();
      }
      if ($this->xmlreader->nodeType == XMLReader::END_ELEMENT || $this->xmlreader->isEmptyElement) {
	
	if ($depth > 0) {
	  unset($this->variables[$depth]);
	  unset($this->properties[$depth]);
	}
	$depth--;
      }
    }
  }

  private function _readDisconnect() {
    $this->xmlreader->next("vxml");
  }

  private function _readVar($depth) {
    $varName = $this->xmlreader->getAttribute("name");
    $this->variables[$depth][$varName] = VoiceXMLParser::readExpr($this->xmlreader->getAttribute("expr"));
  }
  
  private function _readVxml() {
    $appUrl = $this->xmlreader->getAttribute("application");
    if ($appUrl != null && $appUrl != $this->url) {
      throw new UnhandedlVoiceXMLException('Cannot handle application attribute on vxml element');
    }
  }

  private function _readProperty($depth) {
    $this->properties[$depth][$this->xmlreader->getAttribute("name")] = $this->xmlreader->getAttribute("value");
  }

  private function _readForm($depth) {
    $xml = $this->xmlreader->readOuterXML();
    $form = new VoiceXMLFormReader($xml);
    $form->process($this->callback);
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
  private $currentItemIndex = 0;

  public function __construct($xml) {
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
      $formitem->collect($callback);
      $this->_formProcess();
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
	  $this->variables[$this->xmlreader->getAttribute("name")] = VoiceXMLParser::readExpr($this->xmlreader->getAttribute("expr"));
	  break;
	case "property":
	  break;
	case "record":
	case "field":
	case "block":
	  $this->items[] = new VoiceXMLFormItem($this->xmlreader->readOuterXML(), $this->variables);
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
  public $type;
  
  private function _generateName() {
    $length = 10;
    $randomString = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
    return $randomString;
  }


  private function _initFormItem() {
    $this->type = $this->xmlreader->name;
    $name = $this->xmlreader->getAttribute("name");
    if ($name == null && $this->xmlreader->name != "block") {
      throw new UnhandedlVoiceXMLException('Cannot handle form item without an explicit name');
    }
    $this->name = $name;
    $value = VoiceXMLParser::readExpr($this->xmlreader->getAttribute("expr"));
    $this->value = ($value !== null ? $value : Undefined::Instance());
    $this->guardCondition = 
      // whether cond attribute resolve to true
      VoiceXMLParser::readCond($this->xmlreader->getAttribute("cond"), $this->variables) 
      // whether current value is undefined
      && $this->value === Undefined::Instance();
    $this->xmlreader->next();
  }

  public function __construct($xml, $variables) {
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
	break;
	}
      }
    }
  }

  public function collect($callback) {
    $this->xmlreader = new XMLReader();
    $this->xmlreader->xml($this->xml);
    while($this->xmlreader->read()) {
      if ($this->xmlreader->nodeType == XMLReader::ELEMENT) {
	switch($this->xmlreader->name) {
	case "prompt":
	case "grammar":
	case "option":
	case "noinput":
	case "submit":
	case "goto":
	case "filled":
	  call_user_func_array(array($this,'_collect' . ucfirst($this->xmlreader->name)), array($callback));
	$this->xmlreader->next();
	default:
	  break;	  
	}
      }
    }
  }

  private function _collectPrompt($cb) {
    call_user_func_array($cb, array("Prompt", array()));
  }
  private function _collectGrammar($cb) {
    call_user_func_array($cb, array("Grammar", array()));
  }
  private function _collectOption($cb) {
    call_user_func_array($cb, array("Option", array()));
  }
  private function _collectNoinput($cb) {
    call_user_func_array($cb, array("Noinput", array()));
  }
  private function _collectSubmit($cb) {
    call_user_func_array($cb, array("Submit", array()));
  }
  private function _collectGoto($cb) {
    call_user_func_array($cb, array("Goto", array()));
  }
  private function _collectFilled($cb) {
    call_user_func_array($cb, array("Filled", array()));
  }


}

