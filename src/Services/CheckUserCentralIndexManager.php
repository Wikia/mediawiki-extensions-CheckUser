<?php

namespace MediaWiki\CheckUser\Services;

use IDBAccessObject;
use Job;
use JobQueueGroup;
use JobSpecification;
use MediaWiki\CheckUser\CheckUserQueryInterface;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\LBFactory;

/**
 * Service to insert and delete rows in the CheckUser central index tables
 */
class CheckUserCentralIndexManager implements CheckUserQueryInterface {
	private LBFactory $lbFactory;
	private CentralIdLookup $centralIdLookup;
	private JobQueueGroup $jobQueueGroup;
	private LoggerInterface $logger;

	/**
	 * @param LBFactory $lbFactory
	 * @param CentralIdLookup $centralIdLookup
	 * @param JobQueueGroup $jobQueueGroup
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		LBFactory $lbFactory,
		CentralIdLookup $centralIdLookup,
		JobQueueGroup $jobQueueGroup,
		LoggerInterface $logger
	) {
		$this->lbFactory = $lbFactory;
		$this->centralIdLookup = $centralIdLookup;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->logger = $logger;
	}

	/**
	 * Records that an CheckUser logged action (defined as an action that caused an insert to a local CheckUser
	 * result table) has occurred for a given wiki.
	 *
	 * If this is called in code that is producing a web response, then this should be queued for execution
	 * via a DeferredUpdate to be run on POST_SEND so to not block the HTTP response.
	 *
	 * @param UserIdentity $performer The performer of the action that was logged to a local CheckUser result table
	 * @param string $domainID The domain ID for the wiki where the action was performed
	 * @param string $timestamp When the action was performed, as a TS_MW timestamp
	 * @return void
	 */
	public function recordActionInCentralIndexes( UserIdentity $performer, string $domainID, string $timestamp ) {
		// Don't record data when the user does not exist locally or is an IP address, as for the user central index
		// we need a central ID and for the temp edit index the performer has to be a temporary account.
		if ( !$performer->isRegistered() ) {
			return;
		}

		// Get the cuci_wiki_map ID for this domain, to be used in inserting data to the central indexes.
		$wikiMapId = $this->getWikiMapIdForDomainId( $domainID );

		// Update the cuci_user central index for this cuci_wiki_map ID and central ID combination.
		$this->recordActionInUserCentralIndex( $performer, $wikiMapId, $timestamp );
	}

	/**
	 * Records a CheckUser logged action into the cuci_user table for a given wiki and central ID.
	 *
	 * @param UserIdentity $performer The performer of the action that was logged to a local CheckUser result table
	 * @param int $wikiMapId The ciwm_id for the wiki where the action was performed
	 * @param string $timestamp When the action was performed, as a TS_MW timestamp
	 * @return void
	 */
	private function recordActionInUserCentralIndex( UserIdentity $performer, int $wikiMapId, string $timestamp ) {
		// Get the central ID associated with the $performer, trying primary if we cannot find the ID on a replica DB.
		// We may need to try the primary DB when we are recording a account creation action in the index.
		$centralId = $this->centralIdLookup->centralIdFromLocalUser( $performer, CentralIdLookup::AUDIENCE_RAW );

		if ( !$centralId ) {
			$centralId = $this->centralIdLookup->centralIdFromLocalUser(
				$performer, CentralIdLookup::AUDIENCE_RAW, IDBAccessObject::READ_LATEST
			);
		}

		if ( !$centralId ) {
			// We cannot record the action in the cuci_user table if we do not have a central ID for the performer.
			$this->logger->error(
				"Unable to find central ID for local user {username} when recording action in cuci_user table.",
				[ 'username' => $performer->getName() ]
			);
			return;
		}

		// Queue a job to update the cuci_user table. Using a newRootJobParams call ensures that if multiple jobs
		// are submitted at once, we only end up running the newest job.
		$jobParams = [ 'centralID' => $centralId, 'wikiMapID' => $wikiMapId, 'timestamp' => $timestamp ];
		$jobParams += Job::newRootJobParams( "updateUserCentralIndex:$wikiMapId:$centralId" );
		// Modify the 'rootJobTimestamp' to be the timestamp we are submitting, as this will ensure that the
		// newest timestamp will be processed out of a bunch of duplicate jobs.
		$jobParams['rootJobTimestamp'] = $timestamp;
		$this->jobQueueGroup->push( new JobSpecification( 'checkuserUpdateUserCentralIndexJob', $jobParams ) );
	}

	/**
	 * Gets the integer ID for the given string $domainID from the cuci_wiki_map table. Creates an ID if no ID
	 * already exists.
	 *
	 * @param string $domainID The domain ID for the local wiki
	 * @return int
	 */
	public function getWikiMapIdForDomainId( string $domainID ): int {
		// First try to get the wiki ID from the replica DB
		$dbr = $this->lbFactory->getReplicaDatabase( self::VIRTUAL_GLOBAL_DB_DOMAIN );
		$wikiMapId = $dbr->newSelectQueryBuilder()
			->select( 'ciwm_id' )
			->from( 'cuci_wiki_map' )
			->where( [ 'ciwm_wiki' => $domainID ] )
			->caller( __METHOD__ )
			->fetchField();
		if ( $wikiMapId !== false ) {
			return $wikiMapId;
		}

		// We could not find the wiki ID on the replica, so now try to insert the domain ID on the primary to get
		// an ID for this wiki. We need to do this using auto-commit mode, so that we can read the value from
		// the primary DB if the insert fails.
		$dbDomain = $this->lbFactory->getPrimaryDatabase( self::VIRTUAL_GLOBAL_DB_DOMAIN )->getDomainID();
		$dbw = $this->lbFactory->getLoadBalancer( self::VIRTUAL_GLOBAL_DB_DOMAIN )->getConnection(
			DB_PRIMARY, [], $dbDomain, ILoadBalancer::CONN_TRX_AUTOCOMMIT
		);

		// We could not find the wiki ID on the replica, so now try to insert the domain ID on the primary
		// to get an ID for this wiki.
		$dbw->newInsertQueryBuilder()
			->ignore()
			->insertInto( 'cuci_wiki_map' )
			->row( [ 'ciwm_wiki' => $domainID ] )
			->caller( __METHOD__ )
			->execute();

		// Now that we definitely have an ID for this domain ID, grab it from the primary DB.
		return $dbw->newSelectQueryBuilder()
			->select( 'ciwm_id' )
			->from( 'cuci_wiki_map' )
			->where( [ 'ciwm_wiki' => $domainID ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Purge a given number of expired rows from the central index tables where the wiki is the local wiki.
	 *
	 * We need to purge rows per-wiki, as each wiki can have it's own value for the expiry of CU data.
	 *
	 * @param string $cutoff The timestamp used as a "cutoff", where rows which have a timestamp before the given
	 *    cutoff are eligible to be purged from the database
	 * @param string $domain The DB name of the wiki that we are purging rows from
	 * @param int $maximumRowsToPurge The maximum number of rows to purge from cuci_temp_edit and cuci_user
	 * @return int The number of rows that were purged
	 */
	public function purgeExpiredRows( string $cutoff, string $domain, int $maximumRowsToPurge = 100 ): int {
		// Find the ID associated with this DB domain, or if it is not present in the cuci_wiki_map table then
		// return early as there will be no matching rows to purge.
		$dbr = $this->lbFactory->getReplicaDatabase( self::VIRTUAL_GLOBAL_DB_DOMAIN );
		$wikiId = $dbr->newSelectQueryBuilder()
			->select( 'ciwm_id' )
			->from( 'cuci_wiki_map' )
			->where( [ 'ciwm_wiki' => $domain ] )
			->caller( __METHOD__ )
			->fetchField();
		if ( $wikiId === false ) {
			return 0;
		}

		// First purge rows from cuci_temp_edit
		$dbw = $this->lbFactory->getPrimaryDatabase( self::VIRTUAL_GLOBAL_DB_DOMAIN );
		$ipsToPurge = $dbw->newSelectQueryBuilder()
			->forUpdate()
			->select( 'cite_ip_hex' )
			->from( 'cuci_temp_edit' )
			->where( [ 'cite_ciwm_id' => $wikiId, $dbw->expr( 'cite_timestamp', '<', $cutoff ) ] )
			->limit( $maximumRowsToPurge )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( count( $ipsToPurge ) ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'cuci_temp_edit' )
				->where( [ 'cite_ciwm_id' => $wikiId, 'cite_ip_hex' => $ipsToPurge ] )
				->caller( __METHOD__ )
				->execute();
		}

		// Then purge rows from cuci_user
		$centralIdsToPurge = $dbw->newSelectQueryBuilder()
			->forUpdate()
			->select( 'ciu_central_id' )
			->from( 'cuci_user' )
			->where( [ 'ciu_ciwm_id' => $wikiId, $dbw->expr( 'ciu_timestamp', '<', $cutoff ) ] )
			->limit( $maximumRowsToPurge )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( count( $centralIdsToPurge ) ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'cuci_user' )
				->where( [ 'ciu_ciwm_id' => $wikiId, 'ciu_central_id' => $centralIdsToPurge ] )
				->caller( __METHOD__ )
				->execute();
		}

		// Return the sum of the rows found for purging. We do this, instead of ::affectedRows, because the
		// aforementioned method does not work if a DELETE statement was not run (like in the case of
		// 0 rows found for purging).
		return count( $ipsToPurge ) + count( $centralIdsToPurge );
	}
}
