<?php
namespace VoiceBrowser;

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
