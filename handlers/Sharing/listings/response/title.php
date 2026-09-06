<?php
function Sharing_listings_response_title()
{
	$text = Q_Text::get('Sharing/content');
	return Q::ifset($text, 'listings', 'Title', 'Sharing');
}
