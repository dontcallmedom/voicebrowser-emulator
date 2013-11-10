<?php

use VoiceBrowser\VoiceBrowser, VoiceBrowser\Undefined, VoiceBrowser\VoiceXMLReader, VoiceBrowser\VoiceXMLEventHandler, VoiceBrowser\VoiceXMLAudioRecord;
use VoiceBrowser\InvalidVoiceXMLException, VoiceBrowser\Exception\VoiceXMLErrorEvent;

class VoiceBrowserTest extends PHPUnit_Framework_TestCase {
  public function testReadExpr() {
    $this->assertEquals(VoiceBrowser::readExpr("undefined"), Undefined::Instance());
    $this->assertEquals(VoiceBrowser::readExpr("'foo'"), 'foo');
    $this->assertEquals(VoiceBrowser::readExpr("254"), 254);
  }

  public function testReadCond()
  {
    $variables = array("unity" => 1, "name" => "Foo");
    $this->assertTrue(VoiceBrowser::readCond("name == 'Foo'", $variables));
    $this->assertTrue(VoiceBrowser::readCond("foo == undefined", $variables));
    $this->assertTrue(VoiceBrowser::readCond("name != undefined", $variables));
    $this->assertFalse(VoiceBrowser::readCond("foo != undefined", $variables));
    $this->assertFalse(VoiceBrowser::readCond("name == 'Bar'", $variables));
    $this->assertTrue(VoiceBrowser::readCond("unity == 1", $variables));
    $this->assertTrue(VoiceBrowser::readCond("unity >= 1", $variables));
    $this->assertFalse(VoiceBrowser::readCond("unity > 1", $variables));
  }

  public function testReadTime() {
    $this->assertEquals(VoiceBrowser::readTime("10s"), 10000);
    $this->assertEquals(VoiceBrowser::readTime("10ms"), 10);
    $this->assertEquals(VoiceBrowser::readTime("10mas"), null);
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
    VoiceBrowser::setUploadFilter(function ($path) {
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
  }

  /**
   * @covers VoiceXMLReader::load
   */
  public function testLoadingInvalidVoiceXMLThrows()
  {
    $xml = file_get_contents("tests/invalid.vxml");
    $this->vxml->load($xml);
    $this->vxml->read();
  }
  /**
   *  @covers VoiceXMLReader::load
   */
  public function testFormInteraction() {
    $xml = file_get_contents("tests/test.vxml");
    $this->assertTrue($this->vxml->load($xml, "http://example.org/"));
    $gen = $this->vxml->read(); 
    $io = $gen->current();
    $this->assertEquals($io->texts[0], "Please tell us who you are");
    $io = $gen->send(null);
    $this->assertEquals($io, "record");
    $io = $gen->send(new VoiceXMLAudioRecord("tests/test.wav", 15000));
    $this->assertEquals($io->texts[0], "DEFAULT Too long");
    $io = $gen->send(null);
    $this->assertEquals($io->texts[0], "Please tell us who you are");
    $io = $gen->send(null);
    $this->assertEquals($io, "record");

    $io = $gen->send(new VoiceXMLAudioRecord("tests/test.wav", 5000));
    $this->assertEquals($io->texts[0], "Please select what you to want to hear next");
    $io = $gen->send(null);
    $this->assertEquals($io[0]->label, "Listen to Ekene");
    $this->assertEquals($io[0]->dtmf, array("1"));
    $io = $gen->send($io[1]->dtmf);
    $this->assertEquals($io->audios[0], "http://example.org/prompts/EKENE/en/welcome.wav");
  }
}