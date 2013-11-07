<?php
namespace VoiceBrowser;
use VoiceBrowser\Exception\VoiceXMLDisconnectException, VoiceBrowser\Exception\VoiceXMLErrorEvent;

class VoiceBrowser {
  public static $maxReprompts = 10;
  public static $defaultMaxTime = 10000;

  protected static $url;
  protected static $fileuploadPathFilter;



  public static function setUrl($url) {
    self::$url = $url;
  }

  public static function setUploadFilter($filter) {
    self::$fileuploadPathFilter = $filter;
  }

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
      $value = self::readExpr($matches[3]);
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


    // from http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
    public function absoluteUrl($rel)
    {
      /* return if already absolute URL */
        if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;

	if (self::$url === null) {
	  throw new Exception("No base URL set, can't resolve relative URL ".$rel);
	}

        /* queries and anchors */
        if ($rel[0]=='#' || $rel[0]=='?') return self::$url.$rel;

        /* parse base URL and convert to local variables:
         $scheme, $host, $path */
        extract(parse_url(self::$url));

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
	$client = new \Guzzle\Http\Client();
      }
      if ($method == "GET") {
	$req = $client->get($url, array(), null, array("query" => $params));
      } else {
	$filteredParams = array();
	foreach ($params as $p) {
	  // prefix filepath with @ per guzzle convention
	  if (is_object($p) && $p->file) {
	    // limit files that can be sent to path matching a filter
	    if (!is_callable(self::$fileuploadPathFilter)) {
	      throw new VoiceXMLErrorEvent("bad.fetch", "Could not upload file ".$p->file." (no upload filter defined)");
	    }
	    $closure = self::$fileuploadPathFilter;
	    if (call_user_func_array($closure, array($p->file))) {
	      $filteredParams[] = "@".$p->file;
	    } else {
	      throw new VoiceXMLErrorEvent("bad.fetch", "Could not upload file ".$p->file." (unauthorized by filter)");
	    }
	  } else {
	    $filteredParams[] = $p;
	  }
	}
	$req = $client->post($url, array(), 
			     $filteredParams);
      }
      try {
	$response = $req->send();
      } catch (\Guzzle\Http\Exception\BadResponseException $e) {
	throw new VoiceXMLErrorEvent("bad.fetch", "Fetching ".$url." via HTTP ".$method." generated an error ".$e->getResponse()->getStatusCode(). "(".$e->getMessage().")", $e);
      }
      $response = $req->send();
      $vxml = new VoiceXMLReader();
      $vmlx->load($response, $url);
    }
}


?>