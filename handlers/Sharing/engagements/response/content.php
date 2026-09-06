<?php
/**
 * GET /sharing/engagements — mine: what I responded to, and responses on my
 * listings.
 */
function Sharing_engagements_response_content()
{
	$user = Users::loggedInUser(true);
	$mine = Sharing_Engagement::mine($user->id);
	$responded = array_map(array('Sharing_Engagement', 'export'), $mine['responded']);
	$received = array_map(array('Sharing_Engagement', 'export'), $mine['received']);
	return Q::view('Sharing/content/engagements.php', @compact('responded', 'received', 'user'));
}
