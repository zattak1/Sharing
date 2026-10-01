<?php
/**
 * Q/Utils/sendToNode {before}: while a listing lock is held, keep the call
 * for after its COMMIT (ro#868). Sharing_Listing::locked() has switched the
 * transport off for that window, so the call itself sends nothing; outside
 * a lock this does nothing and the call goes out as usual.
 *
 * @event Q/Utils/sendToNode {before}
 * @param {array} $params data, url, options
 * @param {mixed} $result
 * @return {null}
 */
function Sharing_before_Q_Utils_sendToNode($params, &$result)
{
	Sharing_Listing::holdDirectNode($params);
	return null;
}
