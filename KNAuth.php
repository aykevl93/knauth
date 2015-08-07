<?php

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'KNAuth',
	'description' => "Authenticate users against Karpe Noktem's member database.",
	'version'  => 0,
	'author' => 'Ayke van Laethem',
	'url' => 'https://github.com/aykevl93/knauth',
	'license-name' => "Public domain",
);

# Configuration
$wgKNAuthSessionCookieName = 'sessionid';
$wgKNAuthLoginURL = "/accounts/login/";
$wgKNAuthLogoutURL = "/accounts/logout/";
# This should be localhost or similar for performance
$wgKNAuthVerifyURL = 'http://localhost/accounts/api/';

$wgHooks['UserLoadFromSession'][] = 'efKNAuthFromSession';
$wgHooks['PersonalUrls'][] = 'efKNAuthPersonalUrls';
$wgHooks['LinkEnd'][] = 'efKNAuthLinkEnd';

# Load user from the Django session.
function efKNAuthFromSession( User $user, &$result ) {
	global $wgKNAuthSessionCookieName, $wgKNAuthVerifyURL;

	if( $user->isLoggedIn() || !isset( $_COOKIE[$wgKNAuthSessionCookieName] ) ) {
		return;
	}

	$sessionid = $_COOKIE[$wgKNAuthSessionCookieName];
	if( !preg_match( '/^[a-z0-9]+$/', $sessionid ) ) {
		# Strings with these characters are used since Django 1.5. Django 1.4
		# uses 0-9a-f.
		return;
	}

	$ch = curl_init( $wgKNAuthVerifyURL );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_COOKIE, "$wgKNAuthSessionCookieName=" . $sessionid );
	$res = curl_exec( $ch );
	curl_close( $ch );
	$data = json_decode( $res, true );
	if( $data['valid'] !== true ) {
		# invalid cookie
		return;
	}
	$name = User::getCanonicalName( $data['name'] );
	if( $name === false ) {
		# invalid name (cannot use in MediaWiki)
		return;
	}
	$id = User::idFromName( $name );
	if( $id === null ) {
		# user does not exist
		return;
	}
	$user->mId = $id;
	$user->loadFromId();
	$result = true;

	# Ensure a session exists.
	if( session_status() !== PHP_SESSION_ACTIVE ) {
		# Set up session: it does not exist yet.
		wfSetupSession();
	} elseif( !isset( $_SESSION['knauth-sessionid'] ) || $_SESSION['knauth-sessionid'] !== $sessionid ) {
		# Refresh session ID if user changes, to prevent session fixation attacks.
		wfResetSessionID();
		$_SESSION['knauth-sessionid'] = $sessionid;
	}
}

# Change the links in the top right to point to kninfra.
function efKNAuthPersonalUrls( array &$personal_urls, Title $title, SkinTemplate $skin ) {
	global $wgKNAuthLoginURL, $wgKNAuthLogoutURL, $wgScriptPath;
	$request = $skin->getRequest();
	if( isset( $personal_urls['login'] ) ) {
		$personal_urls['login']['active'] = false;
		$returnto = Title::newFromURL( $request->getVal( 'title', '' ) );
		$next = "$wgScriptPath/";
		if( $returnto !== null ) {
			$query = $request->getQueryValues();
			unset( $query['title'] );
			$next = $returnto->getLocalURL( $query );
		}
		$href = $wgKNAuthLoginURL . '?' . wfArrayToCgi( [
			'next' => $next,
		] );
		$personal_urls['login']['href'] = $href;
	}
	if( isset( $personal_urls['logout'] ) ) {
		if( $skin->getUser()->isLoggedIn()
			&& !$request->getSessionData( 'wsUserID' ) )
		{
			$personal_urls['logout']['active'] = false;
			$personal_urls['logout']['href'] = $wgKNAuthLogoutURL;
		}
	}
}

# Change text login/logout links to point to kninfra.
function efKNAuthLinkEnd( $dummy, Title $target, array $options, &$html, array &$attribs, &$ret ) {
	global $wgKNAuthLoginURL, $wgKNAuthLogoutURL;

	if( $target->equals( SpecialPage::getTitleFor( 'Userlogin' ) ) ) {
		$href = $attribs['href'];
		$next = null; // ?next= parameter
		$query = '';  // query-string parameters for the ?next= URL
		$index = strpos( $href, '?' );
		if( $index !== false ) {
			$data = wfCgiToArray( substr( $href, $index+1 ) );
			if( isset( $data['returnto'] ) ) {
				$next = Title::newFromText( $data['returnto'] );
				if( isset( $data['returntoquery'] ) ) {
					$query = $data['returntoquery'];
				}
			}
		}
		if( $next === null ) {
			// No 'returnto' parameter was found, go to the main page.
			$next = Title::newMainPage();
		}
		$href = $wgKNAuthLoginURL . '?' . wfArrayToCgi( array(
			'next' => $next->getLocalURL( $query ),
		) );
		$attribs['href'] = $href;
	}

	if( $target->equals( SpecialPage::getTitleFor( 'Userlogout' ) ) ) {
		// workaround as we can't get a ContextSource
		global $wgUser, $wgRequest;
		if( $wgUser->isLoggedIn()
			&& !$wgRequest->getSessionData( 'wsUserID' ) )
		{
			$attribs['href'] = $wgKNAuthLogoutURL;
		}
	}
}
