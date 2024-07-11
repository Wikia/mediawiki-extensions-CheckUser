<?php

namespace MediaWiki\CheckUser\Investigate\Services;

use LogicException;
use MediaWiki\CheckUser\Services\CheckUserLookupUtils;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\User\UserIdentityLookup;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Rdbms\Subquery;

class CompareService extends ChangeService {

	/**
	 * @internal For use by ServiceWiring
	 */
	public const CONSTRUCTOR_OPTIONS = [
		'CheckUserInvestigateMaximumRowCount',
		'CheckUserEventTablesMigrationStage',
	];

	private int $limit;

	/**
	 * @param ServiceOptions $options
	 * @param IConnectionProvider $dbProvider
	 * @param UserIdentityLookup $userIdentityLookup
	 * @param CheckUserLookupUtils $checkUserLookupUtils
	 */
	public function __construct(
		ServiceOptions $options,
		IConnectionProvider $dbProvider,
		UserIdentityLookup $userIdentityLookup,
		CheckUserLookupUtils $checkUserLookupUtils
	) {
		parent::__construct( $options, $dbProvider, $userIdentityLookup, $checkUserLookupUtils );

		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->limit = $options->get( 'CheckUserInvestigateMaximumRowCount' );
	}

	/**
	 * Get the total number of actions made from an IP.
	 *
	 * @param string $ipHex
	 * @return int
	 */
	public function getTotalActionsFromIP( string $ipHex ): int {
		$dbr = $this->dbProvider->getReplicaDatabase();

		// Select data from all three tables when reading new, and only cu_changes when reading old.
		$resultTables = self::RESULT_TABLES;
		if ( !$this->eventTableReadNew ) {
			$resultTables = [ self::CHANGES_TABLE ];
		}

		$totalCount = 0;
		foreach ( $resultTables as $table ) {
			$tablePrefix = self::RESULT_TABLE_TO_PREFIX[$table];
			$queryBuilder = $dbr->newSelectQueryBuilder()
				->select( $tablePrefix . 'id' )
				->from( $table )
				->where( [ $tablePrefix . 'ip_hex' => $ipHex ] )
				->limit( $this->limit )
				->caller( __METHOD__ );

			if ( $table === self::CHANGES_TABLE && $this->eventTableReadNew ) {
				// When reading new, exclude rows that are only for use when reading old.
				$queryBuilder->andWhere( [ 'cuc_only_for_read_old' => 0 ] );
			}

			$totalCount += $queryBuilder->fetchRowCount();
		}
		return $totalCount;
	}

	/**
	 * Get the compare query info
	 *
	 * @param string[] $targets
	 * @param string[] $excludeTargets
	 * @param string $start
	 * @return array
	 */
	public function getQueryInfo( array $targets, array $excludeTargets, string $start ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		if ( $targets === [] ) {
			throw new LogicException( 'Cannot get query info when $targets is empty.' );
		}
		$limit = $this->getLimitPerQuery( $targets );

		// Select data from all three tables when reading new, and only cu_changes when reading old.
		$resultTables = self::RESULT_TABLES;
		if ( !$this->eventTableReadNew ) {
			$resultTables = [ self::CHANGES_TABLE ];
		}

		$unionQueryBuilder = $dbr->newUnionQueryBuilder()->caller( __METHOD__ );
		foreach ( $targets as $target ) {
			foreach ( $resultTables as $table ) {
				if ( $table === self::CHANGES_TABLE ) {
					$queryBuilder = $this->getPartialQueryBuilderForCuChanges( $target, $excludeTargets, $start );
				} elseif ( $table === self::LOG_EVENT_TABLE ) {
					$queryBuilder = $this->getPartialQueryBuilderForCuLogEvent( $target, $excludeTargets, $start );
				} elseif ( $table === self::PRIVATE_LOG_EVENT_TABLE ) {
					$queryBuilder = $this->getPartialQueryBuilderForCuPrivateEvent( $target, $excludeTargets, $start );
				} else {
					throw new LogicException( "Invalid table: $table" );
				}
				if ( $queryBuilder !== null ) {
					if ( $dbr->unionSupportsOrderAndLimit() ) {
						// Add the limit to the query (if using LIMIT is supported in a UNION for this DB type).
						$queryBuilder->limit( $limit );
					}
					$queryBuilder->useIndex( [
						$table => $this->checkUserLookupUtils->getIndexName(
							IPUtils::isIPAddress( $target ) ? false : null, $table
						),
					] );
					$unionQueryBuilder->add( $queryBuilder );
				}
			}
		}

		$derivedTable = $unionQueryBuilder->getSQL();

		return [
			'tables' => [ 'a' => new Subquery( $derivedTable ) ],
			'fields' => [
				'user' => 'a.user',
				'user_text' => 'a.user_text',
				'actor' => 'MIN(a.actor)',
				'ip' => 'a.ip',
				'ip_hex' => 'a.ip_hex',
				'agent' => 'a.agent',
				'first_action' => 'MIN(a.timestamp)',
				'last_action' => 'MAX(a.timestamp)',
				'total_actions' => 'count(*)',
			],
			'options' => [
				'GROUP BY' => [
					'user',
					'user_text',
					'ip',
					'ip_hex',
					'agent',
				],
			],
		];
	}

	/**
	 * Get the SelectQueryBuilder which can be used to query cu_changes for results. This must be
	 * extended with table independent query information.
	 *
	 * @param string $target The target of this specific query
	 * @param string[] $excludeTargets The targets to exclude from the query
	 * @param string $start The start offset, used for paging.
	 * @return ?SelectQueryBuilder The query builder specific to cu_changes, or null if no valid query builder could
	 *   be generated.
	 */
	private function getPartialQueryBuilderForCuChanges(
		string $target, array $excludeTargets, string $start
	): ?SelectQueryBuilder {
		$targetExpr = $this->buildExprForSingleTarget( $target, $excludeTargets, $start, self::CHANGES_TABLE );
		if ( $targetExpr === null ) {
			return null;
		}
		$dbr = $this->dbProvider->getReplicaDatabase();
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( [
				'id' => 'cuc_id', 'user' => 'actor_user', 'user_text' => 'actor_name', 'actor' => 'cuc_actor',
				'ip' => 'cuc_ip', 'ip_hex' => 'cuc_ip_hex', 'agent' => 'cuc_agent', 'timestamp' => 'cuc_timestamp',
			] )
			->from( 'cu_changes' )
			->join( 'actor', null, 'actor_id=cuc_actor' )
			->where( $targetExpr )
			->caller( __METHOD__ );
		if ( $dbr->unionSupportsOrderAndLimit() ) {
			// TODO: T360712: Add cuc_id to the ORDER BY clause to ensure unique ordering.
			$queryBuilder->orderBy( 'cuc_timestamp', SelectQueryBuilder::SORT_DESC );
		}
		// When reading new, only select results from cu_changes that are
		// for read new (defined as those with cuc_only_for_read_old set to 0).
		if ( $this->eventTableReadNew ) {
			$queryBuilder->andWhere( [ 'cuc_only_for_read_old' => 0 ] );
		}
		return $queryBuilder;
	}

	/**
	 * Get the SelectQueryBuilder which can be used to query cu_log_event for results. This must be
	 * extended with table independent query information.
	 *
	 * @param string $target The target of this specific query
	 * @param string[] $excludeTargets The targets to exclude from the query
	 * @param string $start The start offset, used for paging.
	 * @return ?SelectQueryBuilder The query builder specific to cu_log_event, or null if no valid query builder could
	 *    be generated.
	 */
	private function getPartialQueryBuilderForCuLogEvent(
		string $target, array $excludeTargets, string $start
	): ?SelectQueryBuilder {
		$targetExpr = $this->buildExprForSingleTarget( $target, $excludeTargets, $start, self::LOG_EVENT_TABLE );
		if ( $targetExpr === null ) {
			return null;
		}
		$dbr = $this->dbProvider->getReplicaDatabase();
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( [
				'id' => 'cule_id', 'user' => 'actor_user', 'user_text' => 'actor_name', 'actor' => 'cule_actor',
				'ip' => 'cule_ip', 'ip_hex' => 'cule_ip_hex', 'agent' => 'cule_agent', 'timestamp' => 'cule_timestamp',
			] )
			->from( 'cu_log_event' )
			->join( 'actor', null, 'actor_id=cule_actor' )
			->where( $targetExpr )
			->caller( __METHOD__ );
		if ( $dbr->unionSupportsOrderAndLimit() ) {
			// TODO: T360712: Add cule_id to the ORDER BY clause to ensure unique ordering.
			$queryBuilder->orderBy( 'cule_timestamp', SelectQueryBuilder::SORT_DESC );
		}
		return $queryBuilder;
	}

	/**
	 * Get the SelectQueryBuilder which can be used to query cu_private_event for results. This must be
	 * extended with table independent query information.
	 *
	 * @param string $target The target of this specific query
	 * @param string[] $excludeTargets The targets to exclude from the query
	 * @param string $start The start offset, used for paging.
	 * @return ?SelectQueryBuilder The query builder specific to cu_private_event, or null if no valid query builder
	 *     could be generated.
	 */
	private function getPartialQueryBuilderForCuPrivateEvent(
		string $target, array $excludeTargets, string $start
	): ?SelectQueryBuilder {
		$targetExpr = $this->buildExprForSingleTarget(
			$target, $excludeTargets, $start, self::PRIVATE_LOG_EVENT_TABLE
		);
		if ( $targetExpr === null ) {
			return null;
		}
		$dbr = $this->dbProvider->getReplicaDatabase();
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( [
				'id' => 'cupe_id', 'user' => 'actor_user', 'user_text' => 'actor_name', 'actor' => 'cupe_actor',
				'ip' => 'cupe_ip', 'ip_hex' => 'cupe_ip_hex', 'agent' => 'cupe_agent', 'timestamp' => 'cupe_timestamp',
			] )
			->from( 'cu_private_event' )
			->where( $targetExpr )
			->caller( __METHOD__ );
		if ( IPUtils::isIPAddress( $target ) ) {
			// A LEFT JOIN is required because cupe_actor can be NULL if the performer was an IP address and temporary
			// accounts were enabled.
			$queryBuilder->leftJoin( 'actor', null, 'actor_id=cupe_actor' );
		} else {
			// We only need a JOIN if the target of the check is a username because the username will have a valid
			// actor ID.
			$queryBuilder->join( 'actor', null, 'actor_id=cupe_actor' );
		}
		if ( $dbr->unionSupportsOrderAndLimit() ) {
			// TODO: T360712: Add cupe_id to the ORDER BY clause to ensure unique ordering.
			$queryBuilder->orderBy( 'cupe_timestamp', SelectQueryBuilder::SORT_DESC );
		}
		return $queryBuilder;
	}

	/**
	 * Get the WHERE conditions for a single target in an IExpression object.
	 *
	 * For the main investigation, this is used in a subquery that contributes to a derived
	 * table, used by getQueryInfo.
	 *
	 * For a limit check, this is used to build a query that is used to check whether the number of results for
	 * the target exceed the limit-per-target in getQueryInfo.
	 *
	 * @param string $target
	 * @param string[] $excludeTargets
	 * @param string $start
	 * @param string $table
	 * @return IExpression|null Return null for invalid target.
	 */
	private function buildExprForSingleTarget(
		string $target,
		array $excludeTargets,
		string $start,
		string $table
	): ?IExpression {
		$targetExpr = $this->buildTargetExpr( $target, $table );
		if ( $targetExpr === null ) {
			return null;
		}

		$andExpressionGroup = $this->dbProvider->getReplicaDatabase()->andExpr( [ $targetExpr ] );

		// Add the WHERE conditions to exclude the targets in the $excludeTargets array, if they can be generated.
		$excludeTargetsExpr = $this->buildExcludeTargetsExpr( $excludeTargets, $table );
		if ( $excludeTargetsExpr !== null ) {
			$andExpressionGroup = $andExpressionGroup->andExpr( $excludeTargetsExpr );
		}
		// Add the start timestamp WHERE conditions to the query, if they can be generated.
		$startExpr = $this->buildStartExpr( $start, $table );
		if ( $startExpr !== null ) {
			$andExpressionGroup = $andExpressionGroup->andExpr( $startExpr );
		}

		return $andExpressionGroup;
	}

	/**
	 * We set a maximum number of rows per table per target to be grouped in the Compare table query,
	 * for performance reasons (see ::getQueryInfo). We share these uniformly between the targets,
	 * so the maximum number of rows per target is the limit divided by the number of targets divided again
	 * by the number of tables that will be queried.
	 *
	 * @param array $targets
	 * @return int
	 */
	private function getLimitPerQuery( array $targets ) {
		$divisor = count( $targets );
		if ( $this->eventTableReadNew ) {
			$divisor *= 3;
		}
		return ceil( $this->limit / $divisor );
	}

	/**
	 * Check if we have incomplete data for any of the targets.
	 *
	 * @param string[] $targets
	 * @param string[] $excludeTargets
	 * @param string $start
	 * @return string[]
	 */
	public function getTargetsOverLimit(
		array $targets,
		array $excludeTargets,
		string $start
	): array {
		if ( $targets === [] ) {
			return $targets;
		}

		$dbr = $this->dbProvider->getReplicaDatabase();

		// If the database does not support order and limit on a UNION
		// then none of the targets can be over the limit.
		if ( !$dbr->unionSupportsOrderAndLimit() ) {
			return [];
		}

		$targetsOverLimit = [];
		$offset = $this->getLimitPerQuery( $targets );

		// Select data from all three tables when reading new, and only cu_changes when reading old.
		$resultTables = self::RESULT_TABLES;
		if ( !$this->eventTableReadNew ) {
			$resultTables = [ self::CHANGES_TABLE ];
		}

		foreach ( $targets as $target ) {
			foreach ( $resultTables as $table ) {
				$targetExpr = $this->buildExprForSingleTarget( $target, $excludeTargets, $start, $table );
				if ( $targetExpr !== null ) {
					$tablePrefix = self::RESULT_TABLE_TO_PREFIX[$table];
					$limitCheck = $dbr->newSelectQueryBuilder()
						->select( $tablePrefix . 'id' )
						->from( $table )
						->where( $targetExpr )
						->offset( $offset )
						->limit( 1 )
						->caller( __METHOD__ );
					if ( $table === self::CHANGES_TABLE && $this->eventTableReadNew ) {
						// When reading new, exclude rows from the cu_changes query that are only for use
						// when reading old.
						$limitCheck->andWhere( [ 'cuc_only_for_read_old' => 0 ] );
					}
					if ( $table === self::PRIVATE_LOG_EVENT_TABLE && IPUtils::isIPAddress( $target ) ) {
						// A LEFT JOIN is required because cupe_actor can be NULL if the performer was an IP address
						// and temporary accounts were enabled.
						$limitCheck->leftJoin( 'actor', null, 'actor_id=cupe_actor' );
					} else {
						// We only need a JOIN if the target of the check is a username or the table isn't the
						// cu_private_event table because all rows should have a valid actor ID.
						$limitCheck->join( 'actor', null, 'actor_id=' . $tablePrefix . 'actor' );
					}
					if ( $limitCheck->fetchRowCount() > 0 ) {
						$targetsOverLimit[] = $target;
						// If the target is over limit, then no need to check the other result tables too to see if
						// the target is over limit.
						break;
					}
				}
			}
		}

		return $targetsOverLimit;
	}
}
