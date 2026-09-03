<?php
/**
 * The listing page at /sharing. Slice 1 renders the empty state; slice 2
 * replaces the body with the Sharing/resources tool over the community's
 * Sharing/resources/main category.
 */
function Sharing_resources_response_content()
{
	$canOffer = Sharing::canOffer();
	$canRequest = Sharing::canRequest();
	$loggedIn = (bool)Users::loggedInUser(false, false);
	return Q::view('Sharing/content/resources.php', @compact(
		'canOffer', 'canRequest', 'loggedIn'
	));
}
