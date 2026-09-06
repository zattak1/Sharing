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
		$private = trim((string)Q::ifset($params, 'private', ''));
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

	/**
	 * The community's live listings, newest first.
	 * @method fetchAll
	 * @static
	 * @param {string|null} $asUserId whose access to apply
	 * @param {array} [$options]
	 * @param {string} [$options.direction] "offer" or "need"; omit for both
	 * @param {boolean} [$options.includePaused=false]
	 * @return {array} of Streams_Stream
	 */
	static function fetchAll($asUserId, $options = array())
	{
		$communityId = Sharing::communityId();
		$direction = Q::ifset($options, 'direction', null);
		$includePaused = !empty($options['includePaused']);
		// relationsOnly, then fetch per publisher: Streams::related() only
		// fetches related streams whose publisher is the CATEGORY's publisher
		// (the `$r->fromPublisherId === $publisherId` filter before its
		// Streams::fetch), so member-published listings come back as relations
		// with no streams. This is the same reason Communities publishes events
		// as the community. Streams::fetch() applies the reader's access.
		$relations = Streams::related($asUserId, $communityId, self::CATEGORY, true, array(
			'type' => self::RELATION,
			'relationsOnly' => true,
			// Person-published streams must not vanish behind the Assets
			// peak-credits filter (docs/qbix-gotchas.md "invisible-users").
			'dontFilterUsers' => true,
			'limit' => 100
		));
		$namesByPublisher = array();
		foreach ($relations as $r) {
			$namesByPublisher[$r->fromPublisherId][] = $r->fromStreamName;
		}
		$streams = array();
		foreach ($namesByPublisher as $publisherId => $names) {
			foreach (Streams::fetch($asUserId, $publisherId, $names) as $stream) {
				if ($stream and $stream->testReadLevel('content')) {
					$streams[] = $stream;
				}
			}
		}
		$result = array();
		foreach ($streams as $stream) {
			if ($stream->type !== self::TYPE) {
				continue;
			}
			if ($direction and $stream->getAttribute('direction') !== $direction) {
				continue;
			}
			if (!$includePaused and $stream->getAttribute('paused')) {
				continue;
			}
			// Streams::close() keeps the row (and its relation) and sets
			// closedTime; a closed listing is gone from the market.
			if ($stream->closedTime) {
				continue;
			}
			$result[] = $stream;
		}
		usort($result, function ($a, $b) {
			return strcmp($b->insertedTime, $a->insertedTime);
		});
		return $result;
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
