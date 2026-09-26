<?php
/**
 * @module Sharing
 */
/**
 * Engagements: a member's response to a listing, published by the responder
 * and related to the listing. State changes happen only through
 * transition(), which posts the lifecycle message after validating; the
 * stream type is create:false and edit:false, and Sharing_Guard refuses
 * every other write path at the Streams layer (plan §1.8, ro#586).
 *
 * Concurrency (ro#586, audit R04): every write that reads engagement state
 * to decide -- propose, every transition, closing a listing -- runs inside
 * Sharing_Listing::locked(), which holds the listing's streams_stream row
 * FOR UPDATE for the whole decision, and re-reads the listing and the
 * engagement from the database after taking it. One lock, always the
 * listing's, always taken first, so there is one order and no deadlock
 * between Sharing writers; and because the state is read under the lock,
 * the write is a compare-and-swap against what is actually stored, not
 * against whatever the caller fetched earlier in its request.
 *
 * @class Sharing_Engagement
 */
class Sharing_Engagement
{
	const TYPE = 'Sharing/engagement';
	const RELATION = 'Sharing/engagement';

	static $terminal = array('rejected', 'cancelled', 'completed');
	static $blocking = array('accepted', 'active');

	/** The listing's publisher. */
	const PUBLISHER = 'publisher';
	/** The engagement's publisher: whoever responded. */
	const RESPONDER = 'responder';
	/**
	 * Whichever party supplies the thing: the listing's publisher on an
	 * offer, the responder on a need (ro#586, audit R18). A lent ladder is
	 * handed over and its return confirmed by its owner, whichever of the
	 * two posted the listing.
	 */
	const PROVIDER = 'provider';
	/** A community member passing Sharing::canResolve(). */
	const ADMIN = 'admin';

	/**
	 * The lifecycle (plan §3). Keyed [fromState][verb] => array(
	 *   roles that may post it, resulting state, custody requirement:
	 *   true = custody listings only, false = non-custody only, null = any).
	 * Nothing else encodes the graph; views derive their buttons from it.
	 *
	 * ADMIN appears only on cancel: it is the resolution path for an
	 * engagement a party has abandoned (audit R18), and it is the one way
	 * out of `active` that is not completion -- the plan reserves `disputed`
	 * for the day a finer-grained outcome is needed.
	 */
	static $TRANSITIONS = array(
		'proposed' => array(
			'accept' => array(array(self::PUBLISHER), 'accepted', null),
			'reject' => array(array(self::PUBLISHER), 'rejected', null),
			'cancel' => array(array(self::PUBLISHER, self::RESPONDER, self::ADMIN), 'cancelled', null)
		),
		'accepted' => array(
			'cancel' => array(array(self::PUBLISHER, self::RESPONDER, self::ADMIN), 'cancelled', null),
			'handOver' => array(array(self::PROVIDER), 'active', true),
			'start' => array(array(self::PUBLISHER, self::RESPONDER), 'active', false)
		),
		'active' => array(
			'return' => array(array(self::PROVIDER), 'completed', true),
			'complete' => array(array(self::PUBLISHER), 'completed', false),
			'cancel' => array(array(self::ADMIN), 'cancelled', null)
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
	 * The listing fields that make up what was agreed, snapshotted onto the
	 * engagement's `accepted` attribute at acceptance (plan §4.7, audit R18):
	 * a publisher may still edit the listing's title and content afterwards,
	 * and that must not change what either party agreed to.
	 */
	static $terms = array('direction', 'kind', 'exclusive', 'custody', 'area');

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
		$quantity = max(1, (int)Q::ifset($params, 'quantity', 1));
		$note = trim((string)Q::ifset($params, 'note', ''));
		list($engagement, $listing) = Sharing_Listing::locked(
			$listing->publisherId, $listing->name,
			function ($listing) use ($userId, $quantity, $note) {
				// Everything below reads the listing and its engagements as
				// they are now, under the lock: two proposals from one
				// responder serialize here, and the second sees the first.
				Sharing::requireCanRespond($listing->getAttribute('direction'), $userId);
				if ($listing->publisherId === $userId) {
					throw new Q_Exception("You cannot respond to your own listing");
				}
				if ($listing->getAttribute('paused') or $listing->closedTime) {
					throw new Q_Exception("This listing is not taking responses");
				}
				if (self::liveOfResponder($listing, $userId)) {
					throw new Q_Exception("You have already responded to this listing");
				}
				if (self::conflicts($listing)) {
					throw new Q_Exception("Someone else has already been accepted for this listing");
				}
				return array(self::create($userId, $listing, $quantity, $note), $listing);
			}
		);
		// Neither lifecycle message is deliverable on its own: nobody
		// subscribes to a listing or an engagement, so email, SMS and push
		// come from the counterparty's notice stream (ro#769). After the
		// lock: a notice writes a stream and calls node.
		Sharing_Notice::send($userId, $engagement, $listing, self::$messages['propose']);
		return self::reload($engagement->publisherId, $engagement->name) ?: $engagement;
	}

	/**
	 * The writes of a proposal, under the listing's lock.
	 * @method create
	 * @static
	 * @protected
	 */
	protected static function create($userId, $listing, $quantity, $note)
	{
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
		return $engagement;
	}

	/**
	 * Apply one verb from the transition table.
	 *
	 * The engagement passed in identifies the row; its state is not trusted.
	 * The decision is made on the row as re-read under the listing's lock,
	 * so an accept and a cancel of the same engagement, or two accepts of
	 * one stale page, cannot both land: the second sees the first's result
	 * and is refused with "Cannot <verb> an engagement that is <state>".
	 *
	 * @method transition
	 * @static
	 * @param {string} $userId the actor
	 * @param {Streams_Stream} $engagement
	 * @param {string} $verb a key of self::$TRANSITIONS[state]
	 * @return {Streams_Stream} the engagement, re-read
	 * @throws {Users_Exception_NotAuthorized}
	 * @throws {Q_Exception}
	 */
	static function transition($userId, $engagement, $verb)
	{
		$l = $engagement->getAttribute('listing');
		if (empty($l['publisherId']) or empty($l['streamName'])) {
			throw new Q_Exception("The listing for this engagement no longer exists");
		}
		$publisherId = $engagement->publisherId;
		$name = $engagement->name;
		list($engagement, $listing) = Sharing_Listing::locked(
			$l['publisherId'], $l['streamName'],
			function ($listing) use ($userId, $publisherId, $name, $verb) {
				$engagement = self::reload($publisherId, $name, true);
				if (!$engagement or !self::belongsTo($engagement, $listing)) {
					throw new Q_Exception("The listing for this engagement no longer exists");
				}
				$roles = self::rolesOf($userId, $engagement, $listing);
				if (!$roles) {
					throw new Users_Exception_NotAuthorized();
				}
				$from = $engagement->getAttribute('persistedState');
				$rule = Q::ifset(self::$TRANSITIONS, $from, $verb, null);
				if (!$rule) {
					throw new Q_Exception("Cannot $verb an engagement that is $from");
				}
				list($who, $to, $custodyRule) = $rule;
				if (!self::permits($who, $roles, $listing)) {
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
					// Exclusivity, against every engagement as stored now
					// (plan §4.3). v2 adds the range condition here.
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
					$attributes['accepted'] = self::termsOf($listing, $engagement);
				}
				self::apply($userId, $engagement, $verb, $attributes);
				return array($engagement, $listing);
			}
		);
		// After the lock is released: the message is the audit trail, the
		// notice is what actually gets delivered (ro#769), and it writes a
		// stream and calls node, which has no business inside the lock.
		Sharing_Notice::send($userId, $engagement, $listing, self::$messages[$verb]);
		return self::reload($engagement->publisherId, $engagement->name) ?: $engagement;
	}

	/**
	 * Cancel without the role check: used when a listing closes and its
	 * proposed engagements go with it (plan §4.5). Same message, same state.
	 * Must be called under the listing's lock (Sharing_Listing::close() is
	 * the caller), and returns whether it cancelled, so the caller can send
	 * the notices once the lock is released.
	 * @method forceCancel
	 * @static
	 * @return {boolean}
	 */
	static function forceCancel($byUserId, $engagement)
	{
		$engagement = self::reload($engagement->publisherId, $engagement->name, true);
		if (!$engagement or $engagement->getAttribute('persistedState') !== 'proposed') {
			return false;
		}
		self::apply($byUserId, $engagement, 'cancel', array('persistedState' => 'cancelled'));
		return true;
	}

	protected static function apply($userId, $engagement, $verb, $attributes)
	{
		Sharing::asServer(function () use ($userId, $engagement, $verb, $attributes) {
			foreach ($attributes as $k => $v) {
				$engagement->setAttribute($k, $v);
			}
			$engagement->changed($userId);
			Streams_Message::post($userId, $engagement->publisherId, $engagement->name, array(
				'type' => self::$messages[$verb],
				'instructions' => array('by' => $userId, 'state' => $attributes['persistedState'])
			), true);
		});
	}

	/**
	 * What was agreed, as the listing stands at acceptance.
	 * @method termsOf
	 * @static
	 * @return {array}
	 */
	static function termsOf($listing, $engagement)
	{
		$terms = array(
			'title' => $listing->title,
			'content' => $listing->content,
			'quantity' => (int)$engagement->getAttribute('quantity', 1),
			'acceptedTime' => time()
		);
		foreach (self::$terms as $k) {
			$terms[$k] = $listing->getAttribute($k);
		}
		return $terms;
	}

	/**
	 * Verbs the actor may apply now, for rendering buttons. A reading, not
	 * a decision: transition() re-checks under the lock.
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
		$roles = self::rolesOf($userId, $engagement, $listing);
		if (!$roles) {
			return array();
		}
		$custody = (bool)$listing->getAttribute('custody');
		$result = array();
		$from = $engagement->getAttribute('persistedState');
		foreach (Q::ifset(self::$TRANSITIONS, $from, array()) as $verb => $rule) {
			list($who, $to, $custodyRule) = $rule;
			if (!self::permits($who, $roles, $listing)) continue;
			if ($custodyRule === true and !$custody) continue;
			if ($custodyRule === false and $custody) continue;
			$result[$verb] = $to;
		}
		return $result;
	}

	/**
	 * Whether any of the actor's roles is one the rule names; PROVIDER
	 * resolves to a party by the listing's direction. Pure.
	 * @method permits
	 * @static
	 * @param {array} $who roles from a $TRANSITIONS rule
	 * @param {array} $roles the actor's, from rolesOf()
	 * @param {string|Streams_Stream} $listing the listing, or its direction
	 * @return {boolean}
	 */
	static function permits($who, $roles, $listing)
	{
		$direction = is_string($listing) ? $listing : $listing->getAttribute('direction');
		foreach ($who as $w) {
			if ($w === self::PROVIDER) {
				$w = self::providerRole($direction);
			}
			if (in_array($w, $roles, true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Which party supplies the thing. Pure.
	 * @method providerRole
	 * @static
	 * @param {string} $direction "offer" or "need"
	 * @return {string} self::PUBLISHER or self::RESPONDER
	 */
	static function providerRole($direction)
	{
		return $direction === 'need' ? self::RESPONDER : self::PUBLISHER;
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
		foreach (self::blocking($listing) as $other) {
			if ($exclude and $other->publisherId === $exclude->publisherId
			and $other->name === $exclude->name) {
				continue;
			}
			return $other;
		}
		return null;
	}

	/**
	 * Every accepted or active engagement on a listing.
	 * @method blocking
	 * @static
	 * @return {array} of Streams_Stream
	 */
	static function blocking($listing)
	{
		$result = array();
		foreach (self::forListing($listing) as $e) {
			if (in_array($e->getAttribute('persistedState'), self::$blocking, true)) {
				$result[] = $e;
			}
		}
		return $result;
	}

	/**
	 * Every engagement on a listing, server-side and complete (ro#586,
	 * audit R05): the exclusivity check, the duplicate-response check and
	 * the close guard are correctness decisions, so this reads every
	 * relation rather than a page of them, and reads the rows themselves
	 * past every cache, so a decision under the listing's lock sees what is
	 * stored. The relation is only an index: a row counts only if its own
	 * `listing` attribute names this listing, which only the plugin writes.
	 * Callers decide what to show; nothing here applies access.
	 * @method forListing
	 * @static
	 * @return {array} of Streams_Stream, newest first
	 */
	static function forListing($listing)
	{
		$relations = Streams_RelatedTo::select('fromPublisherId, fromStreamName')->where(array(
			'toPublisherId' => $listing->publisherId,
			'toStreamName' => $listing->name,
			'type' => self::RELATION
		))->ignoreCache()->caching(false)->fetchAll(PDO::FETCH_ASSOC);
		$names = array();
		foreach ($relations as $r) {
			$names[$r['fromPublisherId']][] = $r['fromStreamName'];
		}
		$result = array();
		foreach ($names as $publisherId => $list) {
			foreach (array_chunk($list, 100) as $chunk) {
				$rows = Streams_Stream::select('*')->where(array(
					'publisherId' => $publisherId,
					'name' => $chunk
				))->ignoreCache()->caching(false)->fetchDbRows();
				foreach ($rows as $s) {
					if ($s->type === self::TYPE and self::belongsTo($s, $listing)) {
						$result[] = $s;
					}
				}
			}
		}
		usort($result, function ($a, $b) {
			return strcmp($b->insertedTime, $a->insertedTime);
		});
		return $result;
	}

	/** @method belongsTo @static whether an engagement names this listing */
	static function belongsTo($engagement, $listing)
	{
		$l = $engagement->getAttribute('listing');
		return is_array($l)
			and Q::ifset($l, 'publisherId', null) === $listing->publisherId
			and Q::ifset($l, 'streamName', null) === $listing->name;
	}

	/**
	 * The engagement a given responder has on a listing, if any: their live
	 * one if they have one, else their newest.
	 * @method ofResponder
	 * @static
	 */
	static function ofResponder($listing, $userId)
	{
		$newest = null;
		foreach (self::forListing($listing) as $e) {
			if ($e->publisherId !== $userId) {
				continue;
			}
			if (!in_array($e->getAttribute('persistedState'), self::$terminal, true)) {
				return $e;
			}
			$newest = $newest ?: $e;
		}
		return $newest;
	}

	/**
	 * A responder's engagement on a listing that is not yet terminal.
	 * @method liveOfResponder
	 * @static
	 */
	static function liveOfResponder($listing, $userId)
	{
		$e = self::ofResponder($listing, $userId);
		return ($e and !in_array($e->getAttribute('persistedState'), self::$terminal, true))
			? $e : null;
	}

	/**
	 * "Mine": engagements I responded with, and engagements on my open
	 * listings -- read from my own listings directly, not from a page of the
	 * community's.
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
		foreach (Sharing_Listing::ofPublisher($userId) as $listing) {
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

	/**
	 * An engagement row as stored, past every cache; with $forUpdate, also
	 * locked (inside a transaction the caller began, e.g.
	 * Sharing_Listing::locked()).
	 * @method reload
	 * @static
	 * @return {Streams_Stream|null}
	 */
	static function reload($publisherId, $name, $forUpdate = false)
	{
		$q = Streams_Stream::select('*')->where(array(
			'publisherId' => $publisherId,
			'name' => $name,
			'type' => self::TYPE
		))->ignoreCache()->caching(false);
		if ($forUpdate) {
			$q->lock('FOR UPDATE');
		}
		return $q->fetchDbRow() ?: null;
	}

	/**
	 * The actor's party role: publisher, responder or null. Views use it to
	 * decide what to show; authorization uses rolesOf().
	 * @method roleOf
	 * @static
	 */
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

	/**
	 * Every role the actor holds on this engagement.
	 * @method rolesOf
	 * @static
	 * @return {array}
	 */
	static function rolesOf($userId, $engagement, $listing)
	{
		if (!$userId) {
			return array();
		}
		$roles = array();
		if ($engagement->publisherId === $userId) {
			$roles[] = self::RESPONDER;
		}
		if ($listing->publisherId === $userId) {
			$roles[] = self::PUBLISHER;
		}
		if (Sharing::canResolve($userId)) {
			$roles[] = self::ADMIN;
		}
		return $roles;
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
	 * listing publisher through the access row), or, for a member who may
	 * resolve engagements, regardless of access.
	 * @method fetch
	 * @static
	 */
	static function fetch($asUserId, $publisherId, $name)
	{
		// Streams::fetch returns the row regardless of access; the reader's
		// effective levels are on the object and must be tested here.
		$stream = Streams_Stream::fetch($asUserId, $publisherId, $name, '*', array('refetch' => true));
		if (!$stream or $stream->type !== self::TYPE) {
			return null;
		}
		if ($stream->testReadLevel('content')
		or ($asUserId and Sharing::canResolve($asUserId))) {
			return $stream;
		}
		return null;
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
			'private' => Q::ifset($a, 'private', null),
			'accepted' => Q::ifset($a, 'accepted', null)
		);
	}
}
