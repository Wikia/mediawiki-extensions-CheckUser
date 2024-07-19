<?php

namespace MediaWiki\CheckUser\IPContributions;

use ErrorPageError;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\CheckUser\Services\CheckUserLookupUtils;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\ContributionsSpecialPage;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserNamePrefixSearch;
use MediaWiki\User\UserNameUtils;
use PermissionsError;
use UserBlockedError;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * @ingroup SpecialPage
 */
class SpecialIPContributions extends ContributionsSpecialPage {
	private CheckUserLookupUtils $lookupUtils;
	private IPContributionsPagerFactory $pagerFactory;
	private ?IPContributionsPager $pager = null;

	/**
	 * @param PermissionManager $permissionManager
	 * @param IConnectionProvider $dbProvider
	 * @param NamespaceInfo $namespaceInfo
	 * @param UserNameUtils $userNameUtils
	 * @param UserNamePrefixSearch $userNamePrefixSearch
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param UserFactory $userFactory
	 * @param UserIdentityLookup $userIdentityLookup
	 * @param DatabaseBlockStore $blockStore
	 * @param CheckUserLookupUtils $lookupUtils
	 * @param IPContributionsPagerFactory $pagerFactory
	 */
	public function __construct(
		PermissionManager $permissionManager,
		IConnectionProvider $dbProvider,
		NamespaceInfo $namespaceInfo,
		UserNameUtils $userNameUtils,
		UserNamePrefixSearch $userNamePrefixSearch,
		UserOptionsLookup $userOptionsLookup,
		UserFactory $userFactory,
		UserIdentityLookup $userIdentityLookup,
		DatabaseBlockStore $blockStore,
		CheckUserLookupUtils $lookupUtils,
		IPContributionsPagerFactory $pagerFactory
	) {
		parent::__construct(
			$permissionManager,
			$dbProvider,
			$namespaceInfo,
			$userNameUtils,
			$userNamePrefixSearch,
			$userOptionsLookup,
			$userFactory,
			$userIdentityLookup,
			$blockStore,
			'IPContributions'
		);
		$this->lookupUtils = $lookupUtils;
		$this->pagerFactory = $pagerFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function requiresUnblock() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getTargetField( $target ) {
		return [
			'type' => 'user',
			'default' => str_replace( '_', ' ', $target ),
			'label' => $this->msg( 'checkuser-ip-contributions-target-label' )->text(),
			'name' => 'target',
			'id' => 'mw-target-user-or-ip',
			'size' => 40,
			'autofocus' => $target === '',
			'section' => 'contribs-top',
			'validation-callback' => function ( $target ) {
				if ( !$this->lookupUtils->isValidIPOrRange( $target ) ) {
					return $this->msg( 'checkuser-ip-contributions-target-error-no-ip' );
				}
				return true;
			},
			'ipallowed' => true,
			'iprange' => true,
			'iprangelimits' => $this->lookupUtils->getRangeLimit(),
			'required' => true,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		// These checks are the same as in AbstractTemporaryAccountHandler.
		if (
			!$this->permissionManager->userHasRight(
				$this->getAuthority()->getUser(),
				'checkuser-temporary-account-no-preference'
			)
		) {
			// The user must have the 'checkuser-temporary-account' right.
			if (
				!$this->permissionManager->userHasRight(
					$this->getAuthority()->getUser(),
					'checkuser-temporary-account'
				)
			) {
				throw new PermissionsError( 'checkuser-temporary-account' );
			}

			// The user must also have enabled the preference.
			if (
				!$this->userOptionsLookup->getOption(
					$this->getAuthority()->getUser(),
					'checkuser-temporary-account-enable'
				)
			) {
				throw new ErrorPageError(
					$this->msg( 'checkuser-ip-contributions-permission-error-title' ),
					$this->msg( 'checkuser-ip-contributions-permission-error-description' )
				);
			}
		}

		$isArchive = $this->getRequest()->getBool( 'isArchive' );
		$canSeeDeletedHistory = $this->permissionManager->userHasRight(
			$this->getAuthority()->getUser(),
			'deletedhistory'
		);

		if ( $isArchive && !$canSeeDeletedHistory ) {
			throw new PermissionsError( 'deletedhistory' );
		}

		$block = $this->getAuthority()->getBlock();
		if ( $block ) {
			throw new UserBlockedError(
				$block,
				$this->getAuthority()->getUser(),
				$this->getLanguage(),
				$this->getRequest()->getIP()
			);
		}

		// Add to the $opts array now, so that parent::getForm() can add this as a
		// hidden field. This ensures the search form displayed on each tab submits
		// in the correct mode.
		$this->opts['isArchive'] = $isArchive;

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	public function getPager( $target ) {
		if ( $this->pager === null ) {
			$options = [
				'namespace' => $this->opts['namespace'],
				'tagfilter' => $this->opts['tagfilter'],
				'start' => $this->opts['start'] ?? '',
				'end' => $this->opts['end'] ?? '',
				'deletedOnly' => $this->opts['deletedOnly'],
				'topOnly' => $this->opts['topOnly'],
				'newOnly' => $this->opts['newOnly'],
				'hideMinor' => $this->opts['hideMinor'],
				'nsInvert' => $this->opts['nsInvert'],
				'associated' => $this->opts['associated'],
				'tagInvert' => $this->opts['tagInvert'],
				'isArchive' => $this->opts['isArchive'],
			];

			$this->pager = $this->pagerFactory->createPager(
				$this->getContext(),
				$options,
				$target
			);
		}

		return $this->pager;
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'checkuser-ip-contributions' );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormWrapperLegendMessageKey() {
		return 'checkuser-ip-contributions-search-form-wrapper';
	}

	/**
	 * @inheritDoc
	 */
	protected function getResultsPageTitleMessageKey() {
		return $this->opts['isArchive'] ?
			'checkuser-ip-contributions-archive-results-title' :
			'checkuser-ip-contributions-results-title';
	}
}
