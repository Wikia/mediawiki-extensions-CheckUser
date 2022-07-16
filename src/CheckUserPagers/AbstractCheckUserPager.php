<?php

namespace MediaWiki\CheckUser\CheckUserPagers;

use ActorMigration;
use CentralIdLookup;
use FormOptions;
use Html;
use HtmlArmor;
use IContextSource;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\CheckUser\CheckUserLogService;
use MediaWiki\CheckUser\Specials\SpecialCheckUser;
use MediaWiki\CheckUser\TokenQueryManager;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use RangeChronologicalPager;
use RequestContext;
use SpecialPage;
use Title;
use TitleValue;
use User;
use UserGroupMembership;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

abstract class AbstractCheckUserPager extends RangeChronologicalPager {

	public const TOKEN_MANAGED_FIELDS = [
		'reason',
		'checktype',
		'period',
		'dir',
		'limit',
		'offset',
	];

	/** @var string */
	private $logType;

	/** @var FormOptions */
	protected $opts;

	/** @var UserGroupManager */
	protected $userGroupManager;

	/** @var CentralIdLookup */
	protected $centralIdLookup;

	/** @var UserIdentity */
	protected $target;

	/**
	 * @var bool skip the query if some parsing problem happens in getQueryInfo()
	 *   that should return with a FakeResultWrapper of no results.
	 */
	protected $skipQuery = false;

	/** @var TokenQueryManager */
	private $tokenQueryManager;

	/** @var SpecialPageFactory */
	private $specialPageFactory;

	/** @var UserIdentityLookup */
	private $userIdentityLookup;

	/** @var ActorMigration */
	private $actorMigration;

	/**
	 * @var CheckUserLogService
	 */
	private $checkUserLogService;

	/**
	 * @param FormOptions $opts
	 * @param UserIdentity $target
	 * @param string $logType
	 * @param TokenQueryManager $tokenQueryManager
	 * @param UserGroupManager $userGroupManager
	 * @param CentralIdLookup $centralIdLookup
	 * @param ILoadBalancer $loadBalancer
	 * @param SpecialPageFactory $specialPageFactory
	 * @param UserIdentityLookup $userIdentityLookup
	 * @param ActorMigration $actorMigration
	 * @param CheckUserLogService $checkUserLogService
	 * @param IContextSource|null $context
	 * @param LinkRenderer|null $linkRenderer
	 * @param ?int $limit
	 */
	public function __construct(
		FormOptions $opts,
		UserIdentity $target,
		string $logType,
		TokenQueryManager $tokenQueryManager,
		UserGroupManager $userGroupManager,
		CentralIdLookup $centralIdLookup,
		ILoadBalancer $loadBalancer,
		SpecialPageFactory $specialPageFactory,
		UserIdentityLookup $userIdentityLookup,
		ActorMigration $actorMigration,
		CheckUserLogService $checkUserLogService,
		IContextSource $context = null,
		LinkRenderer $linkRenderer = null,
		?int $limit = null
	) {
		$this->opts = $opts;
		$this->target = $target;
		$this->logType = $logType;

		$this->mDb = $loadBalancer->getConnection( DB_REPLICA );

		parent::__construct( $context, $linkRenderer );

		$maximumRowCount = $this->getConfig()->get( 'CheckUserMaximumRowCount' );
		$this->mDefaultLimit = $limit ?? $maximumRowCount;
		if ( $this->opts->getValue( 'limit' ) ) {
			$this->mLimit = min(
				$this->opts->getValue( 'limit' ),
				$this->getConfig()->get( 'CheckUserMaximumRowCount' )
			);
		} else {
			$this->mLimit = $maximumRowCount;
		}

		$this->mLimitsShown = [
			$maximumRowCount / 25,
			$maximumRowCount / 10,
			$maximumRowCount / 5,
			$maximumRowCount / 2,
			$maximumRowCount,
		];

		$this->mLimitsShown = array_map( 'ceil', $this->mLimitsShown );
		$this->mLimitsShown = array_unique( $this->mLimitsShown );

		$this->userGroupManager = $userGroupManager;
		$this->centralIdLookup = $centralIdLookup;
		$this->tokenQueryManager = $tokenQueryManager;
		$this->specialPageFactory = $specialPageFactory;
		$this->userIdentityLookup = $userIdentityLookup;
		$this->actorMigration = $actorMigration;
		$this->checkUserLogService = $checkUserLogService;

		// Get any set token data. Used for paging without adding extra logs
		$tokenData = $this->tokenQueryManager->getDataFromRequest( $this->getRequest() );
		if ( !$tokenData ) {
			// Log if the token data is not set. A token will only be generated by
			//  the server for CheckUser for paging links after running a check.
			//  It will also only be valid if not tampered with as it's encrypted.
			//  Paging through the entries won't need an extra log entry.
			$this->checkUserLogService->addLogEntry(
				$this->getUser(),
				$this->logType,
				$target->getId() ? 'user' : 'ip',
				$target->getName(),
				$this->opts->getValue( 'reason' ),
				$target->getId()
			);
		}

		$this->getDateRangeCond( '', '' );
	}

	/**
	 * Get the cutoff timestamp and add it to the range conditions for the query
	 *
	 * @param string $startStamp Ignored.
	 * @param string $endStamp Ignored.
	 * @return array the range conditions which are also set in $this->rangeConds
	 */
	public function getDateRangeCond( $startStamp, $endStamp ): array {
		$this->rangeConds = [];
		$period = $this->opts->getValue( 'period' );
		if ( $period ) {
			$cutoff_unixtime = ConvertibleTimestamp::time() - ( $period * 24 * 3600 );
			$cutoff_unixtime -= $cutoff_unixtime % 86400;
			$cutoff = $this->mDb->addQuotes( $this->mDb->timestamp( $cutoff_unixtime ) );
			$this->rangeConds = [ "cuc_timestamp > $cutoff" ];
		}

		return $this->rangeConds;
	}

	/**
	 * Get formatted timestamp(s) to show the time of first and last change.
	 * If both timestamps are the same, it will be shown only once.
	 *
	 * @param string $first Timestamp of the first change
	 * @param string $last Timestamp of the last change
	 * @return string
	 */
	protected function getTimeRangeString( string $first, string $last ): string {
		$s = $this->getFormattedTimestamp( $first );
		if ( $first !== $last ) {
			// @todo i18n issue - hardcoded string
			$s .= ' -- ';
			$s .= $this->getFormattedTimestamp( $last );
		}
		return Html::rawElement(
			'span',
			[ 'class' => 'mw-changeslist-links' ],
			htmlspecialchars( $s )
		);
	}

	/**
	 * Get a link to block information about the passed block for displaying to the user.
	 *
	 * @param DatabaseBlock $block
	 * @return string
	 */
	protected function getBlockFlag( DatabaseBlock $block ): string {
		if ( $block->getType() == DatabaseBlock::TYPE_AUTO ) {
			$ret = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'BlockList' ),
				$this->msg( 'checkuser-blocked' )->text(),
				[],
				[ 'wpTarget' => "#{$block->getId()}" ]
			);
		} else {
			$userPage = Title::makeTitle( NS_USER, $block->getTargetName() );
			$ret = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'Log' ),
				$this->msg( 'checkuser-blocked' )->text(),
				[],
				[
					'type' => 'block',
					'page' => $userPage->getPrefixedText()
				]
			);

			// Add the blocked range if the block is on a range
			if ( $block->getType() == DatabaseBlock::TYPE_RANGE ) {
				$ret .= ' - ' . htmlspecialchars( $block->getTargetName() );
			}
		}

		return Html::rawElement(
			'strong',
			[ 'class' => 'mw-changeslist-links' ],
			$ret
		);
	}

	/**
	 * Get an HTML link (<a> element) to Special:CheckUser
	 *
	 * @param string $text content to use within <a> tag
	 * @param array $params query parameters to use in the URL
	 * @return string
	 */
	protected function getSelfLink( string $text, array $params ): string {
		$title = $this->getTitleValue();
		return $this->getLinkRenderer()->makeKnownLink(
			$title,
			new HtmlArmor( '<bdi>' . htmlspecialchars( $text ) . '</bdi>' ),
			[],
			$params
		);
	}

	/**
	 * @param string $page the string title get the TitleValue for.
	 * @return TitleValue the associated TitleValue object
	 */
	protected function getTitleValue( string $page = 'CheckUser' ): TitleValue {
		return new TitleValue(
			NS_SPECIAL,
			$this->specialPageFactory->getLocalNameFor( $page )
		);
	}

	/**
	 * @param string $page the string title get the Title for.
	 * @return Title the associated Title object
	 */
	protected function getPageTitle( string $page = 'CheckUser' ): Title {
		return Title::newFromLinkTarget(
			$this->getTitleValue( $page )
		);
	}

	/**
	 * Get a formatted timestamp string in the current language
	 * for displaying to the user.
	 *
	 * @param string $timestamp
	 * @return string
	 */
	protected function getFormattedTimestamp( string $timestamp ): string {
		return $this->getLanguage()->userTimeAndDate(
			wfTimestamp( TS_MW, $timestamp ), $this->getUser()
		);
	}

	/**
	 * Give a "no matches found for X" message.
	 * If $checkLast, then mention the last edit by this user or IP.
	 *
	 * @param string $userName
	 * @param bool $checkLast
	 * @return string
	 */
	protected function noMatchesMessage( string $userName, bool $checkLast = true ): string {
		if ( $checkLast ) {
			$user = $this->userIdentityLookup->getUserIdentityByName( $userName );

			$lastEdit = false;

			$revWhere = $this->actorMigration->getWhere( $this->mDb, 'rev_user', $user );
			foreach ( $revWhere['orconds'] as $cond ) {
				$lastEdit = max( $lastEdit, $this->mDb->newSelectQueryBuilder()
					->tables( [ 'revision' ] + $revWhere['tables'] )
					->field( 'rev_timestamp' )
					->conds( $cond )
					->orderBy( 'rev_timestamp', SelectQueryBuilder::SORT_DESC )
					->joinConds( $revWhere['joins'] )
					->caller( __METHOD__ )
					->fetchField()
				);
			}
			$lastEdit = max( $lastEdit, $this->mDb->newSelectQueryBuilder()
				->table( 'logging' )
				->field( 'log_timestamp' )
				->orderBy( 'log_timestamp', SelectQueryBuilder::SORT_DESC )
				->join( 'actor', null, 'actor_id=log_actor' )
				->where( [ 'actor_name' => $userName ] )
				->caller( __METHOD__ )
				->fetchField()
			);

			if ( $lastEdit ) {
				$lastEditTime = wfTimestamp( TS_MW, $lastEdit );
				$lang = $this->getLanguage();
				$contextUser = $this->getUser();
				// FIXME: don't pass around parsed messages
				return $this->msg( 'checkuser-nomatch-edits',
					$lang->userDate( $lastEditTime, $contextUser ),
					$lang->userTime( $lastEditTime, $contextUser )
				)->parseAsBlock();
			}
		}
		return $this->msg( 'checkuser-nomatch' )->parseAsBlock();
	}

	/**
	 * @param string $ip
	 * @param int $userId
	 * @param User $user
	 * @return array
	 */
	protected function userBlockFlags( string $ip, int $userId, User $user ): array {
		$flags = [];

		$block = DatabaseBlock::newFromTarget( $user, $ip );
		if ( $block instanceof DatabaseBlock ) {
			// Locally blocked
			$flags[] = $this->getBlockFlag( $block );
		} elseif ( $ip == $user->getName() && $user->isBlockedGlobally( $ip ) ) {
			// Globally blocked IP
			$flags[] = '<strong>(' . $this->msg( 'checkuser-gblocked' )->escaped() . ')</strong>';
		} elseif ( self::userWasBlocked( $user->getName() ) ) {
			// Previously blocked
			$userpage = $user->getUserPage();
			$blocklog = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'Log' ),
				$this->msg( 'checkuser-wasblocked' )->text(),
				[],
				[
					'type' => 'block',
					'page' => $userpage->getPrefixedText()
				]
			);
			$flags[] = Html::rawElement( 'strong', [ 'class' => 'mw-changeslist-links' ], $blocklog );
		}

		// Show if account is local only
		if ( $user->getId() &&
			$this->centralIdLookup
				->centralIdFromLocalUser( $user, CentralIdLookup::AUDIENCE_RAW ) === 0
		) {
			$flags[] = Html::rawElement(
				'strong',
				[ 'class' => 'mw-changeslist-links' ],
				$this->msg( 'checkuser-localonly' )->escaped()
			);
		}
		// Check for extra user rights...
		if ( $userId ) {
			if ( $user->isLocked() ) {
				$flags[] = Html::rawElement(
					'strong',
					[ 'class' => 'mw-changeslist-links' ],
					$this->msg( 'checkuser-locked' )->escaped()
				);
			}
			$list = [];
			foreach ( $this->userGroupManager->getUserGroups( $user ) as $group ) {
				$list[] = self::buildGroupLink( $group );
			}
			$groups = $this->getLanguage()->commaList( $list );
			if ( $groups ) {
				$flags[] = Html::rawElement( 'i', [ 'class' => 'mw-changeslist-links' ], $groups );
			}
		}

		return $flags;
	}

	/**
	 * Format a link to a group description page
	 *
	 * @param string $group
	 * @return string
	 */
	protected static function buildGroupLink( string $group ): string {
		static $cache = [];
		if ( !isset( $cache[$group] ) ) {
			$cache[$group] = UserGroupMembership::getLink(
				$group, RequestContext::getMain(), 'html'
			);
		}
		return $cache[$group];
	}

	/**
	 * Get whether the user has ever been blocked.
	 *
	 * @param string $name the user name
	 * @return bool whether the user with that username has ever been blocked
	 */
	protected function userWasBlocked( string $name ): bool {
		$userpage = Title::makeTitle( NS_USER, $name );

		return (bool)$this->mDb->newSelectQueryBuilder()
			->table( 'logging' )
			->field( '1' )
			->conds( [
				'log_type' => [ 'block', 'suppress' ],
				'log_action' => 'block',
				'log_namespace' => $userpage->getNamespace(),
				'log_title' => $userpage->getDBkey()
			] )
			->useIndex( 'log_page_time' )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Get the WHERE conditions for an IP address / range, optionally as a XFF.
	 *
	 * @param IDatabase $db
	 * @param string $target an IP address or CIDR range
	 * @param string|bool $xfor
	 * @return array|false array for valid conditions, false if invalid
	 */
	public static function getIpConds( IDatabase $db, string $target, $xfor = false ) {
		$type = $xfor ? 'xff' : 'ip';

		if ( !SpecialCheckUser::isValidRange( $target ) ) {
			return false;
		}

		if ( IPUtils::isValidRange( $target ) ) {
			list( $start, $end ) = IPUtils::parseRange( $target );
			return [ 'cuc_' . $type . '_hex BETWEEN ' . $db->addQuotes( $start ) .
				' AND ' . $db->addQuotes( $end ) ];
		} elseif ( IPUtils::isValid( $target ) ) {
			return [ "cuc_{$type}_hex" => IPUtils::toHex( $target ) ];
		}
		// invalid IP
		return false;
	}

	/** @inheritDoc */
	public function reallyDoQuery( $offset, $limit, $order ) {
		if ( $this->skipQuery ) {
			return new FakeResultWrapper( [] );
		}
		list( $tables, $fields, $conds, $fname, $options, $join_conds ) =
			$this->buildQueryInfo( $offset, $limit, $order );

		return $this->mDb->select( $tables, $fields, $conds, $fname, $options, $join_conds );
	}

	/** @inheritDoc */
	protected function getStartBody(): string {
		$s = $this->getNavigationBar();
		$s .= '<div id="checkuserresults">';

		return $s;
	}

	/** @inheritDoc */
	protected function getEndBody(): string {
		return $this->getNavigationBar();
	}

	/** @inheritDoc */
	protected function makeLink( $text = null, $query = null, $type = null ): string {
		$attrs = [];
		if ( $query !== null && in_array( $type, [ 'prev', 'next' ] ) ) {
			$attrs['rel'] = $type;
		}

		if ( in_array( $type, [ 'asc', 'desc' ] ) ) {
			$attrs['title'] = $this->msg( $type == 'asc' ? 'sort-ascending' : 'sort-descending' )->text();
		}

		if ( $type ) {
			$attrs['class'] = "mw-{$type}link ";
		} else {
			$attrs['class'] = '';
		}

		if ( $query === null ) {
			return Html::rawElement( 'span', $attrs, $text );
		}
		$query += $this->getDefaultQuery();
		$attrs['class'] .= 'mw-checkuser-paging-links';
		$opts = $this->opts;
		$fields = array_filter( self::TOKEN_MANAGED_FIELDS, static function ( $field ) use ( $opts ) {
			return $opts->validateName( $field );
		} );
		$fieldData = [];
		foreach ( $fields as $field ) {
			if ( !in_array( $field, [ 'dir', 'offset', 'limit' ] ) ) {
				$fieldData[$field] = $this->opts->getValue( $field );
			} else {
				// Never persist the dir, offset and limit
				// as the pagination links are responsible
				// for setting or not setting them.
				$fieldData[$field] = null;
			}
		}

		$fieldData['user'] = $this->target->getName();
		if ( $query ) {
			foreach ( $query as $queryItem => $queryValue ) {
				$fieldData[$queryItem] = $queryValue;
			}
		}
		$formFields = [ Html::hidden(
			'wpEditToken',
			$this->getCsrfTokenSet()->getToken(),
			[ 'id' => 'wpEditToken' ]
		) ];
		$formFields[] = Html::hidden(
			'token',
			$this->tokenQueryManager->updateToken( $this->getRequest(), $fieldData )
		);
		$formFields[] = Html::submitButton(
			$text, $attrs
		);
		return Html::rawElement( 'form', [ 'method' => 'post', 'class' => 'mw-checkuser-paging-links-form' ],
			implode( '', $formFields )
		);
	}
}
