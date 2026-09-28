<?php
/**
 * @module Sharing
 */
/**
 * Listings: a member's offer or need, published by that member and related
 * to the community's Sharing/listings/main category.
 *
 * @class Sharing_Listing
 */
class Sharing_Listing
{
	const TYPE = 'Sharing/listing';
	const PRIVATE_TYPE = 'Sharing/listing/private';
	const CATEGORY = 'Sharing/listings/main';
	const RELATION = 'Sharing/listing';

	static $directions = array('offer', 'need');
	/** Lean v1: no clock, so no time or space kinds yet (plan §0). */
	static $kinds = array('item', 'service');

	/**
	 * The community's listings category, created on first use the way
	 * Calendars/availabilities/main is.
	 * @method category
	 * @static
	 * @param {string} [$communityId=null]
	 * @return {Streams_Stream}
	 */
	static function category($communityId = null)
	{
		if (!isset($communityId)) {
			$communityId = Sharing::communityId();
		}
		$category = Streams_Stream::fetch($communityId, $communityId, self::CATEGORY);
		if (!$category) {
			$category = Streams::create($communityId, $communityId, 'Streams/category', array(
				'name' => self::CATEGORY,
				'title' => 'Sharing listings'
			), array('skipAccess' => true));
		}
		return $category;
	}

	/**
	 * Create a listing on behalf of a member. Label-gated by direction.
	 * @method create
	 * @static
	 * @param {string} $userId the publisher
	 * @param {array} $params
	 * @param {string} $params.direction "offer" or "need"
	 * @param {string} $params.kind one of self::$kinds
	 * @param {string} $params.title
	 * @param {string} [$params.content]
	 * @param {string} [$params.area] the public location hint
	 * @param {boolean} [$params.exclusive] defaults by kind
	 * @param {boolean} [$params.custody] defaults by kind
	 * @param {string} [$params.private] instructions shown only to accepted
	 *   responders; stored on a separate Sharing/listing/private stream
	 * @return {Streams_Stream} the listing
	 * @throws {Users_Exception_NotAuthorized}
	 * @throws {Q_Exception_WrongValue}
	 * @throws {Q_Exception_RequiredField}
	 */
	static function create($userId, $params)
	{
		$direction = Q::ifset($params, 'direction', null);
		if (!in_array($direction, self::$directions, true)) {
			throw new Q_Exception_WrongValue(array(
				'field' => 'direction', 'range' => 'offer or need'
			));
		}
		Sharing::requireCanCreate($direction, $userId);

		$kind = Q::ifset($params, 'kind', null);
		if (!in_array($kind, self::$kinds, true)) {
			throw new Q_Exception_WrongValue(array(
				'field' => 'kind', 'range' => implode(' or ', self::$kinds)
			));
		}
		$title = trim((string)Q::ifset($params, 'title', ''));
		if ($title === '') {
			throw new Q_Exception_RequiredField(array('field' => 'title'));
		}
		$content = trim((string)Q::ifset($params, 'content', ''));
		$area = trim((string)Q::ifset($params, 'area', ''));
		$private = trim((string)Q::ifset($params, 'private', ''));
		// Before anything is written: each of these is later copied into a
		// JSON column with a hard cap (Sharing::$limits, ro#586 audit R01).
		foreach (array('title', 'content', 'area', 'private') as $field) {
			Sharing::requireFits($field, $$field);
		}

		// Facts, not workflow (plan §1.6). Defaults follow the kind.
		$isItem = ($kind === 'item');
		$exclusive = self::bool(Q::ifset($params, 'exclusive', $isItem));
		$custody = self::bool(Q::ifset($params, 'custody', $isItem));

		$attributes = array(
			'direction' => $direction,
			'kind' => $kind,
			'exclusive' => $exclusive,
			'custody' => $custody,
			'area' => $area,
			'paused' => false
		);

		$communityId = Sharing::communityId();
		$category = self::category($communityId);

		// In the plugin's write scope: Sharing_Guard refuses to create a
		// Sharing stream, or relate one, from anywhere else (ro#586).
		return Sharing::asServer(function () use (
			$userId, $title, $content, $attributes, $communityId, $category, $private
		) {
			// skipAccess: the label gate above is the authorization; an ordinary
			// member has no write access on the community's category stream, and
			// relating to it is exactly what a listing must do.
			$stream = Streams::create($userId, $userId, self::TYPE, array(
				'title' => $title,
				'content' => $content,
				'attributes' => $attributes
			), array(
				'skipAccess' => true,
				'relate' => array(
					'publisherId' => $communityId,
					'streamName' => $category->name,
					'type' => self::RELATION,
					'weight' => time(),
					'inheritAccess' => false
				)
			));

			// Private data never lives on the listing (plan §1.7): attributes are
			// one blob served whole to anyone at read level.
			if ($private !== '') {
				Streams::create($userId, $userId, self::PRIVATE_TYPE, array(
					'name' => self::privateName($stream->name),
					'title' => 'Private details: ' . $title,
					'attributes' => array('instructions' => $private)
				), array('skipAccess' => true));
			}

			// Re-fetch so callers get a full row (insertedTime etc.), not the
			// partially populated object Streams::create() returns.
			return Streams_Stream::fetch($userId, $userId, $stream->name) ?: $stream;
		});
	}

	/**
	 * Run $callable with the listing's row locked, and return its result.
	 *
	 * The one lock every Sharing decision takes (ro#586, audit R04):
	 * proposing, every engagement transition, pausing and closing all read
	 * state and write on it, so all of them hold the listing's
	 * streams_stream row FOR UPDATE from before the read until after the
	 * write. $callable gets the listing as re-read under the lock -- never
	 * the caller's copy, which may be from before a competing write -- and
	 * runs in the plugin's write scope (Sharing::asServer). The transaction
	 * commits when it returns and rolls back if it throws.
	 *
	 * Lock order: this row first, then whatever $callable touches. Every
	 * Sharing writer goes through here, so none of them can hold an
	 * engagement and wait for its listing.
	 *
	 * Node hears about it after the COMMIT, not before (ro#863). Messages
	 * posted on a Sharing stream while the lock is held are written in the
	 * transaction -- they are the record, and roll back with it -- but
	 * Streams_Message::postMessages() would also call node for them before
	 * returning, which inside this transaction is before anything is
	 * committed: sockets would hear of a decision that could still roll back,
	 * and with node down every decision would hold this row for the call's
	 * one-second timeout. So those calls are held (holdNode) and sent from
	 * here once the COMMIT has returned, and dropped if it never does. The
	 * after-handlers postMessages runs stay inside: they are database work
	 * that has to commit or roll back with the message.
	 *
	 * @method locked
	 * @static
	 * @param {string} $publisherId
	 * @param {string} $name
	 * @param {callable} $callable receives the locked Streams_Stream
	 * @return {mixed}
	 * @throws {Q_Exception_MissingRow} if there is no such listing
	 */
	static function locked($publisherId, $name, $callable)
	{
		$where = array('publisherId' => $publisherId, 'name' => $name);
		$listing = Streams_Stream::select('*')->where($where)
			->begin('FOR UPDATE', self::LOCK_KEY)
			->caching(false)
			->fetchDbRow();
		// Only the outermost lock owns the held node calls; the plugin never
		// nests locked(), but if it did, the inner COMMIT is not the real one.
		$owner = (self::$heldForNode === null);
		if ($owner) {
			self::$heldForNode = array();
		}
		try {
			if (!$listing or $listing->type !== self::TYPE) {
				throw new Q_Exception_MissingRow(array(
					'table' => 'listing', 'criteria' => "$publisherId $name"
				));
			}
			$result = Sharing::asServer(function () use ($callable, $listing) {
				return call_user_func($callable, $listing);
			});
			self::requireStillInTransaction();
		} catch (Exception $e) {
			self::dropHeld($owner);
			self::rollback($where);
			throw $e;
		} catch (Throwable $e) {
			self::dropHeld($owner);
			self::rollback($where);
			throw $e;
		}
		try {
			Streams_Stream::select('publisherId')->where($where)
				->commit(self::LOCK_KEY)->execute();
		} catch (Exception $e) {
			self::dropHeld($owner);
			throw $e;
		}
		if ($owner) {
			$held = self::$heldForNode;
			self::dropHeld(true);
			self::sendHeld($held);
		}
		return $result;
	}

	/**
	 * Node calls for messages posted under the lock, waiting for its COMMIT
	 * (ro#863). Null when no lock is held.
	 * @property $heldForNode
	 * @type {array|null}
	 * @static
	 * @protected
	 */
	protected static $heldForNode = null;

	/**
	 * Streams/post/<Sharing type> and Streams/message/Streams/unrelatedTo
	 * {before} (see holdsNodeFor): while a listing lock is held,
	 * stop postMessages() from calling node itself (it exposes the flag by
	 * reference for this), so the call can be made after the COMMIT. Outside
	 * a lock -- a notice, a chat message -- nothing changes.
	 * @method holdNode
	 * @static
	 * @param {array} $params the event's params; $params['sendToNode'] is a reference
	 */
	static function holdNode($params)
	{
		if (self::$heldForNode === null or !array_key_exists('sendToNode', $params)) {
			return;
		}
		$params['sendToNode'] = false;
	}

	/** The handler that holds a type's node calls, as config registers it. */
	const HOLD_HANDLER = 'Sharing/before/holdNode';

	/**
	 * Streams/postMessages {after}: if this batch's node call was held, keep
	 * exactly what postMessages() would have sent -- every row it inserted,
	 * and the streams, exported the same way -- for after the COMMIT.
	 *
	 * A batch was held when it posted a message holdNode is registered for
	 * (holdsNodeFor): that handler ran for it and cleared the flag. This
	 * is read off the batch rather than from a flag holdNode sets, because
	 * postMessages() nests -- its after-handlers can post -- and an inner
	 * batch would consume a shared flag before the outer one got here.
	 * @method holdPosted
	 * @static
	 * @param {array} $posted publisherId => streamName => array of Streams_Message
	 * @param {array} $streams publisherId => streamName => Streams_Stream
	 */
	static function holdPosted($posted, $streams)
	{
		if (self::$heldForNode === null) {
			return;
		}
		$rows = array();
		$held = false;
		foreach ((array)$posted as $publisherId => $arr) {
			foreach ((array)$arr as $streamName => $messages) {
				foreach ((array)$messages as $message) {
					if (!($message instanceof Streams_Message)) {
						continue;
					}
					$rows[] = $message->fields;
					$stream = Q::ifset($streams, $publisherId, $streamName, null);
					if (self::holdsNodeFor($stream ? $stream->type : null, $message->type)) {
						$held = true;
					}
				}
			}
		}
		if (!$held) {
			return;
		}
		self::$heldForNode[] = array(
			"Q/method" => "Streams/Message/postMessages",
			"posted" => Q::json_encode($rows),
			"streams" => Q::json_encode(Db::exportArray($streams, array("skipAccess" => true)))
		);
	}

	/**
	 * Whether posting this message runs holdNode: config registers it on
	 * the Streams/post event of every Sharing stream type, and on the
	 * Streams/message event of the one platform message a decision posts on
	 * a stream that is not the plugin's (close() unrelating the listing from
	 * its community category).
	 * @method holdsNodeFor
	 * @static
	 * @param {string|null} $streamType
	 * @param {string} $messageType
	 * @return {boolean}
	 */
	static function holdsNodeFor($streamType, $messageType)
	{
		$events = array("Streams/message/$messageType");
		if ($streamType) {
			$events[] = "Streams/post/$streamType";
		}
		foreach ($events as $event) {
			$handlers = Q_Config::get('Q', 'handlersBeforeEvent', $event, array());
			if (in_array(self::HOLD_HANDLER, (array)$handlers, true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Forget the held node calls: the transaction they describe rolled back.
	 * @method dropHeld
	 * @static
	 * @protected
	 */
	protected static function dropHeld($owner)
	{
		if ($owner) {
			self::$heldForNode = null;
		}
	}

	/**
	 * Send the held node calls, now that what they describe is committed.
	 * A failure here is logged: the decision stands, and a missed socket
	 * update is what a node outage already means everywhere else.
	 * @method sendHeld
	 * @static
	 * @protected
	 */
	protected static function sendHeld($held)
	{
		foreach ($held as $data) {
			try {
				Q_Utils::sendToNode($data);
			} catch (Exception $e) {
				Q::log("Sharing_Listing: node call after commit failed: " . $e->getMessage());
			}
		}
	}

	/**
	 * Throw unless the lock's transaction is still open (ro#586 audit R04).
	 *
	 * Db_Query_Mysql rolls back the WHOLE nested transaction on any failed
	 * query and zeroes its count before rethrowing. If something below
	 * locked() catches and swallows that exception -- Streams::close() does,
	 * around its unrelates -- the callable carries on with the listing lock
	 * gone, its remaining writes autocommit, and the keyed commit at the end
	 * is silently a no-op. Asking PDO is the one check that does not depend
	 * on the query layer's bookkeeping it just reset.
	 * @method requireStillInTransaction
	 * @static
	 * @protected
	 * @throws {Q_Exception}
	 */
	protected static function requireStillInTransaction()
	{
		$pdo = Streams_Stream::db()->reallyConnect();
		if (!$pdo or !$pdo->inTransaction()) {
			throw new Q_Exception(
				"The listing's lock was lost to a rolled-back query partway through; nothing after it is guaranteed, so this was refused"
			);
		}
	}

	/** The transaction key of the listing lock. */
	const LOCK_KEY = 'Sharing/listing';

	/**
	 * Roll the listing lock's transaction back. A failed query inside it may
	 * already have rolled everything back (Db_Query_Mysql does, on any query
	 * error), in which case PDO has no transaction to roll back and says so;
	 * that must not mask the exception being handled.
	 * @method rollback
	 * @static
	 * @protected
	 */
	protected static function rollback($where)
	{
		try {
			Streams_Stream::select('publisherId')->where($where)->rollback()->execute();
		} catch (Exception $e) {
			// already rolled back
		}
	}

	/**
	 * Close a listing (plan §4.5), under its lock: refused while an
	 * engagement is accepted or active -- recruitment stops with pause(),
	 * not close(), so commitments survive it (audit R18) -- and otherwise
	 * every proposed engagement is cancelled and the listing closed in the
	 * same transaction, so no proposal or acceptance can land in between.
	 * @method close
	 * @static
	 * @param {string} $userId the publisher, or a member who may resolve
	 * @param {string} $publisherId
	 * @param {string} $name
	 * @return {boolean} whether this call closed it
	 * @throws {Users_Exception_NotAuthorized}
	 * @throws {Q_Exception}
	 */
	static function close($userId, $publisherId, $name)
	{
		$cancelled = array();
		$listing = null;
		$closed = self::locked($publisherId, $name, function ($l) use ($userId, &$cancelled, &$listing) {
			$listing = $l;
			self::requireManager($userId, $l);
			if ($l->closedTime) {
				return false;
			}
			$proposed = array();
			foreach (Sharing_Engagement::forListing($l) as $e) {
				$state = $e->getAttribute('persistedState');
				if (in_array($state, Sharing_Engagement::$blocking, true)) {
					throw new Q_Exception(
						"This listing still has an accepted or active engagement; complete or cancel it before closing"
					);
				}
				if ($state === 'proposed') {
					$proposed[] = $e;
				}
			}
			foreach ($proposed as $e) {
				if (Sharing_Engagement::forceCancel($userId, $e)) {
					$cancelled[] = $e;
				}
			}
			// unrelate/skipAccess too (ro#586 audit R02): Streams::close()
			// unrelates what is related TO the listing with the closer's own
			// access and swallows the refusal, so a resolver's close used to
			// leave the relations a publisher's close removes. The decision to
			// close was made above, under the lock; the unrelates follow it.
			return Streams::close($userId, $l->publisherId, $l->name, array(
				'skipAccess' => true,
				'unrelate' => array('skipAccess' => true)
			));
		});
		foreach ($cancelled as $e) {
			Sharing_Notice::send($userId, $e, $listing, Sharing_Engagement::$messages['cancel']);
		}
		return (bool)$closed;
	}

	/**
	 * Stop or resume taking responses without touching any engagement:
	 * the recruitment switch, separate from fulfilment (audit R18). A poster
	 * who has accepted enough helpers pauses, then completes what they
	 * accepted; closing waits for that.
	 * @method setPaused
	 * @static
	 * @param {string} $userId the publisher, or a member who may resolve
	 * @param {string} $publisherId
	 * @param {string} $name
	 * @param {boolean} $paused
	 * @return {Streams_Stream} the listing
	 */
	static function setPaused($userId, $publisherId, $name, $paused)
	{
		self::locked($publisherId, $name, function ($l) use ($userId, $paused) {
			self::requireManager($userId, $l);
			if ($l->closedTime) {
				throw new Q_Exception("This listing is closed");
			}
			if ((bool)$l->getAttribute('paused') === (bool)$paused) {
				return;
			}
			$l->setAttribute('paused', (bool)$paused);
			$l->changed($userId);
		});
		return Streams_Stream::fetch($userId, $publisherId, $name, '*', array('refetch' => true));
	}

	/**
	 * The listing's publisher manages it; a member who may resolve
	 * engagements may too, for a listing its publisher has abandoned.
	 * @method requireManager
	 * @static
	 * @throws {Users_Exception_NotAuthorized}
	 */
	static function requireManager($userId, $listing)
	{
		if (!$userId
		or ($listing->publisherId !== $userId and !Sharing::canResolve($userId))) {
			throw new Users_Exception_NotAuthorized();
		}
	}

	/**
	 * A member's own open listings, read directly rather than out of a page
	 * of the community's.
	 * @method ofPublisher
	 * @static
	 * @return {array} of Streams_Stream, newest first
	 */
	static function ofPublisher($userId)
	{
		$rows = Streams_Stream::select('*')->where(array(
			'publisherId' => $userId,
			'type' => self::TYPE
		))->orderBy('insertedTime', false)->fetchDbRows();
		$result = array();
		foreach ($rows as $row) {
			if (!$row->closedTime) {
				$result[] = $row;
			}
		}
		return $result;
	}

	/**
	 * The short id used in urls: the unique suffix of the stream name, since
	 * a route segment cannot hold the slashes in "Sharing/listing/<id>".
	 * @method id
	 * @static
	 */
	static function id($name)
	{
		return substr($name, strlen(self::TYPE) + 1);
	}

	/** @method nameFromId @static */
	static function nameFromId($id)
	{
		return self::TYPE . '/' . $id;
	}

	/** @method url @static the listing's page */
	static function url($publisherId, $name)
	{
		return Sharing::url("sharing/$publisherId/" . self::id($name));
	}

	/**
	 * The name of a listing's private-data stream, derived so no attribute
	 * has to point at it.
	 * @method privateName
	 * @static
	 */
	static function privateName($listingName)
	{
		return self::PRIVATE_TYPE . '/' . substr($listingName, strlen(self::TYPE) + 1);
	}

	/**
	 * A listing's private-data stream, or null. Only the publisher (and the
	 * server) can read it; the responder gets a copy on acceptance.
	 * @method privateStream
	 * @static
	 */
	static function privateStream($listing, $asUserId = null)
	{
		return Streams_Stream::fetch($asUserId, $listing->publisherId, self::privateName($listing->name));
	}

	/** Listings per page on the browse page. */
	const PAGE = 50;

	/**
	 * The community's live listings, newest first: one page of them.
	 * @method fetchAll
	 * @static
	 * @param {string|null} $asUserId whose access to apply
	 * @param {array} [$options] as for page()
	 * @return {array} of Streams_Stream
	 */
	static function fetchAll($asUserId, $options = array())
	{
		$page = self::page($asUserId, $options);
		return $page['listings'];
	}

	/**
	 * One page of the community's live listings, newest first.
	 *
	 * The filters -- direction, paused, closed, and what the reader may see
	 * -- are applied BEFORE the page is cut (ro#586, audit R05): relations
	 * are walked in batches, newest first, until the page is full or they
	 * run out. Cutting first, as v1 did with a 100-relation window, let
	 * filtered-out listings use up the window and hide live ones behind it.
	 *
	 * Relations are read directly rather than through Streams::related():
	 * that only fetches streams published by the CATEGORY's publisher, so
	 * member-published listings came back as bare relations anyway, and it
	 * caps the offset at Streams/db/pages. The read check it made on the
	 * category is made here.
	 *
	 * @method page
	 * @static
	 * @param {string|null} $asUserId whose access to apply
	 * @param {array} [$options]
	 * @param {string} [$options.direction] "offer" or "need"; omit for both
	 * @param {boolean} [$options.includePaused=false]
	 * @param {integer} [$options.limit=self::PAGE]
	 * @param {integer} [$options.offset=0] listings to skip, counted after filtering
	 * @return {array} array('listings' => [...], 'hasMore' => bool)
	 * @throws {Users_Exception_NotAuthorized} if the reader cannot see the category
	 */
	static function page($asUserId, $options = array())
	{
		$communityId = Sharing::communityId();
		$direction = Q::ifset($options, 'direction', null);
		$includePaused = !empty($options['includePaused']);
		$limit = max(1, (int)Q::ifset($options, 'limit', self::PAGE));
		$offset = max(0, (int)Q::ifset($options, 'offset', 0));
		$empty = array('listings' => array(), 'hasMore' => false);

		$category = Streams_Stream::fetch($asUserId, $communityId, self::CATEGORY);
		if (!$category) {
			return $empty;
		}
		if (!$category->testReadLevel('relations')) {
			throw new Users_Exception_NotAuthorized();
		}

		$wanted = $offset + $limit + 1; // one more than the page: is there a next?
		$batch = 100;
		$matched = array();
		for ($from = 0; count($matched) < $wanted; $from += $batch) {
			$relations = Streams_RelatedTo::select('fromPublisherId, fromStreamName')->where(array(
				'toPublisherId' => $communityId,
				'toStreamName' => self::CATEGORY,
				'type' => self::RELATION
			))->orderBy('weight', false)->limit($batch, $from)
				->ignoreCache()->caching(false)->fetchAll(PDO::FETCH_ASSOC);
			if (!$relations) {
				break;
			}
			$namesByPublisher = array();
			foreach ($relations as $r) {
				$namesByPublisher[$r['fromPublisherId']][] = $r['fromStreamName'];
			}
			// Streams::fetch() applies the reader's access; keep relation order.
			// refetch, and ignoreCache on the relations above: both caches
			// are per request, and a page must not show a listing as it was
			// before something earlier in the same request changed it.
			$fetched = array();
			foreach ($namesByPublisher as $publisherId => $names) {
				$streams = Streams::fetch($asUserId, $publisherId, $names, '*', array('refetch' => true));
				foreach ($streams as $name => $stream) {
					$fetched[$publisherId][$name] = $stream;
				}
			}
			foreach ($relations as $r) {
				$stream = Q::ifset($fetched, $r['fromPublisherId'], $r['fromStreamName'], null);
				if (self::isBrowsable($stream, $direction, $includePaused)) {
					$matched[] = $stream;
				}
			}
			if (count($relations) < $batch) {
				break;
			}
		}
		return array(
			'listings' => array_slice($matched, $offset, $limit),
			'hasMore' => count($matched) > $offset + $limit
		);
	}

	/**
	 * Whether a fetched stream belongs on the browse page.
	 * @method isBrowsable
	 * @static
	 * @protected
	 */
	protected static function isBrowsable($stream, $direction, $includePaused)
	{
		if (!$stream or $stream->type !== self::TYPE or !$stream->testReadLevel('content')) {
			return false;
		}
		if ($direction and $stream->getAttribute('direction') !== $direction) {
			return false;
		}
		if (!$includePaused and $stream->getAttribute('paused')) {
			return false;
		}
		// A closed listing is gone from the market (Streams::close() also
		// drops its relation to the category; this covers a listing closed
		// some other way).
		return !$stream->closedTime;
	}

	/**
	 * Fetch one listing as a user, or null when it does not exist, is the
	 * wrong type, or the user may not see it.
	 * @method fetch
	 * @static
	 */
	static function fetch($asUserId, $publisherId, $name)
	{
		// Streams::fetch returns the row regardless of access; test the
		// reader's effective level here.
		$stream = Streams_Stream::fetch($asUserId, $publisherId, $name);
		if (!$stream or $stream->type !== self::TYPE or !$stream->testReadLevel('content')) {
			return null;
		}
		return $stream;
	}

	/**
	 * What a listing looks like to the client and to views: the stream's
	 * export plus the facts pulled up from attributes.
	 * @method export
	 * @static
	 */
	static function export($stream)
	{
		$a = $stream->getAllAttributes();
		return array(
			'publisherId' => $stream->publisherId,
			'name' => $stream->name,
			'id' => self::id($stream->name),
			'url' => self::url($stream->publisherId, $stream->name),
			'title' => $stream->title,
			'content' => $stream->content,
			// Absent on a row just returned by Streams::create(); set once fetched.
			'insertedTime' => Q::ifset($stream->fields, 'insertedTime', null),
			'direction' => Q::ifset($a, 'direction', 'offer'),
			'kind' => Q::ifset($a, 'kind', 'item'),
			'exclusive' => !empty($a['exclusive']),
			'custody' => !empty($a['custody']),
			'area' => Q::ifset($a, 'area', ''),
			'paused' => !empty($a['paused']),
			'closed' => !empty($stream->closedTime)
		);
	}

	protected static function bool($value)
	{
		if (is_string($value)) {
			return in_array(strtolower($value), array('1', 'true', 'yes', 'on'), true);
		}
		return (bool)$value;
	}
}
