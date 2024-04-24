<?php

namespace MediaWiki\CheckUser\Tests\Integration\Investigate;

use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Tests\SpecialPage\FormSpecialPageTestCase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use PermissionsError;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\CheckUser\Investigate\SpecialInvestigateBlock
 * @group CheckUser
 * @group Database
 */
class SpecialInvestigateBlockTest extends FormSpecialPageTestCase {

	use MockAuthorityTrait;

	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'InvestigateBlock' );
	}

	/** @dataProvider provideUserRightsForFailure */
	public function testViewSpecialPageWhenMissingNecessaryRights( $rights ) {
		// Expect that a PermissionsError is thrown, which indicates that the special page correctly identified that
		// the authority viewing the page does not have the necessary rights to do so.
		$this->expectException( PermissionsError::class );
		// Execute the special page.
		$this->executeSpecialPage(
			'', new FauxRequest(), null,
			$this->mockRegisteredAuthorityWithPermissions( $rights )
		);
	}

	public static function provideUserRightsForFailure() {
		return [
			'Only the checkuser right' => [ [ 'checkuser' ] ],
			'Only the block right' => [ [ 'block' ] ],
		];
	}

	/** @dataProvider provideUserRightsForFailure */
	public function testUserCanExecute( $rights ) {
		$specialPage = $this->newSpecialPage();
		$userIdentity = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity(
			$this->mockRegisteredAuthorityWithPermissions( $rights )->getUser()
		);
		$this->assertFalse(
			$specialPage->userCanExecute( $userIdentity ),
			'User should not be able to execute the special page if they are missing checkuser and block rights.'
		);
	}

	private function getUserForSuccess() {
		return $this->getMutableTestUser( [ 'checkuser', 'sysop' ] )->getUser();
	}

	public function testViewSpecialPageWithNoDataEntered() {
		// Define wgBlockAllowsUTEdit and wgEnableUserEmail as true to get all the fields that can be in the form.
		$this->overrideConfigValue( MainConfigNames::BlockAllowsUTEdit, true );
		$this->overrideConfigValue( MainConfigNames::EnableUserEmail, true );
		// Execute the special page.
		[ $html ] = $this->executeSpecialPage( '', new FauxRequest(), null, $this->getUserForSuccess() );
		// Verify that the title is shown
		$this->assertStringContainsString( '(checkuser-investigateblock', $html );
		// Verify that the targets multiselect is shown
		$this->assertStringContainsString( '(checkuser-investigateblock-target', $html );
		// Verify that the 'Actions to block' section is shown
		$this->assertStringContainsString( '(checkuser-investigateblock-actions', $html );
		$this->assertStringContainsString( '(checkuser-investigateblock-email-label', $html );
		$this->assertStringContainsString( '(checkuser-investigateblock-usertalk-label', $html );
		$this->assertStringContainsString( '(checkuser-investigateblock-reblock-label', $html );
		// Verify that the 'Reason' section is shown
		$this->assertStringContainsString( '(checkuser-investigateblock-reason', $html );
		// Verify that the 'Options' section is shown
		$this->assertStringContainsString( '(checkuser-investigateblock-options', $html );
		$this->assertStringContainsString( '(checkuser-investigateblock-notice-user-page-label', $html );
		$this->assertStringContainsString( '(checkuser-investigateblock-notice-talk-page-label', $html );
	}

	public function testOnSubmitOneUserTargetWithNotices() {
		// Set-up the valid request and get a test user which has the necessary rights.
		$testPerformer = $this->getUserForSuccess();
		RequestContext::getMain()->setUser( $testPerformer );
		$testTargetUser = $this->getTestUser()->getUser();
		$fauxRequest = new FauxRequest(
			[
				// Test with a single target user, with both notices being added.
				'wpTargets' => $testTargetUser->getName(), 'wpUserPageNotice' => 1, 'wpTalkPageNotice' => 1,
				'wpUserPageNoticeText' => 'Test user page text', 'wpTalkPageNoticeText' => 'Test talk page text',
				'wpReason' => 'other', 'wpReason-other' => 'Test reason',
				'wpEditToken' => $testPerformer->getEditToken(),
			],
			true,
			RequestContext::getMain()->getRequest()->getSession()
		);
		// Assign the fake valid request to the main request context, as well as updating the session user
		// so that the CSRF token is a valid token for the request user.
		RequestContext::getMain()->setRequest( $fauxRequest );
		RequestContext::getMain()->getRequest()->getSession()->setUser( $testPerformer );

		// Execute the special page and get the HTML output.
		[ $html ] = $this->executeSpecialPage( '', $fauxRequest, null, $testPerformer );
		// Assert that the success message is shown.
		$this->assertStringContainsString( '(checkuser-investigateblock-success', $html );

		// Assert that the user is blocked
		$block = $this->getServiceContainer()->getDatabaseBlockStore()->newFromTarget( $testTargetUser );
		$this->assertNotNull( $block, 'The target user was not blocked by Special:InvestigateBlock' );
		// Assert that the block parameters are as expected
		$this->assertSame( 'Test reason', $block->getReasonComment()->text, 'The reason was not as expected' );
		$this->assertSame( $testPerformer->getId(), $block->getBy(), 'The blocking user was not as expected' );
		$this->assertTrue( wfIsInfinity( $block->getExpiry() ), 'The block should be indefinite' );
		$this->assertTrue( $block->isCreateAccountBlocked(), 'The block should prevent account creation' );
		$this->assertTrue( $block->isSitewide(), 'The block should be sitewide' );
		$this->assertTrue( $block->isAutoblocking(), 'The block should be autoblocking' );

		// Assert that the user page and talk page notices are as expected
		$this->assertSame(
			'Test user page text',
			$this->getServiceContainer()->getWikiPageFactory()
				->newFromTitle( $testTargetUser->getUserPage() )
				->getRevisionRecord()
				->getContentOrThrow( SlotRecord::MAIN )
				->getWikitextForTransclusion(),
			'The user page notice was not as expected'
		);
		$this->assertSame(
			'Test talk page text',
			$this->getServiceContainer()->getWikiPageFactory()
				->newFromTitle( $testTargetUser->getTalkPage() )
				->getRevisionRecord()
				->getContentOrThrow( SlotRecord::MAIN )
				->getWikitextForTransclusion(),
			'The user talk page notice was not as expected'
		);
	}

	public function testOnSubmitForIPTargetsWithFailedNotices() {
		ConvertibleTimestamp::setFakeTime( '20210405060708' );
		$testPerformer = $this->getUserForSuccess();
		RequestContext::getMain()->setUser( $testPerformer );
		// Simulate that the user does not have the necessary rights to create the user page and talk page notices by
		// removing the 'edit' right from the user submitting the form (and all other rights than needed to execute
		// the special page).
		$this->overrideUserPermissions( $testPerformer, [ 'block', 'checkuser' ] );
		// Set-up the valid request and get a test user which has the necessary rights.
		$testTargetUser = $this->getTestUser()->getUser();
		$fauxRequest = new FauxRequest(
			[
				// Test with with a single non-existent target user, with both notices being added.
				// The notices should not be added if the block fails to be applied.
				'wpTargets' => "127.0.0.2\n1.2.3.4/24", 'wpUserPageNotice' => 1, 'wpTalkPageNotice' => 1,
				'wpUserPageNoticeText' => 'Test user page text', 'wpTalkPageNoticeText' => 'Test talk page text',
				'wpReason' => 'other', 'wpReason-other' => 'Test reason',
				'wpEditToken' => $testPerformer->getEditToken(),
			],
			true,
			RequestContext::getMain()->getRequest()->getSession()
		);
		// Assign the fake valid request to the main request context, as well as updating the session user
		// so that the CSRF token is a valid token for the request user.
		RequestContext::getMain()->setRequest( $fauxRequest );
		RequestContext::getMain()->getRequest()->getSession()->setUser( $testPerformer );

		// Execute the special page and get the HTML output.
		[ $html ] = $this->executeSpecialPage( '', $fauxRequest, null, $testPerformer );
		// Assert that the notices failed message is shown.
		$this->assertStringContainsString( '(checkuser-investigateblock-notices-failed', $html );

		// Assert that both targets are blocked
		// First check the IP address target
		$block = $this->getServiceContainer()->getDatabaseBlockStore()->newFromTarget( '127.0.0.2' );
		$this->assertNotNull( $block, 'The IP address target was not blocked by Special:InvestigateBlock' );
		// Assert that the block parameters are as expected
		$this->assertSame( '20210412060708', $block->getExpiry(), 'The block should be indefinite' );

		// Secondly check the IP range target
		$block = $this->getServiceContainer()->getDatabaseBlockStore()->newFromTarget( '1.2.3.0/24' );
		$this->assertNotNull( $block, 'The IP range target was not blocked by Special:InvestigateBlock' );
		// Assert that the block parameters are as expected
		$this->assertSame( '20210412060708', $block->getExpiry(), 'The block should be indefinite' );

		// Assert that the user page and talk page for the non-existent user are not created, because the user was
		// prevented from creating the notices
		$this->assertFalse(
			$this->getServiceContainer()->getWikiPageFactory()
				->newFromTitle( $testTargetUser->getUserPage() )
				->exists(),
			'The user page notice should not have been added as the user cannot create the page.'
		);
		$this->assertFalse(
			$this->getServiceContainer()->getWikiPageFactory()
				->newFromTitle( $testTargetUser->getTalkPage() )
				->exists(),
			'The user talk page notice should not have been added as the user cannot create the page.'
		);
	}

	public function testOnSubmitForIPTargetWithMissingReason() {
		$testPerformer = $this->getUserForSuccess();
		RequestContext::getMain()->setUser( $testPerformer );
		$fauxRequest = new FauxRequest(
			[
				'wpTargets' => "127.0.0.2\n1.2.3.4/24",
				// wpReason as 'other' is no text and leave the other field empty. This simulates no provided reason.
				'wpReason' => 'other', 'wpReason-other' => '',
				'wpEditToken' => $testPerformer->getEditToken(),
			],
			true,
			RequestContext::getMain()->getRequest()->getSession()
		);
		// Assign the fake valid request to the main request context, as well as updating the session user
		// so that the CSRF token is a valid token for the request user.
		RequestContext::getMain()->setRequest( $fauxRequest );
		RequestContext::getMain()->getRequest()->getSession()->setUser( $testPerformer );

		// Execute the special page and get the HTML output.
		[ $html ] = $this->executeSpecialPage( '', $fauxRequest, null, $testPerformer );
		// Assert that the required field error is displayed on the page.
		$this->assertStringContainsString( '(htmlform-required', $html );
	}
}
