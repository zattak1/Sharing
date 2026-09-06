<?php
/**
 * Closing a listing (plan §4.5): refuse while an engagement is accepted or
 * active — the publisher must resolve it first — otherwise cancel every
 * proposed engagement, then let the close proceed. For a need, this is also
 * how the poster says "I have enough helpers" in v1.
 * @event Streams/close/Sharing/listing {before}
 * @param {array} $params
 * @param {Streams_Stream} $params.stream
 */
function Sharing_before_Streams_close_Sharing_listing($params)
{
	$listing = $params['stream'];
	$user = Users::loggedInUser(false, false);
	$byUserId = $user ? $user->id : $listing->publisherId;
	$open = array();
	$proposed = array();
	foreach (Sharing_Engagement::forListing($listing) as $e) {
		$state = $e->getAttribute('persistedState');
		if (in_array($state, Sharing_Engagement::$blocking, true)) {
			$open[] = $e;
		} else if ($state === 'proposed') {
			$proposed[] = $e;
		}
	}
	if ($open) {
		throw new Q_Exception(
			"This listing still has an accepted or active engagement; complete or cancel it before closing"
		);
	}
	foreach ($proposed as $e) {
		Sharing_Engagement::forceCancel($byUserId, $e);
	}
}
