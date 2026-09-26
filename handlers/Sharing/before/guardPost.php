<?php
/**
 * Streams/post/<every Sharing type> {before}: the plugin's lifecycle
 * messages are posted only by the plugin (ro#586). See
 * Sharing_Guard::beforePost for why this skips the message rather than
 * throwing.
 *
 * Both halves of the refusal are needed: returning false stops the other
 * handlers, but Q::event() then returns $result, not false, and
 * Streams_Message::postMessages() only skips a message when the event
 * returns exactly false.
 *
 * @event Streams/post/Sharing/engagement {before}
 * @param {array} $params
 * @param {mixed} $result
 * @return {false|null}
 */
function Sharing_before_guardPost($params, &$result)
{
	if (Sharing_Guard::beforePost($params) === false) {
		$result = false;
		return false;
	}
	return null;
}
