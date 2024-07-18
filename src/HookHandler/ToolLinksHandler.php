<?php

namespace MediaWiki\CheckUser\HookHandler;

use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\ContributionsToolLinksHook;
use MediaWiki\Hook\SpecialContributionsBeforeMainOutputHook;
use MediaWiki\Hook\UserToolLinksEditHook;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\TempUser\TempUserConfig;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityUtils;
use OOUI\ButtonGroupWidget;
use OOUI\ButtonWidget;
use Wikimedia\IPUtils;

class ToolLinksHandler implements
	SpecialContributionsBeforeMainOutputHook,
	ContributionsToolLinksHook,
	UserToolLinksEditHook
{

	private PermissionManager $permissionManager;
	private SpecialPageFactory $specialPageFactory;
	private LinkRenderer $linkRenderer;
	private UserIdentityLookup $userIdentityLookup;
	private UserIdentityUtils $userIdentityUtils;
	private UserOptionsLookup $userOptionsLookup;
	private TempUserConfig $tempUserConfig;

	/**
	 * @param PermissionManager $permissionManager
	 * @param SpecialPageFactory $specialPageFactory
	 * @param LinkRenderer $linkRenderer
	 * @param UserIdentityLookup $userIdentityLookup
	 * @param UserIdentityUtils $userIdentityUtils
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param TempUserConfig $tempUserConfig
	 */
	public function __construct(
		PermissionManager $permissionManager,
		SpecialPageFactory $specialPageFactory,
		LinkRenderer $linkRenderer,
		UserIdentityLookup $userIdentityLookup,
		UserIdentityUtils $userIdentityUtils,
		UserOptionsLookup $userOptionsLookup,
		TempUserConfig $tempUserConfig
	) {
		$this->permissionManager = $permissionManager;
		$this->specialPageFactory = $specialPageFactory;
		$this->linkRenderer = $linkRenderer;
		$this->userIdentityLookup = $userIdentityLookup;
		$this->userIdentityUtils = $userIdentityUtils;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->tempUserConfig = $tempUserConfig;
	}

	/**
	 * Determine whether a user is able to reveal IP, based on their rights
	 * and preferences.
	 *
	 * @param UserIdentity $user
	 * @return bool
	 */
	private function userCanRevealIP( UserIdentity $user ) {
		return $this->permissionManager->userHasRight(
				$user,
				'checkuser-temporary-account-no-preference'
			) ||
			(
				$this->permissionManager->userHasRight(
					$user,
					'checkuser-temporary-account'
				) &&
				$this->userOptionsLookup->getOption(
					$user,
					'checkuser-temporary-account-enable'
				)
			);
	}

	/**
	 * Determine whether a user is able to see archived contributions.
	 *
	 * @param UserIdentity $user
	 * @return bool
	 */
	private function userCanSeeDeleted( UserIdentity $user ) {
		return $this->permissionManager->userHasRight( $user, 'deletedhistory' );
	}

	/** @inheritDoc */
	public function onSpecialContributionsBeforeMainOutput( $id, $user, $sp ) {
		// TODO: Handle Special:DeletedContributions here after T370438.
		if (
			$sp->getName() !== 'Contributions' &&
			$sp->getName() !== 'IPContributions'
		) {
			return;
		}

		if (
			!$this->tempUserConfig->isKnown() ||
			!IPUtils::isIPAddress( $user->getName() ) ||
			!$this->userCanRevealIP( $sp->getUser() )
		) {
			return;
		}

		$isArchive = $sp->getRequest()->getBool( 'isArchive' );
		if ( $isArchive && !$this->userCanSeeDeleted( $sp->getUser() ) ) {
			return;
		}

		// Pages showing archived contributions should link to each other.
		$ipContributionsUrl = SpecialPage::getTitleFor(
			'IPContributions',
			$user->getName()
		)->getLinkURL(
			[ 'isArchive' => $isArchive ]
		);
		$contributionsUrl = SpecialPage::getTitleFor(
			$isArchive ? 'DeletedContributions' : 'Contributions',
			$user->getName()
		)->getLinkURL();

		$buttons = new ButtonGroupWidget( [
			'items' => [
				new ButtonWidget( [
					'label' => $sp->msg( 'checkuser-ip-contributions-special-ip-contributions-button' )->text(),
					'href' => $ipContributionsUrl,
					'active' => $sp->getName() === 'IPContributions',
				] ),
				new ButtonWidget( [
					'label' => $sp->msg( 'checkuser-ip-contributions-special-contributions-button' )->text(),
					'href' => $contributionsUrl,
					'active' => $sp->getName() === 'Contributions',
				] )
			],
		] );

		$sp->getOutput()->addSubtitle( $buttons );
	}

	/**
	 * Add a link to Special:CheckUser and Special:CheckUserLog
	 * on Special:Contributions/<username> for
	 * privileged users.
	 *
	 * @param int $id User ID
	 * @param Title $nt User page title
	 * @param string[] &$links Tool links
	 * @param SpecialPage $sp Special page
	 */
	public function onContributionsToolLinks(
		$id, Title $nt, array &$links, SpecialPage $sp
	) {
		$user = $sp->getUser();
		$linkRenderer = $sp->getLinkRenderer();

		if ( $sp->getName() === 'IPContributions' && $this->userCanRevealIP( $user ) ) {
			if ( $sp->getRequest()->getBool( 'isArchive' ) ) {
				// Use the same key to ensure the link is added in the same position
				$links['deletedcontribs'] = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'IPContributions', $nt->getText() ),
					$sp->msg( 'checkuser-ip-contributions-contributions-link' )->text(),
					[ 'class' => 'mw-contributions-link-check-user-ip-contributions' ],
				);
			} elseif ( $this->userCanSeeDeleted( $user ) ) {
				$links['deletedcontribs'] = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'IPContributions', $nt->getText() ),
					$sp->msg( 'checkuser-ip-contributions-deleted-contributions-link' )->text(),
					[ 'class' => 'mw-contributions-link-check-user-ip-contributions' ],
					[ 'isArchive' => true ]
				);
			}
		}

		if ( $this->permissionManager->userHasRight( $user, 'checkuser' ) ) {
			$links['checkuser'] = $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'CheckUser' ),
				$sp->msg( 'checkuser-contribs' )->text(),
				[ 'class' => 'mw-contributions-link-check-user' ],
				[ 'user' => $nt->getText() ]
			);
		}
		if ( $this->permissionManager->userHasRight( $user, 'checkuser-log' ) ) {
			$links['checkuser-log'] = $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'CheckUserLog' ),
				$sp->msg( 'checkuser-contribs-log' )->text(),
				[ 'class' => 'mw-contributions-link-check-user-log' ],
				[ 'cuSearch' => $nt->getText() ]
			);
			$userIdentity = $this->userIdentityLookup->getUserIdentityByUserId( $id );
			if ( $id && $userIdentity && $this->userIdentityUtils->isNamed( $userIdentity ) ) {
				$links['checkuser-log-initiator'] = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'CheckUserLog' ),
					$sp->msg( 'checkuser-contribs-log-initiator' )->text(),
					[ 'class' => 'mw-contributions-link-check-user-initiator' ],
					[ 'cuInitiator' => $nt->getText() ]
				);
			}
		}
	}

	/** @inheritDoc */
	public function onUserToolLinksEdit( $userId, $userText, &$items ) {
		$requestTitle = RequestContext::getMain()->getTitle();
		if (
			$requestTitle !== null &&
			$requestTitle->isSpecialPage()
		) {
			$specialPageName = $this->specialPageFactory->resolveAlias( $requestTitle->getText() )[0];
			if ( $specialPageName === 'CheckUserLog' ) {
				$items[] = $this->linkRenderer->makeLink(
					SpecialPage::getTitleFor( 'CheckUserLog', $userText ),
					wfMessage( 'checkuser-log-checks-on' )->text()
				);
			} elseif ( $specialPageName === 'CheckUser' ) {
				$items[] = $this->linkRenderer->makeLink(
					SpecialPage::getTitleFor( 'CheckUser', $userText ),
					wfMessage( 'checkuser-toollink-check' )->text(),
					[],
					[ 'reason' => RequestContext::getMain()->getRequest()->getVal( 'reason', '' ) ]
				);
			}
		}
	}
}
