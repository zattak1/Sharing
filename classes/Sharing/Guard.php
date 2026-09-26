<?php
/**
 * @module Sharing
 */
/**
 * The plugin's invariants, enforced at the Streams layer rather than in each
 * handler (ro#586, audit R03).
 *
 * Closing the generic routes one config flag at a time was not enough,
 * because several generic Streams handlers write a stream without asking
 * the stream type's rules at all:
 *
 * - Streams/stream POST creates any type whose config says create: true,
 *   copying the caller's attributes and treating a self-publisher as owner.
 * - Streams/related POST checks the caller's write level on the category
 *   only. The related stream is never access-checked, and with
 *   inheritAccess it rewrites that stream's inheritAccess column.
 * - Streams/form POST sets any field or attribute of any stream it can
 *   fetch, and checks no access at all.
 * - Streams/message POST lets a caller post any message type the stream
 *   type declares with a truthy value; `post: true` is not consulted.
 *
 * So the rules live on the events every one of those paths passes through
 * -- Streams/Stream/save, relateTo/relateFrom, unrelateTo/unrelateFrom and
 * Streams/post -- and they all reduce to one question: is this write coming
 * from the plugin's own code (Sharing::asServer)? Outside that scope a
 * Sharing stream cannot be created, cannot have a protected field changed,
 * cannot gain or lose a relation, and takes only the message types its
 * config marks `post: true`. The only field change allowed outside it is a
 * listing's title and content by its own publisher, which is the generic
 * PUT that config grants (`edit: ["title", "content"]`).
 *
 * @class Sharing_Guard
 */
class Sharing_Guard
{
	/**
	 * Fields only the plugin itself may change on any Sharing stream.
	 * Everything else -- updatedTime, the counters -- is bookkeeping the
	 * platform maintains, and blocking it would break ordinary posting.
	 */
	static $protected = array(
		'publisherId', 'name', 'type', 'attributes',
		'readLevel', 'writeLevel', 'adminLevel', 'permissions',
		'inheritAccess', 'closedTime'
	);

	/** Fields a listing's publisher may change through the generic PUT. */
	static $publisherEditable = array('title', 'content');

	/** Presentation fields, which only that PUT may change, and only on a listing. */
	static $presentation = array('title', 'content', 'icon');

	/**
	 * Whether a stream save may proceed. Pure: no database, no session, so
	 * the rule is pinned in the unit tier.
	 * @method saveViolation
	 * @static
	 * @param {string} $type the stream's type
	 * @param {boolean} $isInsert whether the row is new
	 * @param {array} $modifiedFields field => value about to be written
	 * @param {boolean} $actorIsPublisher whether the logged-in user publishes it
	 * @return {string|null} why the save is refused, or null to allow it
	 */
	static function saveViolation($type, $isInsert, $modifiedFields, $actorIsPublisher)
	{
		if ($isInsert) {
			return "Streams of type $type are created only by the Sharing plugin";
		}
		foreach (array_keys((array)$modifiedFields) as $field) {
			if (in_array($field, self::$protected, true)) {
				return "The $field of a $type stream changes only through the Sharing plugin";
			}
			if (!in_array($field, self::$presentation, true)) {
				continue;
			}
			if ($type !== Sharing_Listing::TYPE
			or !$actorIsPublisher
			or !in_array($field, self::$publisherEditable, true)) {
				return "The $field of a $type stream changes only through the Sharing plugin";
			}
		}
		return null;
	}

	/**
	 * Streams/Stream/save/<Sharing type> {before}.
	 * @method beforeSave
	 * @static
	 * @param {array} $params with "stream" and "modifiedFields"
	 * @throws {Users_Exception_NotAuthorized}
	 */
	static function beforeSave($params)
	{
		if (Sharing::isServerWrite()) {
			return;
		}
		$stream = $params['stream'];
		$user = Users::loggedInUser(false, false);
		$reason = self::saveViolation(
			$stream->type,
			!$stream->wasRetrieved(),
			Q::ifset($params, 'modifiedFields', array()),
			$user and $user->id === $stream->publisherId
		);
		if ($reason) {
			throw new Users_Exception_NotAuthorized($reason);
		}
	}

	/**
	 * Streams/relateTo, relateFrom, unrelateTo and unrelateFrom for every
	 * Sharing type {before}. Engagements are related to their listing, and
	 * listings to the community category, by the plugin; nothing else
	 * relates to or from these streams. Refused rather than skipped, so a
	 * client learns why.
	 * @method beforeRelation
	 * @static
	 * @throws {Users_Exception_NotAuthorized}
	 */
	static function beforeRelation($params)
	{
		if (Sharing::isServerWrite()) {
			return;
		}
		throw new Users_Exception_NotAuthorized(
			"Relations to and from Sharing streams change only through the Sharing plugin"
		);
	}

	/**
	 * Whether a message of this type may be posted on this stream type from
	 * outside the plugin. The plugin's own types (Sharing/...) are the
	 * lifecycle record and what notices are made from, so they need an
	 * explicit post: true -- which none has. Platform types (Streams/joined,
	 * Streams/changed, chat) pass: the platform posts them server-side from
	 * generic requests (a join, a title edit), and refusing them would only
	 * break those. Pure, apart from reading config.
	 * @method clientMayPost
	 * @static
	 * @param {string} $streamType
	 * @param {string} $messageType
	 * @return {boolean}
	 */
	static function clientMayPost($streamType, $messageType)
	{
		if (strncmp((string)$messageType, 'Sharing/', 8) !== 0) {
			return true;
		}
		$info = Streams_Stream::getConfigField($streamType, array('messages', $messageType), null);
		return is_array($info) and !empty($info['post']);
	}

	/**
	 * Streams/post/<Sharing type> {before}. Returns false, which the platform
	 * treats as "skip this message", rather than throwing: postMessages holds
	 * the stream rows FOR UPDATE here, and an exception would leave that
	 * transaction for the shutdown handler to roll back.
	 * @method beforePost
	 * @static
	 * @return {false|null}
	 */
	static function beforePost($params)
	{
		if (Sharing::isServerWrite()) {
			return null;
		}
		$stream = $params['stream'];
		$message = $params['message'];
		if (self::clientMayPost($stream->type, $message->type)) {
			return null;
		}
		Q::log("Sharing_Guard: refused a client post of {$message->type} on {$stream->type}"
			. " {$stream->publisherId}/{$stream->name} by {$message->byUserId}");
		return false;
	}
}
