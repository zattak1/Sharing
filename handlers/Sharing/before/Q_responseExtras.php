<?php
/**
 * Load the plugin's CSS/JS on Sharing-module pages only, and tell the client
 * what the current user may do so tools can render the right buttons
 * without a round trip.
 */
function Sharing_before_Q_responseExtras()
{
	$uri = Q_Dispatcher::uri();
	$module = ($uri and isset($uri->module)) ? $uri->module : null;
	if ($module !== 'Sharing') {
		return;
	}
	Q_Response::setScriptData('Q.plugins.Sharing.canOffer', Sharing::canOffer());
	Q_Response::setScriptData('Q.plugins.Sharing.canRequest', Sharing::canRequest());
	Q_Response::addStylesheet('{{Sharing}}/css/Sharing.css', 'Sharing');
	Q_Response::addScript('{{Sharing}}/js/Sharing.js', 'Sharing');
}
