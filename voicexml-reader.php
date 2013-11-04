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
  public static $url;
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

  public static function setUrl($url) {
    VoiceXMLParser::$url = $url;
  }

    // from http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
    public function absoluteUrl($rel)
    {
      /* return if already absolute URL */
        if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;

	if (VoiceXMLParser::$url === null) {
	  throw new Exception("No base URL set, can't resolve relative URL ".$rel);
	}

        /* queries and anchors */
        if ($rel[0]=='#' || $rel[0]=='?') return VoiceXMLParser::$url.$rel;

        /* parse base URL and convert to local variables:
         $scheme, $host, $path */
        extract(parse_url(VoiceXMLParser::$url));

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
    VoiceXMLParser::setUrl($url);

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
  public $prompts = array();
  public $options = array();
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
	  $this->xmlreader->next();
	break;
	}
      }
    }
  }

  public function collect($callback) {
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
	  $this->prompts[] = new VoiceXMLPrompt($this->xmlreader->readOuterXML());
	  $this->xmlreader->next();
	  break;
	case "option":
	  $this->options[] = new VoiceXMLOption($this->xmlreader->readOuterXML());
	  $this->xmlreader->next();
	  break;
	case "enumerate":
	case "value":
	case "script":
	case "link":
	case "grammar":
	case "if":
	  throw new UnhandedlVoiceXMLException('Cannot handle '.$this->xmlreader->name.' element in form item '.$this->name);
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
	case "reprompt":
	case "catch":
	case "help":
	case "noinput":
	case "nomatch":
	case "error":
	  // for process only
	  $this->xmlreader->next();
	  break;
	default:
	  throw new UnhandedlVoiceXMLException('Unexpected '.$this->xmlreader->name.' element in form item '.$this->name);
	}
      }
    }
    $choice = call_user_func_array($callback, array("option", array($this->options)));
    call_user_func_array($callback, array("prompts", array($this->prompts)));
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

class VoiceXMLPrompt {
  protected $texts = array();
  protected $audios = array();

  public function __construct($xml) {
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
	  $this->audios[]=VoiceXMLParser::absoluteUrl($src);
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

  public function __construct($xml) {
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