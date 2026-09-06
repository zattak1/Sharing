<?php
/**
 * Load the plugin's CSS/JS on Sharing-module pages only, and tell the client
 * which of the four gates the current user passes so tools can render the right buttons
 * without a round trip.
 */
function Sharing_before_Q_responseExtras()
{
	$uri = Q_Dispatcher::uri();
	$module = ($uri and isset($uri->module)) ? $uri->module : null;
	if ($module !== 'Sharing') {
		return;
	}
	foreach (Sharing::gates() as $gate => $allowed) {
		Q_Response::setScriptData("Q.plugins.Sharing.$gate", $allowed);
	}
	Q_Response::addStylesheet('{{Sharing}}/css/Sharing.css', 'Sharing');
	Q_Response::addScript('{{Sharing}}/js/Sharing.js', 'Sharing');
}
