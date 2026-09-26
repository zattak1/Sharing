<?php
/**
 * Sharing plugin static facade.
 *
 * Members post listings in either direction -- an offer (an item, later
 * their time or a space) or a need -- and other members respond with
 * engagements. This class holds the label gates and the community-scoped
 * helpers every handler needs; listing and engagement logic live in
 * Sharing_Listing and Sharing_Engagement.
 *
 * App-agnostic on purpose: the labels come from config and the community
 * from Users::currentCommunityId(), never from a host app's name.
 *
 * @module Sharing
 */
/**
 * @class Sharing
 */
abstract class Sharing
{
	/**
	 * The four label gates. Each answers "may this user do X in the current
	 * community" from the matching Sharing/labels/<gate> config list.
	 * @method canCreateOffer
	 * @static
	 * @param {string|null} [$userId=null] Defaults to the logged-in user.
	 * @return {boolean}
	 */
	static function canCreateOffer($userId = null)
	{
		return self::hasAnyLabel('canCreateOffer', $userId);
	}
	/** @method canRespondToOffer */
	static function canRespondToOffer($userId = null)
	{
		return self::hasAnyLabel('canRespondToOffer', $userId);
	}
	/** @method canCreateNeed */
	static function canCreateNeed($userId = null)
	{
		return self::hasAnyLabel('canCreateNeed', $userId);
	}
	/** @method canRespondToNeed */
	static function canRespondToNeed($userId = null)
	{
		return self::hasAnyLabel('canRespondToNeed', $userId);
	}

	/**
	 * Gate by listing direction: "offer" or "need".
	 * @method canCreate
	 * @static
	 */
	static function canCreate($direction, $userId = null)
	{
		return self::hasAnyLabel(self::gateFor('create', $direction), $userId);
	}
	/** @method canRespond */
	static function canRespond($direction, $userId = null)
	{
		return self::hasAnyLabel(self::gateFor('respond', $direction), $userId);
	}
	/** @method requireCanCreate @throws {Users_Exception_NotAuthorized} */
	static function requireCanCreate($direction, $userId = null)
	{
		if (!self::canCreate($direction, $userId)) {
			throw new Users_Exception_NotAuthorized();
		}
	}
	/** @method requireCanRespond @throws {Users_Exception_NotAuthorized} */
	static function requireCanRespond($direction, $userId = null)
	{
		if (!self::canRespond($direction, $userId)) {
			throw new Users_Exception_NotAuthorized();
		}
	}

	/**
	 * All four flags at once, for Q_responseExtras and tools.
	 * @method gates
	 * @static
	 * @return {array}
	 */
	static function gates($userId = null)
	{
		$result = array();
		foreach (self::$gateNames as $gate) {
			$result[$gate] = self::hasAnyLabel($gate, $userId);
		}
		return $result;
	}

	/**
	 * Forget the per-request label cache, e.g. after a label changes in the
	 * same process (tests, scripts).
	 * @method clearGateCache
	 * @static
	 */
	static function clearGateCache()
	{
		unset(Q::$state['Sharing']);
	}

	static $gateNames = array(
		'canCreateOffer', 'canRespondToOffer', 'canCreateNeed', 'canRespondToNeed',
		'canResolve'
	);

	/**
	 * The fifth gate: who may resolve an engagement neither party will
	 * finish -- cancel one that is accepted or active and abandoned, or close
	 * a listing on the publisher's behalf (ro#586, audit R18). Not a
	 * direction gate, so it has no canCreate/canRespond pair.
	 * @method canResolve
	 * @static
	 * @param {string|null} [$userId=null] Defaults to the logged-in user.
	 * @return {boolean}
	 */
	static function canResolve($userId = null)
	{
		return self::hasAnyLabel('canResolve', $userId);
	}

	/**
	 * Run $callable as a server-side Sharing write.
	 *
	 * The plugin's streams are guarded at the Streams layer (the
	 * Sharing/before/guard* handlers, ro#586): outside this scope a
	 * Sharing stream cannot be created, cannot have its attributes, access
	 * levels or closedTime changed, cannot be related or unrelated, and takes
	 * no message but chat. That is what makes the plugin's own methods the
	 * only write path, whatever generic Streams handler a client reaches --
	 * Streams/stream POST, Streams/related, Streams/form and Streams/message
	 * all write streams they never check against this plugin's rules.
	 *
	 * Re-entrant; the depth is per request, and PHP serves one request per
	 * process, so no other request can observe it.
	 *
	 * @method asServer
	 * @static
	 * @param {callable} $callable
	 * @return {mixed} whatever $callable returns
	 */
	static function asServer($callable)
	{
		++self::$serverDepth;
		try {
			return call_user_func($callable);
		} finally {
			--self::$serverDepth;
		}
	}

	/**
	 * Whether a Sharing server-side write is in progress (see asServer).
	 * @method isServerWrite
	 * @static
	 * @return {boolean}
	 */
	static function isServerWrite()
	{
		return self::$serverDepth > 0;
	}

	/**
	 * Server-side length limits, in bytes of the value as JSON-encoded
	 * (ro#586, audit R01).
	 *
	 * Measured encoded, not as typed: every one of these ends up inside a
	 * JSON column -- the engagement's attributes (1023 bytes: note, private),
	 * the listing's attributes (area), the accepted message's instructions
	 * (8191 bytes: title, content, area) -- and json_encode escapes a
	 * non-ASCII character to six bytes or more. Without them an ordinary
	 * four-paragraph description made its listing impossible to accept,
	 * because the save threw a bare "Exceedingly long value" exception.
	 * The composer's maxlength attributes are the matching hint.
	 * @property {array} $limits
	 * @static
	 */
	static $limits = array(
		'title' => 255,
		'content' => 3000,
		'area' => 200,
		'private' => 350,
		'note' => 250
	);

	/**
	 * Refuse a value longer than its limit, as a field error.
	 * @method requireFits
	 * @static
	 * @param {string} $field a key of self::$limits
	 * @param {string} $value
	 * @throws {Q_Exception_WrongValue}
	 */
	static function requireFits($field, $value)
	{
		$max = self::$limits[$field];
		if (self::encodedLength($value) > $max) {
			throw new Q_Exception_WrongValue(array(
				'field' => $field,
				'range' => "shorter (at most about $max characters)"
			), $field);
		}
	}

	/**
	 * Bytes a value takes once JSON-encoded the way Q stores it.
	 * @method encodedLength
	 * @static
	 * @return {integer}
	 */
	static function encodedLength($value)
	{
		return strlen(Q::json_encode($value));
	}

	/** @property {integer} $serverDepth @protected */
	protected static $serverDepth = 0;

	/**
	 * The stream types this plugin owns and guards.
	 * @property {array} $types
	 * @static
	 */
	static $types = array(
		'Sharing/listing', 'Sharing/listing/private', 'Sharing/engagement', 'Sharing/notices'
	);

	protected static function gateFor($verb, $direction)
	{
		if ($direction !== 'offer' and $direction !== 'need') {
			throw new Q_Exception_WrongValue(array(
				'field' => 'direction', 'range' => '"offer" or "need"'
			));
		}
		return ($verb === 'create' ? 'canCreate' : 'canRespondTo') . ucfirst($direction);
	}

	/**
	 * Url of a plugin page by path. Path-based on purpose: Q_Uri::url() wants
	 * module/action form and would generate the API route (routes@start) for
	 * these actions.
	 * @method url
	 * @static
	 * @param {string} $path e.g. "sharing" or "sharing/<publisherId>/<name>"
	 * @return {string}
	 */
	static function url($path)
	{
		return Q_Request::baseUrl() . '/' . ltrim($path, '/');
	}

	/**
	 * The community whose labels gate sharing: the current one.
	 * @method communityId
	 * @static
	 * @return {string}
	 */
	static function communityId()
	{
		return Users::currentCommunityId(true);
	}

	/**
	 * The configured labels for a gate, with {{app}} interpolated.
	 * @method labels
	 * @static
	 * @param {string} $gate one of self::$gateNames
	 * @return {array}
	 */
	static function labels($gate)
	{
		$labels = (array)Q_Config::get('Sharing', 'labels', $gate, array());
		return array_map(function ($label) {
			return Q::interpolate($label, array('app' => Q::app()));
		}, $labels);
	}

	/**
	 * The idiom for "does this user hold any of these labels here", cached
	 * per request. Swap for Users_Label::hasLabel() when Qbix/Users#6 lands.
	 * @method hasAnyLabel
	 * @static
	 * @protected
	 */
	protected static function hasAnyLabel($gate, $userId = null)
	{
		if (!isset($userId)) {
			$user = Users::loggedInUser(false, false);
			if (!$user) {
				return false;
			}
			$userId = $user->id;
		}
		if (isset(Q::$state['Sharing'][$gate][$userId])) {
			return Q::$state['Sharing'][$gate][$userId];
		}
		$labels = self::labels($gate);
		$result = !empty($labels)
			&& !empty(Users::roles(self::communityId(), $labels, array(), $userId));
		Q::$state['Sharing'][$gate][$userId] = $result;
		return $result;
	}
}
