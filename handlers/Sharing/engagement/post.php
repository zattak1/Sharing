<?php
/**
 * POST Q/plugins/Sharing/engagement: respond to a listing. Fills "engagement".
 * @param {string} $_REQUEST.publisherId the listing's publisher
 * @param {string} $_REQUEST.listingId the listing's url id
 * @param {string} [$_REQUEST.note]
 * @param {integer} [$_REQUEST.quantity=1]
 */
function Sharing_engagement_post($params)
{
	Q_Valid::nonce(true);
	$user = Users::loggedInUser(true);
	$params = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array('publisherId', 'listingId'), $params, true);
	$listing = Sharing_Listing::fetch(
		$user->id, $params['publisherId'], Sharing_Listing::nameFromId($params['listingId'])
	);
	if (!$listing) {
		throw new Q_Exception_MissingRow(array('table' => 'listing', 'criteria' => $params['listingId']));
	}
	$engagement = Sharing_Engagement::propose($user->id, $listing, Q::take($params, array('note', 'quantity')));
	Q_Response::setSlot('engagement', Sharing_Engagement::export($engagement));
}
