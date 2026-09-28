<?php
/**
 * Streams/postMessages {after}: keep the node call of a batch whose call
 * Sharing/before/holdNode held, for Sharing_Listing::locked() to send after
 * its COMMIT (ro#863). Runs for every batch in the app; outside a listing
 * lock it returns at once.
 *
 * @event Streams/postMessages {after}
 * @param {array} $params streams, messages, skipAccess, posted
 */
function Sharing_after_Streams_postMessages($params)
{
	Sharing_Listing::holdPosted(
		Q::ifset($params, 'posted', array()),
		Q::ifset($params, 'streams', array())
	);
}
