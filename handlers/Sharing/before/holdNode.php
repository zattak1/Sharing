<?php
/**
 * Streams/post/<every Sharing type> {before}: while a listing lock is held,
 * postMessages() does not call node itself; Sharing_Listing::locked() makes
 * the call once the transaction has committed (ro#863). Registered after
 * guardPost, which may already have skipped the message.
 *
 * @event Streams/post/Sharing/engagement {before}
 * @param {array} $params 'sendToNode' is a reference into postMessages()
 * @param {mixed} $result
 * @return {null}
 */
function Sharing_before_holdNode($params, &$result)
{
	Sharing_Listing::holdNode($params);
	return null;
}
