<?php
/**
 * POST Q/plugins/Sharing/listing: create a listing (offer or need) as the
 * logged-in user. Fills the "stream" slot with the new listing.
 * @param {string} $_REQUEST.direction "offer" or "need"
 * @param {string} $_REQUEST.kind "item" or "service"
 * @param {string} $_REQUEST.title
 * @param {string} [$_REQUEST.content]
 * @param {string} [$_REQUEST.area]
 * @param {boolean} [$_REQUEST.exclusive]
 * @param {boolean} [$_REQUEST.custody]
 * @param {string} [$_REQUEST.private] shown only to accepted responders
 */
function Sharing_listing_post($params)
{
	Q_Valid::nonce(true);
	$user = Users::loggedInUser(true);
	$params = array_merge($_REQUEST, $params);
	$stream = Sharing_Listing::create($user->id, Q::take($params, array(
		'direction', 'kind', 'title', 'content', 'area', 'exclusive', 'custody', 'private'
	)));
	Q_Response::setSlot('stream', Sharing_Listing::export($stream));
}
