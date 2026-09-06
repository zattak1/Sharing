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

	static $gateNames = array(
		'canCreateOffer', 'canRespondToOffer', 'canCreateNeed', 'canRespondToNeed'
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
