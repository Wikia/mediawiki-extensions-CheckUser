<?php

namespace MediaWiki\CheckUser\Test\Integration;

use ManualLogEntry;
use MediaWiki\CheckUser\Hooks;
use MWException;
use RecentChange;

/**
 * Can only be used in classes that extend MediaWikiIntegrationTestCase
 * and are in the Database group.
 */
trait CheckUserCommonTraitTest {
	/**
	 * A function used to insert a RecentChange into
	 *  the correct table when testing.
	 * Called by the individual tests themselves.
	 *
	 * @param array $rcAttribs The attribs for the RecentChange object
	 * @param array $fields The fields to select from the DB when using assertSelect()
	 * @param array &$expectedRow The expected values for the fields from the DB when using assertSelect()
	 * @return RecentChange
	 * @throws MWException If this test cases's needsDB() method doesn't return true.
	 *         Test cases can use "@group Database" to enable database test support,
	 *         or list the tables under testing in $this->tablesUsed, or override the
	 *         needsDB() method.
	 */
	public function commonTestsUpdateCheckUserData(
		array $rcAttribs, array $fields, array &$expectedRow
	): RecentChange {
		if ( !$this->needsDB() ) {
			throw new MWException( 'When testing with logs, the test cases\'s needsDB()' .
				' method should return true. Use @group Database or $this->tablesUsed.' );
		}
		$rc = new RecentChange;
		$rc->setAttribs( $rcAttribs );
		( new Hooks() )->updateCheckUserData( $rc );
		foreach ( $fields as $index => $field ) {
			if ( in_array( $field, [ 'cuc_timestamp', 'cule_timestamp', 'cupe_timestamp' ] ) ) {
				$expectedRow[$index] = $this->getDb()->timestamp( $expectedRow[$index] );
			}
		}
		return $rc;
	}

	/**
	 * Creates a log entry for testing.
	 *
	 * @return int The ID for the created log entry
	 * @throws MWException If this test cases's needsDB() method doesn't return true.
	 *         Test cases can use "@group Database" to enable database test support,
	 *         or list the tables under testing in $this->tablesUsed, or override the
	 *         needsDB() method.
	 */
	public function newLogEntry(): int {
		if ( !$this->needsDB() ) {
			throw new MWException( 'When testing with logs, the test cases\'s needsDB()' .
				' method should return true. Use @group Database or $this->tablesUsed.' );
		}
		$logEntry = new ManualLogEntry( 'phpunit', 'test' );
		$logEntry->setPerformer( $this->getTestUser()->getUserIdentity() );
		$logEntry->setTarget( $this->getExistingTestPage()->getTitle() );
		$logEntry->setComment( 'A very good reason' );
		return $logEntry->insert();
	}

	/**
	 * Asserts that a table has the expected number of rows matching
	 * the given conditions.
	 *
	 * @param int $expectedRowCount The expected row count
	 * @param string $table The table to select from
	 * @param string $idField The primary key for that table
	 * @param string $message The message to be used for an assertion failure.
	 * @param array $where Any conditions to apply (default no conditions; optional)
	 * @return void
	 * @throws MWException If this test cases's needsDB() method doesn't return true.
	 *         Test cases can use "@group Database" to enable database test support,
	 *         or list the tables under testing in $this->tablesUsed, or override the
	 *         needsDB() method.
	 */
	public function assertRowCount(
		int $expectedRowCount, string $table, string $idField, string $message, array $where = []
	) {
		if ( !$this->needsDB() ) {
			throw new MWException( 'When testing with logs, the test cases\'s needsDB()' .
				' method should return true. Use @group Database or $this->tablesUsed.' );
		}
		$this->assertSame(
			$expectedRowCount,
			$this->db->newSelectQueryBuilder()
				->field( $idField )
				->table( $table )
				->where( $where )
				->fetchRowCount(),
			$message
		);
	}

	/**
	 * Provides default attributes for a recent change.
	 * @return array
	 */
	public function getDefaultRecentChangeAttribs() {
		// From RecentChangeTest.php's provideAttribs
		return [
			'rc_timestamp' => wfTimestamp( TS_MW ),
			'rc_namespace' => NS_USER,
			'rc_title' => 'Tony',
			'rc_type' => RC_EDIT,
			'rc_source' => RecentChange::SRC_EDIT,
			'rc_minor' => 0,
			'rc_cur_id' => 77,
			'rc_user' => 858173476,
			'rc_user_text' => 'Tony',
			'rc_comment' => '',
			'rc_comment_text' => '',
			'rc_comment_data' => null,
			'rc_this_oldid' => 70,
			'rc_last_oldid' => 71,
			'rc_bot' => 0,
			'rc_ip' => '',
			'rc_patrolled' => 0,
			'rc_new' => 0,
			'rc_old_len' => 80,
			'rc_new_len' => 88,
			'rc_deleted' => 0,
			'rc_logid' => 0,
			'rc_log_type' => null,
			'rc_log_action' => '',
			'rc_params' => '',
		];
	}
}
