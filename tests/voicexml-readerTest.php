<?php
require("voicexml-reader.php");

class VoiceXMLBrowserTest extends PHPUnit_Framework_TestCase {
  public function testReadCond()
  {
    $variables = array("unity" => 1, "name" => "Foo");
    $this->assertTrue(VoiceXMLBrowser::readCond("name == 'Foo'", $variables));
    $this->assertFalse(VoiceXMLBrowser::readCond("name == 'Bar'", $variables));
    $this->assertTrue(VoiceXMLBrowser::readCond("unity == 1", $variables));
    $this->assertTrue(VoiceXMLBrowser::readCond("unity >= 1", $variables));
    $this->assertFalse(VoiceXMLBrowser::readCond("unity > 1", $variables));
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
    $variables =     array(0=>array('IVRTYPE' => 'VOICEGLUE',
			   'USERID' => -1,
				    'CONFESSIONID' => Undefined::Instance()));
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

  public function testFormInteraction() {
    $eventhandler = new VoiceXMLEventHandler();
    $eventhandler->onprompt = function ($prompts) {
      foreach ($prompts as $p) {
	foreach ($p->texts as $t) {
	  print "Say: ".$t."\n";
	}
      }
    };
    $eventhandler->onoption = function ($options) {
      print "options received, ignoring\n";
      return null;
    };
    $eventhandler->onnoinput = function () use (&$eventhandler) {
      print "noinput\n";
      $eventhandler->onoption = function ($options) {
	print "options received, picking 1st\n";
	return $options[0]->dtmf;
      };
    };

    $this->vxml->callback = $eventhandler;
    $xml = file_get_contents("tests/test.vxml");
    $this->assertTrue($this->vxml->load($xml, "http://example.org/"));
  }

}