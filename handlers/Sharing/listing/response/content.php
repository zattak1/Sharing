<?php
/**
 * GET /sharing/:publisherId/:listingId — one listing.
 */
function Sharing_listing_response_content($params)
{
	// Route fields live on the dispatched uri, not in $_REQUEST.
	$uri = Q_Dispatcher::uri();
	$params = array_merge($_REQUEST, $params);
	$user = Users::loggedInUser(false, false);
	$asUserId = $user ? $user->id : null;
	$publisherId = Q::ifset($params, 'publisherId', $uri ? $uri->publisherId : null);
	$id = Q::ifset($params, 'listingId', $uri ? $uri->listingId : null);
	$name = $id ? Sharing_Listing::nameFromId($id) : null;
	$stream = ($publisherId and $id)
		? Sharing_Listing::fetch($asUserId, $publisherId, $name)
		: null;
	if (!$stream) {
		Q_Response::code(404);
		return Q::view('Sharing/content/listingNotFound.php');
	}
	$listing = Sharing_Listing::export($stream);
	$isPublisher = ($asUserId === $stream->publisherId);
	$private = $isPublisher ? Sharing_Listing::privateStream($stream, $asUserId) : null;
	$privateInstructions = $private ? $private->getAttribute('instructions') : null;
	$canRespond = $user && !$isPublisher && Sharing::canRespond($listing['direction'], $asUserId);
	// The responder's own engagement, if any; the publisher's list of all.
	$mine = ($user && !$isPublisher) ? Sharing_Engagement::ofResponder($stream, $asUserId) : null;
	$myEngagement = $mine ? Sharing_Engagement::export($mine) : null;
	$myAllowed = $mine ? Sharing_Engagement::allowed($asUserId, $mine, $stream) : array();
	// A member who may resolve (ro#586) sees the live engagements too, so an
	// abandoned one can be cancelled and the listing paused or closed.
	$canResolve = $user && !$isPublisher && Sharing::canResolve($asUserId);
	$isManager = $isPublisher || $canResolve;
	$engagements = array();
	if ($isManager) {
		foreach (Sharing_Engagement::forListing($stream) as $e) {
			if (!$isPublisher and in_array(
				$e->getAttribute('persistedState'), Sharing_Engagement::$terminal, true
			)) {
				continue;
			}
			$engagements[] = array(
				'engagement' => Sharing_Engagement::export($e),
				'allowed' => Sharing_Engagement::allowed($asUserId, $e, $stream)
			);
		}
	}
	$openToResponses = $canRespond && !$mine && !$listing['paused'];
	return Q::view('Sharing/content/listing.php', @compact(
		'listing', 'isPublisher', 'isManager', 'canResolve', 'privateInstructions',
		'canRespond', 'user', 'myEngagement', 'myAllowed', 'engagements', 'openToResponses'
	));
}
