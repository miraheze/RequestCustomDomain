<?php

namespace Miraheze\RequestCustomDomain\Maintenance;

use MediaWiki\Logging\LogEntryBase;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\WikiMap\WikiMap;
use stdClass;
use Wikimedia\Rdbms\IDatabase;

class MigrateLogParams extends LoggedUpdateMaintenance {

	private const string LOG_TYPE = 'requestcustomdomain';

	private const array STATUSES = [ 'pending', 'inprogress', 'complete', 'declined' ];

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Rewrites old custom domain request log entries so they store the request ID and status ' .
			'instead of rendered HTML and translated text.'
		);

		$this->setBatchSize( 200 );
		$this->requireExtension( 'RequestCustomDomain' );
	}

	/** @inheritDoc */
	protected function getUpdateKey(): string {
		return 'RequestCustomDomain-MigrateLogParams';
	}

	/** @inheritDoc */
	protected function doDBUpdates(): true {
		$dbw = $this->getPrimaryDB( 'virtual-requestcustomdomain' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbw->getDomainID() ) ) {
			$this->output( "Custom domain request logs are not stored on this wiki. Skipping.\n" );
			return true;
		}

		$statuses = $this->getStatusMap();
		$recentChanges = $this->getRecentChangeIds( $dbw );
		$batchSize = max( 1, $this->getBatchSize() );

		$lastID = 0;
		$migrated = 0;
		$skipped = 0;
		$unmapped = 0;

		do {
			$res = $dbw->newSelectQueryBuilder()
				->select( [ 'log_id', 'log_title', 'log_params' ] )
				->from( 'logging' )
				->where( [ 'log_type' => self::LOG_TYPE ] )
				->andWhere( $dbw->expr( 'log_id', '>', $lastID ) )
				->orderBy( 'log_id' )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchResultSet();

			$count = 0;
			$this->beginTransactionRound( __METHOD__ );

			foreach ( $res as $row ) {
				'@phan-var stdClass $row';
				$count++;
				$lastID = (int)$row->log_id;

				// @phan-suppress-next-line SecurityCheck-PHPSerializeInjection Classes are not allowed
				$params = LogEntryBase::extractParams( (string)$row->log_params );
				if ( !is_array( $params ) || !$this->needsMigration( $params ) ) {
					continue;
				}

				$requestID = $this->getRequestID( (string)$row->log_title, $params );
				if ( $requestID === null ) {
					$this->output( "Could not find the request ID for log entry {$row->log_id}. Skipping.\n" );
					$skipped++;
					continue;
				}

				$unmapped += $this->convert(
					$params, $requestID, $statuses, (int)$row->log_id, $dbw, $recentChanges
				);

				$migrated++;
			}

			$this->commitTransactionRound( __METHOD__ );
		} while ( $count >= $batchSize );

		$this->output(
			"Migrated $migrated log entries. Skipped $skipped. " .
			"Left $unmapped unknown statuses as they were.\n"
		);

		return true;
	}

	/**
	 * @param array<string|int, mixed> $params
	 * @param int $requestID
	 * @param array<string, string> $statuses
	 * @param int $logID
	 * @param IDatabase $dbw
	 * @param array<int, int> $recentChanges Recent change ID by log ID
	 * @return int
	 */
	private function convert(
		array $params,
		int $requestID,
		array $statuses,
		int $logID,
		IDatabase $dbw,
		array $recentChanges
	): int {
		$unmapped = 0;
		$converted = [];

		foreach ( $params as $key => $value ) {
			$key = (string)$key;
			if ( str_ends_with( $key, '::requestLink' ) ) {
				$index = (int)$key;
				$converted["$index:request-link:requestID"] = $requestID;
				continue;
			}

			if ( str_ends_with( $key, '::requestStatus' ) ) {
				$index = (int)$key;
				$status = $statuses[(string)$value] ?? null;
				if ( $status === null ) {
					$unmapped++;
					$status = (string)$value;
				}

				$converted["$index:request-status:requestStatus"] = $status;
				continue;
			}

			$converted[$key] = $value;
		}

		$blob = LogEntryBase::makeParamBlob( $converted );

		$dbw->newUpdateQueryBuilder()
			->update( 'logging' )
			->set( [ 'log_params' => $blob ] )
			->where( [ 'log_id' => $logID ] )
			->caller( __METHOD__ )
			->execute();

		if ( isset( $recentChanges[$logID] ) ) {
			$dbw->newUpdateQueryBuilder()
				->update( 'recentchanges' )
				->set( [ 'rc_params' => $blob ] )
				->where( [ 'rc_id' => $recentChanges[$logID] ] )
				->caller( __METHOD__ )
				->execute();
		}

		return $unmapped;
	}

	/** @param array<string|int, mixed> $params */
	private function needsMigration( array $params ): bool {
		foreach ( array_keys( $params ) as $key ) {
			if ( str_ends_with( (string)$key, '::requestLink' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $title
	 * @param array<string|int, mixed> $params
	 * @return int|null
	 */
	private function getRequestID( string $title, array $params ): ?int {
		if ( preg_match( '!/(\d+)$!', $title, $matches ) ) {
			return (int)$matches[1];
		}

		foreach ( $params as $key => $value ) {
			if ( !str_ends_with( (string)$key, '::requestLink' ) ) {
				continue;
			}

			if ( preg_match( '/#(\d+)\s*<\/a>/', $this->getText( $value ), $matches ) ) {
				return (int)$matches[1];
			}
		}

		return null;
	}

	/**
	 * Recent changes expire, so most log entries no longer have a row there.
	 * Neither rc_logid nor rc_log_type is indexed, so they are looked up once here
	 * and updated by primary key instead of searching recentchanges for every entry.
	 *
	 * @return array<int, int> Recent change ID by log ID
	 */
	private function getRecentChangeIds( IDatabase $dbw ): array {
		$res = $dbw->newSelectQueryBuilder()
			->select( [ 'rc_id', 'rc_logid' ] )
			->from( 'recentchanges' )
			->where( [
				'rc_source' => RecentChange::SRC_LOG,
				'rc_log_type' => self::LOG_TYPE,
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$recentChanges = [];
		foreach ( $res as $row ) {
			'@phan-var stdClass $row';
			$recentChanges[(int)$row->rc_logid] = (int)$row->rc_id;
		}

		return $recentChanges;
	}

	/**
	 * Old entries hold a ScalarParam, which unserializes to an incomplete class.
	 * Its value is a protected property, so the array key has a prefix.
	 */
	private function getText( mixed $value ): string {
		if ( is_array( $value ) ) {
			return (string)( $value['raw'] ?? '' );
		}

		if ( !is_object( $value ) ) {
			return (string)$value;
		}

		foreach ( (array)$value as $key => $property ) {
			if ( is_string( $property ) && str_ends_with( (string)$key, 'value' ) ) {
				return $property;
			}
		}

		return '';
	}

	/**
	 * Old entries stored the status as lowercased text in the content language.
	 *
	 * @return array<string, string>
	 */
	private function getStatusMap(): array {
		$map = [];
		foreach ( self::STATUSES as $status ) {
			$label = wfMessage( "requestcustomdomain-label-$status" )->inContentLanguage()->text();
			$map[mb_strtolower( $label )] = $status;
			$map[$status] = $status;
		}

		return $map;
	}
}

// @codeCoverageIgnoreStart
return MigrateLogParams::class;
// @codeCoverageIgnoreEnd
