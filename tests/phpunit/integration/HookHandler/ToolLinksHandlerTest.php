<?php

namespace MediaWiki\CheckUser\Tests\Integration\HookHandler;

use MediaWiki\CheckUser\HookHandler\ToolLinksHandler;
use MediaWiki\Context\RequestContext;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityUtils;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @group CheckUser
 *
 * @covers \MediaWiki\CheckUser\HookHandler\ToolLinksHandler
 */
class ToolLinksHandlerTest extends MediaWikiIntegrationTestCase {
	use TempUserTestTrait;

	/** @dataProvider provideOnUserToolLinksEditForValidSpecialPage */
	public function testOnUserToolLinksEditForValidSpecialPage( string $requestTitle, array $expectedItems ) {
		// The behaviour when provided a non-Special page / non-matching Special page is unit tested.
		$testUser = new UserIdentityValue( 42, 'Foobar' );
		$mainRequest = RequestContext::getMain();
		$mainRequest->setTitle( Title::newFromText( $requestTitle ) );
		$mainRequest->getRequest()->setVal( 'reason', 'testing' );
		$mockLinkRenderer = $this->createMock( LinkRenderer::class );
		if ( $requestTitle == 'Special:CheckUserLog' ) {
			$mockLinkRenderer->method( 'makeLink' )
				->with(
					SpecialPage::getTitleFor( 'CheckUserLog', $testUser->getName() ),
					wfMessage( 'checkuser-log-checks-on' )->text()
				)->willReturn( 'CheckUserLog mocked link' );
		} else {
			$mockLinkRenderer->method( 'makeLink' )
				->with(
					SpecialPage::getTitleFor( 'CheckUser', $testUser->getName() ),
					wfMessage( 'checkuser-toollink-check' )->text(),
					[],
					[ 'reason' => 'testing' ]
				)->willReturn( 'CheckUser mocked link' );
		}
		$items = [];
		$services = $this->getServiceContainer();
		( new ToolLinksHandler(
			$this->createMock( PermissionManager::class ),
			$services->getSpecialPageFactory(),
			$mockLinkRenderer,
			$this->createMock( UserIdentityLookup::class ),
			$this->createMock( UserIdentityUtils::class ),
			$services->getUserOptionsLookup(),
			$services->getTempUserConfig()
		) )->onUserToolLinksEdit( $testUser->getId(), $testUser->getName(), $items );
		$this->assertCount(
			1, $items, 'A tool link should have been added'
		);
		$this->assertArrayEquals(
			$expectedItems,
			$items,
			true,
			false,
			'The link was not correctly generated'
		);
	}

	public static function provideOnUserToolLinksEditForValidSpecialPage() {
		return [
			'Current title is Special:CheckUser' => [
				'Special:CheckUser', [ 'CheckUser mocked link' ]
			],
			'Current title is Special:CheckUserLog' => [
				'Special:CheckUserLog', [ 'CheckUserLog mocked link' ]
			]
		];
	}

	/**
	 * @dataProvider provideOnSpecialContributionsBeforeMainOutput
	 */
	public function testOnSpecialContributionsBeforeMainOutput(
		bool $tempAccountsEnabled,
		bool $hasNoPreferenceRight,
		bool $hasBasicRight,
		bool $hasPreference,
		string $specialPageName,
		string $target,
		bool $expectAddButtons
	) {
		if ( $tempAccountsEnabled ) {
			$this->enableAutoCreateTempUser();
		} else {
			$this->disableAutoCreateTempUser();
		}

		$user = $this->createMock( User::class );

		$mockOutputPage = $this->createMock( OutputPage::class );
		if ( $expectAddButtons ) {
			$mockOutputPage->expects( $this->once() )->method( 'addSubtitle' );
		} else {
			$mockOutputPage->expects( $this->never() )->method( 'addSubtitle' );
		}

		$mockSpecialPage = $this->getMockBuilder( SpecialPage::class )
			->onlyMethods( [ 'getUser', 'getName', 'getOutput' ] )
			->getMock();
		$mockSpecialPage->method( 'getUser' )
			->willReturn( $user );
		$mockSpecialPage->method( 'getName' )
			->willReturn( $specialPageName );
		$mockSpecialPage->method( 'getOutput' )
			->willReturn( $mockOutputPage );

		$mockPermissionManager = $this->createMock( PermissionManager::class );
		$mockPermissionManager->method( 'userHasRight' )
			->willReturnMap( [
				[ $user, 'checkuser-temporary-account-no-preference', $hasNoPreferenceRight ],
				[ $user, 'checkuser-temporary-account', $hasBasicRight ],
				[ $user, 'checkuser', false ],
				[ $user, 'checkuser-log', false ]
			] );

		$mockUserOptionsLookup = $this->createMock( UserOptionsLookup::class );
		$mockUserOptionsLookup->method( 'getOption' )
			->willReturn( $hasPreference );

		$services = $this->getServiceContainer();
		$hookHandler = new ToolLinksHandler(
			$mockPermissionManager,
			$services->getSpecialPageFactory(),
			$services->getLinkRenderer(),
			$services->getUserIdentityLookup(),
			$services->getUserIdentityUtils(),
			$mockUserOptionsLookup,
			$services->getTempUserConfig()
		);

		$mockUser = $this->createMock( User::class );
		$mockUser->method( 'getName' )
			->willReturn( $target );

		$hookHandler->onSpecialContributionsBeforeMainOutput( 1, $mockUser, $mockSpecialPage );
	}

	/**
	 * Data provider for testing reciprocal links between Special:Contributions
	 * and Special:IPContributions. Since Special:Contributions is publicly
	 * visible, rights are thoroughly tested from Special:Contributions.
	 */
	public function provideOnSpecialContributionsBeforeMainOutput() {
		return [
			'Not an IP target' => [
				'tempAccountsEnabled' => true,
				'noPreferenceRight' => true,
				'basicRight' => true,
				'preference' => true,
				'specialPageName' => 'Contributions',
				'target' => 'TestUser',
				'expectAddButtons' => false,
			],
			'Temp accounts disabled' => [
				'tempAccountsEnabled' => false,
				'noPreferenceRight' => true,
				'basicRight' => true,
				'preference' => true,
				'specialPageName' => 'Contributions',
				'target' => '1.2.3.4',
				'expectAddButtons' => false,
			],
			'Has no-preference right' => [
				'tempAccountsEnabled' => true,
				'noPreferenceRight' => true,
				'basicRight' => false,
				'preference' => false,
				'specialPageName' => 'Contributions',
				'target' => '1.2.3.4',
				'expectAddButtons' => true,
			],
			'Has basic right with preference' => [
				'tempAccountsEnabled' => true,
				'noPreferenceRight' => false,
				'basicRight' => true,
				'preference' => true,
				'specialPageName' => 'Contributions',
				'target' => '1.2.3.4',
				'expectAddButtons' => true,
			],
			'Has basic right without preference' => [
				'tempAccountsEnabled' => true,
				'noPreferenceRight' => false,
				'basicRight' => true,
				'preference' => false,
				'specialPageName' => 'Contributions',
				'target' => '1.2.3.4',
				'expectAddButtons' => false,
			],
			'On Special:IPContributions with rights' => [
				'tempAccountsEnabled' => true,
				'noPreferenceRight' => true,
				'basicRight' => true,
				'preference' => true,
				'specialPageName' => 'IPContributions',
				'target' => '1.2.3.4',
				'expectAddButtons' => true,
			],
			'On an unrelated page' => [
				'tempAccountsEnabled' => true,
				'noPreferenceRight' => true,
				'basicRight' => true,
				'preference' => true,
				'specialPageName' => 'Log',
				'target' => '1.2.3.4',
				'expectAddButtons' => false,
			],
		];
	}

	/**
	 * @dataProvider provideOnSpecialContributionsBeforeMainOutputArchive
	 */
	public function testOnSpecialContributionsBeforeMainOutputArchive(
		bool $canSeeDeleted,
		bool $expectAddButtons
	) {
		$mockPerformingUser = $this->createMock( User::class );

		$mockOutputPage = $this->createMock( OutputPage::class );
		if ( $expectAddButtons ) {
			$mockOutputPage->expects( $this->once() )->method( 'addSubtitle' );
		} else {
			$mockOutputPage->expects( $this->never() )->method( 'addSubtitle' );
		}

		$request = new FauxRequest( [ 'isArchive' => true ] );

		$mockSpecialPage = $this->getMockBuilder( SpecialPage::class )
			->onlyMethods( [ 'getUser', 'getName', 'getOutput', 'getRequest' ] )
			->getMock();
		$mockSpecialPage->method( 'getUser' )
			->willReturn( $mockPerformingUser );
		$mockSpecialPage->method( 'getName' )
			->willReturn( 'IPContributions' );
		$mockSpecialPage->method( 'getOutput' )
			->willReturn( $mockOutputPage );
		$mockSpecialPage->method( 'getRequest' )
			->willReturn( $request );

		$mockPermissionManager = $this->createMock( PermissionManager::class );
		$mockPermissionManager->method( 'userHasRight' )
			->willReturnMap( [
				[ $mockPerformingUser, 'checkuser-temporary-account-no-preference', true ],
				[ $mockPerformingUser, 'deletedhistory', $canSeeDeleted ]
			] );

		$services = $this->getServiceContainer();
		$hookHandler = new ToolLinksHandler(
			$mockPermissionManager,
			$services->getSpecialPageFactory(),
			$services->getLinkRenderer(),
			$services->getUserIdentityLookup(),
			$services->getUserIdentityUtils(),
			$services->getUserOptionsLookup(),
			$services->getTempUserConfig()
		);

		$mockTarget = $this->createMock( User::class );
		$mockTarget->method( 'getName' )
			->willReturn( '1.2.3.4' );

		$hookHandler->onSpecialContributionsBeforeMainOutput( 1, $mockTarget, $mockSpecialPage );
	}

	public function provideOnSpecialContributionsBeforeMainOutputArchive() {
		return [
			'Can see archived contributions' => [
				'canSeeDeleted' => true,
				'expectAddButtons' => true,
			],
			'Can not see archived contributions' => [
				'canSeeDeleted' => false,
				'expectAddButtons' => false,
			],
		];
	}

	/**
	 * @dataProvider provideOnContributionsToolLinksIPContributionsMessage
	 */
	public function testOnContributionsToolLinksIPContributionsMessage(
		bool $isArchive,
		bool $canSeeDeleted,
		?string $expectedLinkKey,
		?string $expectedMessageKey
	) {
		$this->setUserLang( 'qqx' );
		$this->enableAutoCreateTempUser();

		$request = new FauxRequest( [ 'isArchive' => $isArchive ] );

		$mockSpecialPage = $this->getMockBuilder( SpecialPage::class )
			->onlyMethods( [ 'getUser', 'getName', 'getRequest' ] )
			->getMock();
		$mockSpecialPage->method( 'getUser' )
			->willReturn( $this->createMock( User::class ) );
		$mockSpecialPage->method( 'getName' )
			->willReturn( 'IPContributions' );
		$mockSpecialPage->method( 'getRequest' )
			->willReturn( $request );

		$mockPermissionManager = $this->createMock( PermissionManager::class );
		$mockPermissionManager->method( 'userHasRight' )
			->willReturn( $canSeeDeleted );

		$services = $this->getServiceContainer();
		$hookHandler = new ToolLinksHandler(
			$mockPermissionManager,
			$services->getSpecialPageFactory(),
			$services->getLinkRenderer(),
			$this->createMock( UserIdentityLookup::class ),
			$services->getUserIdentityUtils(),
			$this->createMock( UserOptionsLookup::class ),
			$services->getTempUserConfig()
		);

		$mockUserPageTitle = $this->createMock( Title::class );
		$mockUserPageTitle->method( 'getText' )
			->willReturn( '1.2.3.4' );

		$links = [];
		$hookHandler->onContributionsToolLinks( 1, $mockUserPageTitle, $links, $mockSpecialPage );

		if ( $expectedLinkKey ) {
			$this->assertArrayHasKey( $expectedLinkKey, $links );
		} else {
			$this->assertArrayNotHasKey( 'contributions', $links );
			$this->assertArrayNotHasKey( 'deletedcontribs', $links );
		}

		if ( $expectedMessageKey ) {
			$this->assertStringContainsString(
				$expectedMessageKey,
				$links[$expectedLinkKey],
				'The messages were not correctly added by ToolLinksHandler::onContributionsToolLinks.'
			);
		}
	}

	public function provideOnContributionsToolLinksIPContributionsMessage() {
		return [
			'Archive mode' => [
				'isArchive' => true,
				'canSeeDeleted' => true,
				'expectedLinkKey' => 'deletedcontribs',
				'expectedMessageKey' => 'checkuser-ip-contributions-contributions-link',
			],
			'Normal mode, user can see archived revisions' => [
				'isArchive' => false,
				'canSeeDeleted' => true,
				'expectedLinkKey' => 'deletedcontribs',
				'expectedMessageKey' => 'checkuser-ip-contributions-deleted-contributions-link',
			],
			'Normal mode, user cannot see archived revisions' => [
				'isArchive' => false,
				'canSeeDeleted' => false,
				'expectedLinkKey' => null,
				'expectedMessageKey' => null,
			],
		];
	}

	private function commonTestOnContributionsToolLinks(
		string $userName, $linkRenderer, ?UserIdentityUtils $userIdentityUtils,
		bool $hasCheckUserRight, bool $hasCheckUserLogRight, array $expectedLinksArray
	) {
		$mockSpecialPage = $this->getMockBuilder( SpecialPage::class )
			->onlyMethods( [ 'getLinkRenderer', 'getUser' ] )
			->getMock();
		$mockSpecialPage->method( 'getLinkRenderer' )
			->willReturn( $linkRenderer );
		$mockPerformingUser = $this->createMock( User::class );
		$mockPerformingUser->method( 'getName' )
			->willReturn( 'Other user' );
		$mockSpecialPage->method( 'getUser' )
			->willReturn( $mockPerformingUser );
		// Mock the PermissionManager to avoid the database
		$mockPermissionManager = $this->createMock( PermissionManager::class );
		$mockPermissionManager->method( 'userHasRight' )
			->willReturnMap( [
				[ $mockPerformingUser, 'checkuser', $hasCheckUserRight ],
				[ $mockPerformingUser, 'checkuser-log', $hasCheckUserLogRight ]
			] );
		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$userIdentityLookup->method( 'getUserIdentityByUserId' )
			->with( 1 )
			->willReturn( new UserIdentityValue( 1, $userName ) );
		$services = $this->getServiceContainer();
		$hookHandler = new ToolLinksHandler(
			$mockPermissionManager,
			$services->getSpecialPageFactory(),
			$services->getLinkRenderer(),
			$userIdentityLookup,
			$userIdentityUtils ?? $services->getUserIdentityUtils(),
			$services->getUserOptionsLookup(),
			$services->getTempUserConfig()
		);
		$links = [];
		$mockUserPageTitle = $this->createMock( Title::class );
		$mockUserPageTitle->method( 'getText' )
			->willReturn( $userName );
		$hookHandler->onContributionsToolLinks( 1, $mockUserPageTitle, $links, $mockSpecialPage );
		$this->assertArrayEquals(
			$expectedLinksArray,
			$links,
			false,
			true,
			'The links were not correctly added by ToolLinksHandler::onContributionsToolLinks.'
		);
	}

	public function testOnContributionsToolLinksHasCheckUserRight() {
		$userPageTitle = 'Test user';
		// Mock that the LinkRenderer provided via the SpecialPage instance
		// is called.
		$mockLinkRenderer = $this->createMock( LinkRenderer::class );
		$mockLinkRenderer->method( 'makeKnownLink' )
			->with(
				SpecialPage::getTitleFor( 'CheckUser' ),
				wfMessage( 'checkuser-contribs' )->text(),
				[ 'class' => 'mw-contributions-link-check-user' ],
				[ 'user' => $userPageTitle ]
			)->willReturn( 'CheckUser mocked link' );
		$this->commonTestOnContributionsToolLinks(
			$userPageTitle, $mockLinkRenderer, null,
			true, false, [ 'checkuser' => 'CheckUser mocked link' ]
		);
	}

	public function testOnContributionsToolLinksHasCheckUserLogRight() {
		$userPageTitle = 'Test user';
		// Mock that the LinkRenderer provided via the SpecialPage instance
		// is called.
		$mockLinkRenderer = $this->createMock( LinkRenderer::class );
		$expectedReturnMap = [
			[
				SpecialPage::getTitleFor( 'CheckUserLog' ),
				wfMessage( 'checkuser-contribs-log' )->text(),
				[ 'class' => 'mw-contributions-link-check-user-log' ],
				[ 'cuSearch' => $userPageTitle ],
				'CheckUserLog mocked link'
			],
			[
				SpecialPage::getTitleFor( 'CheckUserLog' ),
				wfMessage( 'checkuser-contribs-log-initiator' )->text(),
				[ 'class' => 'mw-contributions-link-check-user-initiator' ],
				[ 'cuInitiator' => $userPageTitle ],
				'CheckUserLog initiator mocked link'
			]
		];
		$mockLinkRenderer->method( 'makeKnownLink' )
			->willReturnCallback( function ( $target, $text, $extraAttribs, $query ) use ( &$expectedReturnMap ) {
				$curExpected = array_shift( $expectedReturnMap );
				$this->assertEquals( $curExpected[0], $target );
				$this->assertSame( $curExpected[1], $text );
				$this->assertSame( $curExpected[2], $extraAttribs );
				$this->assertSame( $curExpected[3], $query );
				return $curExpected[4];
			} );
		$mockUserIdentityUtils = $this->createMock( UserIdentityUtils::class );
		$mockUserIdentityUtils->method( 'isNamed' )
			->with( $userPageTitle )
			->willReturn( true );
		$this->commonTestOnContributionsToolLinks(
			$userPageTitle, $mockLinkRenderer, $mockUserIdentityUtils,
			false, true, [
				'checkuser-log' => 'CheckUserLog mocked link',
				'checkuser-log-initiator' => 'CheckUserLog initiator mocked link',
			]
		);
	}

	public function testOnContributionsToolLinksHasCheckUserLogRightForTemporaryUser() {
		$userPageTitle = '*Unregistered 12';
		// Mock that the LinkRenderer provided via the SpecialPage instance
		// is called.
		$mockLinkRenderer = $this->createMock( LinkRenderer::class );
		$mockLinkRenderer->method( 'makeKnownLink' )
			->with(
				SpecialPage::getTitleFor( 'CheckUserLog' ),
				wfMessage( 'checkuser-contribs-log' )->text(),
				[ 'class' => 'mw-contributions-link-check-user-log' ],
				[ 'cuSearch' => $userPageTitle ]
			)->willReturn( 'CheckUserLog mocked link' );
		$mockUserIdentityUtils = $this->createMock( UserIdentityUtils::class );
		$mockUserIdentityUtils->method( 'isNamed' )
			->with( $userPageTitle )
			->willReturn( false );
		$this->commonTestOnContributionsToolLinks(
			$userPageTitle, $mockLinkRenderer, $mockUserIdentityUtils,
			false, true, [ 'checkuser-log' => 'CheckUserLog mocked link' ]
		);
	}
}
