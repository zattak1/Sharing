<?php
function Sharing_resources_response_title()
{
	$text = Q_Text::get('Sharing/content');
	return Q::ifset($text, 'resources', 'Title', 'Sharing');
}
