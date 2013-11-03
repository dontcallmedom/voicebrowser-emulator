<?php
require("voicexml-reader.php");

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
    $this->assertTrue($this->vxml->load($xml));
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

}