<?php

namespace MediaWiki\CheckUser\HookHandler;

use MediaWiki\CheckUser\GlobalContributions\SpecialGlobalContributions;
use MediaWiki\CheckUser\IPContributions\SpecialIPContributions;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\Hook\SpecialPage_initListHook;
use MediaWiki\User\TempUser\TempUserConfig;

// The name of onSpecialPage_initList raises the following phpcs error. As the
// name is defined in core, this is an unavoidable issue and therefore the check
// is disabled.
//
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

/**
 * Hook handler for the SpecialPage_initList hook
 */
class SpecialPageInitListHandler implements SpecialPage_initListHook {

	private TempUserConfig $tempUserConfig;
	private ExtensionRegistry $extensionRegistry;

	public function __construct(
		TempUserConfig $tempUserConfig,
		ExtensionRegistry $extensionRegistry
	) {
		$this->tempUserConfig = $tempUserConfig;
		$this->extensionRegistry = $extensionRegistry;
	}

	/** @inheritDoc */
	public function onSpecialPage_initList( &$list ) {
		if ( $this->tempUserConfig->isKnown() ) {
			$list['IPContributions'] = [
				'class' => SpecialIPContributions::class,
				'services' => [
					'PermissionManager',
					'ConnectionProvider',
					'NamespaceInfo',
					'UserNameUtils',
					'UserNamePrefixSearch',
					'UserOptionsLookup',
					'UserFactory',
					'UserIdentityLookup',
					'DatabaseBlockStore',
					'CheckUserIPContributionsPagerFactory',
					'CheckUserPermissionManager',
				],
			];

			// Use of Special:GlobalContributions depends on the user enabling IP reveal globally
			if ( $this->extensionRegistry->isLoaded( 'GlobalPreferences' ) ) {
				$list['GlobalContributions'] = [
					'class' => SpecialGlobalContributions::class,
					'services' => [
						'PermissionManager',
						'ConnectionProvider',
						'NamespaceInfo',
						'UserNameUtils',
						'UserNamePrefixSearch',
						'UserOptionsLookup',
						'UserFactory',
						'UserIdentityLookup',
						'DatabaseBlockStore',
						'PreferencesFactory',
						'CheckUserGlobalContributionsPagerFactory',
					],
				];
			}
		}

		return true;
	}
}
