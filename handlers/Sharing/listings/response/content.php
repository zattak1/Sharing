<?php
/**
 * The listing page at /sharing. Slice 2 renders the empty state; slice 3
 * replaces the body with the Sharing/listings tool over the community's
 * Sharing/listings/main category.
 */
function Sharing_listings_response_content()
{
	$gates = Sharing::gates();
	$loggedIn = (bool)Users::loggedInUser(false, false);
	return Q::view('Sharing/content/listings.php', @compact('gates', 'loggedIn'));
}
