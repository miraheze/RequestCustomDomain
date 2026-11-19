<?php

namespace Miraheze\RequestCustomDomain\Tests\Logging;

use MediaWiki\Logging\DatabaseLogEntry;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\TitleValue;
use MediaWikiIntegrationTestCase;
use Miraheze\RequestCustomDomain\Logging\RequestCustomDomainLogFormatter;

/**
 * @group RequestCustomDomain
 * @group Database
 * @group Medium
 * @covers \Miraheze\RequestCustomDomain\Logging\RequestCustomDomainLogFormatter
 */
class RequestCustomDomainLogFormatterTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setUserLang( 'en' );
		$this->overrideConfigValue( MainConfigNames::LanguageCode, 'en' );
	}

	private function newFormatter( string $action, array $params ): RequestCustomDomainLogFormatter {
		$entry = new ManualLogEntry( 'requestcustomdomain', $action );
		$entry->setPerformer( $this->getTestUser()->getUser() );
		$entry->setTarget( new TitleValue( NS_SPECIAL, 'RequestCustomDomainQueue/7' ) );
		$entry->setParameters( $params );

		$logID = $entry->insert( $this->getDb() );
		$logEntry = DatabaseLogEntry::newFromId( $logID, $this->getDb() );
		$this->assertNotNull( $logEntry );

		$formatter = $this->getServiceContainer()->getLogFormatterFactory()
			->newFromEntry( $logEntry );

		$this->assertInstanceOf( RequestCustomDomainLogFormatter::class, $formatter );
		return $formatter;
	}

	public function testStatusUpdateLinksRequestAndTranslatesStatus(): void {
		$formatter = $this->newFormatter( 'statusupdate', [
			'4:request-link:requestID' => 7,
			'5:request-status:requestStatus' => 'inprogress',
		] );

		$html = $formatter->getActionText();
		$this->assertStringContainsString( 'RequestCustomDomainQueue/7', $html );
		$this->assertStringContainsString( '>#7</a>', $html );
		$this->assertStringContainsString(
			"to 'in progress'",
			html_entity_decode( $html, ENT_QUOTES )
		);
	}

	public function testPlainTextHasNoHtml(): void {
		$formatter = $this->newFormatter( 'statusupdate', [
			'4:request-link:requestID' => 7,
			'5:request-status:requestStatus' => 'complete',
		] );

		$text = $formatter->getPlainActionText();
		$this->assertStringContainsString( '[[Special:RequestCustomDomainQueue/7]]', $text );
		$this->assertStringContainsString( "to 'complete'", $text );
		$this->assertStringNotContainsString( '<a ', $text );
	}

	public function testUnknownStatusIsShownAsStored(): void {
		$formatter = $this->newFormatter( 'statusupdate', [
			'4:request-link:requestID' => 7,
			'5:request-status:requestStatus' => 'something else',
		] );

		$this->assertStringContainsString( "to 'something else'", $formatter->getPlainActionText() );
	}

	public function testRequestEntryKeepsTarget(): void {
		$formatter = $this->newFormatter( 'request', [
			'4::requestTarget' => 'testwiki',
			'5:request-link:requestID' => 7,
		] );

		$html = $formatter->getActionText();
		$this->assertStringContainsString( 'testwiki', $html );
		$this->assertStringContainsString( '>#7</a>', $html );
	}

	public function testStoredHtmlIsNotRendered(): void {
		$formatter = $this->newFormatter( 'request', [
			'4::requestTarget' => '<script>alert(1)</script>',
			'5:request-link:requestID' => 7,
		] );

		$this->assertStringNotContainsString( '<script>', $formatter->getActionText() );
	}
}
