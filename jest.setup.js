'use strict';

/* eslint-disable no-undef */
const { config } = require( '@vue/test-utils' );
const mockMediaWiki = require( '@wikimedia/mw-node-qunit/src/mockMediaWiki.js' );

// Mock Vue plugins in test suites
global.mw = mockMediaWiki();

global.mw.message = jest.fn( ( key ) => ( {
	text: () => `(${ key })`,
	parse: () => `(${ key })`
} ) );

config.global.mocks = {
	$i18n: ( ...messageKeyAndParams ) => ( {
		text: () => '(' + messageKeyAndParams.join( ', ' ) + ')',
		parse: () => '(' + messageKeyAndParams.join( ', ' ) + ')'
	} )
};
config.global.directives = {
	'i18n-html': ( el, binding ) => {
		el.innerHTML = `${ binding.arg } (${ binding.value })`;
	}
};

// Ignore all "teleport" behavior for the purpose of testing Dialog;
// see https://test-utils.vuejs.org/guide/advanced/teleport.html
config.global.stubs = {
	teleport: true
};
