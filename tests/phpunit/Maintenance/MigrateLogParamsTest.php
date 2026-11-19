<?php

namespace Miraheze\RequestCustomDomain\Tests\Maintenance;

use MediaWiki\Logging\LogEntryBase;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Title\TitleValue;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\RequestCustomDomain\Maintenance\MigrateLogParams;
use Wikimedia\TestingAccessWrapper;

/**
 * @group RequestCustomDomain
 * @group Database
 * @group Medium
 * @covers \Miraheze\RequestCustomDomain\Maintenance\MigrateLogParams
 */
class MigrateLogParamsTest extends MaintenanceBaseTestCase {

	/** @inheritDoc */
	protected function getMaintenanceClass(): string {
		return MigrateLogParams::class;
	}

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-requestcustomdomain' => [ 'db' => WikiMap::getCurrentWikiId() ],
		] );
	}

	private function newEntry( string $type, string $action, int $requestID, array $params ): ManualLogEntry {
		$entry = new ManualLogEntry( $type, $action );
		$entry->setPerformer( $this->getTestUser()->getUser() );
		$entry->setTarget( new TitleValue( NS_SPECIAL, "RequestCustomDomainQueue/$requestID" ) );
		$entry->setParameters( $params );

		return $entry;
	}

	private function addLog( string $type, string $action, int $requestID, array $params ): int {
		return $this->newEntry( $type, $action, $requestID, $params )->insert( $this->getDb() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getParams( int $logID ): array {
		$blob = $this->getDb()->newSelectQueryBuilder()
			->select( 'log_params' )
			->from( 'logging' )
			->where( [ 'log_id' => $logID ] )
			->caller( __METHOD__ )
			->fetchField();

		// @phan-suppress-next-line SecurityCheck-PHPSerializeInjection Classes are not allowed
		$params = LogEntryBase::extractParams( (string)$blob );
		$this->assertIsArray( $params );
		'@phan-var array<string, mixed> $params';

		return $params;
	}

	public function testMigratesRequestAndStatusEntries(): void {
		$request = $this->addLog( 'requestcustomdomain', 'request', 7, [
			'4::requestTarget' => 'testwiki',
			'5::requestLink' => Message::rawParam( '<a href="/wiki/Special:RequestCustomDomainQueue/7">#7</a>' ),
		] );

		$status = $this->addLog( 'requestcustomdomain', 'statusupdate', 9, [
			'4::requestLink' => Message::rawParam( '<a href="/x">#9</a>' ),
			'5::requestStatus' => 'in progress',
		] );

		$this->expectOutputRegex( '/Migrated 2 log entries\. Skipped 0\. Left 0 unknown statuses/' );
		$this->maintenance->execute();

		$this->assertSame( [
			'4::requestTarget' => 'testwiki',
			'5:request-link:requestID' => 7,
		], $this->getParams( $request ) );

		$this->assertSame( [
			'4:request-link:requestID' => 9,
			'5:request-status:requestStatus' => 'inprogress',
		], $this->getParams( $status ) );
	}

	public function testReadsRequestFromLinkWhenTitleHasNoId(): void {
		$logID = $this->addLog( 'requestcustomdomain', 'request', 0, [
			'4::requestTarget' => 'testwiki',
			'5::requestLink' => Message::rawParam( '<a href="/x">#12</a>' ),
		] );

		$this->getDb()->newUpdateQueryBuilder()
			->update( 'logging' )
			->set( [ 'log_title' => 'RequestCustomDomainQueue' ] )
			->where( [ 'log_id' => $logID ] )
			->caller( __METHOD__ )
			->execute();

		$this->expectOutputRegex( '/Migrated 1 log entries\./' );
		$this->maintenance->execute();

		$this->assertSame( 12, $this->getParams( $logID )['5:request-link:requestID'] );
	}

	public function testLeavesUnknownStatusTextAsItWas(): void {
		$logID = $this->addLog( 'requestcustomdomain', 'statusupdate', 3, [
			'4::requestLink' => Message::rawParam( '<a href="/x">#3</a>' ),
			'5::requestStatus' => 'something else',
		] );

		$this->expectOutputRegex( '/Migrated 1 log entries\. Skipped 0\. Left 1 unknown statuses/' );
		$this->maintenance->execute();

		$this->assertSame( [
			'4:request-link:requestID' => 3,
			'5:request-status:requestStatus' => 'something else',
		], $this->getParams( $logID ) );
	}

	public function testIgnoresEntriesThatAreAlreadyMigrated(): void {
		$params = [
			'4:request-link:requestID' => 5,
			'5:request-status:requestStatus' => 'complete',
		];

		$logID = $this->addLog( 'requestcustomdomain', 'statusupdate', 5, $params );

		$this->expectOutputRegex( '/Migrated 0 log entries/' );
		$this->maintenance->execute();

		$this->assertSame( $params, $this->getParams( $logID ) );
	}

	public function testIgnoresOtherLogTypes(): void {
		$logID = $this->addLog( 'otherlog', 'statusupdate', 1, [
			'4::requestLink' => Message::rawParam( '<a href="/x">#1</a>' ),
		] );

		$this->expectOutputRegex( '/Migrated 0 log entries/' );
		$this->maintenance->execute();

		$stored = $this->getParams( $logID );
		$this->assertArrayHasKey( '4::requestLink', $stored );
		$this->assertArrayNotHasKey( '4:request-link:requestID', $stored );
	}

	public function testUpdatesRecentChangesOnlyForEntriesThatHaveOne(): void {
		$entry = $this->newEntry( 'requestcustomdomain', 'statusupdate', 4, [
			'4::requestLink' => Message::rawParam( '<a href="/x">#4</a>' ),
			'5::requestStatus' => 'complete',
		] );

		$logID = $entry->insert( $this->getDb() );
		$entry->publish( $logID );

		$other = $this->addLog( 'requestcustomdomain', 'statusupdate', 5, [
			'4::requestLink' => Message::rawParam( '<a href="/x">#5</a>' ),
			'5::requestStatus' => 'complete',
		] );

		$this->expectOutputRegex( '/Migrated 2 log entries/' );
		$this->maintenance->execute();

		$blob = $this->getDb()->newSelectQueryBuilder()
			->select( 'rc_params' )
			->from( 'recentchanges' )
			->where( [ 'rc_logid' => $logID ] )
			->caller( __METHOD__ )
			->fetchField();

		$expected = [
			'4:request-link:requestID' => 4,
			'5:request-status:requestStatus' => 'complete',
		];

		// @phan-suppress-next-line SecurityCheck-PHPSerializeInjection Classes are not allowed
		$this->assertSame( $expected, LogEntryBase::extractParams( (string)$blob ) );
		$this->assertSame( $expected, $this->getParams( $logID ) );
		$this->assertSame( 5, $this->getParams( $other )['4:request-link:requestID'] );
	}

	public function testBatches(): void {
		$logIDs = [];
		foreach ( [ 1, 2, 3 ] as $requestID ) {
			$logIDs[$requestID] = $this->addLog( 'requestcustomdomain', 'request', $requestID, [
				'4::requestTarget' => 'testwiki',
				'5::requestLink' => Message::rawParam( "<a href=\"/x\">#$requestID</a>" ),
			] );
		}

		$maintenance = $this->maintenance;
		'@phan-var TestingAccessWrapper $maintenance';
		$maintenance->setBatchSize( 1 );

		$this->expectOutputRegex( '/Migrated 3 log entries/' );
		$this->maintenance->execute();

		foreach ( $logIDs as $requestID => $logID ) {
			$this->assertSame( $requestID, $this->getParams( $logID )['5:request-link:requestID'] );
		}
	}
}
