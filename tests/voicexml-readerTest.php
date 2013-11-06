<?php
require("voicexml-reader.php");

class VoiceXMLBrowserTest extends PHPUnit_Framework_TestCase {
  public function testReadExpr() {
    $this->assertEquals(VoiceXMLBrowser::readExpr("undefined"), Undefined::Instance());
    $this->assertEquals(VoiceXMLBrowser::readExpr("'foo'"), 'foo');
    $this->assertEquals(VoiceXMLBrowser::readExpr("254"), 254);
  }

  public function testReadCond()
  {
    $variables = array("unity" => 1, "name" => "Foo");
    $this->assertTrue(VoiceXMLBrowser::readCond("name == 'Foo'", $variables));
    $this->assertTrue(VoiceXMLBrowser::readCond("foo == undefined", $variables));
    $this->assertTrue(VoiceXMLBrowser::readCond("name != undefined", $variables));
    $this->assertFalse(VoiceXMLBrowser::readCond("foo != undefined", $variables));
    $this->assertFalse(VoiceXMLBrowser::readCond("name == 'Bar'", $variables));
    $this->assertTrue(VoiceXMLBrowser::readCond("unity == 1", $variables));
    $this->assertTrue(VoiceXMLBrowser::readCond("unity >= 1", $variables));
    $this->assertFalse(VoiceXMLBrowser::readCond("unity > 1", $variables));
  }

  public function testReadTime() {
    $this->assertEquals(VoiceXMLBrowser::readTime("10s"), 10000);
    $this->assertEquals(VoiceXMLBrowser::readTime("10ms"), 10);
    $this->assertEquals(VoiceXMLBrowser::readTime("10mas"), null);
  }
}

class VoiceXMLReaderTest extends PHPUnit_Framework_TestCase {
  protected $vxml;

  /**
   * @covers VoiceXMLReader::__construct
   */
  protected function setUp()
  {
    $this->vxml = new VoiceXMLReader();
    VoiceXMLBrowser::setUploadFilter(function ($path) {
	// only allow files given with relatives paths 
	// that are under the current directory 
	$realpath = realpath($path);
	$path_pos = strrpos($realpath, $path);
	return ($realpath != $path &&  $path_pos !== false && $path_pos == strlen($realpath) - strlen($path));
      });
  }
  
  protected function tearDown()
  {
    $this->vxml = NULL;
  }

  /**
   * @covers VoiceXMLReader::load
   */
  public function testLoadingXMLWorks()
  {
    $xml = file_get_contents("tests/test.vxml");
    $this->assertTrue($this->vxml->load($xml, "http://example.org/"));
    $variables =     array('IVRTYPE' => 'VOICEGLUE',
			   'USERID' => -1,
			   'CONFESSIONID' => Undefined::Instance());
    $this->assertEquals($variables, $this->vxml->variables);
  }

  /**
   * @covers VoiceXMLReader::load
   * @expectedException InvalidVoiceXMLException
   */
  public function testLoadingInvalidVoiceXMLThrows()
  {
    $xml = file_get_contents("tests/invalid.vxml");
    $this->vxml->load($xml);
  }
  /**
   *  @covers VoiceXMLReader::load
   */
  public function testFormInteraction() {
    $order = 0;
    $eventhandler = new VoiceXMLEventHandler();
    $eventhandler->onprompt = function ($prompts) use (&$order) {
      $this->assertEquals($prompts[0]->texts[0],"Please tell us who you are");
      $this->assertEquals($order,0);
      $order++;
    };
    $eventhandler->onrecord = function () use (&$eventhandler, &$order) {
      $eventhandler->onprompt = function ($prompts) use (&$order) {
	$this->assertEquals($prompts[0]->texts[0],"DEFAULT Too long");
	$this->assertEquals($prompts[1]->texts[0],"Please tell us who you are");
	$this->assertEquals($order,1);
	$order++;
      };
      return new VoiceXMLAudioRecord("tests/test.wav", 15000);
    };
    $eventhandler->onmaxspeechtimeout = function () use (&$eventhandler, &$order) {
      $eventhandler->onrecord = function () use (&$eventhandler, &$order) {
	$eventhandler->onprompt = function ($prompts) use (&$order) {
	  $this->assertEquals($prompts[0]->texts[0],"Please select what you to want to hear next");
	  $this->assertEquals($order,2);
	  $order++;
	};	
	return new VoiceXMLAudioRecord("tests/test.wav", 5000);
      };
    };
    $eventhandler->onoption = function ($options) use (&$eventhandler, &$order) {
      $eventhandler->onnoinput = function () use (&$eventhandler, &$order) {
	$eventhandler->onprompt = function ($prompts) use (&$order) {
	  $this->assertEquals($prompts[0]->texts[0],"Please select what you to want to hear next");
	  $this->assertEquals($order,3);
	  $order++;
	};	
	$eventhandler->onoption = function ($options) use (&$eventhandler, &$order) {
	  $this->assertEquals($options[0]->label, "Listen to Ekene");
	  $this->assertEquals($options[0]->dtmf, array("1"));
	  $eventhandler->onprompt = function ($prompts) use (&$order) {
	    $this->assertEquals($prompts[0]->audios[0],"http://example.org/prompts/EKENE/en/welcome.wav");
	    $this->assertEquals($order,4);

	  };
	  return $options[0]->dtmf;
	};
      };      
      return null;
    };

    $this->vxml->callback = $eventhandler;
    $xml = file_get_contents("tests/test.vxml");
    $this->assertTrue($this->vxml->load($xml, "http://example.org/"));
  }

}