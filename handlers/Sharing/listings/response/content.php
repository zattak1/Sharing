<?php
/**
 * GET /sharing — the listing page. ?direction=offer|need filters; the
 * default shows both.
 */
function Sharing_listings_response_content($params)
{
	$params = array_merge($_REQUEST, $params);
	$user = Users::loggedInUser(false, false);
	$asUserId = $user ? $user->id : null;
	$direction = Q::ifset($params, 'direction', null);
	if (!in_array($direction, Sharing_Listing::$directions, true)) {
		$direction = null;
	}
	$gates = Sharing::gates();
	$loggedIn = (bool)$user;
	$listings = array_map(
		array('Sharing_Listing', 'export'),
		Sharing_Listing::fetchAll($asUserId, @compact('direction'))
	);
	return Q::view('Sharing/content/listings.php', @compact(
		'gates', 'loggedIn', 'direction', 'listings'
	));
}
