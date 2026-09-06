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
	return Q::view('Sharing/content/listing.php', @compact(
		'listing', 'isPublisher', 'privateInstructions', 'canRespond', 'user'
	));
}
