<?php
/**
 * PUT Q/plugins/Sharing/engagement: apply one lifecycle verb. Fills "engagement".
 * @param {string} $_REQUEST.publisherId the engagement's publisher (the responder)
 * @param {string} $_REQUEST.engagementId the engagement's url id
 * @param {string} $_REQUEST.transition accept | reject | cancel | handOver | start | return | complete
 */
function Sharing_engagement_put($params)
{
	Q_Valid::nonce(true);
	$user = Users::loggedInUser(true);
	$params = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array('publisherId', 'engagementId', 'transition'), $params, true);
	$engagement = Sharing_Engagement::fetch(
		$user->id, $params['publisherId'], Sharing_Engagement::nameFromId($params['engagementId'])
	);
	if (!$engagement) {
		throw new Q_Exception_MissingRow(array('table' => 'engagement', 'criteria' => $params['engagementId']));
	}
	$engagement = Sharing_Engagement::transition($user->id, $engagement, $params['transition']);
	Q_Response::setSlot('engagement', Sharing_Engagement::export($engagement));
}
