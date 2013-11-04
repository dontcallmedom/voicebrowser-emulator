<?php
require("voicexml-reader.php");

class VoiceXMLParserTest extends PHPUnit_Framework_TestCase {
  public function testReadCond()
  {
    $variables = array("unity" => 1, "name" => "Foo");
    $this->assertTrue(VoiceXMLParser::readCond("name == 'Foo'", $variables));
    $this->assertFalse(VoiceXMLParser::readCond("name == 'Bar'", $variables));
    $this->assertTrue(VoiceXMLParser::readCond("unity == 1", $variables));
    $this->assertTrue(VoiceXMLParser::readCond("unity >= 1", $variables));
    $this->assertFalse(VoiceXMLParser::readCond("unity > 1", $variables));
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
    $this->vxml->callback = function($type, $params) {
      echo $type."\n";
      print_r($params);
    };
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

}