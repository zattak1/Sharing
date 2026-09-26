<?php
/**
 * GET /sharing/engagements — mine: what I responded to, and responses on my
 * listings.
 */
function Sharing_engagements_response_content()
{
	$user = Users::loggedInUser(true);
	$mine = Sharing_Engagement::mine($user->id);
	$export = function ($e) use ($user) { return Sharing_Engagement::export($e, $user->id); };
	$responded = array_map($export, $mine['responded']);
	$received = array_map($export, $mine['received']);
	return Q::view('Sharing/content/engagements.php', @compact('responded', 'received', 'user'));
}
