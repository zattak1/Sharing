<?php
/**
 * GET /sharing — the listing page. ?direction=offer|need filters; the
 * default shows both. ?page=N (from 1) pages through them, filtered first
 * (ro#586, audit R05).
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
	$page = max(1, (int)Q::ifset($params, 'page', 1));
	$gates = Sharing::gates();
	$loggedIn = (bool)$user;
	$result = Sharing_Listing::page($asUserId, array(
		'direction' => $direction,
		'limit' => Sharing_Listing::PAGE,
		'offset' => ($page - 1) * Sharing_Listing::PAGE
	));
	$listings = array_map(array('Sharing_Listing', 'export'), $result['listings']);
	$hasMore = $result['hasMore'];
	return Q::view('Sharing/content/listings.php', @compact(
		'gates', 'loggedIn', 'direction', 'listings', 'page', 'hasMore'
	));
}
