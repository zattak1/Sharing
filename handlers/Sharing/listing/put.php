<?php
/**
 * PUT Q/plugins/Sharing/listing: manage a listing. Fills "stream".
 *
 * The listing's recruitment switch and its end, both under the listing's
 * lock (ro#586): pause and resume stop and restart responses without
 * touching an engagement, and close is refused while one is accepted or
 * active. The publisher manages their listing; a member passing
 * Sharing::canResolve() may too.
 *
 * @param {string} $_REQUEST.publisherId the listing's publisher
 * @param {string} $_REQUEST.listingId the listing's url id
 * @param {string} $_REQUEST.action pause | resume | close
 */
function Sharing_listing_put($params)
{
	Q_Valid::nonce(true);
	$user = Users::loggedInUser(true);
	$params = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array('publisherId', 'listingId', 'action'), $params, true);
	$publisherId = $params['publisherId'];
	$name = Sharing_Listing::nameFromId($params['listingId']);
	if (!Sharing_Listing::fetch($user->id, $publisherId, $name)) {
		throw new Q_Exception_MissingRow(array('table' => 'listing', 'criteria' => $params['listingId']));
	}
	switch ($params['action']) {
		case 'pause':
		case 'resume':
			Sharing_Listing::setPaused($user->id, $publisherId, $name, $params['action'] === 'pause');
			break;
		case 'close':
			Sharing_Listing::close($user->id, $publisherId, $name);
			break;
		default:
			throw new Q_Exception_WrongValue(array(
				'field' => 'action', 'range' => 'pause, resume or close'
			));
	}
	$stream = Streams_Stream::fetch($user->id, $publisherId, $name, '*', array('refetch' => true));
	Q_Response::setSlot('stream', Sharing_Listing::export($stream));
}
