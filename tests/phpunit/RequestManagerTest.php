<?php

namespace Miraheze\RequestCustomDomain\Tests;

use MediaWiki\Logging\LogEntryBase;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\RequestCustomDomain\RequestManager;
use stdClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group RequestCustomDomain
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\RequestCustomDomain\RequestManager
 */
class RequestManagerTest extends MediaWikiIntegrationTestCase {

	public function addDBDataOnce(): void {
		$this->setMwGlobals( MainConfigNames::VirtualDomainsMapping, [
			'virtual-requestcustomdomain' => [ 'db' => 'wikidb' ],
		] );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbw = $connectionProvider->getPrimaryDatabase( 'virtual-requestcustomdomain' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'customdomain_requests' )
			->ignore()
			->row( [
				'request_customdomain' => 'https://requestcustomdomaintest.com',
				'request_target' => 'requestcustomdomaintest',
				'request_reason' => 'test',
				'request_status' => 'pending',
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->db->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'RequestCustomDomainManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$manager = $this->getServiceContainer()->getService( 'RequestCustomDomainManager' );
		$this->assertInstanceOf( RequestManager::class, $manager );
	}

	/**
	 * @covers ::loadFromID
	 */
	public function testLoadFromID(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertInstanceOf( RequestManager::class, $manager );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertTrue( $manager->exists() );

		$manager = $this->getRequestManager( id: 2 );
		$this->assertFalse( $manager->exists() );
	}

	/**
	 * @covers ::logStatusUpdate
	 */
	public function testLogStoresRequestDataInsteadOfRenderedText(): void {
		$manager = $this->getRequestManager( id: 1 );
		$manager->logStatusUpdate( 'Done', 'complete', $this->getTestUser()->getUser() );

		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'log_action', 'log_params' ] )
			->from( 'logging' )
			->where( [ 'log_type' => 'requestcustomdomain' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$params = [];
		foreach ( $rows as $row ) {
			'@phan-var stdClass $row';
			// @phan-suppress-next-line SecurityCheck-PHPSerializeInjection Classes are not allowed
			$params[$row->log_action] = LogEntryBase::extractParams( $row->log_params );
		}

		$this->assertSame( [
			'4:request-link:requestID' => 1,
			'5:request-status:requestStatus' => 'complete',
		], $params['statusupdate'] );
	}
}
