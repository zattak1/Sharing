<?php
/**
 * GET /sharing/engagement/:publisherId/:engagementId — one engagement.
 */
function Sharing_engagement_response_content($params)
{
	$uri = Q_Dispatcher::uri();
	$params = array_merge($_REQUEST, $params);
	$user = Users::loggedInUser(false, false);
	$asUserId = $user ? $user->id : null;
	$publisherId = Q::ifset($params, 'publisherId', $uri ? $uri->publisherId : null);
	$id = Q::ifset($params, 'engagementId', $uri ? $uri->engagementId : null);
	$stream = ($publisherId and $id)
		? Sharing_Engagement::fetch($asUserId, $publisherId, Sharing_Engagement::nameFromId($id))
		: null;
	if (!$stream) {
		Q_Response::code(404);
		return Q::view('Sharing/content/engagementNotFound.php');
	}
	$listingStream = Sharing_Engagement::listingOf($stream);
	$engagement = Sharing_Engagement::export($stream);
	$listing = $listingStream ? Sharing_Listing::export($listingStream) : null;
	$role = $listingStream ? Sharing_Engagement::roleOf($asUserId, $stream, $listingStream) : null;
	$allowed = $listingStream ? Sharing_Engagement::allowed($asUserId, $stream, $listingStream) : array();
	return Q::view('Sharing/content/engagement.php', @compact(
		'engagement', 'listing', 'role', 'allowed', 'user'
	));
}
