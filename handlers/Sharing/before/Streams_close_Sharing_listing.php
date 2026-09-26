<?php
/**
 * Closing a listing happens only through Sharing_Listing::close() (ro#586,
 * audit R04): that takes the listing's lock, refuses while an engagement is
 * accepted or active, cancels the proposed ones and closes the stream in one
 * transaction. The type is close:false, so the generic Streams/stream DELETE
 * never gets here; this refuses any other caller of Streams::close() -- a
 * script, another plugin -- that would close a listing without that guard.
 * @event Streams/close/Sharing/listing {before}
 * @param {array} $params
 * @param {Streams_Stream} $params.stream
 */
function Sharing_before_Streams_close_Sharing_listing($params)
{
	if (Sharing::isServerWrite()) {
		return;
	}
	throw new Users_Exception_NotAuthorized(
		"Close a listing through Sharing_Listing::close(), which checks its engagements under the listing's lock"
	);
}
