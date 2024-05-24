<?php

namespace MediaWiki\CheckUser\Tests\Integration\Investigate\Services;

use LogicException;
use MediaWiki\CheckUser\CheckUserQueryInterface;
use MediaWiki\CheckUser\Investigate\Services\TimelineService;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWikiIntegrationTestCase;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CheckUser
 * @group Database
 * @covers \MediaWiki\CheckUser\Investigate\Services\TimelineService
 * @covers \MediaWiki\CheckUser\Investigate\Services\ChangeService
 */
class TimelineServiceTest extends MediaWikiIntegrationTestCase {

	/**
	 * @dataProvider provideGetQueryInfo
	 */
	public function testGetQueryInfo(
		$targets, $excludeTargets, $start, $limit, $eventTablesMigrationStage, $expected
	) {
		$this->overrideConfigValue( 'CheckUserEventTablesMigrationStage', $eventTablesMigrationStage );
		$user1 = $this->createMock( UserIdentity::class );
		$user1->method( 'getId' )
			->willReturn( 11111 );

		$user2 = $this->createMock( UserIdentity::class );
		$user2->method( 'getId' )
			->willReturn( 22222 );

		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$userIdentityLookup->method( 'getUserIdentityByName' )
			->willReturnMap(
				[
					[ 'User1', 0, $user1, ],
					[ 'User2', 0, $user2, ],
				]
			);

		$timelineService = new TimelineService(
			new ServiceOptions(
				TimelineService::CONSTRUCTOR_OPTIONS,
				$this->getServiceContainer()->getMainConfig()
			),
			$this->getServiceContainer()->getConnectionProvider(),
			$userIdentityLookup,
			$this->getServiceContainer()->get( 'CheckUserLookupUtils' )
		);

		$queryInfo = $timelineService->getQueryInfo( $targets, $excludeTargets, $start, $limit );

		foreach ( $expected['targets'] as $target ) {
			$this->assertStringContainsString( $target, $queryInfo['tables']['a'] );
		}

		foreach ( $expected['excludedTargets'] ?? [] as $excludedTarget ) {
			$this->assertStringContainsString( $excludedTarget, $queryInfo['tables']['a'] );
		}

		foreach ( $expected['conds'] as $cond ) {
			$this->assertStringContainsString( $cond, $queryInfo['tables']['a'] );
		}

		if ( $eventTablesMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
			foreach ( CheckUserQueryInterface::RESULT_TABLES as $table ) {
				$this->assertStringContainsString( $table, $queryInfo['tables']['a'] );
				$columnPrefix = CheckUserQueryInterface::RESULT_TABLE_TO_PREFIX[$table];
				if ( $start === '' ) {
					$this->assertStringNotContainsString( $columnPrefix . 'timestamp >=', $queryInfo['tables']['a'] );
				} else {
					$this->assertStringContainsString(
						$columnPrefix . "timestamp >= '$start'", $queryInfo['tables']['a']
					);
				}
			}
			$this->assertStringContainsString( 'cuc_only_for_read_old = 0', $queryInfo['tables']['a'] );
		} else {
			$this->assertStringContainsString( CheckUserQueryInterface::CHANGES_TABLE, $queryInfo['tables']['a'] );
			$this->assertStringNotContainsString(
				CheckUserQueryInterface::PRIVATE_LOG_EVENT_TABLE, $queryInfo['tables']['a']
			);
			$this->assertStringNotContainsString( CheckUserQueryInterface::LOG_EVENT_TABLE, $queryInfo['tables']['a'] );
			if ( $start === '' ) {
				$this->assertStringNotContainsString( 'cuc_timestamp >=', $queryInfo['tables']['a'] );
			} else {
				$this->assertStringContainsString( "cuc_timestamp >= '$start'", $queryInfo['tables']['a'] );
				$this->assertStringNotContainsString( 'cule_timestamp', $queryInfo['tables']['a'] );
				$this->assertStringNotContainsString( 'cupe_timestamp', $queryInfo['tables']['a'] );
			}
			$this->assertStringNotContainsString( 'cuc_only_for_read_old', $queryInfo['tables']['a'] );
		}

		// This assertion will fail on SQLite, as it does not support ORDER BY and LIMIT in UNION queries
		// so only run the assertion if the DB supports this.
		if ( $this->getDb()->unionSupportsOrderAndLimit() ) {
			$actualLimit = $limit + 1;
			$this->assertStringContainsString( "LIMIT $actualLimit", $queryInfo['tables']['a'] );
		}
	}

	public static function provideGetQueryInfo() {
		$range = IPUtils::parseRange( '127.0.0.1/24' );
		return [
			'Valid username' => [
				[ 'User1' ], [],
				'', 500, SCHEMA_COMPAT_NEW,
				[
					'targets' => [ '11111' ],
					'conds' => [ 'actor_user' ],
				],
			],
			'Valid username while reading old' => [
				[ 'User1' ], [],
				'', 500, SCHEMA_COMPAT_OLD,
				[
					'targets' => [ '11111' ],
					'conds' => [ 'actor_user' ],
				],
			],
			'Valid username, with start' => [
				[ 'User1' ], [],
				'111', 500, SCHEMA_COMPAT_NEW,
				[
					'targets' => [ '11111' ],
					'conds' => [ 'actor_user' ],
				],
			],
			'Valid IP' => [
				[ '1.2.3.4' ], [],
				'', 500, SCHEMA_COMPAT_NEW,
				[
					'targets' => [ IPUtils::toHex( '1.2.3.4' ) ],
					'conds' => [ 'cuc_ip_hex' ],
				],
			],
			'Multiple valid targets' => [
				[ '1.2.3.4', 'User1' ], [],
				'', 500, SCHEMA_COMPAT_NEW,
				[
					'targets' => [ '11111', IPUtils::toHex( '1.2.3.4' ) ],
					'conds' => [ 'cuc_ip_hex', 'actor_user' ],
				],
			],
			'Multiple valid targets with some excluded' => [
				[ '1.2.3.4', 'User1' ], [ 'User2' ],
				'', 500, SCHEMA_COMPAT_NEW,
				[
					'targets' => [ '11111', IPUtils::toHex( '1.2.3.4' ) ],
					'excludedTargets' => [ '22222' ],
					'conds' => [ 'cuc_ip_hex', 'actor_user' ],
				],
			],
			'Valid IP range' => [
				[ '127.0.0.1/24', 'User1' ], [],
				'', 500, SCHEMA_COMPAT_NEW,
				[
					'targets' => [ '11111' ] + $range,
					'conds' => [ 'cuc_ip_hex >=', 'cuc_ip_hex <=', 'actor_user' ],
				],
			],
			'Some valid targets' => [
				[ 'User1', 'InvalidUser', '1.1..23', '::1' ], [],
				'', 20, SCHEMA_COMPAT_NEW,
				[
					'targets' => [ '11111', IPUtils::toHex( '::1' ) ],
					'conds' => [ 'actor_user', 'cuc_ip_hex' ],
				],
			],
		];
	}

	/** @dataProvider provideGetQueryInfoForInvalidTargets */
	public function testGetQueryInfoForInvalidTargets( $targets ) {
		$this->expectException( LogicException::class );
		$this->getServiceContainer()->get( 'CheckUserTimelineService' )->getQueryInfo( $targets, [], '', 500 );
	}

	public static function provideGetQueryInfoForInvalidTargets() {
		return [
			'Invalid targets' => [ [ 'InvalidUser' ] ],
			'Empty targets' => [ [] ],
		];
	}

	public function testCastValueToTypeForPostgres() {
		// Mock that the database that says it is the 'postgres' DB type.
		$mockDbr = $this->createMock( IReadableDatabase::class );
		$mockDbr->method( 'getType' )
			->willReturn( 'postgres' );
		$mockConnectionProvider = $this->createMock( IConnectionProvider::class );
		$mockConnectionProvider->method( 'getReplicaDatabase' )->willReturn( $mockDbr );
		// Get the object under test while using the mock IConnectionProvider that returns a mock DB type.
		$timelineService = new TimelineService(
			new ServiceOptions(
				TimelineService::CONSTRUCTOR_OPTIONS,
				$this->getServiceContainer()->getMainConfig()
			),
			$mockConnectionProvider,
			$this->getServiceContainer()->getUserIdentityLookup(),
			$this->getServiceContainer()->get( 'CheckUserLookupUtils' )
		);
		// Call the method under test
		$timelineService = TestingAccessWrapper::newFromObject( $timelineService );
		$this->assertSame(
			'CAST(0 AS smallint)',
			$timelineService->castValueToType( '0', 'smallint' )
		);
	}
}
