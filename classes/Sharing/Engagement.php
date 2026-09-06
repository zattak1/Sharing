<?php
/**
 * @module Sharing
 */
/**
 * Engagements: a member's response to a listing, published by the responder
 * and related to the listing. State changes happen only through
 * transition(), which posts the lifecycle message after validating; the
 * stream type is edit:false so there is no other write path (plan §1.8).
 *
 * @class Sharing_Engagement
 */
class Sharing_Engagement
{
	const TYPE = 'Sharing/engagement';
	const RELATION = 'Sharing/engagement';

	static $terminal = array('rejected', 'cancelled', 'completed');
	static $blocking = array('accepted', 'active');

	const PUBLISHER = 'publisher';   // the listing's publisher
	const RESPONDER = 'responder';   // the engagement's publisher
	const EITHER = 'either';

	/**
	 * The lifecycle (plan §3). Keyed [fromState][verb] => array(
	 *   who may post, resulting state, custody requirement:
	 *   true = custody listings only, false = non-custody only, null = any).
	 * Nothing else encodes the graph; views derive their buttons from it.
	 */
	static $TRANSITIONS = array(
		'proposed' => array(
			'accept' => array(self::PUBLISHER, 'accepted', null),
			'reject' => array(self::PUBLISHER, 'rejected', null),
			'cancel' => array(self::EITHER, 'cancelled', null)
		),
		'accepted' => array(
			'cancel' => array(self::EITHER, 'cancelled', null),
			'handOver' => array(self::PUBLISHER, 'active', true),
			'start' => array(self::EITHER, 'active', false)
		),
		'active' => array(
			'return' => array(self::PUBLISHER, 'completed', true),
			'complete' => array(self::PUBLISHER, 'completed', false)
		)
	);

	/** Message type posted for each verb, and for creation. */
	static $messages = array(
		'propose' => 'Sharing/engagement/proposed',
		'accept' => 'Sharing/engagement/accepted',
		'reject' => 'Sharing/engagement/rejected',
		'cancel' => 'Sharing/engagement/cancelled',
		'handOver' => 'Sharing/engagement/handedOver',
		'start' => 'Sharing/engagement/started',
		'return' => 'Sharing/engagement/returned',
		'complete' => 'Sharing/engagement/completed'
	);

	/**
	 * Respond to a listing.
	 * @method propose
	 * @static
	 * @param {string} $userId the responder
	 * @param {Streams_Stream} $listing
	 * @param {array} [$params] note, quantity
	 * @return {Streams_Stream} the engagement
	 */
	static function propose($userId, $listing, $params = array())
	{
		$direction = $listing->getAttribute('direction');
		Sharing::requireCanRespond($direction, $userId);
		if ($listing->publisherId === $userId) {
			throw new Q_Exception("You cannot respond to your own listing");
		}
		if ($listing->getAttribute('paused') or $listing->closedTime) {
			throw new Q_Exception("This listing is not taking responses");
		}
		foreach (self::forListing($listing) as $existing) {
			if ($existing->publisherId === $userId
			and !in_array($existing->getAttribute('persistedState'), self::$terminal, true)) {
				throw new Q_Exception("You have already responded to this listing");
			}
		}
		if (self::conflicts($listing)) {
			throw new Q_Exception("Someone else has already been accepted for this listing");
		}
		$quantity = max(1, (int)Q::ifset($params, 'quantity', 1));
		$note = trim((string)Q::ifset($params, 'note', ''));

		$engagement = Streams::create($userId, $userId, self::TYPE, array(
			'title' => $listing->title,
			'attributes' => array(
				'listing' => array(
					'publisherId' => $listing->publisherId,
					'streamName' => $listing->name
				),
				'persistedState' => 'proposed',
				'quantity' => $quantity,
				'note' => $note
			)
		), array(
			'skipAccess' => true,
			'relate' => array(
				'publisherId' => $listing->publisherId,
				'streamName' => $listing->name,
				'type' => self::RELATION,
				'weight' => time(),
				'inheritAccess' => false
			)
		));

		// The responder publishes it, so the listing publisher needs an
		// explicit access row (plan §2.2).
		$access = new Streams_Access();
		$access->publisherId = $userId;
		$access->streamName = $engagement->name;
		$access->ofUserId = $listing->publisherId;
		if (!$access->retrieve()) {
			$access->readLevel = Streams::$READ_LEVEL['max'];
			$access->writeLevel = Streams::$WRITE_LEVEL['post'];
			$access->adminLevel = Streams::$ADMIN_LEVEL['none'];
			$access->save();
		}

		$instructions = array(
			'engagement' => array('publisherId' => $userId, 'streamName' => $engagement->name),
			'listing' => array('publisherId' => $listing->publisherId, 'streamName' => $listing->name),
			'responderId' => $userId
		);
		Streams_Message::post($userId, $userId, $engagement->name, array(
			'type' => self::$messages['propose'],
			'instructions' => $instructions
		), true);
		// ...and on the listing, so its publisher hears about it without
		// subscribing to every engagement.
		Streams_Message::post($userId, $listing->publisherId, $listing->name, array(
			'type' => self::$messages['propose'],
			'instructions' => $instructions
		), true);

		return Streams_Stream::fetch($userId, $userId, $engagement->name) ?: $engagement;
	}

	/**
	 * Apply one verb from the transition table.
	 * @method transition
	 * @static
	 * @param {string} $userId the actor
	 * @param {Streams_Stream} $engagement
	 * @param {string} $verb a key of self::$TRANSITIONS[state]
	 * @return {Streams_Stream} the engagement, re-fetched
	 * @throws {Users_Exception_NotAuthorized}
	 * @throws {Q_Exception}
	 */
	static function transition($userId, $engagement, $verb)
	{
		$listing = self::listingOf($engagement);
		if (!$listing) {
			throw new Q_Exception("The listing for this engagement no longer exists");
		}
		$role = self::roleOf($userId, $engagement, $listing);
		if (!$role) {
			throw new Users_Exception_NotAuthorized();
		}
		$from = $engagement->getAttribute('persistedState');
		$rule = Q::ifset(self::$TRANSITIONS, $from, $verb, null);
		if (!$rule) {
			throw new Q_Exception("Cannot $verb an engagement that is $from");
		}
		list($who, $to, $custodyRule) = $rule;
		if ($who !== self::EITHER and $who !== $role) {
			throw new Users_Exception_NotAuthorized();
		}
		$custody = (bool)$listing->getAttribute('custody');
		if ($custodyRule === true and !$custody) {
			throw new Q_Exception("This listing does not change hands; use start and complete");
		}
		if ($custodyRule === false and $custody) {
			throw new Q_Exception("This listing changes hands; use handOver and return");
		}

		$attributes = array('persistedState' => $to);
		if ($verb === 'accept') {
			// Under the listing's row lock so two accepts cannot both pass
			// the exclusivity check (plan §4.3); v2 adds the range condition
			// to the same code.
			Streams_Stream::select('*')->where(array(
				'publisherId' => $listing->publisherId,
				'name' => $listing->name
			))->begin('FOR UPDATE', 'Sharing/accept')->fetchDbRow();
			try {
				if (self::conflicts($listing, $engagement)) {
					throw new Q_Exception("Someone else has already been accepted for this listing");
				}
				// The private data reaches the responder only here (plan §4.7).
				$private = Sharing_Listing::privateStream($listing, $listing->publisherId);
				if ($private) {
					$attributes['private'] = array(
						'instructions' => $private->getAttribute('instructions')
					);
				}
				self::apply($userId, $engagement, $verb, $attributes);
				Streams_Stream::select('publisherId')->where(array(
					'publisherId' => $listing->publisherId,
					'name' => $listing->name
				))->commit('Sharing/accept')->execute();
			} catch (Exception $e) {
				Streams_Stream::select('publisherId')->where(array(
					'publisherId' => $listing->publisherId,
					'name' => $listing->name
				))->rollback()->execute();
				throw $e;
			}
		} else {
			self::apply($userId, $engagement, $verb, $attributes);
		}
		return Streams_Stream::fetch($userId, $engagement->publisherId, $engagement->name, '*', array(
			'skipAccess' => true
		)) ?: $engagement;
	}

	/**
	 * Cancel without the role check: used when a listing closes and its
	 * proposed engagements go with it (plan §4.5). Same message, same state.
	 * @method forceCancel
	 * @static
	 */
	static function forceCancel($byUserId, $engagement)
	{
		if ($engagement->getAttribute('persistedState') !== 'proposed') {
			return $engagement;
		}
		self::apply($byUserId, $engagement, 'cancel', array('persistedState' => 'cancelled'));
		return $engagement;
	}

	protected static function apply($userId, $engagement, $verb, $attributes)
	{
		foreach ($attributes as $k => $v) {
			$engagement->setAttribute($k, $v);
		}
		$engagement->changed($userId);
		Streams_Message::post($userId, $engagement->publisherId, $engagement->name, array(
			'type' => self::$messages[$verb],
			'instructions' => array('by' => $userId, 'state' => $attributes['persistedState'])
		), true);
	}

	/**
	 * Verbs the actor may apply now, for rendering buttons.
	 * @method allowed
	 * @static
	 * @return {array} verb => resulting state
	 */
	static function allowed($userId, $engagement, $listing = null)
	{
		$listing = $listing ?: self::listingOf($engagement);
		if (!$listing) {
			return array();
		}
		$role = self::roleOf($userId, $engagement, $listing);
		if (!$role) {
			return array();
		}
		$custody = (bool)$listing->getAttribute('custody');
		$result = array();
		$from = $engagement->getAttribute('persistedState');
		foreach (Q::ifset(self::$TRANSITIONS, $from, array()) as $verb => $rule) {
			list($who, $to, $custodyRule) = $rule;
			if ($who !== self::EITHER and $who !== $role) continue;
			if ($custodyRule === true and !$custody) continue;
			if ($custodyRule === false and $custody) continue;
			$result[$verb] = $to;
		}
		return $result;
	}

	/**
	 * v1 exclusivity (plan §4.3): an exclusive listing conflicts when any
	 * other engagement is accepted or active. No clock, so no ranges.
	 * @method conflicts
	 * @static
	 * @return {Streams_Stream|null} the blocking engagement
	 */
	static function conflicts($listing, $exclude = null)
	{
		if (!$listing->getAttribute('exclusive')) {
			return null;
		}
		foreach (self::forListing($listing) as $other) {
			if ($exclude and $other->publisherId === $exclude->publisherId
			and $other->name === $exclude->name) {
				continue;
			}
			if (in_array($other->getAttribute('persistedState'), self::$blocking, true)) {
				return $other;
			}
		}
		return null;
	}

	/**
	 * Every engagement on a listing, server-side (access skipped: callers
	 * decide what to show).
	 * @method forListing
	 * @static
	 * @return {array} of Streams_Stream, newest first
	 */
	static function forListing($listing)
	{
		$relations = Streams::related(null, $listing->publisherId, $listing->name, true, array(
			'type' => self::RELATION,
			'relationsOnly' => true,
			'dontFilterUsers' => true,
			'skipAccess' => true,
			'limit' => 100
		));
		$names = array();
		foreach ($relations as $r) {
			$names[$r->fromPublisherId][] = $r->fromStreamName;
		}
		$result = array();
		foreach ($names as $publisherId => $list) {
			// refetch: Streams::fetch caches per request, and conflicts() must
			// see the state an accept wrote moments ago in the same request.
			$fetched = Streams::fetch(null, $publisherId, $list, '*', array(
				'skipAccess' => true, 'refetch' => true
			));
			foreach ($fetched as $s) {
				if ($s and $s->type === self::TYPE) {
					$result[] = $s;
				}
			}
		}
		usort($result, function ($a, $b) {
			return strcmp($b->insertedTime, $a->insertedTime);
		});
		return $result;
	}

	/**
	 * The engagement a given responder has on a listing, if any.
	 * @method ofResponder
	 * @static
	 */
	static function ofResponder($listing, $userId)
	{
		foreach (self::forListing($listing) as $e) {
			if ($e->publisherId === $userId) {
				return $e;
			}
		}
		return null;
	}

	/**
	 * "Mine": engagements I responded with, and engagements on my listings.
	 * @method mine
	 * @static
	 * @return {array} array('responded' => [...], 'received' => [...])
	 */
	static function mine($userId)
	{
		$responded = Streams_Stream::select('*')->where(array(
			'publisherId' => $userId,
			'type' => self::TYPE
		))->orderBy('insertedTime', false)->limit(100)->fetchDbRows();
		$received = array();
		foreach (Sharing_Listing::fetchAll($userId, array('includePaused' => true)) as $listing) {
			if ($listing->publisherId !== $userId) {
				continue;
			}
			foreach (self::forListing($listing) as $e) {
				$received[] = $e;
			}
		}
		return compact('responded', 'received');
	}

	/** @method listingOf @static the listing an engagement belongs to */
	static function listingOf($engagement)
	{
		$l = $engagement->getAttribute('listing');
		if (empty($l['publisherId']) or empty($l['streamName'])) {
			return null;
		}
		return Streams_Stream::fetch(null, $l['publisherId'], $l['streamName'], '*', array('skipAccess' => true));
	}

	/** @method roleOf @static publisher, responder or null */
	static function roleOf($userId, $engagement, $listing)
	{
		if (!$userId) {
			return null;
		}
		if ($engagement->publisherId === $userId) {
			return self::RESPONDER;
		}
		if ($listing->publisherId === $userId) {
			return self::PUBLISHER;
		}
		return null;
	}

	/** @method id @static url id: the unique suffix of the stream name */
	static function id($name)
	{
		return substr($name, strlen(self::TYPE) + 1);
	}

	/** @method nameFromId @static */
	static function nameFromId($id)
	{
		return self::TYPE . '/' . $id;
	}

	/** @method url @static */
	static function url($publisherId, $name)
	{
		return Sharing::url("sharing/engagement/$publisherId/" . self::id($name));
	}

	/**
	 * Fetch one engagement as a user (access applies: the responder, the
	 * listing publisher through the access row, admins through config).
	 * @method fetch
	 * @static
	 */
	static function fetch($asUserId, $publisherId, $name)
	{
		// Streams::fetch returns the row regardless of access; the reader's
		// effective levels are on the object and must be tested here.
		$stream = Streams_Stream::fetch($asUserId, $publisherId, $name, '*', array('refetch' => true));
		if (!$stream or $stream->type !== self::TYPE or !$stream->testReadLevel('content')) {
			return null;
		}
		return $stream;
	}

	/** @method export @static */
	static function export($stream, $forUserId = null)
	{
		$a = $stream->getAllAttributes();
		$listing = Q::ifset($a, 'listing', array());
		return array(
			'publisherId' => $stream->publisherId,
			'name' => $stream->name,
			'id' => self::id($stream->name),
			'url' => self::url($stream->publisherId, $stream->name),
			'title' => $stream->title,
			'insertedTime' => Q::ifset($stream->fields, 'insertedTime', null),
			'listing' => $listing,
			'listingUrl' => (!empty($listing['publisherId']) && !empty($listing['streamName']))
				? Sharing_Listing::url($listing['publisherId'], $listing['streamName']) : null,
			'state' => Q::ifset($a, 'persistedState', 'proposed'),
			'quantity' => (int)Q::ifset($a, 'quantity', 1),
			'note' => Q::ifset($a, 'note', ''),
			'private' => Q::ifset($a, 'private', null)
		);
	}
}
